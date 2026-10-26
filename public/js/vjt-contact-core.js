// Contact measurement requires the current analytics consent cycle.
// Native contact navigation remains available without statistics.
(function() {
  if (window.__vjtContactCoreBound) return;
  window.__vjtContactCoreBound = true;
  var config = (document.currentScript && document.currentScript.dataset) || {};
  var contactIntentUrl = config.contactIntentUrl || '/api/track-contact-intent.php';
  var capabilityUrl = config.capabilityUrl || '/api/vjt-capability.php';
  var isDev = config.dev === 'true';
  var contactCapabilities = [];
  var capabilityRequest = null;
  var capabilityRefreshTimer = null;
  var capabilitiesLoadedAt = 0;
  // A failed refill (e.g. a proof cut short by a soft navigation) is retried
  // a bounded number of times per document; success resets the budget.
  var capabilityRetryDelays = [15 * 1000, 60 * 1000, 5 * 60 * 1000];
  var capabilityFailures = 0;
  var capabilityRetryTimer = null;
  var capabilityRetryDue = false;
  var contactEndpoint = contactIntentUrl;
  var capabilityEndpoint = capabilityUrl;
  var contactEpoch = 0;
  var contactCycle = null;

  function currentContactCycle() {
    return window.__kssmiConsent && window.__kssmiConsent.trackingCycle() || null;
  }

  function resetContactMeasurement() {
    contactEpoch++;
    contactCycle = null;
    contactCapabilities = [];
    capabilityRequest = null;
    if (capabilityRefreshTimer) clearTimeout(capabilityRefreshTimer);
    capabilityRefreshTimer = null;
    clearCapabilityRetry();
    capabilityFailures = 0;
  }
  if (isDev) {
    var devHostname = window.location.hostname === '127.0.0.1'
      ? '127.0.0.1'
      : ((window.location.hostname === '::1' || window.location.hostname === '[::1]') ? '[::1]' : 'localhost');
    var devApiOrigin = 'http://' + devHostname + ':4325';
    contactEndpoint = devApiOrigin + '/api/track-contact-intent.php';
    capabilityEndpoint = devApiOrigin + '/api/vjt-capability.php';
  }

  function capabilityIsFresh(token) {
    var parts = String(token || '').split('.');
    if (parts.length !== 2) return false;
    try {
      var encoded = parts[0].replace(/-/g, '+').replace(/_/g, '/');
      while (encoded.length % 4) encoded += '=';
      var claims = JSON.parse(atob(encoded));
      return typeof claims.exp === 'number' && claims.exp * 1000 > Date.now() + 5000;
    } catch (_) {
      return false;
    }
  }

  function scheduleCapabilityRefresh() {
    if (capabilityRefreshTimer) clearTimeout(capabilityRefreshTimer);
    capabilityRefreshTimer = setTimeout(loadContactCapabilities, 7 * 60 * 1000);
  }

  function clearCapabilityRetry() {
    if (capabilityRetryTimer) clearTimeout(capabilityRetryTimer);
    capabilityRetryTimer = null;
    capabilityRetryDue = false;
  }

  function scheduleCapabilityRetry() {
    capabilityFailures++;
    // One pending retry at a time; navigations never refill the budget.
    if (capabilityRetryTimer || capabilityRetryDue
        || capabilityFailures > capabilityRetryDelays.length) return;
    capabilityRetryTimer = setTimeout(function() {
      capabilityRetryTimer = null;
      // A background tab waits for visibilitychange instead of spending a proof.
      if (document.visibilityState === 'hidden') {
        capabilityRetryDue = true;
        return;
      }
      loadContactCapabilities();
    }, capabilityRetryDelays[capabilityFailures - 1]);
  }

  function freshCapabilityCount() {
    return contactCapabilities.filter(capabilityIsFresh).length;
  }

  function loadContactCapabilities() {
    var cycle = currentContactCycle();
    if (!cycle) return Promise.resolve(false);
    if (contactCycle !== cycle) {
      resetContactMeasurement();
      contactCycle = cycle;
    }
    if (capabilityRequest) return capabilityRequest;
    var epoch = contactEpoch;
    capabilityRetryDue = false;
    var refilled = false;
    var proof = typeof window.KSSMI_VJT_CAPABILITY_PROOF === 'function'
      ? window.KSSMI_VJT_CAPABILITY_PROOF() : Promise.resolve('');
    capabilityRequest = proof.then(function(token) {
      if (epoch !== contactEpoch || currentContactCycle() !== cycle) return null;
      if (!token && !isDev) return null;
      var api = window.__kssmiConsent;
      if (!api || typeof api.withIdentityLock !== 'function') return null;
      // Serialize the entire cookie response with the controller's withdrawal
      // cleanup across tabs, just as analytics identity issuance already does.
      return api.withIdentityLock(function() {
        if (epoch !== contactEpoch || currentContactCycle() !== cycle) return null;
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = controller ? setTimeout(function() { controller.abort(); }, 30000) : null;
        return fetch(capabilityEndpoint, {
          method: 'POST', credentials: 'include',
          signal: controller ? controller.signal : undefined,
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ mode: 'contact', turnstile_token: token, analytics: true, consent_cycle: cycle })
        }).then(function(response) {
          return response.ok ? response.json() : null;
        }).then(function(data) {
          if (timer) clearTimeout(timer);
          return data;
        }, function(error) {
          if (timer) clearTimeout(timer);
          throw error;
        });
      });
    }).then(function(data) {
      if (epoch !== contactEpoch || currentContactCycle() !== cycle) return false;
      var issued = data && data.capabilities && data.capabilities.contact_intent;
      if (Array.isArray(issued)) {
        contactCapabilities = issued.filter(function(token) {
          return typeof token === 'string' && capabilityIsFresh(token);
        }).slice(0, 16);
        refilled = contactCapabilities.length > 0;
        capabilitiesLoadedAt = Date.now();
        scheduleCapabilityRefresh();
      }
      return contactCapabilities.length > 0;
    }).catch(function() {
      return false;
    }).then(function(ok) {
      if (epoch !== contactEpoch || currentContactCycle() !== cycle) return false;
      capabilityRequest = null;
      if (refilled) {
        capabilityFailures = 0;
        clearCapabilityRetry();
      } else {
        scheduleCapabilityRetry();
      }
      return ok;
    });
    return capabilityRequest;
  }

  function takeContactCapability() {
    if (!currentContactCycle() || currentContactCycle() !== contactCycle) return '';
    var token = '';
    while (contactCapabilities.length && !token) {
      var candidate = contactCapabilities.shift() || '';
      if (capabilityIsFresh(candidate)) token = candidate;
    }
    if (contactCapabilities.length < 3) loadContactCapabilities();
    return token;
  }

  loadContactCapabilities();
  window.addEventListener('vjt:consent-withdrawn', resetContactMeasurement);
  window.addEventListener('kssmi:consent-changed', function() {
    if (!currentContactCycle()) resetContactMeasurement();
    else loadContactCapabilities();
  });

  function readJson(key) {
    try {
      var value = localStorage.getItem(key);
      return value ? JSON.parse(value) : null;
    } catch (_) {
      return null;
    }
  }

  function newCoreEventId() {
    try {
      if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return 'vjtce_' + window.crypto.randomUUID().replace(/-/g, '');
      }
    } catch (_) {}
    return 'vjtce_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 14);
  }

  function resolveAnchor(target) {
    var node = target;
    while (node && node.tagName) {
      if (node.tagName.toLowerCase() === 'a') return node;
      node = node.parentElement;
    }
    return null;
  }

  // vjt-tracker.js stores VJT values as {c: consent cycle, v: value}.
  function readCycleValue(key, cycle) {
    var raw = readJson(key);
    return raw && typeof raw === 'object' && !Array.isArray(raw) && raw.c === cycle ? raw.v : null;
  }

  function addAnalyticsLinkage(payload) {
    // Linkage requires the current consent cycle and identity values the
    // tracker stored under that same cycle; nothing from an older cycle.
    var cycle = window.__kssmiConsent && window.__kssmiConsent.trackingCycle();
    if (!cycle) return;

    var visitorId = readCycleValue('vjt_visitor_id', cycle);
    if (typeof visitorId !== 'string') visitorId = '';
    var session = readCycleValue('vjt_session_meta', cycle);
    var pageview = readCycleValue('vjt_current_pageview', cycle);
    var sessionId = session && typeof session.id === 'string' ? session.id : '';
    if (!/^vjtv_[A-Za-z0-9_-]{8,60}$/.test(visitorId)
        || !/^vjts_[A-Za-z0-9_-]{8,60}$/.test(sessionId)) return;

    payload.analytics = true;
    payload.vjt_visitor_id = visitorId;
    payload.vjt_session_id = sessionId;
    if (pageview && pageview.session_id === sessionId) {
      var step = Number(pageview.step_order);
      if (step >= 1 && step <= 10000) payload.journey_step = Math.floor(step);
    }
  }

  function sendIntent(payload) {
    if (!currentContactCycle()) return;
    payload.consent_cycle = currentContactCycle();
    var capability = takeContactCapability();
    if (!capability) return;
    payload.capability_token = capability;
    var body = JSON.stringify(payload);
    if (navigator.sendBeacon) {
      try {
        if (navigator.sendBeacon(contactEndpoint, new Blob([body], { type: 'text/plain;charset=UTF-8' }))) return;
      } catch (_) {}
    }
    try {
      fetch(contactEndpoint, {
        method: 'POST',
        credentials: 'include',
        keepalive: true,
        headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
        body: body
      }).catch(function() {});
    } catch (_) {}
  }

  function recordContactIntent(event) {
    if (!currentContactCycle()) return;
    var anchor = resolveAnchor(event.target);
    if (!anchor) return;

    var channel = (anchor.getAttribute('data-vjt-contact') || '').toLowerCase();
    var href = anchor.getAttribute('href') || '';

    // Same-site redirect links authenticate the navigation itself. The
    // analytics tracker deliberately ignores these links so this one handler
    // owns the temporary href and native navigation stays race-free.
    if (anchor.getAttribute('data-contact-core') === '1') {
      if (channel !== 'whatsapp' && channel !== 'mailto') return;
      try {
        var coreUrl = new URL(href, window.location.href);
        if (coreUrl.origin !== window.location.origin
            || coreUrl.pathname !== '/api/contact-intent.php') return;
        var capability = takeContactCapability();
        if (!capability) return;
        coreUrl.searchParams.set('capability_token', capability);
        coreUrl.searchParams.set('consent_cycle', currentContactCycle());
        coreUrl.searchParams.set('analytics', '1');

        var analyticsLink = {};
        addAnalyticsLinkage(analyticsLink);
        if (analyticsLink.analytics === true) {
          coreUrl.searchParams.set('analytics', '1');
          if (analyticsLink.journey_step) {
            coreUrl.searchParams.set('step', String(analyticsLink.journey_step));
          } else {
            coreUrl.searchParams.delete('step');
          }
        } else {
          coreUrl.searchParams.delete('step');
        }

        var originalHref = anchor.getAttribute('href');
        var enhancedHref = coreUrl.pathname + coreUrl.search + coreUrl.hash;
        anchor.setAttribute('href', enhancedHref);
        setTimeout(function() {
          if (anchor.getAttribute('href') !== enhancedHref) return;
          if (originalHref === null) anchor.removeAttribute('href');
          else anchor.setAttribute('href', originalHref);
        }, 0);
      } catch (_) {}
      return;
    }

    if (channel === 'whatsapp') {
      if (!/^https:\/\/(?:wa\.me|api\.whatsapp\.com|web\.whatsapp\.com)(?:\/|$)/i.test(href)) return;
    } else if (channel === 'mailto') {
      if (href.slice(0, 7).toLowerCase() !== 'mailto:') return;
    } else {
      return;
    }

    var payload = {
      event_id: newCoreEventId(),
      channel: channel,
      event_type: 'open_intent',
      status: 'intent',
      page_path: window.location.pathname || '/',
      placement: anchor.getAttribute('data-vjt-placement') || '',
      site_language: (document.documentElement.lang || 'en').slice(0, 3).toUpperCase(),
      analytics: true
    };
    addAnalyticsLinkage(payload);
    sendIntent(payload);
  }

  document.addEventListener('click', recordContactIntent, true);
  document.addEventListener('auxclick', function(event) {
    if (event.button === 1) recordContactIntent(event);
  }, true);
  document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'visible'
        && (capabilityRetryDue || Date.now() - capabilitiesLoadedAt >= 7 * 60 * 1000)) {
      loadContactCapabilities();
    }
  });
  // Soft navigations are a recovery point, but never stack a request or
  // timer on one already pending, nor run past the retry budget.
  document.addEventListener('astro:page-load', function() {
    if (capabilityRequest || capabilityRetryTimer || capabilityRetryDue) return;
    if (capabilityFailures > capabilityRetryDelays.length) return;
    if (freshCapabilityCount() >= 3) return;
    loadContactCapabilities();
  });
  })();
