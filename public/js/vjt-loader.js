// VJT tracker bootstrap. Loaded as a classic external script at the end of
// <body> (see VisitorTracker.astro), so it runs at the same point the former
// inline copy did. Per-page values come from the script element's data-*
// attributes instead of define:vars, which keeps this file identical on every
// page and cacheable as a fingerprinted runtime asset.
//
// Astro's ClientRouter re-executes body scripts after a soft navigation;
// window.__vjtLoaderBound keeps one persistent loader/listener.
(function() {
  var config = (document.currentScript && document.currentScript.dataset) || {};
  var trackerScriptUrl = config.trackerUrl || '';
  var allowLocalVjt = config.allowLocalVjt === 'true';
  var isDev = config.dev === 'true';
  // Keep one persistent loader/listener so a navigation cannot start the same
  // pageview twice.
  if (window.__vjtLoaderBound) return;
  window.__vjtLoaderBound = true;
  var loading = false;
  var loaded = false;
  function injectTracker() {
    if (loading || loaded || window.VJT_init) return;
    if (!isDev && !trackerScriptUrl) return;
    loading = true;
    var s = document.createElement('script');
    if (isDev) {
      var devHostname = window.location.hostname === '127.0.0.1'
        ? '127.0.0.1'
        : ((window.location.hostname === '::1' || window.location.hostname === '[::1]') ? '[::1]' : 'localhost');
      s.src = 'http://' + devHostname + ':4325/js/vjt-tracker.js';
    } else {
      s.src = trackerScriptUrl;
    }
    s.async = true;
    s.onload = function() {
      loading = false;
      loaded = true;
      if (window.VJT_init) window.VJT_init();
    };
    s.onerror = function() {
      loading = false;
      loaded = false;
    };
    document.head.appendChild(s);
  }
  function scheduleInject() {
    var hostname = window.location.hostname;
    var isLocalHost = hostname === 'localhost' || hostname === '127.0.0.1'
      || hostname === '::1' || hostname === '[::1]';
    if (isLocalHost && !allowLocalVjt) return;
    if (!window.VJTTracker || !window.VJTTracker.enabled) return;
    injectTracker();
  }
  // Existing consent may be available on the first load.
  scheduleInject();
  // The cookie banner dispatches this after the visitor grants analytics consent.
  window.addEventListener('vjt:consent-granted', scheduleInject);
  // On SPA navigations, call init directly instead of re-downloading the script
  document.addEventListener('astro:page-load', function() {
    if (window.VJT_init) {
      // Already loaded — just re-init with updated config and URL (I1 fix)
      window.VJTTracker.page.url = window.location.href;
      window.VJTTracker.page.title = document.title || '';
      window.VJT_init();
    } else {
      // First load has not completed yet. injectTracker() keeps the in-flight
      // script unique; never clear its guard from astro:page-load.
      scheduleInject();
    }
  });
})();
