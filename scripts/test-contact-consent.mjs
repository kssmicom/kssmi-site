import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const code = readFileSync(new URL('../public/js/vjt-contact-core.js', import.meta.url), 'utf8');
assert.ok(code.includes('document.currentScript'), 'Exercise the deployed external script.');
const windowListeners = new Map();
const documentListeners = new Map();
const timers = new Map();
let timerId = 0;
let cycle = null;
let proofs = 0;
let identityLocks = 0;
let pendingResponse = null;
const requests = [];
const listen = (map) => (name, fn) => map.set(name, [...(map.get(name) || []), fn]);
const fire = (map, name, event = {}) => { for (const fn of map.get(name) || []) fn(event); };
const token = Buffer.from(JSON.stringify({ exp: Math.floor(Date.now() / 1000) + 600 })).toString('base64url') + '.signature';
const response = () => ({ ok: true, json: async () => ({ capabilities: { contact_intent: [token, token, token, token] } }) });
const window = {
  location: { hostname: 'kssmi.com', origin: 'https://kssmi.com', href: 'https://kssmi.com/', pathname: '/' },
  __kssmiConsent: { trackingCycle: () => cycle, withIdentityLock: async (task) => { identityLocks++; return task(); } },
  KSSMI_VJT_CAPABILITY_PROOF: async () => { proofs++; return 'synthetic-proof'; },
  addEventListener: listen(windowListeners),
};
const context = {
  window,
  document: { currentScript: { dataset: { contactIntentUrl: '/api/track-contact-intent.php', capabilityUrl: '/api/vjt-capability.php', dev: 'false' } }, addEventListener: listen(documentListeners), visibilityState: 'visible', documentElement: { lang: 'en' } },
  contactIntentUrl: '/api/track-contact-intent.php', capabilityUrl: '/api/vjt-capability.php', isDev: false,
  setTimeout(fn) { const id = ++timerId; timers.set(id, fn); return id; },
  clearTimeout(id) { timers.delete(id); },
  fetch: async (url, options) => {
    requests.push({ url, data: JSON.parse(options.body) });
    if (pendingResponse) return await new Promise((resolve) => { pendingResponse.resolve = resolve; });
    return response();
  },
  atob: (text) => Buffer.from(text, 'base64').toString('binary'),
  localStorage: { getItem: () => null }, navigator: {}, URL, Promise, Date, JSON, console,
};
function anchor() {
  const attrs = { href: '/api/contact-intent.php?channel=whatsapp', 'data-vjt-contact': 'whatsapp', 'data-contact-core': '1' };
  return { tagName: 'A', getAttribute: (key) => attrs[key] ?? null, setAttribute: (key, value) => { attrs[key] = value; }, removeAttribute: (key) => { delete attrs[key]; }, attrs };
}
const settle = async () => { for (let i = 0; i < 8; i++) await new Promise(setImmediate); };
vm.runInNewContext(code, context, { filename: 'VisitorTracker-contact-core.js' });
await settle();
assert.equal(proofs, 0, 'No consent must mean no proof or cookie-issuing request.');
assert.equal(requests.length, 0);
const initial = anchor();
fire(documentListeners, 'click', { target: initial });
assert.equal(initial.attrs.href, '/api/contact-intent.php?channel=whatsapp', 'Native contact navigation must survive refusal.');

cycle = 'A'.repeat(22);
fire(windowListeners, 'kssmi:consent-changed');
await settle();
assert.equal(requests.length, 1);
assert.equal(requests[0].data.analytics, true);
assert.equal(requests[0].data.consent_cycle, cycle);
assert.equal(identityLocks, 1, 'Cookie issuance must use the controller identity lock shared with withdrawal cleanup.');
const granted = anchor();
fire(documentListeners, 'click', { target: granted });
assert.match(granted.attrs.href, /analytics=1/);
assert.match(granted.attrs.href, /consent_cycle=A{22}/);

// Revoke while the next grant's response is in flight. Its late capabilities
// must not revive tracking or install a refresh timer.
cycle = 'B'.repeat(22);
pendingResponse = {};
fire(windowListeners, 'kssmi:consent-changed');
await settle();
assert.equal(typeof pendingResponse.resolve, 'function');
cycle = null;
fire(windowListeners, 'vjt:consent-withdrawn');
pendingResponse.resolve(response());
pendingResponse = null;
await settle();
assert.equal(timers.size, 1, 'Only the click href-restoration timer may remain; refresh/retry timers must be cancelled.');
const withdrawn = anchor();
fire(documentListeners, 'click', { target: withdrawn });
assert.equal(withdrawn.attrs.href, '/api/contact-intent.php?channel=whatsapp');
const count = requests.length;
fire(documentListeners, 'astro:page-load');
await settle();
assert.equal(requests.length, count, 'Soft navigation must not reissue contact cookies after withdrawal.');
cycle = 'C'.repeat(22);
fire(windowListeners, 'kssmi:consent-changed');
await settle();
assert.equal(requests.at(-1).data.consent_cycle, cycle, 'A later grant must use its new cycle.');
console.log('Contact consent runtime tests passed: refusal, grant, native navigation, withdrawal race, soft navigation and regrant.');
