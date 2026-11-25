// Runs resources/js/tour.js against a tiny fake browser and prints what happened as JSON.
// Used by tests/Feature/GuidedToursTest.php. Usage: node tour-harness.mjs '<scenario json>'
//
// scenario: {
//   data: the tour payload (same as <script id="cv-tour-data">), urls: {reset, restarted},
//   elements: { "<selector>": { rect: {top,left,width,height}, hiddenAncestor?: bool } }  (a selector that is absent is not on the page)
//   narrow?: bool, modal?: bool, dirtyForm?: bool, focusedInput?: bool, reduced?: bool,
//   actions: [ "next" | "prev" | "skip" | "close" | "esc" | "right" | "left" | "tick" | "tab" | "shifttab" | "start" | "restart" | { "wait": ms } ]
// }
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const sc = JSON.parse(process.argv[2] || '{}');
const out = { requests: [], toasts: [], drawerClicks: 0, focus: null, layer: false, steps: [] };
const created = [];

class Classes {
    constructor() { this.set = new Set(); }
    add(c) { this.set.add(c); }
    remove(c) { this.set.delete(c); }
    contains(c) { return this.set.has(c); }
}

class Node {
    constructor(tag) {
        this.tag = tag;
        this.nodeType = 1;
        this.children = [];
        this.parentNode = null;
        this.attrs = {};
        this.style = {};
        this.listeners = {};
        this.className = '';
        this.id = '';
        this.hidden = false;
        this.disabled = false;
        this.checked = false;
        this.isConnected = true;
        this._text = '';
        created.push(this);
    }

    get tagName() { return this.tag.toUpperCase(); }
    get textContent() { return this._text + this.children.map((c) => (c.nodeType === 3 ? c.text : c.textContent)).join(''); }
    set textContent(value) { this._text = value; this.children = []; }
    get offsetParent() { return this.hidden ? null : this; }
    get offsetHeight() { return 180; }
    setAttribute(name, value) { this.attrs[name] = value; if (name === 'id') { this.id = value; } }
    getAttribute(name) { return this.attrs[name] ?? null; }
    append(...nodes) { nodes.forEach((n) => this.appendChild(n)); }
    appendChild(node) {
        if (typeof node === 'string') { node = { nodeType: 3, text: node }; }
        node.parentNode = this;
        this.children.push(node);
        if (this === body && node.className === 'cv-tour-toast') { out.toasts.push(node.textContent); }

        return node;
    }
    addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
    removeEventListener() {}
    click() { (this.listeners.click || []).forEach((fn) => fn({ target: this, preventDefault() {}, stopPropagation() {} })); }
    focus() { document.activeElement = this; }
    remove() { this.isConnected = false; if (this.parentNode) { this.parentNode.children = this.parentNode.children.filter((c) => c !== this); } }
    contains(node) { return node === this || this.children.some((c) => c.contains && c.contains(node)); }
    querySelectorAll() { return this.all().filter((n) => ['button', 'input'].includes(n.tag)); }
    all() { return this.children.flatMap((c) => (c.nodeType === 1 ? [c, ...c.all()] : [])); }
    closest() { return null; }
    getBoundingClientRect() { return { top: 0, left: 0, width: 0, height: 0, bottom: 0, right: 0 }; }
}

function pageElement(selector, spec) {
    const rect = spec.rect || { top: 100, left: 100, width: 200, height: 60 };
    const el = new Node('div');
    el.getBoundingClientRect = () => ({ ...rect, bottom: rect.top + rect.height, right: rect.left + rect.width });
    el.scrollIntoView = () => {};
    el.closest = (sel) => (sel === '[hidden]' && spec.hiddenAncestor ? el : null);

    return el;
}

const pageElements = {};
Object.entries(sc.elements || {}).forEach(([selector, spec]) => { pageElements[selector] = pageElement(selector, spec); });

const classes = new Classes();
const documentListeners = {};
const toggle = new Node('button');
toggle.click = () => { out.drawerClicks++; if (classes.contains('sidebar-open')) { classes.remove('sidebar-open'); } else { classes.add('sidebar-open'); } };
const body = new Node('body');
const docEl = new Node('html');
docEl.classList = classes;

globalThis.document = {
    body,
    documentElement: docEl,
    activeElement: sc.focusedInput ? Object.assign(new Node('input'), { type: 'text' }) : body,
    forms: sc.dirtyForm ? [{ elements: [Object.assign(new Node('input'), { type: 'text', value: 'typed', defaultValue: '' })] }] : [],
    createElement: (tag) => new Node(tag),
    createTextNode: (text) => ({ nodeType: 3, text }),
    getElementById: (id) => {
        if (id === 'cv-tour-data') { return sc.data ? { textContent: JSON.stringify(sc.data) } : null; }
        if (id === 'cv-tour-urls') { return { dataset: sc.urls || {} }; }

        return null;
    },
    querySelector: (selector) => {
        if (selector === '[data-cv-sidebar-toggle]') { return toggle; }
        if (selector === 'meta[name="csrf-token"]') { return { content: 'token-1' }; }
        if (selector.includes('(')) { throw new SyntaxError('unsupported selector'); } // like :has() in an old browser

        return pageElements[selector] || null;
    },
    querySelectorAll: (selector) => (selector.includes('.modal.show') && sc.modal ? [Object.assign(new Node('div'), { getBoundingClientRect: () => ({ width: 300, height: 200 }) })] : []),
    addEventListener: (type, fn) => { (documentListeners[type] ||= []).push(fn); },
    removeEventListener: () => {},
};

