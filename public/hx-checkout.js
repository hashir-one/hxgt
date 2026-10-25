/* Call from a user click after your server creates and persists the gateway order.
 * onReturn means only that the window closed: read YOUR server's verified status.
 * No browser event or redirect is a payment confirmation.
 */
(() => {
    'use strict';
    globalThis.HXUPI = Object.freeze({
        openCheckout({checkoutUrl, checkoutOrigin, onReturn = () => {}}) {
            const allowed = new URL(checkoutOrigin);
            const target = new URL(checkoutUrl);
            if (allowed.protocol !== 'https:' || allowed.username || allowed.password ||
                target.origin !== allowed.origin || target.username || target.password ||
                !/^\/checkout\/[a-zA-Z0-9]{64}$/.test(target.pathname) || target.hash || target.search) {
                throw new Error('Invalid hosted checkout URL');
            }
            const popup = window.open('about:blank', '_blank', 'popup,width=480,height=760');
            if (!popup) {
                window.location.assign(target.href);
                return {mode: 'redirect'};
            }
            popup.opener = null;
            popup.location.replace(target.href);
            let finished = false;
            let timer;
            const stop = () => { finished = true; clearInterval(timer); window.removeEventListener('pagehide', stop); };
            timer = setInterval(() => {
                if (!finished && popup.closed) {
                    stop();
                    onReturn();
                }
            }, 1000);
            window.addEventListener('pagehide', stop, {once: true});
            return {mode: 'popup', stop};
        }
    });
})();
