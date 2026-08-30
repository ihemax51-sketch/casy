import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

window.casyToast = (message) => {
    const existing = document.querySelector('.casy-toast');
    if (existing) existing.remove();
    const toast = document.createElement('div');
    toast.className = 'casy-toast';
    toast.textContent = message;
    document.body.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));
    window.setTimeout(() => {
        toast.classList.remove('show');
        window.setTimeout(() => toast.remove(), 220);
    }, 2600);
};

Alpine.start();

// The compact command/search field doubles as fast page navigation. It stays
// intentionally local: only the allowlisted sidebar destinations are searched.
const pageSearch = document.querySelector('.global-search input');
if (pageSearch) {
    const pages = [...document.querySelectorAll('.studio-nav-link')].map((link) => ({
        label: link.querySelector('.nav-label')?.textContent?.trim().toLowerCase() ?? '',
        href: link.getAttribute('href'),
    }));

    const openPage = () => {
        const query = pageSearch.value.trim().toLowerCase();
        if (!query) return;
        const match = pages.find((page) => page.label.includes(query));
        if (match?.href) {
            window.location.assign(match.href);
        } else {
            window.casyToast?.('No matching page found.');
        }
    };

    pageSearch.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            openPage();
        }
    });

    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            pageSearch.focus();
            pageSearch.select();
        }
    });
}
