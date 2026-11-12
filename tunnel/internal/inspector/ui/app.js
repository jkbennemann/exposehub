// The inspector page. Everything that came from a captured request is put on
// the page as text (textContent), never as markup.
'use strict';

const $ = (id) => document.getElementById(id);
const ACTION = { 'X-Exposehub-Inspector': '1' };

const state = {
    byId: new Map(),     // id -> summary
    order: [],           // ids, newest first
    fresh: new Set(),    // ids that arrived since the last render
    selected: null,
    detail: null,        // the selected exchange in full
    tab: 'request',
    filter: '',
    classes: new Set(['2', '3', '4', '5']),
    tunnels: [],
    awaitingReplayOf: null,
};

// ------------------------------------------------------------------ helpers

function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs)) {
        if (value === undefined || value === null || value === false) continue;
        if (key === 'class') node.className = value;
        // Through the CSSOM: the page's security policy refuses style attributes.
        else if (key === 'style') for (const [prop, v] of Object.entries(value)) node.style.setProperty(prop, v);
        else if (key === 'text') node.textContent = value;
        else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
        else node.setAttribute(key, value === true ? '' : value);
    }
    for (const child of children.flat(Infinity)) {
        if (child === null || child === undefined || child === false) continue;
        node.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return node;
}

// replaceChildren without the surprises: nested lists are flattened, and
// null or false leave nothing instead of the text "null".
function fill(node, ...children) {
    node.replaceChildren(...children.flat(Infinity).filter((c) => c !== null && c !== undefined && c !== false));
}

const pad = (n) => String(n).padStart(2, '0');
const clock = (iso) => { const d = new Date(iso); return `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`; };

function took(ms, done) {
    if (!done) return '…';
    if (ms < 1) return '<1 ms';
    if (ms < 1000) return `${Math.round(ms)} ms`;
    return `${(ms / 1000).toFixed(ms < 10000 ? 2 : 1)} s`;
}

