// Header lifecycle (mega menu, mobile menu, language dropdown, transparent
// header on the hero). Loaded by Header.astro as a deferred external script
// with the same text on every page, so ClientRouter does not run it again on a
// soft navigation; astro:before-swap / astro:page-load rebind each new page. A
// new fingerprint after a deploy does run in a tab that stayed open; the
// lifecycle key below then tears the previous instance down first.
(() => {
  // Keep exactly one Header lifecycle alive and tear down any previous one first.
  const lifecycleKey = '__kssmiHeaderLifecycle';
  const previousLifecycle = window[lifecycleKey];
  if (previousLifecycle && typeof previousLifecycle.destroy === 'function') {
    previousLifecycle.destroy();
  }

  const lifecycleController = new AbortController();
  let pageController = null;
  let activeHeader = null;
  let megaMenuTimeout;
  let megaMenuPanel = null;
  let megaMenuContent = null;
  let headerMenuData = null;
  let transparentObserver = null;
  let transparentRafId = 0;
  const pageTimers = new Set();
  const pageRafs = new Set();

  function setListener(element, event, handler, options = {}) {
    if (!pageController || pageController.signal.aborted) return;
    element.addEventListener(event, handler, {
      ...options,
      signal: pageController.signal,
    });
  }

  function setPageTimeout(callback, delay) {
    if (!pageController || pageController.signal.aborted) return 0;
    const controller = pageController;
    const timerId = window.setTimeout(() => {
      pageTimers.delete(timerId);
      if (!controller.signal.aborted) callback();
    }, delay);
    pageTimers.add(timerId);
    return timerId;
  }

  function clearPageTimeout(timerId) {
    if (!timerId) return;
    window.clearTimeout(timerId);
    pageTimers.delete(timerId);
  }

  function requestPageFrame(callback) {
    if (!pageController || pageController.signal.aborted) return 0;
    const controller = pageController;
    const rafId = window.requestAnimationFrame(() => {
      pageRafs.delete(rafId);
      if (!controller.signal.aborted) callback();
    });
    pageRafs.add(rafId);
    return rafId;
  }

  function cleanupPage() {
    pageController?.abort();
    pageController = null;

    pageTimers.forEach(timerId => window.clearTimeout(timerId));
    pageTimers.clear();
    pageRafs.forEach(rafId => window.cancelAnimationFrame(rafId));
    pageRafs.clear();

    transparentObserver?.disconnect();
    transparentObserver = null;
    transparentRafId = 0;

    window.clearTimeout(megaMenuTimeout);
    megaMenuTimeout = undefined;
    megaMenuPanel = null;
    megaMenuContent = null;
    headerMenuData = null;
    activeHeader = null;

    // A route change while the mobile menu is open must not leave the new page
    // fixed and unscrollable.
    document.body.style.overflow = '';
    document.body.style.position = '';
    document.body.style.width = '';
  }

  function destroy() {
    cleanupPage();
    lifecycleController.abort();
  }

  window[lifecycleKey] = { destroy };

  function createMenuLink(label, href, className) {
    const link = document.createElement('a');
    link.href = href;
    link.className = className;
    link.textContent = label;
    return link;
  }

  function buildMenuHref(menuBase, slug) {
    return `${headerMenuData.basePath}${menuBase}/${slug}`;
  }

  function renderMenuContent(container, menuBase, mode) {
    if (!headerMenuData?.groups || !menuBase) return;
    if (container.dataset.renderedBase === menuBase && container.dataset.renderedMode === mode) return;

    const fragment = document.createDocumentFragment();
    const contentRoot = document.createElement('div');
    if (mode === 'desktop') {
      contentRoot.className = 'grid grid-cols-6 gap-4 xl:gap-6';
    }

    headerMenuData.groups.forEach(group => {
      const groupElement = document.createElement('div');
      groupElement.className = mode === 'desktop' ? 'flex flex-col' : 'pt-4 pb-2';

      const groupLink = createMenuLink(
        group.label,
        buildMenuHref(menuBase, group.slug),
        mode === 'desktop'
          ? "font-Manrope font-bold text-xs uppercase tracking-wider text-[#5D4E37] mb-4 whitespace-nowrap hover:text-[#5D4E37] active:text-[#5D4E37] focus-visible:text-[#5D4E37] focus-within:text-[#5D4E37] transition-colors"
          : "block py-3 text-xs md:text-sm font-semibold text-[#5D4E37] font-Manrope uppercase tracking-wide"
      );
      groupElement.appendChild(groupLink);

      const itemsElement = document.createElement('div');
      if (mode === 'desktop') itemsElement.className = 'flex flex-col space-y-1';

      group.items.forEach(item => {
        const itemLink = createMenuLink(
          item.label,
          buildMenuHref(menuBase, item.slug),
          mode === 'desktop'
            ? "font-sans text-sm font-medium text-stone-600 py-1.5 transition-all duration-200 hover:text-[#5D4E37] active:text-[#5D4E37] focus-visible:text-[#5D4E37] focus-within:text-[#5D4E37] hover:pl-1 active:pl-1 focus-visible:pl-1 focus-within:pl-1 whitespace-nowrap"
            : "flex items-center font-sans text-base md:text-lg font-medium text-stone-700 py-3 pl-6 transition-all duration-200 hover:text-[#5D4E37] active:text-[#5D4E37] focus-visible:text-[#5D4E37] focus-within:text-[#5D4E37] hover:pl-8 active:pl-8 focus-visible:pl-8 focus-within:pl-8"
        );
        if (mode === 'mobile') {
          const arrow = document.createElement('span');
          arrow.className = 'mr-3 text-[#5D4E37]';
          arrow.textContent = '→';
          itemLink.prepend(arrow);
        }
        itemsElement.appendChild(itemLink);
      });

      groupElement.appendChild(itemsElement);
      contentRoot.appendChild(groupElement);
    });

    fragment.appendChild(contentRoot);
    container.replaceChildren(fragment);
    container.dataset.renderedBase = menuBase;
    container.dataset.renderedMode = mode;
  }

  function showMegaMenu(menuKey, menuBase) {
    clearPageTimeout(megaMenuTimeout);
    if (megaMenuContent) renderMenuContent(megaMenuContent, menuBase, 'desktop');

    megaMenuPanel?.classList.add('active');
    megaMenuPanel?.setAttribute('data-active-menu', menuKey);
  }

  function hideMegaMenu() {
    clearPageTimeout(megaMenuTimeout);
    megaMenuTimeout = setPageTimeout(() => {
      megaMenuPanel?.classList.remove('active');
      megaMenuPanel?.setAttribute('data-active-menu', '');
    }, 150);
  }

  function initHeader() {
    const header = document.getElementById('site-header');
    if (!header) {
      cleanupPage();
      return;
    }
    if (activeHeader === header && pageController && !pageController.signal.aborted) {
      return;
    }

    cleanupPage();
    activeHeader = header;
    pageController = new AbortController();
    megaMenuPanel = document.getElementById('mega-menu-panel');
    megaMenuContent = document.getElementById('mega-menu-content');
    const menuDataElement = document.getElementById('header-menu-data');
    try {
      headerMenuData = JSON.parse(menuDataElement?.textContent || 'null');
    } catch {
      headerMenuData = null;
    }

    // Update language switcher links to preserve search params (e.g. ?q=001)
    // This runs on page load AND handles clicks dynamically for View Transitions
    function updateLangLinks() {
      const currentSearch = window.location.search;
      if (currentSearch) {
        document.querySelectorAll('.lang-dropdown-menu a').forEach(link => {
          const href = link.getAttribute('href');
          if (href && !href.includes('?')) {
            link.setAttribute('href', href + currentSearch);
          }
        });
      }
    }
    updateLangLinks();

    // Also handle clicks on language links to ensure search params are preserved
    // This is needed because View Transitions might intercept navigation
    document.querySelectorAll('.lang-dropdown-menu').forEach(menu => {
      setListener(menu, 'click', (e) => {
        const link = e.target.closest('a');
        if (link) {
          const currentSearch = window.location.search;
          const href = link.getAttribute('href');
          if (currentSearch && href && !href.includes('?')) {
            // Update the href before the click processes
            link.setAttribute('href', href + currentSearch);
          }
        }
      });
    });

    // Mobile Menu Toggle
    const menuButton = document.getElementById('mobile-menu-button');
    const menuClose = document.getElementById('mobile-menu-close');
    const mobileMenu = document.getElementById('mobile-menu');

    function toggleMobileMenu() {
      if (!mobileMenu) return;
      // Determine new state before DOM write to avoid forced reflow
      const willBeHidden = !mobileMenu.classList.contains('hidden');
      mobileMenu.classList.toggle('hidden');
      document.body.style.overflow = willBeHidden ? '' : 'hidden';
      document.body.style.position = willBeHidden ? '' : 'fixed';
      document.body.style.width = willBeHidden ? '' : '100%';
    }

    if (menuButton) {
      setListener(menuButton, 'click', toggleMobileMenu);
    }
    if (menuClose) {
      setListener(menuClose, 'click', toggleMobileMenu);
    }

    // Mobile Submenu Toggle
    document.querySelectorAll('.mobile-toggle-btn').forEach(btn => {
      const handler = () => {
        const parentDiv = btn.closest('.mobile-menu-item');
        const subMenu = parentDiv?.querySelector('.mobile-sub-menu');
        if (subMenu) {
          renderMenuContent(subMenu, parentDiv.dataset.menuBase, 'mobile');
          subMenu.classList.toggle('open');
          btn.classList.toggle('rotated');
          btn.setAttribute('aria-expanded', subMenu.classList.contains('open') ? 'true' : 'false');
        }
      };
      setListener(btn, 'click', handler);
    });

    // Mega Menu triggers
    const megaTriggers = document.querySelectorAll('[data-mega-trigger]');

    megaTriggers.forEach(trigger => {
      const menuKey = trigger.getAttribute('data-mega-trigger');
      const menuBase = trigger.getAttribute('data-mega-base');

      const enterHandler = () => {
        if (menuKey && menuBase) showMegaMenu(menuKey, menuBase);
      };
      setListener(trigger, 'mouseenter', enterHandler);

      // Keyboard support for accessibility
      const keydownHandler = (e) => {
        const keyEvent = e;
        if (keyEvent.key === 'Enter' || keyEvent.key === ' ') {
          keyEvent.preventDefault();
          if (menuKey && menuBase) {
            // Toggle menu on key press
            if (megaMenuPanel?.getAttribute('data-active-menu') === menuKey) {
              hideMegaMenu();
            } else {
              showMegaMenu(menuKey, menuBase);
            }
          }
        }
      };
      setListener(trigger, 'keydown', keydownHandler);

      const parentLi = trigger.closest('li');
      if (parentLi) {
        const leaveHandler = (e) => {
          const relatedTarget = e.relatedTarget;
          if (!megaMenuPanel?.contains(relatedTarget)) {
            hideMegaMenu();
          }
        };
        setListener(parentLi, 'mouseleave', leaveHandler);
      }
    });

    if (megaMenuPanel) {
      setListener(megaMenuPanel, 'mouseenter', () => clearPageTimeout(megaMenuTimeout));
      setListener(megaMenuPanel, 'mouseleave', () => hideMegaMenu());
    }

    // Header Search
    const headerSearchInput = document.getElementById('header-search-input');
    const headerSearchClear = document.getElementById('header-search-clear');

    if (headerSearchInput) {
      setListener(headerSearchInput, 'input', () => {
        if (headerSearchInput.value.trim()) {
          headerSearchClear?.classList.remove('hidden');
        } else {
          headerSearchClear?.classList.add('hidden');
        }
      });
    }

    if (headerSearchClear && headerSearchInput) {
      setListener(headerSearchClear, 'click', () => {
        headerSearchInput.value = '';
        headerSearchClear.classList.add('hidden');
        headerSearchInput.focus();
      });
    }

    // Mobile Search
    const mobileSearchInput = document.getElementById('mobile-search-input');
    const mobileSearchClear = document.getElementById('mobile-search-clear');

    if (mobileSearchInput) {
      setListener(mobileSearchInput, 'input', () => {
        if (mobileSearchInput.value.trim()) {
          mobileSearchClear?.classList.remove('hidden');
        } else {
          mobileSearchClear?.classList.add('hidden');
        }
      });
    }

    if (mobileSearchClear && mobileSearchInput) {
      setListener(mobileSearchClear, 'click', () => {
        mobileSearchInput.value = '';
        mobileSearchClear.classList.add('hidden');
        mobileSearchInput.focus();
      });
    }

    // Language Dropdown Scroll Hints
    document.querySelectorAll('.lang-dropdown-menu').forEach(menu => {
      const hint = menu.nextElementSibling;
      if (!hint || !hint.classList.contains('scroll-hint')) return;

      let hintRafPending = false;
      const updateHint = () => {
        if (hintRafPending) return;
        hintRafPending = true;
        // Measure only after the menu is used; keep all reads before the write.
        requestPageFrame(() => {
          const scrollHeight = menu.scrollHeight;
          const clientHeight = menu.clientHeight;
          const scrollTop = menu.scrollTop;
          const isScrollable = scrollHeight > clientHeight;
          const isNotAtBottom = Math.ceil(scrollTop + clientHeight) < scrollHeight - 2;
          hint.style.opacity = (isScrollable && isNotAtBottom) ? '1' : '0';
          hintRafPending = false;
        });
      };

      setListener(menu, 'scroll', updateHint);

      const container = menu.closest('.lang-dropdown-container');
      if (container) {
        setListener(container, 'mouseenter', () => {
          setPageTimeout(updateHint, 50);
        });
      }

      // Do not measure the hidden dropdown during page initialization. Hover,
      // click and scroll already call updateHint once the menu is relevant.
    });

    // Language Dropdown Click Toggle (for touch devices)
    document.querySelectorAll('.lang-dropdown-container').forEach(container => {
      const btn = container.querySelector('.lang-btn');
      const wrapper = container.querySelector('.lang-dropdown-wrapper');

      if (btn && wrapper) {
        setListener(btn, 'click', (e) => {
          e.preventDefault();
          e.stopPropagation();

          // Close all other language dropdowns first
          document.querySelectorAll('.lang-dropdown-container').forEach(otherContainer => {
            if (otherContainer !== container) {
              otherContainer.classList.remove('is-open');
              otherContainer.querySelector('.lang-btn')?.setAttribute('aria-expanded', 'false');
            }
          });

          // Toggle current dropdown
          const isVisible = container.classList.contains('is-open');
          if (isVisible) {
            container.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
          } else {
            container.classList.add('is-open');
            btn.setAttribute('aria-expanded', 'true');
            // update hint when opened via click
            const menu = wrapper.querySelector('.lang-dropdown-menu');
            if (menu) {
              setPageTimeout(() => menu.dispatchEvent(new Event('scroll')), 50);
            }
          }
        });
      }
    });

    // Close language dropdowns when clicking outside
    setListener(document, 'click', (e) => {
      const target = e.target;
      if (!target.closest('.lang-dropdown-container')) {
        document.querySelectorAll('.lang-dropdown-container').forEach(container => {
          const btn = container.querySelector('.lang-btn');
          container.classList.remove('is-open');
          if (btn) btn.setAttribute('aria-expanded', 'false');
        });
      }
    });

    // Escape closes an open language dropdown and returns focus to its button.
    setListener(document, 'keydown', (e) => {
      if (e.key !== 'Escape') return;
      document.querySelectorAll('.lang-dropdown-container.is-open').forEach(container => {
        const btn = container.querySelector('.lang-btn');
        container.classList.remove('is-open');
        if (btn) {
          btn.setAttribute('aria-expanded', 'false');
          if (container.contains(document.activeElement)) btn.focus();
        }
      });
    });

    // Deferred, so the document is normally parsed by now; keep the fallback
    // for a call made while the parser is still running.
    if (document.readyState === 'loading') {
      setListener(document, 'DOMContentLoaded', scheduleTransparentHeader, { once: true });
    } else {
      scheduleTransparentHeader();
    }
  }

  // ── Transparent-on-hero mode ───────────────────────────────────────────────
  // When the header is rendered transparent (home page only), watch the hero
  // section. Once it leaves the viewport, flip the header back to the solid
  // white state so the rest of the page stays readable.
  function initTransparentHeader() {
    const header = document.getElementById('site-header');
    if (!header || header !== activeHeader || header.dataset.transparent !== 'true') return;
    const controller = pageController;
    if (!controller || controller.signal.aborted) return;

    const hero = document.getElementById('hero-section');
    if (!hero) return;

    // rootMargin top: -80px accounts for the header's own height, so the
    // toggle happens when the hero has fully scrolled out from under the
    // header (not just when its top edge passes the viewport top).
    transparentObserver?.disconnect();
    transparentObserver = new IntersectionObserver(
      ([entry]) => {
        if (controller.signal.aborted || header !== activeHeader) return;
        header.classList.toggle('is-scrolled', !entry.isIntersecting);
      },
      { rootMargin: '-80px 0px 0px 0px', threshold: 0 }
    );
    transparentObserver.observe(hero);
  }

  function scheduleTransparentHeader() {
    if (transparentObserver || transparentRafId) return;
    transparentRafId = requestPageFrame(() => {
      transparentRafId = 0;
      initTransparentHeader();
    });
  }

  // `astro:before-swap` owns cleanup of the old DOM. `astro:page-load` binds
  // the new Header. Calling init once also covers the initial non-router load.
  document.addEventListener('astro:before-swap', cleanupPage, {
    signal: lifecycleController.signal,
  });
  document.addEventListener('astro:page-load', initHeader, {
    signal: lifecycleController.signal,
  });
  initHeader();
})();
