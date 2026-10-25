<?php

use HxDev\UpiMerchant\RequestSigner;
use HxDev\UpiMerchant\TwoOfTwoVerifier;

// $expectedOrder comes from YOUR persisted order created at checkout initiation.
// $rawBody is the unmodified request body; $httpClient has a trusted HTTPS base URL.
$verifier = new TwoOfTwoVerifier($_ENV['HX_UPI_WEBHOOK_SECRET']);
if (! $verifier->verifyWebhook($rawBody, $_SERVER['HTTP_X_UPI_EVENT_TIMESTAMP'] ?? '', $_SERVER['HTTP_X_UPI_EVENT_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}
$event = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
$path = '/api/v1/orders/'.rawurlencode($event['order_id']).'?event_id='.$event['event_id'];
$pulled = json_decode($httpClient->get($path, [
    'headers' => RequestSigner::headers($_ENV['HX_UPI_READ_KEY'], 'GET', $path),
    'allow_redirects' => false, 'timeout' => 5,
])->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);
$result = $verifier->verifyFulfillment($event, $pulled, [
    'order_id' => $expectedOrder->gateway_order_id,
    'amount_minor' => $expectedOrder->gateway_payable_amount_minor,
    'currency' => 'INR',
    'reference_number_hash' => $pulled['payment_reference_hash'], // Bound to the signed webhook by verification_hash.
    'accept_manual_confirmation' => false,
]);
if (! $result->verified) {
    // Persist both payloads and source IP, alert the operator, and do not fulfill.
    http_response_code(409);
    exit;
}
// Required: transactionally insert a unique order/event receipt and enqueue an
// outbox fulfillment job in YOUR database. Retry duplicates without fulfilling again.
$commitOnce($event['event_id'], $pulled['id'], $pulled['event_sequence']);
$ack = json_encode(['event_id' => $event['event_id']], JSON_THROW_ON_ERROR);
$httpClient->post('/api/v1/events/ack', [
    'headers' => RequestSigner::headers($_ENV['HX_UPI_READ_KEY'], 'POST', '/api/v1/events/ack', $ack),
    'body' => $ack, 'allow_redirects' => false, 'timeout' => 5,
]);
http_response_code(200);
