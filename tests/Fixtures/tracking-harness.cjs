// Runs a generated website script (app/Services/Tracking/TrackingScript.php) in a fake browser and prints what it did
// as JSON. Used by tests/Feature/Private/WebsiteScriptTest.php. Usage: node tracking-harness.cjs <script.js> '<scenario json>'
const fs = require('fs');
const vm = require('vm');

const code = fs.readFileSync(process.argv[2], 'utf8');
const sc = JSON.parse(process.argv[3] || '{}');
const out = { beacons: [], fetches: [], gtag: [], dataLayer: [], cookieTouched: false, storageTouched: false, canvasTouched: false, debug: [], threw: false, listeners: 0 };
const pending = [];
const listeners = [];

function element(tag) {
  const el = {
    tag,
    nodeType: 1,
    children: [],
    setAttribute() {},
    appendChild(child) { this.children.push(child); out.debug.push(child.text !== undefined ? child.text : '[element]'); return child; },
  };
  if (tag === 'canvas') { out.canvasTouched = true; }
  if (tag === 'a') {
    Object.defineProperty(el, 'href', {
      set(value) { try { this.hostname = new URL(value, 'https://hotel.example/').hostname; } catch (e) { this.hostname = ''; } },
      get() { return ''; },
    });
  }
  return el;
}

const document = {
  referrer: sc.referrer || '',
  body: element('body'),
  documentElement: element('html'),
  createElement: element,
  createTextNode: (text) => ({ text }),
  addEventListener(type, fn) { if (type === 'click') { listeners.push(fn); out.listeners++; } },
  querySelector(selector) {
    if (sc.breakQuerySelector) { throw new Error('boom'); }
    for (const key of Object.keys(sc.inputs || {})) {
      if (String(selector).toLowerCase().includes(key)) { return { value: sc.inputs[key] }; }
    }
    return null;
  },
};
Object.defineProperty(document, 'cookie', { get() { out.cookieTouched = true; return ''; }, set() { out.cookieTouched = true; } });

const window = {
  location: { search: sc.search || '', pathname: sc.path || '/rooms' },
  document,
  navigator: {},
};
['localStorage', 'sessionStorage', 'indexedDB'].forEach((name) => Object.defineProperty(window, name, { get() { out.storageTouched = true; return {}; } }));
if (sc.gtag) { window.gtag = (...args) => out.gtag.push(args); }
if (sc.dataLayer) { window.dataLayer = { push: (item) => out.dataLayer.push(item) }; }
if (sc.noBeacon) {
  window.fetch = (url, options) => { out.fetches.push({ url, body: options.body, credentials: options.credentials }); return Promise.resolve(); };
} else {
  window.navigator.sendBeacon = (url, blob) => { pending.push(blob.text().then((body) => out.beacons.push({ url, body: JSON.parse(body), type: blob.type }))); return true; };
}

const sandbox = { window, document, navigator: window.navigator, Blob, console: { log() {} } };
window.window = window;

try {
  vm.runInNewContext(code, sandbox);
  if (sc.click) {
    const target = {
      nodeType: 1,
      parentNode: null,
      matches(selector) { return sc.click.match !== false && String(selector).includes(sc.click.mustInclude || ''); },
      getAttribute(name) { return name === 'href' ? (sc.click.href || null) : null; },
    };
    listeners.forEach((fn) => fn({ target }));
  }
} catch (e) {
  out.threw = true;
  out.error = String(e && e.message);
}

Promise.all(pending).then(() => console.log(JSON.stringify(out)));
