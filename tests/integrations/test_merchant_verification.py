import importlib.util
import hashlib
import hmac
import json
from pathlib import Path
import sqlite3
import time
import unittest

spec = importlib.util.spec_from_file_location('merchant', Path(__file__).resolve().parents[2] / 'examples/python-merchant-verification.py')
merchant = importlib.util.module_from_spec(spec)
spec.loader.exec_module(merchant)

class MerchantVerificationTest(unittest.TestCase):
    def test_bad_signatures_fail_closed(self):
        self.assertFalse(merchant.verify_webhook(b'{}', 'invalid', 'short', 'secret'))
        timestamp = str(int(time.time()))
        signature = hmac.new(b'secret', timestamp.encode()+b'.{}', hashlib.sha256).hexdigest()
        self.assertTrue(merchant.verify_webhook(b'{}', timestamp, signature, 'secret'))
        self.assertFalse(merchant.verify_webhook(b'{"changed":true}', timestamp, signature, 'secret'))

    def test_verification_requires_both_snapshot_and_local_expectation(self):
        order = dict(id='order-1', payable_amount_minor=10001, currency='INR',payment_reference_hash='a'*64,event_sequence=2,status='paid',confirmation_method='automatic')
        values = ['order-1',10001,'INR','a'*64,2,'paid','automatic']
        event = dict(event_id=1, order_id='order-1', sequence_number=2, event_type='order.paid', verification_hash=hashlib.sha256(json.dumps(values,separators=(',',':')).encode()).hexdigest())
        expected = dict(order_id='order-1',amount_minor=10001,currency='INR')
        self.assertTrue(merchant.verify_fulfillment(event,order,expected))
        self.assertFalse(merchant.verify_fulfillment(event,dict(order,payment_reference_hash='b'*64),expected))
        self.assertFalse(merchant.verify_fulfillment(event,dict(order,status='expired'),expected))
        self.assertFalse(merchant.verify_fulfillment(event,order,dict(expected,amount_minor=10002)))

    def test_duplicate_webhooks_enqueue_one_fulfillment(self):
        db = sqlite3.connect(':memory:')
        db.execute('CREATE TABLE fulfillment_outbox (order_id TEXT)')
        callback = lambda conn, order_id: conn.execute('INSERT INTO fulfillment_outbox VALUES (?)',(order_id,))
        merchant.commit_once(db,1,'order-1',2,callback)
        merchant.commit_once(db,1,'order-1',2,callback)
        self.assertEqual(db.execute('SELECT COUNT(*) FROM fulfillment_outbox').fetchone()[0],1)

if __name__ == '__main__':
    unittest.main()
