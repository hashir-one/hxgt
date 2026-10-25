"""Server-side helpers. Persist expected order data and fulfill atomically once."""
import hashlib
import hmac
import json
import re
import secrets
import time


def signed_headers(token: str, method: str, path_with_query: str, raw_body: bytes = b"") -> dict:
    secret = token.split("_", 3)[3]
    timestamp, nonce = str(int(time.time())), secrets.token_hex(24)
    canonical = "\n".join([method.upper(), path_with_query, timestamp, nonce, hashlib.sha256(raw_body).hexdigest()])
    return {"Authorization": "Bearer " + token, "Content-Type": "application/json",
            "X-UPI-Timestamp": timestamp, "X-UPI-Nonce": nonce,
            "X-UPI-Signature": hmac.new(secret.encode(), canonical.encode(), hashlib.sha256).hexdigest()}


def verify_webhook(raw_body: bytes, timestamp: str, signature: str, secret: str) -> bool:
    if not isinstance(timestamp, str) or not re.fullmatch(r"[0-9]+", timestamp):
        return False
    if abs(time.time() - int(timestamp)) > 300 or not isinstance(signature, str):
        return False
    signature = signature.removeprefix("sha256=")
    if not re.fullmatch(r"[a-f0-9]{64}", signature):
        return False
    expected = hmac.new(secret.encode(), timestamp.encode() + b"." + raw_body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, signature)


def verify_fulfillment(event: dict, order: dict, expected: dict) -> bool:
    if event.get("event_type") not in ("order.paid", "order.manual_confirmed") or order.get("status") != "paid":
        return False
    if (event.get("event_type") == "order.manual_confirmed" or order.get("confirmation_method") == "manual_confirmed") and expected.get("accept_manual_confirmation") is not True:
        return False
    if type(order.get("payable_amount_minor")) is not int or type(order.get("event_sequence")) is not int:
        return False
    if not re.fullmatch(r"[a-f0-9]{64}", order.get("payment_reference_hash") or ""):
        return False
    if not (event.get("order_id") == order.get("id") == expected.get("order_id") and order["payable_amount_minor"] == expected.get("amount_minor") and order.get("currency") == expected.get("currency", "INR") and event.get("sequence_number") == order["event_sequence"]):
        return False
    values = [order["id"], order["payable_amount_minor"], order["currency"], order["payment_reference_hash"], order["event_sequence"], order["status"], order.get("confirmation_method")]
    proof = hashlib.sha256(json.dumps(values, separators=(",", ":"), ensure_ascii=False).encode()).hexdigest()
    return isinstance(event.get("verification_hash"), str) and hmac.compare_digest(proof, event["verification_hash"])


def commit_once(connection, event_id: int, order_id: str, sequence: int, enqueue_fulfillment):
    """SQLite example: callback writes to a merchant fulfillment outbox using this connection.

    Run the table DDL during merchant deployment, and keep fulfillment execution in
    a worker. Never perform external shipping/email/payment actions inside callback.
    """
    with connection:
        connection.execute("CREATE TABLE IF NOT EXISTS hx_verified_orders (order_id TEXT PRIMARY KEY, event_id INTEGER UNIQUE NOT NULL, sequence INTEGER NOT NULL)")
        cursor = connection.execute("INSERT OR IGNORE INTO hx_verified_orders VALUES (?, ?, ?)", (order_id, event_id, sequence))
        if cursor.rowcount:
            enqueue_fulfillment(connection, order_id)

# Handler flow: verify_webhook(raw bytes), decode, fetch /api/v1/orders/{id}
# via HTTPS with signed_headers, then verify_fulfillment using YOUR stored order.
# On mismatch: persist both payloads/source IP and alert; never fulfill.
# After commit_once, POST /api/v1/events/ack with its signed JSON body.


def handle_payment(*, raw_body, timestamp, signature, webhook_secret, api_base,
                   read_key, expected, commit_once_callback, on_mismatch):
    """Verify both channels, durably enqueue once, then acknowledge the event.

    commit_once_callback receives (event_id, order_id, sequence, order). It MUST
    atomically deduplicate order/event IDs and write a fulfillment outbox entry.
    """
    from urllib.parse import quote, urlparse
    from urllib.request import Request, build_opener, HTTPRedirectHandler

    class NoRedirect(HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            return None

    if not verify_webhook(raw_body, timestamp, signature, webhook_secret):
        raise ValueError("Invalid webhook")
    event = json.loads(raw_body)
    if type(event.get("event_id")) is not int or event["event_id"] < 1 or not isinstance(event.get("order_id"), str):
        raise ValueError("Invalid event")
    if event.get("event_type") not in ("order.paid", "order.manual_confirmed"):
        return False
    base = urlparse(api_base)
    if base.scheme != "https" or not base.hostname or base.username or base.password:
        raise ValueError("HTTPS API required")
    origin = f"https://{base.netloc}"
    opener = build_opener(NoRedirect)
    path = "/api/v1/orders/" + quote(event["order_id"], safe="") + "?event_id=" + str(event["event_id"])
    with opener.open(Request(origin + path, headers=signed_headers(read_key, "GET", path)), timeout=5) as response:
        order = json.load(response)
    if not verify_fulfillment(event, order, expected):
        on_mismatch(event, order)
        raise ValueError("Payment verification mismatch")
    commit_once_callback(event["event_id"], order["id"], order["event_sequence"], order)
    path = "/api/v1/events/ack"
    body = json.dumps({"event_id": event["event_id"]}, separators=(",", ":")).encode()
    with opener.open(Request(origin + path, data=body, method="POST", headers=signed_headers(read_key, "POST", path, body)), timeout=5) as response:
        if response.status != 200:
            raise ValueError("Acknowledgement failed; retry safely")
    return True
