(function () {
  var cfg = window.VJTTracker || { page: {}, routes: {} };
  var VISITOR_KEY = 'vjt_visitor_id';
  var SESSION_KEY = 'vjt_session_meta';
  var PAGE_KEY    = 'vjt_current_pageview';
  var PATH_KEY    = 'vjt_session_path';
  var CONVERSION_QUEUE_KEY = 'vjt_pending_conversions';
  var MAX_PENDING_CONVERSIONS = 20;
  var PENDING_CONVERSION_TTL_MS = 7 * 24 * 60 * 60 * 1000;
  var ACTIVE_WINDOW_MS = 15000;
  var HEARTBEAT_MS = 45000;
  var CAPABILITY_LOW_WATER = 3;
  var MAX_CAPABILITY_POOL = 32;
  var hiddenNames = [
    'vjt_visitor_id',
    'vjt_session_id',
    'vjt_submit_page',
    'vjt_submit_title',
    'vjt_referrer',
    'vjt_landing_url',
    'vjt_landing_title',
    'vjt_path_snapshot',
    'vjt_utm_source',
    'vjt_utm_medium',
    'vjt_utm_campaign',
    'vjt_utm_content',
    'vjt_utm_term',
    'vjt_analytics_consent',
    'vjt_journey_step'
  ];
  var flushed         = false;
  var maxScrollDepth  = 0;
  var scrollTimer     = null;
  var _deviceInfo     = null; // I3: stored globally for event handlers
  var lastActivityAtMs = 0;
  var lastSyncMs = 0;
  var activeAccumulatedMs = 0;
  var heartbeatTimer = null;
  var capabilityPools = { pageview: [], submission: [] };
  var capabilityRefreshPromise = null;
  var capabilityEpoch = 0;
  var serverIdentity = null;
  var identityReady = false;
  var initPromise = null;
  var initPromiseUrl = '';

  function newTrackingId(prefix) {
    try {
      if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return prefix + window.crypto.randomUUID().replace(/-/g, '');
      }
      if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return prefix + Array.prototype.map.call(bytes, function (value) {
          return value.toString(16).padStart(2, '0');
        }).join('');
      }
    } catch (e) {}
    var now = new Date();
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    var dateStr = now.getFullYear() + '-' + p(now.getMonth() + 1) + '-' + p(now.getDate());
    var timeStr = p(now.getHours()) + p(now.getMinutes()) + p(now.getSeconds());
    var rand = Math.random().toString(16).slice(2, 6);
    return prefix + dateStr + '-' + timeStr + '-' + rand;
  }

  function nowBeijing() {
    // Always Beijing time (Asia/Shanghai), no matter where the visitor is
    var d = new Date();
    var bj = new Date(d.getTime() + d.getTimezoneOffset() * 60000 + 480 * 60000);
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    return bj.getUTCFullYear() + '-' +
      p(bj.getUTCMonth() + 1) + '-' +
      p(bj.getUTCDate()) + ' ' +
      p(bj.getUTCHours()) + ':' +
      p(bj.getUTCMinutes()) + ':' +
      p(bj.getUTCSeconds());
  }

  function secondsBetween(start, end) {
    return Math.max(0, Math.round((end - start) / 1000));
  }

  function accrueActiveTime(endMs) {
    if (!lastSyncMs) {
      lastSyncMs = endMs;
      return;
    }
    var activeUntil = Math.min(endMs, (lastActivityAtMs || 0) + ACTIVE_WINDOW_MS);
    if (lastActivityAtMs && activeUntil > lastSyncMs) {
      activeAccumulatedMs += activeUntil - lastSyncMs;
    }
    lastSyncMs = endMs;
  }

  function recordActivity() {
    var now = Date.now();
    // Close the previous activity window before starting a new one. Without
    // this, an interaction after a long idle gap would count that entire gap.
    accrueActiveTime(now);
    lastActivityAtMs = now;
  }

  function updateActivityMetrics(pageview, endMs, isHeartbeat) {
    var startMs = pageview.created_at_ms || endMs;
    accrueActiveTime(endMs);
    pageview.duration_seconds = secondsBetween(startMs, endMs);
    pageview.active_duration_seconds = Math.min(
      pageview.duration_seconds,
      Math.max(pageview.active_duration_seconds || 0, Math.round(activeAccumulatedMs / 1000))
    );
    pageview.max_scroll_depth = Math.max(pageview.max_scroll_depth || 0, maxScrollDepth);
    pageview.scroll_depth = pageview.max_scroll_depth;
    if (lastActivityAtMs) pageview.last_activity_at = new Date(lastActivityAtMs).toISOString();
    if (isHeartbeat) pageview.heartbeat_count = (pageview.heartbeat_count || 0) + 1;
    pageview.is_engaged = !!(pageview.active_duration_seconds >= 10 || pageview.max_scroll_depth >= 50 || (pageview.heartbeat_count || 0) >= 2);
    pageview.engagement_score = Math.min(100,
      Math.min(60, pageview.active_duration_seconds || 0) +
      Math.min(20, (pageview.heartbeat_count || 0) * 5) +
      Math.min(20, Math.round((pageview.max_scroll_depth || 0) / 5))
    );
  }

  function readStorage(key) {
    try { return window.localStorage.getItem(key); } catch (e) { return null; }
  }

  function readCookie(name) {
    try {
      var escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      var match = document.cookie.match(new RegExp('(?:^|; )' + escaped + '=([^;]*)'));
      return match ? decodeURIComponent(match[1]) : '';
    } catch (e) { return ''; }
  }

  function writeStorage(key, val) {
    try { window.localStorage.setItem(key, val); } catch (e) {}
  }

  function readJson(key) {
    var raw = readStorage(key);
    if (!raw) return null;
    try { return JSON.parse(raw); } catch (e) { return null; }
  }

  function writeJson(key, val) {
    try { writeStorage(key, JSON.stringify(val)); } catch (e) {}
  }

  function setCookie(name, val, seconds) {
    document.cookie =
      name + '=' + encodeURIComponent(val) +
      '; expires=' + new Date(Date.now() + seconds * 1000).toUTCString() +
      '; path=/; SameSite=Lax';
  }

  function getSiteLanguage() {
    try {
      var path = window.location.pathname;
      var seg = (path.split('/')[1] || '').toLowerCase();
      var known = ['it','es','fr','de','pt','ru','ja','tr','ar','ko','zh','hi','vi','jv','ms','tg'];
      if (known.indexOf(seg) !== -1) return seg.toUpperCase();
      return 'EN';
    } catch (e) { return 'EN'; }
  }

  function extractUtm() {
    try {
      var search = window.location.search;
      if (!search) return {};
      var utm = {};
      search.slice(1).split('&').forEach(function (pair) {
        var separator = pair.indexOf('=');
        var rawKey = separator === -1 ? pair : pair.slice(0, separator);
        var rawValue = separator === -1 ? '' : pair.slice(separator + 1);
        var key = decodeURIComponent(rawKey || '').toLowerCase();
        if (key.slice(0, 4) === 'utm_' && !Object.prototype.hasOwnProperty.call(utm, key)) {
          utm[key] = decodeURIComponent(rawValue.replace(/\+/g, ' '));
        }
      });
      return utm;
    } catch (e) { return {}; }
  }

  function referrerHost(value) {
    try {
      var link = document.createElement('a');
      link.href = value || '';
      return (link.hostname || '').toLowerCase().replace(/^www\./, '').replace(/\.$/, '');
    } catch (e) { return ''; }
  }

  function isInternalReferrer(value) {
    var host = referrerHost(value);
    var siteHost = (window.location.hostname || '').toLowerCase().replace(/^www\./, '').replace(/\.$/, '');
    return !!host && (host === siteHost || host.slice(-(siteHost.length + 1)) === '.' + siteHost);
  }

  function hasUtm(utm) {
    return !!(utm.utm_source || utm.utm_medium || utm.utm_campaign || utm.utm_content || utm.utm_term);
  }

  function campaignChanged(session, utm) {
    if (!session || !hasUtm(utm)) return false;
    return (utm.utm_source || '') !== (session.utmSource || '') ||
      (utm.utm_medium || '') !== (session.utmMedium || '') ||
      (utm.utm_campaign || '') !== (session.utmCampaign || '') ||
      (utm.utm_content || '') !== (session.utmContent || '') ||
      (utm.utm_term || '') !== (session.utmTerm || '');
  }

  function getDeviceInfo() {
    try {
      var tz = '';
      try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
      return {
        screen_resolution : (screen.width  || 0) + 'x' + (screen.height || 0),
        viewport_width    : window.innerWidth  || 0,
        viewport_height   : window.innerHeight || 0,
        timezone          : tz,
        language          : navigator.language || navigator.userLanguage || ''
      };
    } catch (e) { return {}; }
  }

  function calcScrollDepth() {
    try {
      var scrollY = window.scrollY || window.pageYOffset || 0;
      var docH = Math.max(
        document.body.scrollHeight || 0,
        document.body.offsetHeight || 0,
        document.documentElement.scrollHeight || 0,
        document.documentElement.offsetHeight || 0
      );
      var winH = window.innerHeight || 0;
      if (docH <= winH || docH <= 0) return 100;
      return Math.min(100, Math.max(0, Math.round((scrollY + winH) / docH * 100)));
    } catch (e) { return 0; }
  }

  function getVisitorId() {
    // Analytics identities are authoritative only after the server has issued
    // the signed HttpOnly identity cookie and matching capability bundle.
    if (!identityReady || !serverIdentity) return '';
    var id = serverIdentity.visitorId;
    writeStorage(VISITOR_KEY, id);
    setCookie('vjt_visitor_id', id, cfg.cookieExpires || 31536000);
    return id;
  }

  function readPath() {
    var p = readJson(PATH_KEY);
    return Array.isArray(p) ? p : [];
  }

  function writePath(p) {
    try { writeJson(PATH_KEY, Array.isArray(p) ? p.slice(-20) : []); } catch (e) {}
  }

  function sessionNeedsRotation() {
    var session    = readJson(SESSION_KEY);
    var now        = Date.now();
    var timeoutMs  = (cfg.sessionTimeout || 30) * 60 * 1000;
    var expired    = !session || !session.lastActivity || (now - session.lastActivity > timeoutMs);
    var utm        = extractUtm();
    var pageReferrer = document.referrer || '';
    var externalReferrer = pageReferrer && !isInternalReferrer(pageReferrer) ? pageReferrer : '';
    if (!expired && (campaignChanged(session, utm) ||
        (externalReferrer && referrerHost(externalReferrer) !== referrerHost(session.originalReferrer || session.referrer)))) {
      expired = true;
    }
    return expired;
  }

  function getSession(deviceInfo, forceNewMetadata) {
    if (!identityReady || !serverIdentity) return null;
    var session    = readJson(SESSION_KEY);
    var previous   = session;
    var now        = Date.now();
    var utm        = extractUtm();
    var pageReferrer = document.referrer || '';
    var externalReferrer = pageReferrer && !isInternalReferrer(pageReferrer) ? pageReferrer : '';
    var resetMetadata = !!forceNewMetadata || !session || session.id !== serverIdentity.sessionId;

    if (resetMetadata) {
      var di  = deviceInfo || {};
      // If a timed-out visit continues through an internal link, retain the
      // previous non-direct attribution (last non-direct model). A later true
      // direct visit has an empty referrer and therefore does not inherit it.
      var internalContinuation = !!(!externalReferrer && isInternalReferrer(pageReferrer) && previous);
      var previousReferrer = previous ? (previous.originalReferrer || previous.referrer || '') : '';
      var inheritedReferrer = internalContinuation && previousReferrer && !isInternalReferrer(previousReferrer)
        ? previousReferrer
        : '';
      var inheritCampaign = !!(internalContinuation && previous && !hasUtm(utm));
      var attributedReferrer = externalReferrer || inheritedReferrer;
      session = {
        id               : serverIdentity.sessionId,
        startedAt        : new Date().toISOString(),
        landingUrl       : cfg.page.url,
        landingTitle     : cfg.page.title,
        referrer         : attributedReferrer,
        originalReferrer : attributedReferrer,
        stepOrder        : 0,
        lastActivity     : now,
        utmSource        : utm['utm_source']   || (inheritCampaign ? previous.utmSource   || '' : ''),
        utmMedium        : utm['utm_medium']   || (inheritCampaign ? previous.utmMedium   || '' : ''),
        utmCampaign      : utm['utm_campaign'] || (inheritCampaign ? previous.utmCampaign || '' : ''),
        utmContent       : utm['utm_content']  || (inheritCampaign ? previous.utmContent  || '' : ''),
        utmTerm          : utm['utm_term']     || (inheritCampaign ? previous.utmTerm     || '' : ''),
        screenResolution : di.screen_resolution || '',
        timezone         : di.timezone          || '',
        language         : di.language          || '',
        siteLanguage     : getSiteLanguage()    || 'EN'
      };
      writePath([]);
    }

    // The server identity always wins over any predictable/stale client ID.
    session.id = serverIdentity.sessionId;
    session.lastActivity = now;

    writeJson(SESSION_KEY, session);
    setCookie('vjt_session_id', session.id, (cfg.sessionTimeout || 30) * 60);
    return session;
  }

  function saveSession(session) {
    if (!identityReady || !serverIdentity || !session) return;
    session.id = serverIdentity.sessionId;
    session.lastActivity = Date.now();
    writeJson(SESSION_KEY, session);
    setCookie('vjt_session_id', session.id, (cfg.sessionTimeout || 30) * 60);
  }

  // ── Network ────────────────────────────────────────────────────────────────

  function clearCapabilityPools() {
    capabilityPools.pageview = [];
    capabilityPools.submission = [];
  }

  function invalidateServerIdentity() {
    capabilityEpoch += 1;
    clearCapabilityPools();
    serverIdentity = null;
    identityReady = false;
    if (heartbeatTimer) clearInterval(heartbeatTimer);
    heartbeatTimer = null;
    window.__vjtInitializedUrl = '';
  }

  function isValidTrackingId(value, prefix) {
    var pattern = prefix === 'vjtv_'
      ? /^vjtv_[A-Za-z0-9_-]{8,60}$/
      : /^vjts_[A-Za-z0-9_-]{8,60}$/;
    return typeof value === 'string' && pattern.test(value);
  }

  function validCapabilityList(value) {
    return Array.isArray(value) && value.length > 0 && value.every(function (token) {
      return typeof token === 'string' && token.length > 0 && token.length <= 4096;
    });
  }

  function mergeCapabilities(kind, tokens) {
    var merged = capabilityPools[kind].slice();
    tokens.forEach(function (token) {
      if (merged.indexOf(token) === -1) merged.push(token);
    });
    capabilityPools[kind] = merged.slice(0, MAX_CAPABILITY_POOL);
  }

  function updateHeartbeatInterval(seconds) {
    HEARTBEAT_MS = seconds * 1000;
    if (heartbeatTimer) {
      clearInterval(heartbeatTimer);
      heartbeatTimer = window.setInterval(sendHeartbeat, HEARTBEAT_MS);
    }
  }

  function persistServerIdentity(visitorId, sessionId) {
    writeStorage(VISITOR_KEY, visitorId);
    setCookie('vjt_visitor_id', visitorId, cfg.cookieExpires || 31536000);
    setCookie('vjt_session_id', sessionId, (cfg.sessionTimeout || 30) * 60);

    // Overwrite an old client-generated session ID immediately. getSession()
    // will rebuild attribution metadata before anything is patched or sent.
    var storedSession = readJson(SESSION_KEY);
    if (storedSession) {
      storedSession.id = sessionId;
      writeJson(SESSION_KEY, storedSession);
    }
  }

  function requestCapabilityProof() {
    if (typeof window.KSSMI_VJT_CAPABILITY_PROOF !== 'function') {
      return Promise.reject(new Error('VJT Turnstile proof unavailable'));
    }
    return window.KSSMI_VJT_CAPABILITY_PROOF().then(function (token) {
      if (typeof token !== 'string' || token.length === 0 || token.length > 4096) {
        throw new Error('VJT Turnstile proof rejected');
      }
      return token;
    });
  }

  function requestAnalyticsCapabilities(rotateSession, allowIdentityChange) {
    if (!cfg.enabled || !cfg.routes || !cfg.routes.capability) {
      return Promise.reject(new Error('VJT capability route unavailable'));
    }

    var requestEpoch = capabilityEpoch;
    return requestCapabilityProof().then(function (turnstileToken) {
      return fetch(cfg.routes.capability, {
        method      : 'POST',
        credentials : 'include',
        headers     : { 'Content-Type': 'application/json' },
        body        : JSON.stringify({
          mode: 'analytics',
          rotate_session: !!rotateSession,
          turnstile_token: turnstileToken
        })
      });
    }).then(function (response) {
      if (!response.ok) throw new Error('VJT capability request failed');
      return response.json();
    }).then(function (data) {
      if (!cfg.enabled || requestEpoch !== capabilityEpoch) {
        var supersededError = new Error('VJT capability request superseded');
        supersededError.vjtSuperseded = true;
        throw supersededError;
      }
      var capabilities = data && data.capabilities;
      var visitorId = data && data.visitor_id;
      var sessionId = data && data.session_id;
      var heartbeatSeconds = data && Number(data.heartbeat_seconds);
      if (!data || data.success !== true ||
          !isValidTrackingId(visitorId, 'vjtv_') ||
          !isValidTrackingId(sessionId, 'vjts_') ||
          !heartbeatSeconds || heartbeatSeconds < 30 || heartbeatSeconds > 120 ||
          !capabilities ||
          !validCapabilityList(capabilities.pageview) ||
          !validCapabilityList(capabilities.submission)) {
        throw new Error('Invalid VJT capability response');
      }

      var identityChanged = !!serverIdentity &&
        (serverIdentity.visitorId !== visitorId || serverIdentity.sessionId !== sessionId);
      var refillIdentityChanged = identityChanged && !allowIdentityChange;

      var storedSession = readJson(SESSION_KEY);
      var localSessionChanged = !storedSession || storedSession.id !== sessionId;
      if (!serverIdentity || identityChanged) clearCapabilityPools();
      serverIdentity = { visitorId: visitorId, sessionId: sessionId };
      identityReady = true;
      persistServerIdentity(visitorId, sessionId);
      mergeCapabilities('pageview', capabilities.pageview);
      mergeCapabilities('submission', capabilities.submission);
      updateHeartbeatInterval(heartbeatSeconds);

      if (refillIdentityChanged) {
        // The server may rotate an expired session during an ordinary refill.
        // Adopt it, rebuild local attribution, and reinitialize the current
        // page; old page payloads are rejected by payloadMatchesServerIdentity.
        getSession(_deviceInfo, true);
        window.__vjtInitializedUrl = '';
        setTimeout(function () {
          if (cfg.enabled && identityReady) initVJT();
        }, 0);
      }
      return {
        localSessionChanged: localSessionChanged,
        identityChanged: identityChanged,
        refillIdentityChanged: refillIdentityChanged
      };
    });
  }

  function replenishCapabilities() {
    if (!cfg.enabled || !identityReady || !serverIdentity) return Promise.resolve(false);
    if (capabilityRefreshPromise) return capabilityRefreshPromise;
    capabilityRefreshPromise = requestAnalyticsCapabilities(false, false).then(function () {
      return true;
    }, function () {
      return false;
    }).then(function (result) {
      capabilityRefreshPromise = null;
      return result;
    });
    return capabilityRefreshPromise;
  }

  function bootstrapCapabilities(rotateSession) {
    // A SPA navigation can race a low-water refill triggered while flushing
    // the previous page. Serialize them so an old response cannot replace the
    // newly rotated server session.
    var waitForRefill = capabilityRefreshPromise || Promise.resolve(true);
    return waitForRefill.then(function () {
      return requestAnalyticsCapabilities(rotateSession, true);
    }).catch(function (error) {
      if (!error || !error.vjtSuperseded) invalidateServerIdentity();
      throw error;
    });
  }

  function capabilityIsFresh(token) {
    // Server capabilities expose their signed expiry in the first base64url
    // segment. Treat opaque future token formats as usable; the server still
    // performs the authoritative signature and expiry validation.
    var parts = String(token || '').split('.');
    if (parts.length !== 2) return true;
    try {
      var encoded = parts[0].replace(/-/g, '+').replace(/_/g, '/');
      while (encoded.length % 4) encoded += '=';
      var claims = JSON.parse(atob(encoded));
      return typeof claims.exp !== 'number' || claims.exp * 1000 > Date.now() + 5000;
    } catch (e) {
      return true;
    }
  }

  function takeCapability(kind) {
    if (!cfg.enabled || !identityReady || !capabilityPools[kind]) return '';
    var token = '';
    while (capabilityPools[kind].length && !token) {
      var candidate = capabilityPools[kind].shift() || '';
      if (candidate && capabilityIsFresh(candidate)) token = candidate;
    }
    if (capabilityPools[kind].length <= CAPABILITY_LOW_WATER) replenishCapabilities();
    return token;
  }

  function acquireCapability(kind) {
    var token = takeCapability(kind);
    if (token) return Promise.resolve(token);
    return replenishCapabilities().then(function () { return takeCapability(kind); });
  }

  function payloadWithCapability(payload, token) {
    var securedPayload = {};
    Object.keys(payload || {}).forEach(function (key) { securedPayload[key] = payload[key]; });
    securedPayload.capability_token = token;
    return securedPayload;
  }

  function payloadMatchesServerIdentity(payload) {
    return !!(identityReady && serverIdentity && payload &&
      payload.visitor_id === serverIdentity.visitorId &&
      payload.session_id === serverIdentity.sessionId);
  }

  function sendPageviewWithToken(url, payload, token, useBeacon) {
    if (!payloadMatchesServerIdentity(payload)) return false;
    var securedPayload = payloadWithCapability(payload, token);
    if (useBeacon && navigator.sendBeacon) {
      try {
        if (navigator.sendBeacon(url, new Blob([JSON.stringify(securedPayload)], { type: 'application/json' }))) {
          return true;
        }
      } catch (e) {}
    }
    return fetch(url, {
      method      : 'POST',
      credentials : 'include',
      keepalive   : !!useBeacon,
      headers     : { 'Content-Type': 'application/json' },
      body        : JSON.stringify(securedPayload)
    }).catch(function () { return null; });
  }

  function post(url, payload, useBeacon) {
    // Consent can be withdrawn after the persistent SPA/pagehide listeners were
    // registered. Re-check at the single network boundary so no later
    // heartbeat, leave event or conversion intent can escape after withdrawal.
    if (!cfg.enabled || !identityReady || !url) return false;

    // Unload paths cannot wait for the network. They use one already-issued
    // token and fail closed when the pageview pool is empty.
    if (useBeacon) {
      var beaconToken = takeCapability('pageview');
      return beaconToken ? sendPageviewWithToken(url, payload, beaconToken, true) : false;
    }

    return acquireCapability('pageview').then(function (token) {
      return token ? sendPageviewWithToken(url, payload, token, false) : false;
    });
  }

  // Conversion events are queued before sending. A page can leave immediately
  // after a WhatsApp/mail click, so keeping the event ID locally makes a later
  // retry safe without recording the same click twice.
  function pendingConversions() {
    var items = readJson(CONVERSION_QUEUE_KEY);
    if (!Array.isArray(items)) return [];
    var cutoff = Date.now() - PENDING_CONVERSION_TTL_MS;
    return items.filter(function (item) {
      return item && item.payload && item.payload.event_id && item.queued_at >= cutoff;
    }).slice(-MAX_PENDING_CONVERSIONS);
  }

  function savePendingConversions(items) {
    writeJson(CONVERSION_QUEUE_KEY, Array.isArray(items) ? items.slice(-MAX_PENDING_CONVERSIONS) : []);
  }

  function queueConversion(payload) {
    var items = pendingConversions();
    var exists = items.some(function (item) { return item.payload.event_id === payload.event_id; });
    if (!exists) {
      items.push({ payload: payload, queued_at: Date.now() });
      savePendingConversions(items);
    }
  }

  function removeQueuedConversion(eventId) {
    savePendingConversions(pendingConversions().filter(function (item) {
      return item.payload.event_id !== eventId;
    }));
  }

  function deliverConversion(payload) {
    if (!cfg.enabled || !identityReady || !cfg.routes || !cfg.routes.submission) return Promise.resolve('retry');
    try {
      // Acquire inside every delivery attempt: queued payloads never contain a
      // token, and each retry consumes a newly issued submission capability.
      return acquireCapability('submission').then(function (token) {
        if (!token || !identityReady || !serverIdentity) return 'retry';
        // A refill may rotate an expired server session. Never relabel a queued
        // browser event as belonging to that new identity: the form fields and
        // canonical mail outcome still describe the identity captured when the
        // event was created. Drop the now-stale diagnostic attempt instead.
        if (!payloadMatchesServerIdentity(payload)) return 'drop';
        return fetch(cfg.routes.submission, {
          method      : 'POST',
          credentials : 'include',
          keepalive   : true,
          headers     : { 'Content-Type': 'application/json' },
          body        : JSON.stringify(payloadWithCapability(payload, token))
        }).then(function (response) {
          // Malformed input is not recoverable; 429/5xx remain queued for retry.
          if (!response.ok) return (response.status === 429 || response.status >= 500) ? 'retry' : 'drop';
          return response.json().then(function (body) {
            if (!body || body.success !== true) return 'retry';
            if (body.result === 'skipped_internal' || body.result === 'skipped_bot') return 'drop';
            // Accept legacy successful responses during a rolling deployment.
            return 'stored';
          }, function () { return 'retry'; });
        }, function () { return 'retry'; });
      });
    } catch (e) {
      return Promise.resolve('retry');
    }
  }

  function submitConversion(payload, onSettled) {
    if (!payload.event_id) payload.event_id = newTrackingId('vjtev_');
    queueConversion(payload);
    deliverConversion(payload).then(function (result) {
      if (result === 'stored' || result === 'drop') removeQueuedConversion(payload.event_id);
      if (onSettled) onSettled(result);
    }, function () {
      if (onSettled) onSettled('retry');
    });
  }

  function flushPendingConversions() {
    if (window.__vjtConversionFlush || !cfg.enabled) return;
    var items = pendingConversions();
    if (!items.length) return;
    window.__vjtConversionFlush = true;
    var next = function () {
      var item = items.shift();
      if (!item) { window.__vjtConversionFlush = false; return; }
      deliverConversion(item.payload).then(function (result) {
        if (result === 'stored' || result === 'drop') removeQueuedConversion(item.payload.event_id);
        next();
      }, next);
    };
    next();
  }

  function ensureHidden(form, name, value) {
    var f = form.querySelector('input[name="' + name + '"]');
    if (!f) {
      f = document.createElement('input');
      f.type = 'hidden'; f.name = name;
      form.appendChild(f);
    }
    f.value = value;
  }

  function buildPathSnapshot(currentPageview) {
    // localStorage is browser-controlled and may contain legacy/corrupt items.
    // Invalid entries are telemetry loss only; they must never throw through a
    // real inquiry form submission.
    var path = readPath().filter(function (item) {
      return !!item && typeof item === 'object' && !Array.isArray(item);
    });
    if (currentPageview) {
      var exists = path.some(function (item) {
        return item.session_id === currentPageview.session_id &&
               item.step_order === currentPageview.step_order &&
               item.visited_at === currentPageview.visited_at;
      });
      if (!exists) path.push(currentPageview);
    }
    return path.slice(-20);
  }

  function cleanUrl(url) {
    // Strip VJT tracking parameters from URLs
    if (!url) return url;
    return url.replace(/[?&]vjt_(visitor_id|session_id|submit_page|submit_title|referrer|landing_url|landing_title|path_snapshot|utm_source|utm_medium|utm_campaign|utm_content|utm_term|analytics_consent|journey_step)=[^&]*/gi, '')
              .replace(/\?&/, '?')   // fix leftover ?& after first param is stripped
              .replace(/\?$/, '')
              .replace(/&$/, '');
  }

  function patchForms(visitorId, session) {
    if (!identityReady || !visitorId || !session) return;
    var forms = document.querySelectorAll('form');
    if (!forms.length) return; // nothing to patch — skip the localStorage/JSON work
    var pageview = readJson(PAGE_KEY);
    var snapshot = JSON.stringify(buildPathSnapshot(pageview));
    var attrReferrer = session.originalReferrer || session.referrer || '';
    var pageUrl = cleanUrl(cfg.page.url); // hoisted: same for every form
    forms.forEach(function (form) {
      // Skip GET forms — hidden fields would pollute the URL
      if ((form.method || '').toUpperCase() === 'GET') return;
      ensureHidden(form, hiddenNames[0], visitorId);
      ensureHidden(form, hiddenNames[1], session.id);
      ensureHidden(form, hiddenNames[2], pageUrl);
      ensureHidden(form, hiddenNames[3], cfg.page.title);
      ensureHidden(form, hiddenNames[4], attrReferrer);
      ensureHidden(form, hiddenNames[5], session.landingUrl   || '');
      ensureHidden(form, hiddenNames[6], session.landingTitle || '');
      ensureHidden(form, hiddenNames[7], snapshot);
      ensureHidden(form, hiddenNames[8], session.utmSource   || '');
      ensureHidden(form, hiddenNames[9], session.utmMedium   || '');
      ensureHidden(form, hiddenNames[10], session.utmCampaign || '');
      ensureHidden(form, hiddenNames[11], session.utmContent || '');
      ensureHidden(form, hiddenNames[12], session.utmTerm    || '');
      ensureHidden(form, hiddenNames[13], '1');
      ensureHidden(form, hiddenNames[14], pageview && pageview.session_id === session.id ? String(pageview.step_order || 0) : '0');
    });
  }

  function currentPageview(visitorId, session) {
    session.stepOrder += 1;
    saveSession(session);

    maxScrollDepth = calcScrollDepth();

    var pv = {
      visitor_id        : visitorId,
      session_id        : session.id,
      url               : cfg.page.url,
      title             : cfg.page.title,
      referrer          : session.referrer,
      landing_url       : session.landingUrl,
      landing_title     : session.landingTitle,
      session_started_at: session.startedAt,
      visited_at        : new Date().toISOString(),
      leave_at          : '',
      duration_seconds  : 0,
      active_duration_seconds: 0,
      engagement_score  : 0,
      is_engaged        : false,
      last_activity_at  : '',
      heartbeat_count   : 0,
      scroll_depth      : 0,
      max_scroll_depth  : maxScrollDepth,
      step_order        : session.stepOrder,
      created_at_ms     : Date.now()
    };

    writeJson(PAGE_KEY, pv);
    var path = readPath();
    path.push(pv);
    writePath(path);
    return pv;
  }

  function startTracking(pageview, session) {
    post(cfg.routes.pageview, {
      visitor_id        : pageview.visitor_id,
      session_id        : pageview.session_id,
      url               : pageview.url,
      title             : pageview.title,
      referrer          : pageview.referrer,
      landing_url       : pageview.landing_url,
      landing_title     : pageview.landing_title,
      session_started_at: pageview.session_started_at,
      visited_at        : pageview.visited_at,
      leave_at          : pageview.leave_at,
      duration_seconds  : pageview.duration_seconds,
      active_duration_seconds: pageview.active_duration_seconds || 0,
      engagement_score  : pageview.engagement_score || 0,
      is_engaged        : pageview.is_engaged ? 1 : 0,
      last_activity_at  : pageview.last_activity_at || '',
      heartbeat_count   : pageview.heartbeat_count || 0,
      scroll_depth      : pageview.scroll_depth,
      max_scroll_depth  : pageview.max_scroll_depth || pageview.scroll_depth || 0,
      event_type        : 'start',
      step_order        : pageview.step_order,
      utm_source        : session.utmSource        || '',
      utm_medium        : session.utmMedium        || '',
      utm_campaign      : session.utmCampaign      || '',
      utm_content       : session.utmContent       || '',
      utm_term          : session.utmTerm           || '',
      screen_resolution : session.screenResolution || '',
      timezone          : session.timezone          || '',
      language          : session.language          || '',
      site_language     : session.siteLanguage || getSiteLanguage()
    }, false);
  }

  function flushPageview(reason) {
    var pageview = readJson(PAGE_KEY);
    if (!pageview) return;

    var leaveMs = Date.now();
    pageview.leave_at         = new Date().toISOString();
    pageview.flush_reason     = reason || 'unknown';
    maxScrollDepth             = Math.max(maxScrollDepth, calcScrollDepth());
    updateActivityMetrics(pageview, leaveMs, false);

    writeJson(PAGE_KEY, pageview);

    var path = readPath().map(function (item) {
      if (item.session_id === pageview.session_id &&
          item.step_order === pageview.step_order &&
          item.visited_at === pageview.visited_at) {
        item.leave_at         = pageview.leave_at;
        item.duration_seconds = pageview.duration_seconds;
        item.active_duration_seconds = pageview.active_duration_seconds;
        item.scroll_depth     = pageview.scroll_depth;
        item.max_scroll_depth = pageview.max_scroll_depth;
      }
      return item;
    });
    writePath(path);

    if (!flushed || reason === 'beforeunload' || reason === 'pagehide' || reason === 'spa-navigate') {
      post(cfg.routes.pageview, pageview, true);
    }
    flushed = true;
  }

  function sendHeartbeat() {
    var pageview = readJson(PAGE_KEY);
    if (!pageview || document.visibilityState !== 'visible' || !lastActivityAtMs || (Date.now() - lastActivityAtMs) > ACTIVE_WINDOW_MS) return;
    maxScrollDepth = Math.max(maxScrollDepth, calcScrollDepth());
    updateActivityMetrics(pageview, Date.now(), true);
    writeJson(PAGE_KEY, pageview);
    var payload = {};
    Object.keys(pageview).forEach(function (key) { payload[key] = pageview[key]; });
    payload.leave_at = '';
    payload.event_type = 'heartbeat';
    post(cfg.routes.pageview, payload, false);
  }

  // ── Form submission detection ───────────────────────────────────────────────

  function getFieldValue(form, sel) {
    var f = form.querySelector(sel);
    return f ? f.value || '' : '';
  }

  function deriveFormMeta(form) {
    var cls      = typeof form.className === 'string' ? form.className : '';
    var idAttr   = form.getAttribute('data-form-id') || form.getAttribute('data-formid') || form.getAttribute('id') || '';
    var nameAttr = form.getAttribute('data-form-name') || form.getAttribute('name') || form.getAttribute('id') || '';
    var id       = idAttr;

    // KSSMI inquiry form detection
    if (form.classList.contains('inquiry-form') || idAttr.indexOf('inquiry-form') !== -1) {
      return { plugin: 'kssmi-inquiry', id: id, name: 'Inquiry Form' };
    }

    // Zeroy forms
    if (idAttr.indexOf('zeroy-form') !== -1) {
      return { plugin: 'zeroy', id: id, name: nameAttr };
    }

    return { plugin: 'generic', id: id, name: nameAttr };
  }

  function isSearchForm(form) {
    if (form.getAttribute('role') === 'search') return true;
    // Input name: s (WordPress-style) or q (KSSMI-style)
    if (form.querySelector('input[name="s"], input[name="q"]')) return true;
    var action = (form.getAttribute('action') || '').toLowerCase();
    // Action path: /search, /search/, ?s=, /search?... (any form posting to a search route)
    if (action.indexOf('?s=') !== -1 || action.indexOf('/search') !== -1) return true;
    // Form ID: any id containing "search" (header-search, mobile-search, etc.)
    var id = (form.getAttribute('id') || '').toLowerCase();
    if (id.indexOf('search') !== -1) return true;
    return false;
  }

  function trackSubmissionAttempt(form, requestedEventId) {
    if (!cfg.enabled || !identityReady || !serverIdentity || !form || form.tagName.toLowerCase() !== 'form' || isSearchForm(form)) return '';

    var visitorId = getVisitorId();
    var session  = getSession(_deviceInfo);
    if (!visitorId || !session) return '';
    var pageview = readJson(PAGE_KEY);
    var snapshot = buildPathSnapshot(pageview);
    patchForms(visitorId, session);

    var meta = deriveFormMeta(form);
    var attrReferrer = session.originalReferrer || session.referrer || '';
    var eventId = /^vjtev_[A-Za-z0-9_-]{8,80}$/.test(requestedEventId || '')
      ? requestedEventId
      : newTrackingId('vjtev_');

    var payload = {
      visitor_id   : visitorId,
      session_id   : session.id,
      event_id     : eventId,
      form_plugin  : meta.plugin,
      form_id      : meta.id,
      form_name    : meta.name,
      submit_page  : cfg.page.url,
      submit_title : cfg.page.title,
      submitted_at : new Date().toISOString(),
      status       : 'attempt',
      referrer     : attrReferrer,
      landing_url  : session.landingUrl   || '',
      landing_title: session.landingTitle || '',
      utm_source   : session.utmSource     || '',
      utm_medium   : session.utmMedium     || '',
      utm_campaign : session.utmCampaign  || '',
      utm_content  : session.utmContent    || '',
      utm_term     : session.utmTerm       || '',
      path_snapshot: snapshot,
      site_language: session.siteLanguage || getSiteLanguage()
    };

    submitConversion(payload);
    return eventId;
  }

  function bindSubmissionAttempts() {
    document.addEventListener('submit', function (event) {
      if (!cfg.enabled) return;
      var form = event.target;
      if (!form || form.tagName.toLowerCase() !== 'form' || isSearchForm(form)) return;

      // Inquiry Form owns its lifecycle. It starts tracking only after its
      // Turnstile check passes, then sends the same event ID to send-mail.php.
      // Keeping it out of this capture-phase listener prevents a single valid
      // click from being counted once here and once by the real request path.
      if (deriveFormMeta(form).plugin === 'kssmi-inquiry') return;
      trackSubmissionAttempt(form, '');
    }, true);
  }

  // ── Outbound link tracking (WhatsApp / mailto) ─────────────────────────────

  function resolveAnchor(target) {
    var el = target;
    while (el && el.tagName) {
      if (el.tagName.toLowerCase() === 'a') return el;
      el = el.parentElement;
    }
    return null;
  }

  function classifyOutboundLink(href) {
    if (!href) return null;
    if (href.indexOf('wa.me') !== -1 || href.indexOf('api.whatsapp.com') !== -1 || href.indexOf('web.whatsapp.com') !== -1) {
      return 'whatsapp';
    }
    if (href.slice(0, 7).toLowerCase() === 'mailto:') {
      return 'mailto';
    }
    return null;
  }

  function explicitContactKind(anchor) {
    var kind = (anchor.getAttribute('data-vjt-contact') || '').toLowerCase();
    return kind === 'whatsapp' || kind === 'mailto' ? kind : null;
  }

  function resolveContactHref(anchor, kind) {
    var href = anchor.getAttribute('href') || anchor.href || '';
    if (anchor.getAttribute('data-contact-core') === '1') return href;
    // Email addresses are intentionally obfuscated in static HTML. On touch
    // and keyboard activation there may be no mouseenter before this handler.
    if (kind === 'mailto' && href.slice(0, 7).toLowerCase() !== 'mailto:') {
      var user = anchor.getAttribute('data-email-user') || '';
      var domain = anchor.getAttribute('data-email-domain') || '';
      if (user && domain) return 'mailto:' + user + '@' + domain;
    }
    return href;
  }

  function isContactCoreLink(anchor, href) {
    if (anchor.getAttribute('data-contact-core') !== '1') return false;
    try {
      var url = new URL(href, window.location.href);
      return url.origin === window.location.origin && url.pathname === '/api/contact-intent.php';
    } catch (e) {
      return false;
    }
  }

  function enhanceModifiedContactCoreClick(event) {
    if (!cfg.enabled || event.defaultPrevented) return;
    var anchor = resolveAnchor(event.target);
    if (!anchor) return;
    var kind = explicitContactKind(anchor);
    var href = resolveContactHref(anchor, kind || '');
    if (!kind) kind = classifyOutboundLink(href);
    if (!kind || !isContactCoreLink(anchor, href)) return;
    // The always-on Contact Core owns its capability and navigation lifecycle.
    return;
  }

  function bindOutboundLinks() {
    document.addEventListener('click', function (event) {
      if (!cfg.enabled) return;
      if (event.defaultPrevented) return;
      var anchor = resolveAnchor(event.target);
      if (!anchor) return;

      var kind = explicitContactKind(anchor);
      var href = resolveContactHref(anchor, kind || '');
      if (!kind) kind = classifyOutboundLink(href);
      if (!kind) return;

      // Contact Core independently attaches its one-time capability plus the
      // optional analytics/step linkage. Do not mutate or navigate this href in
      // the analytics tracker; two listeners would race when restoring it.
      if (isContactCoreLink(anchor, href)) return;

      // Keep native new-tab/new-window behaviour for modified clicks; the
      // analytics tracker never intercepts direct contact navigation.
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
      }

      // Direct mailto/WhatsApp links are owned by the always-on Contact Core.
      // Never duplicate the same click in the legacy submissions table.
      return;
    }, true);

    // Chromium dispatches middle-click as auxclick rather than click.
    document.addEventListener('auxclick', function (event) {
      if (event.button === 1) enhanceModifiedContactCoreClick(event);
    }, true);
  }

  // ── Bootstrap ──────────────────────────────────────────────────────────────

  function completeVJTInitialization(forceNewMetadata) {
    // Admin traffic is excluded from analytics SERVER-SIDE only:
    // private/http-security.php validates the HMAC-signed vjt_admin marker
    // (kssmi_admin_tracking_excluded) at every track/contact API endpoint.
    // The marker cookie is HttpOnly, so this client script can never read it
    // and no client-side check exists here — a forged cookie cannot pass
    // server-side HMAC validation, and a visitor cannot opt out of analytics
    // by setting any cookie value.

    var visitorId  = getVisitorId();
    var session    = getSession(_deviceInfo, forceNewMetadata);
    if (!visitorId || !session) return false;

    // Always update site language to reflect CURRENT page language,
    // not the stale value from session creation (fixes /ko/ pages showing as EN)
    session.siteLanguage = getSiteLanguage();

    // ── Consent-gated conversion context (form / WhatsApp / mailto) ──────────
    // Delegated listeners bind once; forms are re-patched on navigation so
    // hidden fields retain the current session and attribution evidence.
    patchForms(visitorId, session);
    if (!window.__vjtConvBound) {
      bindSubmissionAttempts();
      bindOutboundLinks();

      // Debounced form patching on DOM mutations (B3 fix).
      // Perf: bail out cheaply unless a <form> was actually added, so image
      // carousels / lazy-load / animations don't churn timers on every mutation
      // (this is the main cause of phones running hot).
      var formsTimer = null;
      var mutationAddedForm = function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
          var added = mutations[i].addedNodes;
          for (var j = 0; j < added.length; j++) {
            var n = added[j];
            if (n.nodeType !== 1) continue; // elements only
            if (n.tagName === 'FORM' || (n.querySelector && n.querySelector('form'))) return true;
          }
        }
        return false;
      };
      new MutationObserver(function (mutations) {
        if (!mutationAddedForm(mutations)) return;
        clearTimeout(formsTimer);
        formsTimer = setTimeout(function () {
          if (!cfg.enabled || !identityReady) return;
          patchForms(getVisitorId(), getSession());
        }, 500);
      }).observe(document.documentElement, { childList: true, subtree: true });

      window.__vjtConvBound = true;
    }
    if (!window.__vjtConvOnlineBound) {
      window.addEventListener('online', flushPendingConversions);
      window.__vjtConvOnlineBound = true;
    }
    flushPendingConversions();

    // ── Passive pageview / behaviour tracking ───────────────────────────────

    var pageview = currentPageview(visitorId, session);
    activeAccumulatedMs = 0;
    lastActivityAtMs = Date.now();
    lastSyncMs = pageview.created_at_ms;

    if (!window.__vjtPvBound) {
      // Scroll depth tracking (B1 fix — bound once, not on every SPA nav).
      // Perf: throttle (skip while a tick is pending) instead of re-creating a
      // timer on every scroll event — far fewer wakeups while scrolling.
      window.addEventListener('scroll', function () {
        recordActivity();
        if (scrollTimer) return;
        scrollTimer = setTimeout(function () {
          scrollTimer = null;
          var d = calcScrollDepth();
          if (d > maxScrollDepth) maxScrollDepth = d;
        }, 300);
      }, { passive: true });

      ['pointerdown', 'keydown', 'touchstart', 'input', 'change'].forEach(function (eventName) {
        document.addEventListener(eventName, recordActivity, { passive: true });
      });

      window.addEventListener('pagehide', function () { flushPageview('pagehide'); });

      document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
          flushPageview('visibilitychange');
          // Do not let the 15-second activity window continue accumulating
          // while the tab is hidden.
          lastActivityAtMs = 0;
        } else {
          recordActivity();
          flushed = false;
        }
      });

      window.addEventListener('beforeunload', function () { flushPageview('beforeunload'); });
      heartbeatTimer = window.setInterval(sendHeartbeat, HEARTBEAT_MS);
      window.__vjtPvBound = true;
    } else {
      // B2 fix: reset flushed so subsequent SPA navigations send leave events
      flushed = false;
      if (!heartbeatTimer) heartbeatTimer = window.setInterval(sendHeartbeat, HEARTBEAT_MS);
    }

    session = getSession(_deviceInfo);
    startTracking(pageview, session);
    return true;
  }

  function initVJT() {
    cfg = window.VJTTracker || cfg;
    if (!cfg) return Promise.resolve(false);
    cfg.page = cfg.page || {};

    // Always use live URL/title for SPA navigations (Astro View Transitions).
    var initUrl = cleanUrl(window.location.href);
    var initTitle = document.title || '';
    cfg.page.url = initUrl;
    cfg.page.title = initTitle;

    // VJT identifiers, form context, contact intents and passive behaviour are
    // all analytics-consent gated. A real Inquiry is still recorded server-side
    // by send-mail.php even when no VJT identifier exists.
    if (!cfg.enabled) return Promise.resolve(false);

    // The async loader, DOM-ready bootstrap and Astro page-load event can all
    // meet on the same document. Share the in-flight server bootstrap for the
    // same URL and queue a newer SPA URL behind it.
    if (initPromise) {
      if (initPromiseUrl === initUrl) return initPromise;
      return initPromise.then(function () { return initVJT(); });
    }

    if (window.__vjtInitializedUrl === initUrl && identityReady) {
      _deviceInfo = getDeviceInfo();
      var existingVisitorId = getVisitorId();
      var existingSession = getSession(_deviceInfo);
      patchForms(existingVisitorId, existingSession);
      flushPendingConversions();
      return Promise.resolve(true);
    }

    var rotateSession = sessionNeedsRotation();

    // Flush the old SPA view while its identity and prefetched token are still
    // current. A rotated capability bundle cannot authorize the old session.
    if (window.__vjtPvBound && identityReady && window.__vjtInitializedUrl !== initUrl) {
      flushPageview('spa-navigate');
    }

    _deviceInfo = getDeviceInfo();
    initPromiseUrl = initUrl;
    var pendingInit = bootstrapCapabilities(rotateSession).then(function (capabilityResult) {
      // A faster SPA navigation may have superseded this initialization while
      // the capability request was in flight. Leave no page write for it.
      if (!cfg.enabled || cleanUrl(window.location.href) !== initUrl) return false;
      cfg.page.url = initUrl;
      cfg.page.title = initTitle;
      var completed = completeVJTInitialization(
        rotateSession || capabilityResult.localSessionChanged || capabilityResult.identityChanged
      );
      if (completed) window.__vjtInitializedUrl = initUrl;
      return !!completed;
    }).catch(function (error) {
      // Invalid/missing configuration and bootstrap failures are fail closed.
      if (!error || !error.vjtSuperseded) invalidateServerIdentity();
      return false;
    });

    initPromise = pendingInit;
    pendingInit.then(function () {
      if (initPromise === pendingInit) {
        initPromise = null;
        initPromiseUrl = '';
      }
    });
    return pendingInit;
  }

  // Expose init globally so VisitorTracker.astro can call it directly
  // without re-downloading the entire script on every SPA navigation (I1 fix)
  window.VJT_init = initVJT;
  window.VJT_beginInquirySubmission = function (form) {
    if (!form || deriveFormMeta(form).plugin !== 'kssmi-inquiry') return '';
    return trackSubmissionAttempt(form, '');
  };

  window.addEventListener('vjt:consent-withdrawn', function () {
    invalidateServerIdentity();
    initPromise = null;
    initPromiseUrl = '';
    flushed = true;
    window.__vjtInitializedUrl = '';
  });

  // Bootstrap — first load
  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    setTimeout(initVJT, 0);
  } else {
    document.addEventListener('DOMContentLoaded', function () { initVJT(); });
  }

})();
