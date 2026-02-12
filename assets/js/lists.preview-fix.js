// assets/js/lists.preview-fix.js
// Improve "Vorschau"/Ansehen behavior:
// - Robustly calls api_get and handles non-JSON responses (shows error details)
// - Falls back to cfg.pdf_url?id=... if no file_url returned by API
// - Ensures iframe src is absolute to avoid /pages/pages/... loops
(function () {
  'use strict';
  function resolveToAbsoluteUrl(href) {
    if (!href) return href;
    href = String(href);
    if (/^https?:\/\//i.test(href)) return href;
    if (href.startsWith('/')) return window.location.origin + href;
    return window.location.origin + '/' + href.replace(/^\/+/, '');
  }

  async function handlePreviewClick(id) {
    const cfg = window.LIST_CONFIG || {};
    const modal = document.getElementById('modal');
    if (!modal) {
      alert('Modal fehlt; kann Vorschau nicht anzeigen');
      return;
    }
    const body = document.getElementById('modal-body');
    body.innerHTML = 'Lade…';
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden','false');

    if (cfg.api_get) {
      try {
        const res = await fetch(resolveToAbsoluteUrl(cfg.api_get) + '?id=' + encodeURIComponent(id), {cache:'no-store'});
        const ct = res.headers.get('Content-Type') || '';
        if (!res.ok) {
          let txt = await res.text();
          try { const j = JSON.parse(txt); txt = JSON.stringify(j, null, 2); } catch(e){}
          body.innerHTML = `<div style="color:#c00"><strong>Serverfehler ${res.status}</strong></div><pre style="white-space:pre-wrap;">${escapeHtml(txt)}</pre>`;
          return;
        }

        if (ct.indexOf('application/json') >= 0 || ct.indexOf('application/ld+json') >= 0) {
          const json = await res.json();
          const file = json.file_url || json.pdf_path || json.pdf || null;
          if (file) {
            const src = resolveToAbsoluteUrl(file);
            body.innerHTML = `<iframe src="${escapeHtml(src)}" style="width:100%;height:80vh;border:0"></iframe>`;
            return;
          }
          if (cfg.pdf_url) {
            const pdfLink = resolveToAbsoluteUrl(cfg.pdf_url) + (cfg.pdf_url.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(id);
            body.innerHTML = `<iframe src="${escapeHtml(pdfLink)}" style="width:100%;height:80vh;border:0"></iframe>`;
            return;
          }
          let html = '<div style="max-height:70vh;overflow:auto">';
          for (const k of Object.keys(json)) html += `<div><strong>${escapeHtml(k)}:</strong> ${escapeHtml(String(json[k]))}</div>`;
          html += '</div>';
          body.innerHTML = html;
          return;
        } else if (ct.indexOf('text/html') >= 0 || ct.indexOf('text/plain') >= 0) {
          const txt = await res.text();
          body.innerHTML = `<div><strong>Antwort vom Server (text/html):</strong></div><div style="max-height:70vh;overflow:auto"><pre>${escapeHtml(txt)}</pre></div>`;
          return;
        } else {
          const blob = await res.blob();
          const isPdf = blob.type === 'application/pdf' || blob.type === 'application/octet-stream';
          const url = URL.createObjectURL(blob);
          if (isPdf) {
            body.innerHTML = `<iframe src="${url}" style="width:100%;height:80vh;border:0"></iframe>`;
            return;
          } else {
            body.innerHTML = `<div>Unbekannter Inhaltstyp: ${escapeHtml(blob.type || '(unknown)')}</div><a href="${url}" target="_blank">In neuem Tab öffnen</a>`;
            return;
          }
        }
      } catch (e) {
        body.innerHTML = `<div style="color:#c00">Fehler beim Abrufen der Daten: ${escapeHtml(String(e))}</div>`;
      }
    }

    if (cfg.pdf_url) {
      const pdfLink = resolveToAbsoluteUrl(cfg.pdf_url) + (cfg.pdf_url.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(id);
      body.innerHTML = `<iframe src="${escapeHtml(pdfLink)}" style="width:100%;height:80vh;border:0"></iframe>`;
      return;
    }

    body.innerHTML = '<div>Keine Vorschau verfügbar.</div>';
  }

  function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  document.addEventListener('click', function (ev) {
    const btn = ev.target.closest && ev.target.closest('.btn-view');
    if (!btn) return;
    ev.preventDefault();
    const id = btn.getAttribute('data-id');
    if (!id) return;
    handlePreviewClick(id);
  }, true);
})();