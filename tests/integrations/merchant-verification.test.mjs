import test from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import {verifyWebhook, verifyFulfillment, signedHeaders} from '../../examples/node-merchant-verification.mjs';

test('invalid signatures and expired timestamps fail closed without throwing', () => {
  assert.equal(verifyWebhook('{}', 'invalid', 'short', 'secret'), false);
  assert.equal(verifyWebhook('{}', '1', 'short', 'secret'), false);
  const now = Math.floor(Date.now()/1000);
  const signature = crypto.createHmac('sha256','secret').update(`${now}.{}`).digest('hex');
  assert.equal(verifyWebhook('{}', String(now), signature, 'secret'), true);
  assert.equal(verifyWebhook('{"changed":true}', String(now), signature, 'secret'), false);
});
test('signed requests use the exact body and path including query', () => {
  const headers = signedHeaders('upi_live_prefix_secret', 'POST', '/api/v1/orders?q=1', '{}');
  const input = ['POST','/api/v1/orders?q=1', headers['X-UPI-Timestamp'],headers['X-UPI-Nonce'],crypto.createHash('sha256').update('{}').digest('hex')].join('\n');
  assert.equal(headers['X-UPI-Signature'], crypto.createHmac('sha256','secret').update(input).digest('hex'));
});
test('snapshot binds reference and amount; unpaid and manual default never fulfill', () => {
  const order = {id:'order-1', payable_amount_minor:10001, currency:'INR',payment_reference_hash:'a'.repeat(64),event_sequence:2,status:'paid',confirmation_method:'automatic'};
  const event = {event_id:1,order_id:'order-1',sequence_number:2,event_type:'order.paid'};
  const expected = {order_id:'order-1',amount_minor:10001,currency:'INR'};
  event.verification_hash = crypto.createHash('sha256').update(JSON.stringify([order.id,10001,'INR',order.payment_reference_hash,2,'paid','automatic'])).digest('hex');
  assert.equal(verifyFulfillment(event,order,expected),true);
  assert.equal(verifyFulfillment(event,{...order,payment_reference_hash:'b'.repeat(64)},expected),false);
  assert.equal(verifyFulfillment(event,{...order,status:'expired'},expected),false);
  assert.equal(verifyFulfillment(event,order,{...expected,amount_minor:10002}),false);
  assert.equal(verifyFulfillment({...event,event_type:'order.manual_confirmed'},order,expected),false);
});
