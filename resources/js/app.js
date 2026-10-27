import { mountPreview } from './merchant-preview';

const themeButton = document.querySelector('[data-theme-toggle]');
const updateThemeLabel = () => {
    const dark = document.documentElement.dataset.theme === 'dark';
    themeButton?.setAttribute('aria-label', `Switch to ${dark ? 'light' : 'dark'} theme`);
};
updateThemeLabel();
themeButton?.addEventListener('click', () => {
    const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = theme;
    try { localStorage.setItem('hx-theme', theme); } catch (_) {}
    updateThemeLabel();
});
document.querySelector('[data-menu-toggle]')?.addEventListener('click', (event) => {
    const open = document.body.classList.toggle('nav-open');
    event.currentTarget.setAttribute('aria-expanded', String(open));
});

const preview = document.getElementById('merchant-preview');
if (preview) mountPreview(preview);

const pairingStatus = document.querySelector('[data-pairing-status-url]');
if (pairingStatus?.dataset.pairingPoll === 'true') {
    const updatePairingStatus = async () => {
        if (document.hidden) {
            window.setTimeout(updatePairingStatus, 5000);
            return;
        }
        try {
            const response = await fetch(pairingStatus.dataset.pairingStatusUrl, {
                headers: { Accept: 'application/json' }, cache: 'no-store',
                credentials: 'same-origin', signal: AbortSignal.timeout(10000),
            });
            if (!response.ok) throw new Error('Pairing status unavailable');
            const status = await response.json();
            pairingStatus.querySelector('[data-pairing-message]').textContent = status.message;
            pairingStatus.querySelector('[data-pairing-code]').textContent = status.code ?? '';
            pairingStatus.querySelector('[data-pairing-details]').textContent = Object.entries(status.details ?? {})
                .map(([key, value]) => `${key}: ${value}`).join(' · ');
            if (!status.poll) return;
        } catch (_) {
            pairingStatus.querySelector('[data-pairing-message]').textContent = 'Could not refresh pairing status. Reconnecting; keep your phone data intact.';
        }
        window.setTimeout(updatePairingStatus, 5000);
    };
    window.setTimeout(updatePairingStatus, 5000);
}