function bytes(n) {
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KiB`;
    return `${(n / 1024 / 1024).toFixed(1)} MiB`;
}

const statusClass = (s) => (s >= 200 && s < 600 ? `s${String(s)[0]}` : 'pending');
const pathOf = (url) => url.split('?')[0] || '/';

function percentile(sorted, p) {
    if (sorted.length === 0) return null;
    return sorted[Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length))];
}

async function api(path, options = {}) {
    const res = await fetch(path, { ...options, headers: { ...(options.headers || {}) } });
    if (!res.ok) throw new Error((await res.text()).trim() || res.statusText);
    return res.status === 204 || res.status === 202 ? null : res.json();
}

// -------------------------------------------------------------------- list

function visible() {
    const q = state.filter.trim().toLowerCase();
    return state.order
        .map((id) => state.byId.get(id))
        .filter((s) => {
            if (s.done && s.status >= 200 && !state.classes.has(String(s.status)[0])) return false;
            if (!q) return true;
            return `${s.method} ${s.url} ${s.status}`.toLowerCase().includes(q);
        });
}

let scheduled = false;
function render() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => {
        scheduled = false;
        const shown = visible();
        renderRows(shown);
        renderStats(shown);
        state.fresh.clear();
    });
}

function renderRows(shown) {
    const rows = $('rows');
    fill(rows, ...shown.map((s) => el('li', {
        class: `row${state.fresh.has(s.id) ? ' new' : ''}`,
        'data-id': s.id,
        'aria-selected': String(s.id === state.selected),
        onclick: () => select(s.id),
    },
    el('span', { class: 'time', text: clock(s.started) }),
    el('span', { class: 'method', text: s.method }),
    el('span', { class: 'path', title: s.url }, s.replay_of ? el('span', { class: 'replay-mark', text: 'replay' }) : null, s.url),
    el('span', { class: `status ${s.done ? statusClass(s.status) : 'pending'}`, text: s.done ? (s.upgraded ? 'ws' : String(s.status)) : '…' }),
    el('span', { class: 'took', text: took(s.duration_ms, s.done) }),
    )));

    const empty = $('empty');
    empty.hidden = shown.length > 0;
    if (shown.length === 0) {
        if (state.order.length > 0) {
            fill(empty, el('h2', { text: 'Nothing matches' }), el('p', { text: 'No request matches the filter and the statuses selected above.' }));
        } else {
            const url = state.tunnels[0]?.url;
            fill(empty, 
                el('h2', { text: 'No requests yet' }),
                el('p', {}, url ? ['Open ', el('code', { text: url }), ' and every request appears here as it happens.'] : 'Requests through your tunnels appear here as they happen.'),
            );
        }
    }
}

function renderStats(shown) {
    const done = shown.filter((s) => s.done && !s.upgraded);
    const errors = done.filter((s) => s.status >= 400).length;
    const sorted = done.map((s) => s.duration_ms).sort((a, b) => a - b);
    const p50 = percentile(sorted, 50);
    const p95 = percentile(sorted, 95);

    const counts = new Map();
    for (const s of shown) counts.set(pathOf(s.url), (counts.get(pathOf(s.url)) || 0) + 1);
    const top = [...counts].sort((a, b) => b[1] - a[1]).slice(0, 3);
    const most = top[0]?.[1] || 1;

    const stat = (value, label, bad) => el('div', { class: `stat${bad ? ' bad' : ''}` }, el('b', { text: value }), el('span', { text: label }));
    fill($('stats'), 
        stat(String(shown.length), shown.length === 1 ? 'request' : 'requests'),
        stat(done.length ? `${Math.round((errors / done.length) * 100)}%` : '–', 'answered 4xx or 5xx', errors > 0),
        stat(p50 === null ? '–' : took(p50, true), 'median time'),
        stat(p95 === null ? '–' : took(p95, true), '95th percentile'),
        el('div', { class: 'busiest' },
            el('span', { text: 'Busiest paths' }),
            top.length
                ? el('ol', {}, top.map(([path, n]) => el('li', {},
                    el('span', { class: 'path', title: path, style: { '--share': `${Math.max(4, (n / most) * 100)}%` } }, path),
                    el('span', { class: 'count', text: String(n) }))))
                : el('span', { class: 'nothing', text: 'none yet' })),
    );
}

// ------------------------------------------------------------------ detail

async function select(id) {
    state.selected = id;
    render();
    try {
        state.detail = await api(`api/requests/${id}`);
    } catch (e) {
        state.detail = null;
        fill($('detail'), el('p', { class: 'note bad', text: e.message }));
        return;
    }
    if (state.selected === id) renderDetail();
}

function renderDetail(note) {
    const e = state.detail;
    if (!e) return;
    const replayable = e.done && !e.upgraded && !e.request_body.truncated;
    const why = e.upgraded ? 'WebSocket connections cannot be replayed.'
        : e.request_body.truncated ? 'The body was larger than what is kept, so it cannot be replayed exactly.'
        : !e.done ? 'Wait until the request has finished.' : null;

    fill($('detail'), 
        el('div', { class: 'headline' },
            el('h2', {}, el('span', { class: 'method', text: e.method }), e.url),
            e.done ? el('span', { class: `status ${statusClass(e.status)}`, text: e.upgraded ? '101 WebSocket' : String(e.status) }) : el('span', { class: 'status pending', text: 'in progress' })),
        el('p', { class: 'meta' },
            el('span', {}, 'at ', el('b', { text: new Date(e.started).toLocaleTimeString() })),
            el('span', {}, 'took ', el('b', { text: took(e.duration_ms, e.done) })),
            e.remote ? el('span', {}, 'from ', el('b', { text: e.remote })) : null,
            el('span', {}, 'via ', el('b', { text: e.host })),
            e.replay_of ? el('span', { text: 'replayed from an earlier request' }) : null),
        // The visitor only saw a generic page; this is the actual cause.
        e.error ? el('p', { class: 'note bad', text: e.error }) : null,
        el('div', { class: 'actions' },
            el('button', { type: 'button', class: 'primary', disabled: !replayable, title: why || 'Send this request to your local service again', onclick: replay }, 'Replay'),
            el('button', { type: 'button', onclick: copyCurl }, 'Copy as curl')),
        note ? el('p', { class: `note${note.bad ? ' bad' : ''}`, role: 'status', text: note.text }) : (why && e.done ? el('p', { class: 'note', text: why }) : null),
        el('div', { class: 'tabs', role: 'tablist' },
            ['request', 'response'].map((tab) => el('button', {
                type: 'button', role: 'tab', 'aria-selected': String(state.tab === tab),
                onclick: () => { state.tab = tab; renderDetail(); },
            }, tab === 'request' ? 'Request' : 'Response'))),
        state.tab === 'request'
            ? section(e.request_header, e.request_body)
            : (e.done ? section(e.response_header || {}, e.response_body) : el('p', { class: 'nothing', text: 'No response yet.' })),
    );
}

function section(headers, body) {
    const names = Object.keys(headers || {}).sort();
    return el('div', {},
        el('h3', {}, 'Headers', el('small', { text: String(names.length) })),
        names.length
            ? el('dl', { class: 'headers' }, names.flatMap((n) => headers[n].map((v) => [el('dt', { text: n }), el('dd', { text: v })])))
            : el('p', { class: 'nothing', text: 'none' }),
        el('h3', {}, 'Body', el('small', { text: body.size ? `${bytes(body.size)}${body.truncated ? `, first ${bytes(atob(body.data || '').length)} kept` : ''}` : 'empty' })),
        body.size ? renderBody(body, contentType(headers)) : null,
    );
}

function contentType(headers) {
    for (const [name, values] of Object.entries(headers || {})) {
        if (name.toLowerCase() === 'content-type') return (values[0] || '').toLowerCase();
    }
    return '';
}

function renderBody(body, type) {
    const raw = atob(body.data || '');
    const u8 = Uint8Array.from(raw, (c) => c.charCodeAt(0));

    if (type.startsWith('image/') && !body.truncated && !type.includes('svg')) {
        return el('img', { class: 'image-body', alt: 'Image body', src: `data:${type.split(';')[0]};base64,${body.data}` });
    }

    const text = new TextDecoder('utf-8', { fatal: false }).decode(u8);
    const binary = /[\u0000-\u0008\u000E-\u001F]/.test(text.slice(0, 2048)) || (text.match(/\uFFFD/g) || []).length > 8;
    if (binary) {
        const hex = [...u8.slice(0, 256)].map((b) => b.toString(16).padStart(2, '0')).join(' ');
        return el('pre', { class: 'body', text: `Binary data. The first ${Math.min(256, u8.length)} bytes:\n\n${hex}` });
    }

    if (type.includes('json') || /^\s*[[{]/.test(text)) {
        try {
            return el('pre', { class: 'body' }, highlightJSON(JSON.stringify(JSON.parse(text), null, 2)));
        } catch { /* not complete JSON, e.g. cut short: show as text */ }
    }

    if (type.includes('x-www-form-urlencoded')) {
        const params = [...new URLSearchParams(text)];
        return el('dl', { class: 'headers' }, params.flatMap(([k, v]) => [el('dt', { text: k }), el('dd', { text: v })]));
    }

    return el('pre', { class: 'body', text });
}

function highlightJSON(json) {
    const out = [];
    const token = /("(?:\\.|[^"\\])*"(\s*:)?|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|\btrue\b|\bfalse\b|\bnull\b|[{}[\],])/g;
    let last = 0;
    for (const m of json.matchAll(token)) {
        if (m.index > last) out.push(json.slice(last, m.index));
        const t = m[0];
        const cls = t.startsWith('"') ? (m[2] ? 'k' : 'str') : /[{}[\],]/.test(t) ? 'punct' : /^-?\d/.test(t) ? 'num' : 'lit';
        out.push(el('span', { class: cls, text: t }));
        last = m.index + t.length;
    }
    out.push(json.slice(last));
    return out;
}

// ---------------------------------------------------------------- actions

async function replay() {
    const e = state.detail;
    try {
        await api(`api/requests/${e.id}/replay`, { method: 'POST', headers: ACTION });
        state.awaitingReplayOf = e.id;
        renderDetail({ text: 'Sent again. The result is selected as soon as it arrives.' });
    } catch (err) {
        renderDetail({ text: err.message, bad: true });
    }
}

function shellQuote(s) {
    return `'${s.replace(/'/g, `'\\''`)}'`;
}

