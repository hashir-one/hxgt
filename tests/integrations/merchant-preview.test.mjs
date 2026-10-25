import test from 'node:test';
import assert from 'node:assert/strict';
import {parsePreviewAmount, createPreview, advancePreview} from '../../resources/js/merchant-preview.js';

test('two distinct bank alerts confirm; a duplicate alone cannot', () => {
    const initial = createPreview('500.00');
    const sms = advancePreview(initial, 'sms');
    assert.equal(sms.state, 'detected');
    assert.equal(advancePreview(sms, 'sms').state, 'detected');
    const paid = advancePreview(sms, 'notification');
    assert.equal(paid.state, 'confirmed');
    assert.equal(paid.method, 'Two bank alerts');
    assert.equal(initial.state, 'waiting');
    assert.deepEqual(initial.sources, []);
});

test('notification first and one-click success both confirm', () => {
    const first = advancePreview(createPreview('25'), 'notification');
    assert.equal(first.state, 'detected');
    assert.equal(advancePreview(first, 'sms').state, 'confirmed');
    assert.equal(advancePreview(createPreview('25'), 'automatic').state, 'confirmed');
});

test('bank email obeys the configured payable threshold', () => {
    assert.throws(() => advancePreview(createPreview('499.62'), 'email'), /at least ₹500.00/);
    const paid = advancePreview(createPreview('499.63'), 'email');
    assert.equal(paid.amount, 50000);
    assert.equal(paid.state, 'confirmed');
    assert.equal(paid.method, 'Bank email');
    assert.throws(() => advancePreview(createPreview('500', 100000), 'email'), /₹1000.00/);
});

test('pause blocks confirmation and resume preserves the first bank alert', () => {
    const paused = advancePreview(advancePreview(createPreview('10'), 'sms'), 'pause');
    assert.equal(paused.state, 'paused');
    assert.throws(() => advancePreview(paused, 'automatic'), /Resume/);
    const resumed = advancePreview(paused, 'pause');
    assert.equal(resumed.state, 'detected');
    assert.equal(advancePreview(resumed, 'notification').state, 'confirmed');
    assert.equal(advancePreview(advancePreview(createPreview('10'), 'pause'), 'pause').state, 'waiting');
});

test('expired and review states cannot later confirm or return to a success page', () => {
    for (const action of ['expire', 'review']) {
        const terminal = advancePreview(createPreview('10'), action);
        assert.equal(terminal.state, action === 'expire' ? 'expired' : 'needs_review');
        assert.throws(() => advancePreview(terminal, 'automatic'), /complete/);
        assert.throws(() => advancePreview(terminal, 'return'), /Confirm/);
    }
    assert.equal(advancePreview(advancePreview(createPreview('10'), 'pause'), 'expire').state, 'expired');
});

test('receipt return requires confirmation and confirmed results cannot be overwritten', () => {
    assert.throws(() => advancePreview(createPreview('10'), 'return'), /Confirm/);
    const paid = advancePreview(createPreview('10'), 'automatic');
    assert.equal(advancePreview(paid, 'return').returned, true);
    assert.throws(() => advancePreview(paid, 'expire'), /complete/);
    assert.throws(() => advancePreview(paid, 'pause'), /complete/);
});

test('starting a new test resets evidence and receipt state', () => {
    const completed = advancePreview(advancePreview(createPreview('10'), 'automatic'), 'return');
    const fresh = createPreview('20.10');
    assert.equal(completed.returned, true);
    assert.deepEqual(fresh, {amount: 2047, emailMinimum: 50000, state: 'waiting', sources: [], method: null, returned: false});
});

test('amount validation uses exact paise and rejects malformed or out-of-range inputs', () => {
    assert.equal(parsePreviewAmount('0.01'), 1);
    assert.equal(parsePreviewAmount('12.3'), 1230);
    assert.equal(parsePreviewAmount('1000000.00'), 100000000);
    for (const amount of ['', '-1', '0', '1e3', '5.001', '1000000.01', 'NaN', '<script>', '1,000']) {
        assert.throws(() => createPreview(amount));
    }
    assert.throws(() => advancePreview(createPreview('10'), 'unknown'), /supported/);
});
