/**
 * Independent sticky sidebar scroll synchronization for product listing and category pages.
 * Persists sidebar scrollTop across ClientRouter soft navigations and keeps active links visible.
 */

let pageLoadBound = false;

export const syncProductSidebarScroll = (): void => {
  const sidebar = document.querySelector<HTMLElement>('[data-product-sidebar-scroll]');
  if (!sidebar) return;

  const storageKey = sidebar.dataset.sidebarScrollKey || 'kssmi-product-sidebar-scroll';

  if (!sidebar.dataset.scrollBound) {
    sidebar.dataset.scrollBound = '1';
    sidebar.addEventListener(
      'scroll',
      () => {
        try {
          sessionStorage.setItem(storageKey, String(sidebar.scrollTop));
        } catch {
          // Ignore private-mode storage errors
        }
      },
      { passive: true },
    );
  }

  try {
    const saved = sessionStorage.getItem(storageKey);
    if (saved !== null) {
      const y = Number(saved);
      if (Number.isFinite(y) && y >= 0) sidebar.scrollTop = y;
    }
  } catch {
    // Ignore private-mode storage errors
  }

  const activeLink = sidebar.querySelector<HTMLElement>('[data-sidebar-active="true"]');
  if (activeLink) {
    const boxRect = sidebar.getBoundingClientRect();
    const linkRect = activeLink.getBoundingClientRect();
    if (linkRect.top < boxRect.top + 8 || linkRect.bottom > boxRect.bottom - 8) {
      const offset =
        linkRect.top - boxRect.top + sidebar.scrollTop - sidebar.clientHeight / 2 + linkRect.height / 2;
      sidebar.scrollTop = Math.max(0, offset);
    }
  }
};

export const initProductSidebarScroll = (): void => {
  if (!pageLoadBound) {
    pageLoadBound = true;
    document.addEventListener('astro:page-load', syncProductSidebarScroll);
  }
  syncProductSidebarScroll();
};
