// Times marked with data-local are rendered by the server in its own timezone
// and rewritten here in the browser's, so nothing about the viewer's timezone
// has to be stored or sent. The moment comes from the datetime attribute of a
// <time> element, or from data-at on anything else.
const pad = (n, width = 2) => String(n).padStart(width, '0');

const formats = {
    hm: (d) => `${pad(d.getHours())}:${pad(d.getMinutes())}`,
    hms: (d) => `${formats.hm(d)}:${pad(d.getSeconds())}`,
    dm: (d) => `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.`,
    'day-hm': (d) => `${d.toLocaleDateString('en', { weekday: 'short' })} ${formats.hm(d)}`,
    'dmy-hm': (d) => `${formats.dm(d)}${d.getFullYear()} ${formats.hm(d)}`,
    full: (d) => `${formats.dm(d)}${d.getFullYear()} ${formats.hms(d)}.${pad(d.getMilliseconds(), 3)}`,
};

function localize(el) {
    const format = formats[el.dataset.local];
    const date = new Date(el.getAttribute('datetime') ?? el.dataset.at);

    if (!format || Number.isNaN(date.getTime())) {
        return;
    }

    const text = format(date) + (el.dataset.localSuffix ?? '');
    const attr = el.dataset.localAttr;

    // Only write when something differs, so the observer below does not loop.
    if (attr && el.getAttribute(attr) !== text) {
        el.setAttribute(attr, text);
    } else if (!attr && el.textContent !== text) {
        el.textContent = text;
    }
}

function localizeWithin(root) {
    if (root.matches?.('[data-local]')) {
        localize(root);
    }

    root.querySelectorAll?.('[data-local]').forEach(localize);
}

// wire:navigate replaces the whole body, so watch the document root.
document.addEventListener('livewire:navigated', () => localizeWithin(document.body));

document.addEventListener('DOMContentLoaded', () => {
    localizeWithin(document.body);

    // Livewire swaps in server-rendered markup on every update; catch it.
    new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            const target = mutation.target.nodeType === Node.TEXT_NODE ? mutation.target.parentElement : mutation.target;

            if (target) {
                localizeWithin(target.closest?.('[data-local]') ?? target);
            }
        }
    }).observe(document.documentElement, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['title', 'datetime', 'data-at'] });
});
