// assets/js/lists.fix-links.js
// Fixes relative PDF/preview/edit links that cause /pages/pages/... loops.
// Load this after your other list scripts.
(function () {
  'use strict';
  function resolveToAbsoluteUrl(href) {
    if (!href) return href;
    href = String(href);
    if (/^https?:\/\//i.test(href)) return href;
    if (href.startsWith('/')) return window.location.origin + href;
    return window.location.origin + '/' + href.replace(/^\/+/, '');
  }
  function buildPdfLink(cfgPdfUrl, id) {
    if (!cfgPdfUrl) return null;
    let base = cfgPdfUrl.split('?')[0];
    const qs = cfgPdfUrl.indexOf('?') >= 0 ? cfgPdfUrl.split('?').slice(1).join('?') : '';
    const sep = base.indexOf('?') >= 0 ? '&' : (qs ? '&' : '?');
    base = resolveToAbsoluteUrl(base);
    return base + (base.includes('?') || qs ? sep : '?') + 'id=' + encodeURIComponent(id);
  }
  function normalizeExistingDownloadLinks(cfg) {
    document.querySelectorAll('a.btn-download, a.btn-preview, a[data-role="pdf-link"]').forEach(a => {
      const href = a.getAttribute('href') || '';
      if (!/^https?:\/\//i.test(href) && !href.startsWith('/')) {
        a.setAttribute('href', resolveToAbsoluteUrl(href));
      }
    });
    document.addEventListener('click', function (ev) {
      const a = ev.target.closest && ev.target.closest('a');
      if (!a) return;
      if (a.matches('a.btn-download, a.btn-preview, a[data-role="pdf-link"]')) {
        const href = a.getAttribute('href') || '';
        if (!/^https?:\/\//i.test(href) && !href.startsWith('/')) {
          a.setAttribute('href', resolveToAbsoluteUrl(href));
        }
      }
    }, true);
  }
  function interceptActionButtons(cfg) {
    document.addEventListener('click', function (ev) {
      const btn = ev.target;
      if (btn.matches && btn.matches('a.btn-download')) {
        const href = btn.getAttribute('href') || '';
        if (!/^https?:\/\//i.test(href) && !href.startsWith('/')) {
          btn.setAttribute('href', resolveToAbsoluteUrl(href));
        }
        return;
      }
      if (btn.matches && btn.matches('button.btn-pdf')) {
        ev.preventDefault();
        try {
          const id = btn.getAttribute('data-id');
          if (!id) return;
          const pdfLink = buildPdfLink(cfg.pdf_url, id);
          if (pdfLink) window.open(pdfLink, '_blank');
        } catch (e) { console.error('open pdf', e); }
        return;
      }
      if (btn.matches && btn.matches('a.btn-edit')) {
        const href = btn.getAttribute('href') || '';
        if (!/^https?:\/\//i.test(href) && !href.startsWith('/')) {
          btn.setAttribute('href', resolveToAbsoluteUrl(href));
        }
      }
    }, true);
  }
  function patchModalIframeFix() {
    const modalBody = document.getElementById('modal-body');
    if (!modalBody) return;
    const obs = new MutationObserver(function (mutations) {
      for (const m of mutations) {
        for (const n of m.addedNodes) {
          if (n.nodeType === 1) {
            const iframes = n.querySelectorAll ? n.querySelectorAll('iframe') : [];
            iframes.forEach(ifr => {
              const src = ifr.getAttribute('src') || '';
              if (src && !/^https?:\/\//i.test(src) && !src.startsWith('/')) {
                ifr.setAttribute('src', resolveToAbsoluteUrl(src));
              } else if (src && src.startsWith('/')) {
                ifr.setAttribute('src', window.location.origin + src);
              }
            });
          }
        }
      }
    });
    obs.observe(modalBody, { childList: true, subtree: true });
  }
  document.addEventListener('DOMContentLoaded', function () {
    const cfg = window.LIST_CONFIG || {};
    if (cfg) {
      if (cfg.pdf_url && !/^https?:\/\//i.test(cfg.pdf_url) && !cfg.pdf_url.startsWith('/')) {
        cfg.pdf_url = '/' + cfg.pdf_url.replace(/^\/+/, '');
      }
      if (cfg.create_page && !/^https?:\/\//i.test(cfg.create_page) && !cfg.create_page.startsWith('/')) {
        cfg.create_page = '/' + cfg.create_page.replace(/^\/+/, '');
      }
    }
    normalizeExistingDownloadLinks(cfg);
    interceptActionButtons(cfg);
    patchModalIframeFix();
    setTimeout(() => normalizeExistingDownloadLinks(cfg), 100);
  });
})();