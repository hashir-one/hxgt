import crypto from 'node:crypto';

export function signedHeaders(token, method, pathWithQuery, rawBody = '') {
  const secret = token.split('_').slice(3).join('_');
  if (!secret) throw new Error('Invalid API key');
  const timestamp = String(Math.floor(Date.now() / 1000));
  const nonce = crypto.randomBytes(24).toString('hex');
  const canonical = [method.toUpperCase(), pathWithQuery, timestamp, nonce, crypto.createHash('sha256').update(rawBody).digest('hex')].join('\n');
  return {Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', 'X-UPI-Timestamp': timestamp, 'X-UPI-Nonce': nonce, 'X-UPI-Signature': crypto.createHmac('sha256', secret).update(canonical).digest('hex')};
}

export function verifyWebhook(rawBody, timestamp, providedSignature, secret, now = Date.now() / 1000) {
  if (!/^\d+$/.test(String(timestamp)) || Math.abs(now - Number(timestamp)) > 300 || typeof providedSignature !== 'string') return false;
  const signature = providedSignature.replace(/^sha256=/, '');
  if (!/^[a-f0-9]{64}$/.test(signature)) return false;
  const expected = crypto.createHmac('sha256', secret).update(`${timestamp}.`).update(rawBody).digest('hex');
  return crypto.timingSafeEqual(Buffer.from(expected, 'hex'), Buffer.from(signature, 'hex'));
}

export function verifyFulfillment(webhook, order, expected) {
  if (!['order.paid', 'order.manual_confirmed'].includes(webhook.event_type) || order.status !== 'paid') return false;
  if ((webhook.event_type === 'order.manual_confirmed' || order.confirmation_method === 'manual_confirmed') && expected.accept_manual_confirmation !== true) return false;
  if (!Number.isSafeInteger(order.payable_amount_minor) || !Number.isSafeInteger(order.event_sequence) || !/^[a-f0-9]{64}$/.test(order.payment_reference_hash || '')) return false;
  if (webhook.order_id !== order.id || order.id !== expected.order_id || order.payable_amount_minor !== expected.amount_minor || order.currency !== (expected.currency || 'INR') || webhook.sequence_number !== order.event_sequence) return false;
  const proof = crypto.createHash('sha256').update(JSON.stringify([order.id, order.payable_amount_minor, order.currency, order.payment_reference_hash, order.event_sequence, order.status, order.confirmation_method])).digest('hex');
  return typeof webhook.verification_hash === 'string' && /^[a-f0-9]{64}$/.test(webhook.verification_hash) && crypto.timingSafeEqual(Buffer.from(proof, 'hex'), Buffer.from(webhook.verification_hash, 'hex'));
}

// expected MUST come from the merchant's persisted order, never from the webhook.
// commitOnce MUST atomically deduplicate event/order IDs and enqueue fulfillment in
// your database. Returning the browser to checkout never calls this handler.
export async function handlePayment({rawBody, timestamp, signature, webhookSecret, apiBase, readKey, expected, commitOnce, onMismatch}) {
  if (!verifyWebhook(rawBody, timestamp, signature, webhookSecret)) throw new Error('Invalid webhook');
  const event = JSON.parse(rawBody);
  if (!Number.isSafeInteger(event.event_id) || event.event_id < 1 || typeof event.order_id !== 'string') throw new Error('Invalid event');
  if (!['order.paid', 'order.manual_confirmed'].includes(event.event_type)) return {fulfilled: false};
  const base = new URL(apiBase);
  if (base.protocol !== 'https:' || base.username || base.password) throw new Error('HTTPS API required');
  const path = `/api/v1/orders/${encodeURIComponent(event.order_id)}?event_id=${event.event_id}`;
  const response = await fetch(new URL(path, base), {headers: signedHeaders(readKey, 'GET', path), redirect: 'error', signal: AbortSignal.timeout(5000)});
  if (!response.ok) throw new Error('Authoritative order fetch failed');
  const order = await response.json();
  if (!verifyFulfillment(event, order, expected)) {
    await onMismatch({event, order});
    throw new Error('Payment verification mismatch');
  }
  await commitOnce({eventId: event.event_id, orderId: order.id, sequence: order.event_sequence, order});
  const ackPath = '/api/v1/events/ack';
  const body = JSON.stringify({event_id: event.event_id});
  const ack = await fetch(new URL(ackPath, base), {method: 'POST', headers: signedHeaders(readKey, 'POST', ackPath, body), body, redirect: 'error', signal: AbortSignal.timeout(5000)});
  if (!ack.ok) throw new Error('Acknowledgement failed; retry safely with commitOnce');
  return {fulfilled: true};
}
