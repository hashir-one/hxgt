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
