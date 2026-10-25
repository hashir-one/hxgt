import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const source = readFileSync(new URL('../../public/hx-checkout.js', import.meta.url), 'utf8');
const token = 'a'.repeat(64);
function setup(blocked = false) {
    let tick; let returned = 0; let opened = 0; let redirect;
    const popup = {closed: false, opener: {}, location: {replace(url) { redirect = url; }}};
    const context = {URL, setInterval(fn) { tick = fn; return 1; }, clearInterval() {}, window: {
        open() { opened++; return blocked ? null : popup; }, addEventListener() {}, removeEventListener() {}, location: {assign(url) { redirect = url; }}
    }};
    vm.runInNewContext(source, context);
    return {open: context.HXUPI.openCheckout, popup, tick: () => tick(), returned: () => returned, opened: () => opened, redirect: () => redirect,
        options: {checkoutOrigin: 'https://pay.example', checkoutUrl: `https://pay.example/checkout/${token}`, onReturn() { returned++; }}};
}
test('rejects an untrusted checkout origin before opening a window', () => {
    const fixture = setup();
    assert.throws(() => fixture.open({...fixture.options, checkoutUrl: `https://evil.example/checkout/${token}`}));
    assert.equal(fixture.opened(), 0);
});
test('popup close is only a return notification and fires once', () => {
    const fixture = setup();
    assert.equal(fixture.open(fixture.options).mode, 'popup');
    assert.equal(fixture.popup.opener, null);
    fixture.tick(); assert.equal(fixture.returned(), 0);
    fixture.popup.closed = true; fixture.tick(); fixture.tick();
    assert.equal(fixture.returned(), 1);
});
test('popup blocked browsers redirect without reporting payment success', () => {
    const fixture = setup(true);
    assert.equal(fixture.open(fixture.options).mode, 'redirect');
    assert.equal(fixture.redirect(), fixture.options.checkoutUrl);
    assert.equal(fixture.returned(), 0);
});
