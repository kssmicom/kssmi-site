(function () {
  var cfg = window.VJTTracker || { page: {}, routes: {} };
  var VISITOR_KEY = 'vjt_visitor_id';
  var SESSION_KEY = 'vjt_session_meta';
  var PAGE_KEY    = 'vjt_current_pageview';
  var PATH_KEY    = 'vjt_session_path';
  var hiddenNames = [
    'vjt_visitor_id',
    'vjt_session_id',
    'vjt_submit_page',
    'vjt_submit_title',
    'vjt_referrer',
    'vjt_landing_url',
    'vjt_landing_title',
    'vjt_path_snapshot'
  ];
  var flushed         = false;
  var maxScrollDepth  = 0;
  var scrollTimer     = null;
  var _deviceInfo     = null; // I3: stored globally for event handlers

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

  function readStorage(key) {
    try { return window.localStorage.getItem(key); } catch (e) { return null; }
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

  function getCookie(name) {
    var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? decodeURIComponent(match[2]) : null;
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
        var kv = pair.split('=');
        if (kv[0] && kv[0].slice(0, 4) === 'utm_') {
          utm[kv[0]] = decodeURIComponent((kv[1] || '').replace(/\+/g, ' '));
        }
      });
      return utm;
    } catch (e) { return {}; }
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
    var id = readStorage(VISITOR_KEY);
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
    var now        = Date.now();
    var timeoutMs  = (cfg.sessionTimeout || 30) * 60 * 1000;
    var expired    = !session || !session.lastActivity || (now - session.lastActivity > timeoutMs);

    if (expired) {
      var utm = extractUtm();
      var di  = deviceInfo || {};
      session = {
        id               : newTrackingId('vjts_'),
        startedAt        : nowBeijing(),
        landingUrl       : cfg.page.url,
        landingTitle     : cfg.page.title,
        referrer         : document.referrer || '',
        originalReferrer : document.referrer || '',
        stepOrder        : 0,
        lastActivity     : now,
        utmSource        : utm['utm_source']   || '',
        utmMedium        : utm['utm_medium']   || '',
        utmCampaign      : utm['utm_campaign'] || '',
        utmContent       : utm['utm_content']  || '',
        utmTerm          : utm['utm_term']      || '',
        screenResolution : di.screen_resolution || '',
        timezone         : di.timezone          || '',
        language         : di.language          || '',
        siteLanguage     : getSiteLanguage()    || 'EN'
      };
      writePath([]);
    }

    session.lastActivity = now;

    if (!session.referrer && document.referrer) {
      session.referrer = document.referrer;
    }

    writeJson(SESSION_KEY, session);
    setCookie('vjt_session_id', session.id, cfg.cookieExpires || 31536000);
    return session;
  }

  function saveSession(session) {
    session.lastActivity = Date.now();
    writeJson(SESSION_KEY, session);
    setCookie('vjt_session_id', session.id, cfg.cookieExpires || 31536000);
  }

  // ── Network ────────────────────────────────────────────────────────────────

  function post(url, payload, useBeacon) {
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
    return url.replace(/[?&]vjt_(visitor_id|session_id|submit_page|submit_title|referrer|landing_url|landing_title|path_snapshot)=[^&]*/gi, '')
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
      visited_at        : nowBeijing(),
      leave_at          : '',
      duration_seconds  : 0,
      scroll_depth      : 0,
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
      scroll_depth      : pageview.scroll_depth,
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
    pageview.leave_at         = nowBeijing();
    pageview.duration_seconds = secondsBetween(pageview.created_at_ms || leaveMs, leaveMs);
    pageview.flush_reason     = reason || 'unknown';
    maxScrollDepth             = Math.max(maxScrollDepth, calcScrollDepth());
    pageview.scroll_depth     = maxScrollDepth;

    writeJson(PAGE_KEY, pageview);

    var path = readPath().map(function (item) {
      if (item.session_id === pageview.session_id &&
          item.step_order === pageview.step_order &&
          item.visited_at === pageview.visited_at) {
        item.leave_at         = pageview.leave_at;
        item.duration_seconds = pageview.duration_seconds;
        item.scroll_depth     = maxScrollDepth;
      }
      return item;
    });
    writePath(path);

    if (!flushed || reason === 'beforeunload' || reason === 'pagehide' || reason === 'spa-navigate') {
      post(cfg.routes.pageview, pageview, true);
    }
    flushed = true;
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
    var searchInput = form.querySelector('input[name="s"]');
    if (searchInput) return true;
    var action = form.getAttribute('action') || '';
    if (action.indexOf('?s=') !== -1 || action.indexOf('/search/') !== -1) return true;
    return false;
  }

  function bindSubmissionAttempts(visitorId) {
    document.addEventListener('submit', function (event) {
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
        form_plugin  : meta.plugin,
        form_id      : meta.id,
        form_name    : meta.name,
        submit_page  : cfg.page.url,
        submit_title : cfg.page.title,
        submitted_at : nowBeijing(),
        status       : 'success',
        referrer     : attrReferrer,
        landing_url  : session.landingUrl   || '',
        landing_title: session.landingTitle || '',
        path_snapshot: snapshot,
        site_language: session.siteLanguage || getSiteLanguage()
      };

      try {
        fetch(cfg.routes.submission, {
          method      : 'POST',
          credentials : 'same-origin',
          keepalive   : true,
          headers     : { 'Content-Type': 'application/json' },
          body        : JSON.stringify(payload)
        }).catch(function () {
          if (navigator.sendBeacon) {
            navigator.sendBeacon(cfg.routes.submission, new Blob([JSON.stringify(payload)], { type: 'application/json' }));
          }
        });
      } catch (e) {
        if (navigator.sendBeacon) {
          navigator.sendBeacon(cfg.routes.submission, new Blob([JSON.stringify(payload)], { type: 'application/json' }));
        }
      }
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
    if (href.indexOf('wa.me') !== -1 || href.indexOf('api.whatsapp.com') !== -1) {
      return 'whatsapp';
    }
    if (href.slice(0, 7).toLowerCase() === 'mailto:') {
      return 'mailto';
    }
    return null;
  }

  function bindOutboundLinks(visitorId) {
    document.addEventListener('click', function (event) {
      var anchor = resolveAnchor(event.target);
      if (!anchor) return;

      var href = anchor.getAttribute('href') || anchor.href || '';
      var kind = classifyOutboundLink(href);
      if (!kind) return;

      if (!event.defaultPrevented) {
        event.preventDefault();
      }

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
        form_plugin  : kind,
        form_id      : label,
        form_name    : kind === 'whatsapp' ? 'WhatsApp Click' : 'Mailto Click',
        submit_page  : cfg.page.url,
        submit_title : cfg.page.title,
        submitted_at : nowBeijing(),
        status       : 'success',
        contact_url  : href,
        referrer     : attrReferrer,
        landing_url  : session.landingUrl   || '',
        landing_title: session.landingTitle || '',
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

      try {
        fetch(cfg.routes.submission, {
          method      : 'POST',
          credentials : 'same-origin',
          keepalive   : true,
          headers     : { 'Content-Type': 'application/json' },
          body        : JSON.stringify(payload)
        })
        .then(markDone)
        .catch(function () {
          if (navigator.sendBeacon) {
            navigator.sendBeacon(cfg.routes.submission, new Blob([JSON.stringify(payload)], { type: 'application/json' }));
          }
          markDone();
        });
      } catch (e) {
        if (navigator.sendBeacon) {
          navigator.sendBeacon(cfg.routes.submission, new Blob([JSON.stringify(payload)], { type: 'application/json' }));
        }
        markDone();
      }
    }, true);
  }

  // ── Bootstrap ──────────────────────────────────────────────────────────────

  function initVJT() {
    cfg = window.VJTTracker || cfg;
    if (!cfg || !cfg.enabled) return;

    // Skip tracking for admin (cookie set by dashboard login)
    if (getCookie('vjt_admin')) return;

    // Always use live URL/title for SPA navigations (Astro View Transitions)
    cfg.page.url = cleanUrl(window.location.href);
    cfg.page.title = document.title || '';

    _deviceInfo = getDeviceInfo();
    var visitorId  = getVisitorId();
    var session    = getSession(_deviceInfo);

    // Always update site language to reflect CURRENT page language,
    // not the stale value from session creation (fixes /ko/ pages showing as EN)
    session.siteLanguage = getSiteLanguage();

    // If tracker is already bound (this is an Astro SPA navigation), flush previous view
    if (window.__vjtBound) {
      flushPageview('spa-navigate');
    }

    var pageview   = currentPageview(visitorId, session);

    patchForms(visitorId, session);

    if (!window.__vjtBound) {
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
          patchForms(visitorId, getSession());
        }, 500);
      }).observe(document.documentElement, { childList: true, subtree: true });

      // Scroll depth tracking (B1 fix — bound once, not on every SPA nav).
      // Perf: throttle (skip while a tick is pending) instead of re-creating a
      // timer on every scroll event — far fewer wakeups while scrolling.
      window.addEventListener('scroll', function () {
        if (scrollTimer) return;
        scrollTimer = setTimeout(function () {
          scrollTimer = null;
          var d = calcScrollDepth();
          if (d > maxScrollDepth) maxScrollDepth = d;
        }, 300);
      }, { passive: true });

      window.addEventListener('pagehide', function () { flushPageview('pagehide'); });

      document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
          flushPageview('visibilitychange');
        } else {
          flushed = false;
        }
      });

      window.addEventListener('beforeunload', function () { flushPageview('beforeunload'); });
      window.__vjtBound = true;
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