globalThis.window = {
    innerWidth: sc.narrow ? 400 : 1200,
    innerHeight: 800,
    matchMedia: (q) => ({ matches: q.includes('max-width') ? !!sc.narrow : (q.includes('reduced-motion') ? !!sc.reduced : false) }),
    addEventListener: () => {},
    removeEventListener: () => {},
    requestAnimationFrame: (fn) => setTimeout(fn, 5),
    cancelAnimationFrame: () => {},
    setTimeout: (fn, ms) => setTimeout(fn, Math.min(ms, 40)),
};
globalThis.getComputedStyle = (el) => ({ visibility: 'visible', display: el.hidden ? 'none' : 'block' });
globalThis.MutationObserver = class { observe() {} disconnect() {} };
globalThis.fetch = (url, options) => { out.requests.push({ url, body: JSON.parse(options.body || '{}'), headers: options.headers }); return Promise.resolve({}); };

const { initTour } = await import(pathToFileURL(resolve('resources/js/tour.js')).href);
initTour();

const find = (predicate) => created.filter((n) => n.isConnected && predicate(n));
const first = (predicate) => find(predicate)[0];
const wait = (ms) => new Promise((done) => setTimeout(done, ms));
const press = (key, extra = {}) => (documentListeners.keydown || []).forEach((fn) => fn({ key, preventDefault() {}, stopPropagation() {}, shiftKey: false, ...extra }));
const button = (cls) => first((n) => n.tag === 'button' && n.className.includes(cls));

function snapshot() {
    const title = first((n) => n.id === 'cv-tour-title');
    const counter = first((n) => n.className === 'cv-tour__counter');
    const next = button('btn-primary');
    out.layer = !!first((n) => n.className === 'cv-tour');
    out.title = title ? title.textContent : null;
    out.counter = counter ? counter.textContent : null;
    out.next = next ? next.textContent : null;
    out.prevHidden = button('btn-outline-secondary') ? button('btn-outline-secondary').hidden : null;
    out.focus = document.activeElement && document.activeElement.className ? document.activeElement.className : null;
    out.label = first((n) => n.attrs && n.attrs.role === 'dialog')?.attrs['aria-label'] ?? null;
    out.aria = first((n) => n.attrs && n.attrs.role === 'dialog') ? { modal: first((n) => n.attrs.role === 'dialog').attrs['aria-modal'], labelledby: first((n) => n.attrs.role === 'dialog').attrs['aria-labelledby'] } : null;
    out.spot = first((n) => n.className === 'cv-tour__spot')?.style ?? null;
    out.pop = first((n) => n.className === 'cv-tour__pop')?.style ?? null;
    out.drawerOpen = classes.contains('sidebar-open');
    out.tourActive = classes.contains('cv-tour-active');
}

await wait(sc.autostart === false ? 150 : 1300);
out.initial = (() => { snapshot(); return { layer: out.layer, title: out.title, counter: out.counter, next: out.next }; })();
out.steps.push({ title: out.title, counter: out.counter, next: out.next, drawerOpen: out.drawerOpen, focus: out.focus, tourActive: out.tourActive });

for (const action of sc.actions || []) {
    if (action === 'next') { button('btn-primary')?.click(); }
    else if (action === 'prev') { button('btn-outline-secondary')?.click(); }
    else if (action === 'skip') { button('cv-tour__skip')?.click(); }
    else if (action === 'close') { button('cv-tour__close')?.click(); }
    else if (action === 'esc') { press('Escape'); }
    else if (action === 'right') { press('ArrowRight'); }
    else if (action === 'left') { press('ArrowLeft'); }
    else if (action === 'tick') { const box = first((n) => n.tag === 'input'); if (box) { box.checked = true; } }
    else if (action === 'tab') { press('Tab'); }
    else if (action === 'shifttab') { press('Tab', { shiftKey: true }); }
    else if (action === 'focus-last') { button('btn-primary')?.focus(); }
    else if (action === 'focus-first') { button('cv-tour__close')?.focus(); }
    else if (action === 'start') { document.querySelector; (documentListeners.click || []).forEach((fn) => fn({ target: { closest: (s) => (s === '[data-cv-tour-start]' ? {} : null) }, preventDefault() {} })); }
    else if (action === 'restart') { (documentListeners.click || []).forEach((fn) => fn({ target: { closest: (s) => (s === '[data-cv-tour-restart]' ? {} : null) }, preventDefault() {} })); }
    else if (action.wait) { await wait(action.wait); continue; }

    await wait(60);
    snapshot();
    out.steps.push({ title: out.title, counter: out.counter, next: out.next, drawerOpen: out.drawerOpen, focus: out.focus, tourActive: out.tourActive });
}

await wait(80);
snapshot();
console.log(JSON.stringify(out));
process.exit(0);
