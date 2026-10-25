// VJT capability proof provider (window.KSSMI_VJT_CAPABILITY_PROOF): mints one
// fresh, single-use Invisible Turnstile response per capability request, with
// its own widget and VJT site key. Loaded by VisitorTracker.astro as a classic
// external script at the same position the inline copy had; the site key
// comes from its data-sitekey attribute, so the file is identical on every
// page and ClientRouter runs it once per document.
(function() {
  if (window.KSSMI_VJT_CAPABILITY_PROOF) return;
  var siteKey = String((document.currentScript && document.currentScript.dataset.sitekey) || '');
  var apiPromise = null;
  // A Turnstile response is single-use. Contact Core and analytics can
  // both ask for capabilities during page startup, so queue requests
  // instead of handing the same response to concurrent callers.
  var proofQueue = Promise.resolve();

  function ensureApi() {
    if (window.turnstile && typeof window.turnstile.render === 'function') return Promise.resolve(window.turnstile);
    // Layout's shared loader keeps one API request per document and
    // retries after a failure; the widget and proof queue stay local.
    if (typeof window.__kssmiTurnstileApi === 'function') return window.__kssmiTurnstileApi();
    if (apiPromise) return apiPromise;
    apiPromise = new Promise(function(resolve, reject) {
      var script = document.createElement('script');
      script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
      script.async = true;
      script.defer = true;
      script.onload = function() {
        if (window.turnstile && typeof window.turnstile.render === 'function') resolve(window.turnstile);
        else reject(new Error('Turnstile API unavailable'));
      };
      script.onerror = function() {
        apiPromise = null;
        reject(new Error('Turnstile API failed to load'));
      };
      document.head.appendChild(script);
    });
    return apiPromise;
  }

  // A proof is for background statistics only: the visitor must never be
  // asked to interact for it. Any attempt that would need interaction,
  // fails, cannot load the API or times out is ended for real (its own
  // widget and container removed) and this tab stops asking for 30
  // minutes. Every attempt owns its widget and callbacks, so a late
  // callback can only ever end the attempt that rendered it.
  var COOLDOWN_KEY = 'kssmi_vjt_proof_cooldown_until';
  var COOLDOWN_MS = 30 * 60 * 1000;
  var PROOF_TIMEOUT_MS = 15000;
  var API_LOAD_TIMEOUT_MS = 30000;
  var navGeneration = 0;
  var activeAttempt = null;

  function coolingDown() {
    try { return Number(sessionStorage.getItem(COOLDOWN_KEY) || 0) > Date.now(); } catch (_) { return false; }
  }

  function startCooldown() {
    try { sessionStorage.setItem(COOLDOWN_KEY, String(Date.now() + COOLDOWN_MS)); } catch (_) {}
  }

  function destroyAttemptWidget(proofAttempt) {
    var container = proofAttempt.container;
    proofAttempt.container = null;
    // Remove the widget while its container is still attached, so Turnstile
    // can find and clean it up; detach the container afterwards.
    if (proofAttempt.widgetId !== null && window.turnstile) {
      try { window.turnstile.remove(proofAttempt.widgetId); } catch (_) {}
    }
    proofAttempt.widgetId = null;
    if (container && container.parentNode) container.parentNode.removeChild(container);
  }

  // Ends one attempt exactly once. A failure starts the cooldown only if
  // the attempt still belongs to the current page; navigation aborts and
  // anything from an earlier page never do.
  function endAttempt(proofAttempt, token, failed) {
    if (proofAttempt.done) return;
    proofAttempt.done = true;
    if (activeAttempt === proofAttempt) activeAttempt = null;
    window.clearTimeout(proofAttempt.timer);
    destroyAttemptWidget(proofAttempt);
    if (!token && failed && proofAttempt.navGeneration === navGeneration) startCooldown();
    proofAttempt.finish(token || '');
  }

  function renderAttemptWidget(api, proofAttempt) {
    var stale = document.getElementById('kssmi-vjt-capability-turnstile');
    if (stale && stale.parentNode) stale.parentNode.removeChild(stale);
    var container = document.createElement('div');
    container.id = 'kssmi-vjt-capability-turnstile';
    container.setAttribute('aria-label', 'Security verification');
    container.style.cssText = 'position:fixed;right:12px;bottom:12px;z-index:2147483647;max-width:calc(100vw - 24px)';
    document.body.appendChild(container);
    proofAttempt.container = container;
    proofAttempt.widgetId = api.render(container, {
      sitekey: siteKey,
      action: 'vjt_capability',
      execution: 'execute',
      appearance: 'interaction-only',
      callback: function(token) {
        var proof = typeof token === 'string' ? token : '';
        endAttempt(proofAttempt, proof, !proof);
      },
      // A notification, not a cancel switch: end the attempt and remove
      // its widget ourselves before any checkbox can be shown.
      'before-interactive-callback': function() {
        endAttempt(proofAttempt, '', true);
      },
      'error-callback': function() {
        endAttempt(proofAttempt, '', true);
        return true;
      },
      'unsupported-callback': function() {
        endAttempt(proofAttempt, '', true);
      },
      'timeout-callback': function() {
        endAttempt(proofAttempt, '', true);
      },
      'expired-callback': function() {
        endAttempt(proofAttempt, '', true);
      }
    });
    if (proofAttempt.widgetId === undefined || proofAttempt.widgetId === null) {
      throw new Error('Turnstile render failed');
    }
    api.execute(container);
  }

  // Leaving a page aborts the active attempt (including one still waiting
  // for the API) without a cooldown; nothing from it may touch the next page.
  document.addEventListener('astro:before-swap', function() {
    navGeneration += 1;
    if (activeAttempt) endAttempt(activeAttempt, '', false);
  });

  function obtainSingleProof() {
    if (coolingDown()) return Promise.resolve('');
    return new Promise(function(resolve) {
      var proofAttempt = {
        navGeneration: navGeneration,
        done: false,
        container: null,
        widgetId: null,
        timer: 0,
        finish: resolve
      };
      activeAttempt = proofAttempt;
      // Two budgets: loading the API (slow networks can take several
      // seconds; the shared loader also gives up after 15 s) and then the
      // challenge itself. A slow download must not eat the challenge time.
      proofAttempt.timer = window.setTimeout(function() {
        endAttempt(proofAttempt, '', true);
      }, API_LOAD_TIMEOUT_MS);
      ensureApi().then(function(api) {
        if (proofAttempt.done) return;
        window.clearTimeout(proofAttempt.timer);
        proofAttempt.timer = window.setTimeout(function() {
          endAttempt(proofAttempt, '', true);
        }, PROOF_TIMEOUT_MS);
        try {
          renderAttemptWidget(api, proofAttempt);
        } catch (_) {
          endAttempt(proofAttempt, '', true);
        }
      }, function() {
        // API blocked or failed to load: a failure like any other, so the
        // tab cools down instead of reloading the API on every request.
        endAttempt(proofAttempt, '', true);
      });
    });
  }

  function obtainProof() {
    if (siteKey === '' || coolingDown()) return Promise.resolve('');
    var next = proofQueue.then(obtainSingleProof);
    // Keep the queue alive after a network or widget failure while each
    // caller receives its own result.
    proofQueue = next.catch(function() { return ''; });
    return next.catch(function() { return ''; });
  }

  window.KSSMI_VJT_CAPABILITY_PROOF = obtainProof;
})();
