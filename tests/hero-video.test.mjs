import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

const source = readFileSync(new URL('../src/components/collection/home/S00_Hero.astro', import.meta.url), 'utf8');
const script = ts.transpileModule(source.match(/<script>([\s\S]*?)<\/script>/)[1], {
  compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.None },
}).outputText;

function setup({ loaded = true, failures = 0 } = {}) {
  const documentListeners = new Map();
  const windowListeners = new Map();
  const idle = [];
  const mediaListeners = new Map();
  let attempts = 0;
  const video = {
    isConnected: true, dataset: { src: '/hero.mp4' }, paused: true, readyState: 0,
    error: null, style: {}, load() {},
    addEventListener(name, fn) {
      if (!mediaListeners.has(name)) mediaListeners.set(name, new Set());
      mediaListeners.get(name).add(fn);
    },
    play() {
      attempts++;
      if (attempts <= failures) return Promise.reject(new DOMException('Blocked', 'NotAllowedError'));
      this.paused = false;
      return Promise.resolve();
    },
  };
  let videos = [video];
  class Element {
    closest() { return this.anchor || null; }
  }
  const context = {
    document: {
      readyState: loaded ? 'complete' : 'interactive', visibilityState: 'visible',
      querySelectorAll: () => videos,
      addEventListener: (name, fn) => documentListeners.set(name, fn),
      removeEventListener: name => documentListeners.delete(name),
    },
    window: {
      location: new URL('https://example.test/'),
      addEventListener: (name, fn) => windowListeners.set(name, fn),
      requestIdleCallback: fn => idle.push(fn),
    },
    Element, Error, URL,
    requestAnimationFrame: fn => fn(), setTimeout: fn => idle.push(fn),
  };
  vm.runInNewContext(script, context);
  const fire = (name, event = {}) => documentListeners.get(name)?.({ isTrusted: true, target: null, ...event });
  return {
    video, fire, Element, documentListeners,
    attempts: () => attempts,
    pause() {
      video.paused = true;
      for (const fn of mediaListeners.get('pause') || []) fn();
    },
    visibility(state) {
      context.document.visibilityState = state;
      fire('visibilitychange');
    },
    leave() {
      video.isConnected = false;
      videos = [];
      fire('astro:page-load');
    },
    load() { windowListeners.get('load')?.(); },
    runIdle() { while (idle.length) idle.shift()(); },
  };
}

const settle = () => new Promise(resolve => setImmediate(resolve));

test('no initial request; trusted tap starts synchronously even before window load', async () => {
  const h = setup({ loaded: false });
  assert.equal(h.video.src, undefined);
  h.fire('click', { isTrusted: false });
  assert.equal(h.attempts(), 0);
  h.fire('click');
  assert.equal(h.attempts(), 1);
  assert.equal(h.video.src, '/hero.mp4');
  await settle();
  assert.equal(h.documentListeners.has('click'), false);
});

test('rejected playback keeps listeners and a second tap retries without reloading', async () => {
  const h = setup({ failures: 1 });
  let loads = 0;
  h.video.load = () => loads++;
  h.fire('click');
  await settle();
  assert.equal(h.video.dataset.playError, 'NotAllowedError');
  assert.equal(h.documentListeners.has('click'), true);
  h.fire('click');
  assert.equal(h.attempts(), 2);
  assert.equal(loads, 1);
  await settle();
  assert.equal(h.video.dataset.playError, undefined);
  assert.equal(h.documentListeners.has('touchmove'), false);
});

test('touch scrolling retries after rejection and ignores small movements', async () => {
  const h = setup({ failures: 1 });
  h.fire('touchstart', { touches: [{ clientY: 100 }] });
  h.fire('touchmove', { touches: [{ clientY: 95 }] });
  assert.equal(h.attempts(), 0);
  h.fire('touchmove', { touches: [{ clientY: 75 }] });
  await settle();
  assert.equal(h.attempts(), 1);
  h.fire('touchstart', { touches: [{ clientY: 100 }] });
  h.fire('touchmove', { touches: [{ clientY: 75 }] });
  assert.equal(h.attempts(), 2);
  await settle();
});

test('scroll before page load defers downloading until load', async () => {
  const h = setup({ loaded: false });
  h.fire('touchstart', { touches: [{ clientY: 100 }] });
  h.fire('touchmove', { touches: [{ clientY: 75 }] });
  assert.equal(h.video.src, undefined);
  h.load();
  h.runIdle();
  assert.equal(h.attempts(), 1);
  await settle();
});

test('navigation clicks and modifier/repeat keys do not start the video', () => {
  const h = setup();
  const target = new h.Element();
  target.anchor = { href: 'https://example.test/quote/', target: '', hasAttribute: () => false };
  h.fire('click', { target });
  h.fire('keydown', { key: 'Enter', target });
  h.fire('keydown', { key: 'Shift' });
  h.fire('keydown', { key: 'a', repeat: true });
  assert.equal(h.attempts(), 0);
});

test('pause after successful playback restores tap and touch-scroll recovery', async () => {
  const h = setup();
  h.fire('click');
  await settle();
  assert.equal(h.documentListeners.has('click'), false);
  h.pause();
  assert.equal(h.documentListeners.has('click'), true);
  h.fire('click');
  await settle();
  assert.equal(h.attempts(), 2);
  assert.equal(h.video.paused, false);
  h.pause();
  h.fire('touchstart', { touches: [{ clientY: 100 }] });
  h.fire('touchmove', { touches: [{ clientY: 75 }] });
  await settle();
  assert.equal(h.attempts(), 3);
  assert.equal(h.video.paused, false);
});

test('returning to a visible page resumes only an already-started video', async () => {
  const h = setup();
  h.visibility('hidden');
  h.visibility('visible');
  assert.equal(h.attempts(), 0);
  assert.equal(h.video.src, undefined);
  h.fire('click');
  await settle();
  h.visibility('hidden');
  h.pause();
  assert.equal(h.attempts(), 1);
  h.visibility('visible');
  await settle();
  assert.equal(h.attempts(), 2);
  assert.equal(h.video.paused, false);
});

test('a rejected visibility resume keeps the next tap available', async () => {
  const h = setup();
  h.fire('click');
  await settle();
  h.pause();
  const originalPlay = h.video.play;
  h.video.play = () => Promise.reject(new DOMException('Blocked', 'NotAllowedError'));
  h.visibility('visible');
  await settle();
  assert.equal(h.documentListeners.has('click'), true);
  assert.equal(h.video.dataset.playError, 'NotAllowedError');
  h.video.play = originalPlay;
  h.fire('click');
  await settle();
  assert.equal(h.video.paused, false);
  assert.equal(h.video.dataset.playError, undefined);
});

test('a pause from a detached hero does not attach interactions on another page', async () => {
  const h = setup();
  h.fire('click');
  await settle();
  h.leave();
  h.pause();
  h.visibility('visible');
  assert.equal(h.documentListeners.has('click'), false);
  assert.equal(h.attempts(), 1);
});
