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
    'vjt_utm_term'
  ];
  var flushed         = false;
  var maxScrollDepth  = 0;
  var scrollTimer     = null;
  var _deviceInfo     = null; // I3: stored globally for event handlers
  var lastActivityAtMs = 0;
  var lastSyncMs = 0;
  var activeAccumulatedMs = 0;
  var heartbeatTimer = null;

  function newTrackingId(prefix) {
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
    // Cookie fallback keeps one visitor identity when localStorage is blocked
    // or cleared independently. The server-facing cookie never wins over a
    // valid localStorage value.
    var id = readStorage(VISITOR_KEY) || readCookie('vjt_visitor_id');
    if (!id) {
      id = newTrackingId('vjtv_');
    }
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

  function getSession(deviceInfo) {
    var session    = readJson(SESSION_KEY);
    var previous   = session;
    var now        = Date.now();
    var timeoutMs  = (cfg.sessionTimeout || 30) * 60 * 1000;
    var expired    = !session || !session.lastActivity || (now - session.lastActivity > timeoutMs);
    var utm        = extractUtm();
    var pageReferrer = document.referrer || '';
    var externalReferrer = pageReferrer && !isInternalReferrer(pageReferrer) ? pageReferrer : '';

    // A new campaign or a genuinely new external referrer starts a fresh
    // attribution session even if the inactivity timeout has not elapsed.
    if (!expired && (campaignChanged(session, utm) ||
        (externalReferrer && referrerHost(externalReferrer) !== referrerHost(session.originalReferrer || session.referrer)))) {
      expired = true;
    }

    if (expired) {
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
        id               : newTrackingId('vjts_'),
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

    session.lastActivity = now;

    writeJson(SESSION_KEY, session);
    setCookie('vjt_session_id', session.id, (cfg.sessionTimeout || 30) * 60);
    return session;
  }

  function saveSession(session) {
    session.lastActivity = Date.now();
    writeJson(SESSION_KEY, session);
    setCookie('vjt_session_id', session.id, (cfg.sessionTimeout || 30) * 60);
  }

  // ── Network ────────────────────────────────────────────────────────────────

  function post(url, payload, useBeacon) {
    // Consent can be withdrawn after the persistent SPA/pagehide listeners were
    // registered. Re-check at the single network boundary so no later
    // heartbeat, leave event or conversion intent can escape after withdrawal.
    if (!cfg.enabled) return false;

    if (useBeacon && navigator.sendBeacon) {
      try {
        return navigator.sendBeacon(url, new Blob([JSON.stringify(payload)], { type: 'application/json' }));
      } catch (e) {}
    }

    return fetch(url, {
      method      : 'POST',
      credentials : 'same-origin',
      keepalive   : !!useBeacon,
      headers     : { 'Content-Type': 'application/json' },
      body        : JSON.stringify(payload)
    }).catch(function () { return null; });
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
    if (!cfg.enabled || !cfg.routes || !cfg.routes.submission) return Promise.resolve('drop');
    try {
      return fetch(cfg.routes.submission, {
        method      : 'POST',
        credentials : 'same-origin',
        keepalive   : true,
        headers     : { 'Content-Type': 'application/json' },
        body        : JSON.stringify(payload)
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
    var path = readPath().slice();
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
    return url.replace(/[?&]vjt_(visitor_id|session_id|submit_page|submit_title|referrer|landing_url|landing_title|path_snapshot|utm_source|utm_medium|utm_campaign|utm_content|utm_term)=[^&]*/gi, '')
              .replace(/\?&/, '?')   // fix leftover ?& after first param is stripped
              .replace(/\?$/, '')
              .replace(/&$/, '');
  }

  function patchForms(visitorId, session) {
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

  function loadTrackerConfig() {
    if (window.__vjtConfigLoaded || !cfg.routes || !cfg.routes.config) return;
    window.__vjtConfigLoaded = true;
    fetch(cfg.routes.config, { credentials: 'same-origin' })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) {
        var seconds = data && Number(data.heartbeat_seconds);
        if (!seconds || seconds < 30 || seconds > 120) return;
        HEARTBEAT_MS = seconds * 1000;
        if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = window.setInterval(sendHeartbeat, HEARTBEAT_MS); }
      })
      .catch(function () {});
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

  function bindSubmissionAttempts(visitorId) {
    document.addEventListener('submit', function (event) {
      if (!cfg.enabled) return;
      var form = event.target;
      if (!form || form.tagName.toLowerCase() !== 'form') return;
      if (isSearchForm(form)) return;

      var session  = getSession(_deviceInfo);
      var pageview = readJson(PAGE_KEY);
      var snapshot = buildPathSnapshot(pageview);
      patchForms(visitorId, session);

      var meta = deriveFormMeta(form);
      var attrReferrer = session.originalReferrer || session.referrer || '';

      var payload = {
        visitor_id   : visitorId,
        session_id   : session.id,
        event_id     : newTrackingId('vjtev_'),
        form_plugin  : meta.plugin,
        form_id      : meta.id,
        form_name    : meta.name,
        submit_page  : cfg.page.url,
        submit_title : cfg.page.title,
        submitted_at : new Date().toISOString(),
        // Browser submit only proves an attempt. The PHP mail handler records
        // success after SMTP accepts the message.
        status       : 'attempt',
        referrer     : attrReferrer,
        landing_url  : session.landingUrl   || '',
        landing_title: session.landingTitle || '',
        utm_source   : session.utmSource     || '',
        utm_medium   : session.utmMedium     || '',
        utm_campaign : session.utmCampaign   || '',
        utm_content  : session.utmContent    || '',
        utm_term     : session.utmTerm       || '',
        path_snapshot: snapshot,
        site_language: session.siteLanguage || getSiteLanguage()
      };

      submitConversion(payload);
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
    // Email addresses are intentionally obfuscated in static HTML. On touch
    // and keyboard activation there may be no mouseenter before this handler.
    if (kind === 'mailto' && href.slice(0, 7).toLowerCase() !== 'mailto:') {
      var user = anchor.getAttribute('data-email-user') || '';
      var domain = anchor.getAttribute('data-email-domain') || '';
      if (user && domain) return 'mailto:' + user + '@' + domain;
    }
    return href;
  }

  function bindOutboundLinks(visitorId) {
    document.addEventListener('click', function (event) {
      if (!cfg.enabled) return;
      if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      var anchor = resolveAnchor(event.target);
      if (!anchor) return;

      var kind = explicitContactKind(anchor);
      var href = resolveContactHref(anchor, kind || '');
      if (!kind) kind = classifyOutboundLink(href);
      if (!kind) return;

      event.preventDefault();

      var session  = getSession(_deviceInfo);
      var pageview = readJson(PAGE_KEY);
      var snapshot = buildPathSnapshot(pageview);
      var attrReferrer = session.originalReferrer || session.referrer || '';

      var label = href;
      if (kind === 'whatsapp') {
        label = href.replace(/^https?:\/\/[^/]*wa\.me\//i, '').replace(/^https?:\/\/api\.whatsapp\.com\/send\??/i, '').split('?')[0] || href;
      } else if (kind === 'mailto') {
        label = href.slice(7).split('?')[0] || href;
      }

      var payload = {
        visitor_id   : visitorId,
        session_id   : session.id,
        event_id     : newTrackingId('vjtev_'),
        form_plugin  : kind,
        form_id      : label,
        form_name    : kind === 'whatsapp' ? 'WhatsApp Click' : 'Mailto Click',
        submit_page  : cfg.page.url,
        submit_title : cfg.page.title,
        submitted_at : new Date().toISOString(),
        // A click is a contact intent, not proof that WhatsApp/mail was sent.
        status       : 'intent',
        contact_url  : href,
        referrer     : attrReferrer,
        landing_url  : session.landingUrl   || '',
        landing_title: session.landingTitle || '',
        utm_source   : session.utmSource     || '',
        utm_medium   : session.utmMedium     || '',
        utm_campaign : session.utmCampaign   || '',
        utm_content  : session.utmContent    || '',
        utm_term     : session.utmTerm       || '',
        path_snapshot: snapshot,
        site_language: session.siteLanguage || getSiteLanguage()
      };

      var proceed = function() {
        if (anchor.target === '_blank') {
          window.open(href, '_blank');
        } else {
          window.location.href = href;
        }
      };

      var navTimer = setTimeout(proceed, 300);
      var done = false;
      var markDone = function() {
        if (done) return;
        done = true;
        clearTimeout(navTimer);
        proceed();
      };

      submitConversion(payload, markDone);
    }, true);
  }

  // ── Bootstrap ──────────────────────────────────────────────────────────────

  function initVJT() {
    cfg = window.VJTTracker || cfg;
    if (!cfg) return;
    loadTrackerConfig();

    // P3-1 (N11): Skip tracking for admin users (vjt_admin cookie set by
    // email-logs.php / visitor-journey.php after successful login).
    // This prevents admin pageviews from polluting analytics data.
    // Note: this is a client-side convenience check — a visitor could manually
    // set this cookie to bypass tracking, but that only affects data accuracy
    // (not security). Server-side admin filtering is the long-term plan.
    if (document.cookie.indexOf('vjt_admin=1') !== -1) return;

    // Always use live URL/title for SPA navigations (Astro View Transitions)
    cfg.page.url = cleanUrl(window.location.href);
    cfg.page.title = document.title || '';

    // VJT identifiers, form context, contact intents and passive behaviour are
    // all analytics-consent gated. A real Inquiry is still recorded server-side
    // by send-mail.php even when no VJT identifier exists.
    if (!cfg.enabled) return;

    _deviceInfo = getDeviceInfo();
    var visitorId  = getVisitorId();
    var session    = getSession(_deviceInfo);

    // Always update site language to reflect CURRENT page language,
    // not the stale value from session creation (fixes /ko/ pages showing as EN)
    session.siteLanguage = getSiteLanguage();

    // ── Consent-gated conversion context (form / WhatsApp / mailto) ──────────
    // Delegated listeners bind once; forms are re-patched on navigation so
    // hidden fields retain the current session and attribution evidence.
    patchForms(visitorId, session);
    if (!window.__vjtConvBound) {
      bindSubmissionAttempts(visitorId);
      bindOutboundLinks(visitorId);

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
          if (!cfg.enabled) return;
          patchForms(visitorId, getSession());
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

    // If pageview tracking already bound (Astro SPA navigation), flush previous view
    if (window.__vjtPvBound) {
      flushPageview('spa-navigate');
    }

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
    }

    session = getSession(_deviceInfo);
    startTracking(pageview, session);
  }

  // Expose init globally so VisitorTracker.astro can call it directly
  // without re-downloading the entire script on every SPA navigation (I1 fix)
  window.VJT_init = initVJT;

  // Bootstrap — first load
  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    setTimeout(initVJT, 0);
  } else {
    document.addEventListener('DOMContentLoaded', function () { initVJT(); });
  }

})();