// Control characters that a terminal may act on when the command is pasted,
// even inside quotes. Tab, newline and carriage return are left out: quoted,
// they are plain text.
const CONTROL = /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F-\u009F]/;

// curlCommand turns a captured request into a curl command. The method,
// address, headers and body all come from whoever sent the request, so each
// is one single-quoted word: pasting the command runs curl and nothing else.
function curlCommand(e) {
    const skip = new Set(['host', 'content-length', 'connection', 'accept-encoding', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto']);
    const proto = (e.request_header['X-Forwarded-Proto'] || [])[0] === 'http' ? 'http' : 'https';
    // --globoff: brackets and braces in the path are sent as they are, not
    // expanded by curl into many requests.
    const parts = ['curl', '--globoff', '-X', shellQuote(e.method), shellQuote(`${proto}://${e.host}${e.url}`)];
    const left = [];
    for (const [name, values] of Object.entries(e.request_header)) {
        if (skip.has(name.toLowerCase())) continue;
        for (const v of values) {
            if (CONTROL.test(name) || CONTROL.test(v)) left.push('headers with control characters');
            else parts.push('-H', shellQuote(`${name}: ${v}`));
        }
    }
    if (e.request_body.size) {
        const raw = atob(e.request_body.data || '');
        const text = new TextDecoder().decode(Uint8Array.from(raw, (c) => c.charCodeAt(0)));
        if (e.request_body.truncated || CONTROL.test(text)) left.push('the body, which is binary or was cut short');
        // --data-raw, unlike --data-binary, never reads a file when the body
        // starts with @.
        else parts.push('--data-raw', shellQuote(text));
    }
    const note = left.length ? `Copied without ${[...new Set(left)].join(' and ')}.` : 'Copied.';
    return { command: parts.join(' '), note };
}

async function copyCurl() {
    const { command, note } = curlCommand(state.detail);
    try {
        await navigator.clipboard.writeText(command);
        renderDetail({ text: note });
    } catch {
        renderDetail({ text: 'The browser did not allow copying to the clipboard.', bad: true });
    }
}

async function clearAll() {
    await api('api/requests', { method: 'DELETE', headers: ACTION });
    state.byId.clear();
    state.order = [];
    state.selected = null;
    state.detail = null;
    fill($('detail'), el('div', { class: 'placeholder', text: 'Select a request to see what was sent and what came back.' }));
    render();
}

// --------------------------------------------------------------- live feed

function upsert(s, fresh) {
    if (!state.byId.has(s.id)) {
        state.order.unshift(s.id);
        if (fresh) state.fresh.add(s.id);
    }
    state.byId.set(s.id, s);
    if (state.order.length > 1000) state.byId.delete(state.order.pop());

    if (state.awaitingReplayOf && s.replay_of === state.awaitingReplayOf) {
        state.awaitingReplayOf = null;
        select(s.id);
    } else if (s.id === state.selected && s.done && !state.detail?.done) {
        select(s.id);
    }
}

function connect() {
    const live = $('live');
    const source = new EventSource('api/events');
    source.addEventListener('open', () => { live.dataset.state = 'live'; live.textContent = 'live'; });
    source.addEventListener('error', () => { live.dataset.state = 'lost'; live.textContent = 'reconnecting'; });
    source.addEventListener('exchange', (ev) => { upsert(JSON.parse(ev.data), true); render(); });
}

async function loadTunnels() {
    try {
        state.tunnels = await api('api/tunnels');
    } catch { return; }
    fill($('tunnels'), ...state.tunnels.map((t) => el('li', {},
        el('a', { href: t.url, target: '_blank', rel: 'noopener', text: t.url }),
        el('span', { class: 'wire', 'aria-hidden': 'true' }),
        el('span', { class: 'local', text: t.upstream.replace(/^https?:\/\//, '') }),
        t.protected ? el('span', { class: 'lock', text: 'password protected' }) : null)));
}

// -------------------------------------------------------------- keyboard

function move(step) {
    const shown = visible();
    if (!shown.length) return;
    const at = shown.findIndex((s) => s.id === state.selected);
    const next = shown[Math.max(0, Math.min(shown.length - 1, at === -1 ? 0 : at + step))];
    select(next.id);
    document.querySelector(`.row[data-id="${next.id}"]`)?.scrollIntoView({ block: 'nearest' });
}

document.addEventListener('keydown', (ev) => {
    const typing = ev.target instanceof HTMLInputElement;
    if (ev.key === '/' && !typing) { ev.preventDefault(); $('filter').focus(); }
    else if (ev.key === 'Escape' && typing) { $('filter').value = ''; state.filter = ''; $('filter').blur(); render(); }
    else if (!typing && (ev.key === 'ArrowDown' || ev.key === 'j')) { ev.preventDefault(); move(1); }
    else if (!typing && (ev.key === 'ArrowUp' || ev.key === 'k')) { ev.preventDefault(); move(-1); }
});

$('filter').addEventListener('input', (ev) => { state.filter = ev.target.value; render(); });
$('clear').addEventListener('click', clearAll);
for (const button of document.querySelectorAll('.classes button')) {
    button.addEventListener('click', () => {
        const c = button.dataset.class;
        if (state.classes.has(c)) state.classes.delete(c); else state.classes.add(c);
        button.setAttribute('aria-pressed', String(state.classes.has(c)));
        render();
    });
}

(async () => {
    await loadTunnels();
    try {
        for (const s of (await api('api/requests')).reverse()) upsert(s, false);
    } catch { /* the live feed fills the list */ }
    render();
    connect();
    setInterval(loadTunnels, 5000);
})();
