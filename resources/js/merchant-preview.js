// This module has no network or payment API calls. All outcomes live in this tab.
export function parsePreviewAmount(value) {
    const match = /^(\d{1,7})(?:\.(\d{1,2}))?$/.exec(String(value).trim());
    if (!match) throw new Error('Enter a rupee amount with up to two decimal places.');
    const minor = Number(match[1]) * 100 + Number((match[2] || '').padEnd(2, '0'));
    if (minor < 1 || minor > 100000000) throw new Error('Enter an amount between ₹0.01 and ₹1,000,000.00.');
    return minor;
}

export function createPreview(amount, emailMinimum = 50000) {
    const base = parsePreviewAmount(amount);
    return {amount: base + 37, emailMinimum, state: 'waiting', sources: [], method: null, returned: false};
}

export function advancePreview(current, action) {
    const next = {...current, sources: [...current.sources]};
    if (action === 'return') {
        if (next.state !== 'confirmed') throw new Error('Confirm a test payment before returning to the store.');
        return {...next, returned: true};
    }
    if (['confirmed', 'expired', 'needs_review'].includes(next.state)) {
        throw new Error('This test is complete. Choose “Start new test” to try another scenario.');
    }
    if (action === 'pause') {
        next.state = next.state === 'paused' ? (next.sources.length ? 'detected' : 'waiting') : 'paused';
        return next;
    }
    if (action === 'expire') return {...next, state: 'expired'};
    if (next.state === 'paused') throw new Error('Resume the gateway before simulating a payment.');
    if (action === 'review') return {...next, state: 'needs_review'};
    const sources = {automatic: ['sms', 'notification'], sms: ['sms'], notification: ['notification'], email: ['email']}[action];
    if (!sources) throw new Error('Choose a supported test scenario.');
    if (action === 'email' && next.amount < next.emailMinimum) {
        throw new Error(`Bank email confirmation requires at least ₹${(next.emailMinimum / 100).toFixed(2)} payable. Start a new test with a higher amount.`);
    }
    next.sources = [...new Set([...next.sources, ...sources])];
    if (next.sources.includes('email') || (next.sources.includes('sms') && next.sources.includes('notification'))) {
        next.state = 'confirmed';
        next.method = next.sources.includes('email') ? 'Bank email' : 'Two bank alerts';
    } else next.state = 'detected';
    return next;
}

export function mountPreview(root) {
    const find = (name) => root.querySelector(`[data-preview-${name}]`);
    const amountInput = root.querySelector('#preview-amount');
    const lifetime = Number(root.dataset.expiryMinutes) * 60000;
    const minimum = Number(root.dataset.emailMinimum);
    let state = createPreview(amountInput.value, minimum);
    let expiresAt = Date.now() + lifetime;
    let testNumber = 1;
    const labels = {
        waiting: 'Waiting for payment', detected: 'Payment detected. Verifying the second bank alert.',
        confirmed: 'Test payment confirmed', expired: 'This order has expired. Start a new test to retry.',
        needs_review: 'Your payment needs merchant review.', paused: 'Payments temporarily unavailable. Please wait before paying.',
    };
    const log = (message) => {
        const item = document.createElement('li');
        item.textContent = `${new Date().toLocaleTimeString()} · ${message}`;
        find('timeline').prepend(item);
        while (find('timeline').children.length > 12) find('timeline').lastElementChild.remove();
    };
    const message = (text) => { find('message').textContent = text; };
    const render = () => {
        const paid = state.state === 'confirmed';
        find('state').textContent = labels[state.state];
        find('payment').hidden = !['waiting', 'detected'].includes(state.state);
        find('receipt').hidden = !paid || state.returned;
        find('return').hidden = !state.returned;
        find('help').hidden = paid;
        find('method').textContent = state.method || '';
        find('payable').textContent = new Intl.NumberFormat('en-IN', {style: 'currency', currency: 'INR'}).format(state.amount / 100);
        find('amount-label').textContent = paid ? 'Amount received · demo only' : 'Pay exactly · demo amount';
        find('verify').textContent = paid ? `Simulated confirmation: ${state.method}.` : labels[state.state];
        find('delivery').textContent = paid ? 'A live integration would now verify the signed event and fetch the order. No webhook was sent.' : 'No real webhook will be sent.';
        root.querySelector('[data-preview-action="pause"]').textContent = state.state === 'paused' ? 'Resume gateway' : 'Pause gateway';
    };
    const tick = () => {
        const seconds = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
        find('countdown').textContent = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
        if (!seconds && ['waiting', 'detected', 'paused'].includes(state.state)) {
            state = advancePreview(state, 'expire');
            log('Demo order expired. No payment was confirmed.');
            render();
        }
    };
    find('form').addEventListener('submit', (event) => {
        event.preventDefault();
        try {
            state = createPreview(amountInput.value, minimum);
            expiresAt = Date.now() + lifetime;
            testNumber += 1;
            find('order').textContent = `DEMO-${String(testNumber).padStart(4, '0')}`;
            find('timeline').replaceChildren();
            log('New demo checkout opened. No real order was created.');
            message('Test reset. Choose a scenario or add one bank alert at a time.');
            render(); tick();
        } catch (error) { message(error.message); amountInput.focus(); }
    });
    root.querySelectorAll('[data-preview-action]').forEach((button) => button.addEventListener('click', () => {
        tick();
        const action = button.dataset.previewAction;
        if (action === 'app' || action === 'copy') {
            message(action === 'app' ? 'In live checkout this opens a UPI app. Here, choose “Successful payment” or send the bank alerts to continue the demo.' : 'Demo UPI ID: preview@demo. Your real UPI ID appears only in live checkout.');
            log(action === 'app' ? 'Customer tried the UPI app button (demo).' : 'Customer tried copying the demo UPI ID.');
            return;
        }
        try {
            state = advancePreview(state, action);
            const events = {automatic: 'Matching SMS and bank notification simulated.', sms: 'SMS alert simulated.', notification: 'Bank notification simulated.', email: 'Bank email simulated.', review: 'Payment sent for review in the demo.', expire: 'Order expiry simulated.', pause: state.state === 'paused' ? 'Gateway paused in the demo.' : 'Gateway resumed in the demo.', return: 'Store return page previewed. No redirect or fulfillment occurred.'};
            log(events[action]);
            message(state.returned ? 'Demo journey complete. Start a new test to explore another outcome.' : labels[state.state]);
            render();
        } catch (error) { message(error.message); }
    }));
    root.querySelectorAll('[data-preview-width]').forEach((button) => button.addEventListener('click', () => {
        find('device').classList.toggle('preview-mobile', button.dataset.previewWidth === 'mobile');
        root.querySelectorAll('[data-preview-width]').forEach((choice) => choice.setAttribute('aria-pressed', String(choice === button)));
    }));
    find('form').hidden = false;
    root.querySelectorAll('[data-preview-controls]').forEach((element) => { element.hidden = false; });
    log('Demo checkout opened. No real order was created.');
    render(); tick();
    let interval = setInterval(tick, 1000);
    window.addEventListener('pagehide', () => clearInterval(interval));
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) { tick(); interval = setInterval(tick, 1000); }
    });
}
