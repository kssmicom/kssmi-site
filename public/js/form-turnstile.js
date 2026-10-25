// Form Turnstile controller for the inquiry and 404 rescue forms. Mounts the
// widget into the current page's #turnstile-container (site key from its
// data-sitekey) when the form nears the viewport, and removes the previous
// widget on navigation. Loaded by FormTurnstile.astro as a classic external
// script after the container; identical on every page, so ClientRouter runs it
// once per document and its astro:page-load listener mounts each new form.
(function() {
  if (window.__kssmiFormTurnstile) return;
  var current = { container: null, widgetId: null, observer: null, attempt: 0 };
  window.__kssmiFormTurnstile = current;

  function isCurrent(container) {
    return current.container === container && container.isConnected;
  }

  function hideTurnstileError(container) {
    var form = container.closest('form');
    var errorEl = form ? form.querySelector('#turnstile-error') : null;
    if (errorEl) errorEl.classList.add('hidden');
  }

  function release() {
    if (current.observer) {
      current.observer.disconnect();
      current.observer = null;
    }
    if (current.widgetId !== null && window.turnstile && typeof window.turnstile.remove === 'function') {
      try { window.turnstile.remove(current.widgetId); } catch (_) {}
    }
    current.widgetId = null;
    current.container = null;
    current.attempt++;
    window.turnstileWidgetId = undefined;
    window.turnstileToken = null;
  }

  function showLoadFailure(container) {
    if (!isCurrent(container)) return;
    container.setAttribute('data-turnstile-state', 'failed');
    var box = document.createElement('div');
    box.className = 'text-center';
    var message = document.createElement('p');
    message.className = 'text-sm text-stone-600 mb-2';
    message.textContent = container.getAttribute('data-load-failed') || '';
    var retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'px-4 py-2 rounded-lg border border-[#8B7355] text-sm font-semibold text-[#5D4E37] hover:bg-stone-100 active:bg-stone-100 focus-visible:bg-stone-100 focus-within:bg-stone-100 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-[#8B7355]';
    retry.textContent = container.getAttribute('data-retry') || 'Retry';
    retry.addEventListener('click', function() { renderWidget(container); });
    box.appendChild(message);
    box.appendChild(retry);
    container.replaceChildren(box);
  }

  function renderWidget(container) {
    if (!isCurrent(container)) return;
    // One render attempt at a time: a second trigger (double-clicked
    // retry, a late observer callback) must not render a second widget.
    if (container.getAttribute('data-turnstile-state') === 'loading') return;
    if (typeof window.__kssmiTurnstileApi !== 'function') {
      showLoadFailure(container);
      return;
    }
    var attempt = ++current.attempt;
    container.setAttribute('data-turnstile-state', 'loading');
    window.__kssmiTurnstileApi().then(function(api) {
      // The visitor may have navigated away, or a newer attempt started,
      // while the API was loading.
      if (!isCurrent(container) || attempt !== current.attempt) return;
      if (current.widgetId !== null) {
        try { api.remove(current.widgetId); } catch (_) {}
        current.widgetId = null;
      }
      var target = document.createElement('div');
      container.replaceChildren(target);
      window.turnstileToken = null;
      var widgetId = api.render(target, {
        sitekey: container.getAttribute('data-sitekey') || '',
        action: 'contact_form',
        theme: 'light',
        callback: function(token) {
          if (!isCurrent(container)) return;
          window.turnstileToken = token;
          hideTurnstileError(container);
        },
        'expired-callback': function() {
          if (isCurrent(container)) window.turnstileToken = null;
        },
        'error-callback': function() {
          // The widget shows its own error state and retries itself.
          if (isCurrent(container)) window.turnstileToken = null;
        }
      });
      if (widgetId === undefined || widgetId === null) throw new Error('Turnstile render failed');
      current.widgetId = widgetId;
      window.turnstileWidgetId = widgetId;
      container.setAttribute('data-turnstile-state', 'ready');
    }).catch(function() {
      if (attempt === current.attempt) showLoadFailure(container);
    });
  }

  function mount() {
    var container = document.getElementById('turnstile-container');
    if (container && container === current.container) return;
    release();
    if (!container || !container.hasAttribute('data-kssmi-turnstile')) return;
    current.container = container;
    // Defer the challenge until the form approaches the viewport.
    if (!('IntersectionObserver' in window)) {
      renderWidget(container);
      return;
    }
    var observer = new IntersectionObserver(function(entries) {
      var visible = false;
      for (var i = 0; i < entries.length; i++) {
        if (entries[i].isIntersecting) visible = true;
      }
      if (!visible) return;
      observer.disconnect();
      if (current.observer === observer) current.observer = null;
      renderWidget(container);
    }, { rootMargin: '300px' });
    current.observer = observer;
    observer.observe(container.closest('form') || container);
  }

  mount();
  document.addEventListener('astro:page-load', mount);
  document.addEventListener('astro:before-swap', release);
  // A page restored from the back/forward cache still holds the token it
  // already submitted; Turnstile tokens are single-use.
  window.addEventListener('pageshow', function(event) {
    if (!event.persisted) return;
    window.turnstileToken = null;
    if (current.widgetId !== null && window.turnstile && typeof window.turnstile.reset === 'function') {
      try { window.turnstile.reset(current.widgetId); } catch (_) {}
    }
  });
})();
