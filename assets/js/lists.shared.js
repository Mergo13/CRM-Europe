// assets/js/lists.shared.js
// Robust shared list script with absolute path helper and dynamic column mapping.
// Use with pages that set window.LIST_CONFIG.

(function () {
  // idempotent guard to avoid duplicate listeners across partial loads
  if (window.__LISTS_SHARED_INIT__) return; window.__LISTS_SHARED_INIT__ = true;
  'use strict';
  if (!window.LIST_CONFIG) { console.error('LIST_CONFIG is required for lists.shared.js'); return; }
  const cfg = window.LIST_CONFIG;

  function normalizeStatus(val){
    const s = String(val||'').trim().toLowerCase();
    if (s === 'open' || s === 'offen') return 'offen';
    if (s === 'delivered' || s === 'geliefert' || s === 'done' || s === 'erledigt') return 'geliefert';
    if (s === 'cancelled' || s === 'storniert' || s === 'canceled') return 'storniert';
    return s || '';
  }

  function formatYMD(val) {
    if (val == null || val === '') return '';
    const str = String(val).trim();
    // If already YYYY-MM-DD
    if (/^\d{4}-\d{2}-\d{2}$/.test(str)) return str;
    const d = new Date(str);
    if (isNaN(d.getTime())) return str; // fallback to raw
    const y = d.getFullYear();
    const m = String(d.getMonth()+1).padStart(2,'0');
    const da = String(d.getDate()).padStart(2,'0');
    return `${y}-${m}-${da}`;
  }

  function makeAbsolutePath(path) {
    if (!path) return '';
    let p = String(path).trim();

    // Normalize Windows backslashes to URL slashes
    p = p.replace(/\\/g, '/');

    // If backend accidentally returns a filesystem path, don't try to use it as a URL
    // (common: "/Users/...", "C:/...")
    if (/^(?:[A-Za-z]:\/|\/Users\/|\/Volumes\/)/.test(p)) return '';

    // Already absolute URL or absolute web path
    if (/^https?:\/\//i.test(p) || p.startsWith('/')) return p;

    // Relative web path
    return '/' + p.replace(/^\/+/, '');
  }


  cfg.api_list   = makeAbsolutePath(cfg.api_list || '');
  cfg.api_get    = makeAbsolutePath(cfg.api_get || '');
  cfg.api_bulk   = makeAbsolutePath(cfg.api_bulk || '');
  cfg.api_export = makeAbsolutePath(cfg.api_export || '');
  cfg.pdf_url    = makeAbsolutePath(cfg.pdf_url || '');
  cfg.api_create = makeAbsolutePath(cfg.create_api || (cfg.api_list ? cfg.api_list.replace('_list.php','_create.php') : ''));

  const tableBody   = document.querySelector('#list-table tbody');
  const pageInfo    = document.getElementById('page-info');
  const prevBtn     = document.getElementById('prev-page');
  const nextBtn     = document.getElementById('next-page');
  const searchInput = document.getElementById('search');
  const filterSelect= document.getElementById('filter-status');
  const filterStage = document.getElementById('filter-stage');
  const selectAll   = document.getElementById('select-all');
  const exportBtn   = document.getElementById('export-csv');
  const bulkMark    = document.getElementById('bulk-mark');
  const bulkDelete  = document.getElementById('bulk-delete');

  window._LIST_STATE = window._LIST_STATE || {
    page: 1,
    per_page: cfg.per_page || 25,
    sort: cfg.default_sort || 'date',
    dir: 'desc',
    q: '',
    status: '',
    stage: ''
  };

  function qs(params) {
    return Object.keys(params).map(k => encodeURIComponent(k) + '=' + encodeURIComponent(params[k])).join('&');
  }

  function pickField(row, names) {
    for (let n of names) {
      if (row == null) continue;
      if (Object.prototype.hasOwnProperty.call(row, n) && row[n] !== null && row[n] !== undefined) return row[n];
    }
    return null;
  }

  function formatCurrency(v) {
    const n = Number(v);
    if (!Number.isFinite(n)) return '';
    return n.toFixed(2) + ' €';
  }

  function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  async function fetchData() {
    const state = window._LIST_STATE;
    const extra = window.LIST_EXTRA || {};
    const url = cfg.api_list + '?' + qs(Object.assign({}, state, extra));

    try {
      const res = await fetch(url, { cache: 'no-store' });

      const ct = (res.headers.get('content-type') || '').toLowerCase();
      const isJson = ct.includes('application/json');

      if (!res.ok) {
        const body = await res.text().catch(() => '');
        throw new Error(`HTTP ${res.status} ${res.statusText} — ${body.slice(0, 300)}`);
      }

      if (!isJson) {
        const body = await res.text().catch(() => '');
        console.error('Expected JSON but got:', ct, 'Body preview:', body.slice(0, 800));
        if (tableBody) {
          tableBody.innerHTML = '<tr><td colspan="8">API liefert HTML statt JSON (siehe Konsole/Network → Response)</td></tr>';
        }
        return;
      }

      const json = await res.json();
      window._LAST_LIST_JSON = json;

      renderTable(json.data || []);
      updatePagination(json.page, json.per_page, json.total);
    } catch (e) {
      console.error('fetchData', e);
      if (tableBody) tableBody.innerHTML = '<tr><td colspan="8">Fehler beim Laden der Daten</td></tr>';
    }
  }

  function renderTable(rows) {
    const updateBulkUi = window.__updateBulkUi || function(){
      const ids = Array.from(document.querySelectorAll('.row-select:checked'));
      const bulkEmailBtn = document.getElementById('bulk-email');
      if (bulkEmailBtn) bulkEmailBtn.disabled = ids.length === 0;
      const bulkDeleteBtn = document.getElementById('bulk-delete');
      if (bulkDeleteBtn) bulkDeleteBtn.disabled = ids.length === 0;
    };
    if (!tableBody) return;
    tableBody.innerHTML = '';
    if (!rows || !rows.length) {
      tableBody.innerHTML = '<tr><td colspan="8">Keine Einträge gefunden</td></tr>';
      if (typeof window.updateStats === 'function') {
        try { window.updateStats(); } catch(e) {}
      }
      return;
    }

    // Debug-Hilfe (kannst du nach Bedarf wieder rausnehmen)
    // console.log('Erste Row aus API:', rows[0]);

    // Detect Lieferschein layout (custom columns: Nummer, Kundennummer, Datum, Bemerkung, Status)
    const hasLsKdnr = !!document.querySelector('#list-table thead th[data-sort="kundennummer"]');
    const hasLsBem  = !!document.querySelector('#list-table thead th[data-sort="bemerkung"]');
    const isLieferscheinLayout = hasLsKdnr && hasLsBem;

    rows.forEach(r => {
      // Map alternate field name used by some endpoints
      if (r && r.rechnung_nummer && !r.rechnungsnummer) { r.rechnungsnummer = r.rechnung_nummer; }
      const id     = pickField(r, ['id','ID','_id']) || '';
      const nummer = pickField(r, ['rechnungsnummer','rechnung_nummer','nummer','angebot_nummer','lieferschein_nummer','nummernr','number']) || '';
      const kunde  = pickField(r, ['kunde','client_name','client','empfaenger','partner','customer']) ||
          pickField(r, ['client_id','customer_id']) || '';
      const amountRaw = pickField(r, ['betrag','total_due','gesamt','total','amount','summe']) || '';
      const amount    = formatCurrency(amountRaw === null ? '' : amountRaw);
      const statusRaw = pickField(r, ['status','stauts','state']) || '';
      const status    = normalizeStatus(statusRaw);

      // Flligkeit explizit bevorzugen; ansonsten standardisiert 'datum'
      const faellig = pickField(r, [
        'faelligkeit_formatted',
        'faelligkeit',
        'due_date',
        'faellig_bis'
      ]);
      const fallbackDate = pickField(r, [
        'datum_formatted',
        'datum',
        'date',
        'created_at',
        'datum_erfasst'
      ]);
      const date = faellig || fallbackDate || '';

      const fileUrl = pickField(r, [
        'file_url',
        'pdf_path',
        'pdf',
        'file',
        'filename',
        'file_path',
        'filepath',
        'attachment',
        'document'
      ]) || null;

      const downloadLink = fileUrl
          ? `<a class="btn-download" href="${escapeHtml(makeAbsolutePath(fileUrl))}" target="_blank">Datei</a>`
          : (cfg.pdf_url
              ? `<a class="btn-download" href="${escapeHtml(cfg.pdf_url)}?id=${encodeURIComponent(id)}" target="_blank">PDF</a>`
              : '');

      const viewButton = `<button class="btn-view" data-id="${escapeHtml(id)}">Ansehen</button>`;
      const printUrl   = (fileUrl
          ? makeAbsolutePath(fileUrl)
          : (cfg.pdf_url ? (cfg.pdf_url + '?id=' + encodeURIComponent(id)) : null));
      const printButton = printUrl
          ? `<button class="btn-print" data-src="${escapeHtml(printUrl)}" title="Drucken"><span class="bi bi-printer"></span></button>`
          : '';
      const editButton  = cfg.create_page
          ? `<a class="btn-edit" href="${escapeHtml(makeAbsolutePath(cfg.create_page))}?id=${encodeURIComponent(id)}" target="_blank">Bearbeiten</a>`
          : '';

      const actionsHtml = `<div class="row-actions">
        ${viewButton} ${printButton} ${downloadLink} ${editButton}
        <button class="btn-email" data-id="${escapeHtml(id)}">E-Mail</button>
        <button class="btn-delete" data-id="${escapeHtml(id)}">Löschen</button>
      </div>`;

      const tr = document.createElement('tr');
      tr.setAttribute('data-id', String(id));
      tr.setAttribute('data-status', String(status));

      // Mahnstufe (optional column for Mahnungen pages)
      const stufeValRaw = pickField(r, ['stufe','mahnstufe','stage','level']);
      const stufeStr = (stufeValRaw === null || stufeValRaw === undefined || stufeValRaw === '') ? '' : String(stufeValRaw);
      const stufeNum = Number.isFinite(Number(stufeStr)) ? Number(stufeStr) : null;
      let stufeLabel = '';
      if (stufeNum === 0) stufeLabel = 'Zahlungserinnerung';
      else if (stufeNum === 1) stufeLabel = '1. Mahnung';
      else if (stufeNum === 2) stufeLabel = '2. letzte Mahnung';
      else if (stufeStr !== '') stufeLabel = stufeStr;

      const stufeBadge = (stufeStr === '')
        ? ''
        : `<span class="status-badge stage-${escapeHtml(stufeStr)}" data-stage="${escapeHtml(stufeStr)}">${escapeHtml(stufeLabel)}</span>`;

      const hasStufeCol = !!document.querySelector('#list-table thead th[data-sort="stufe"]');

      if (isLieferscheinLayout) {
        // Lieferscheine list layout: Nummer, Kundennummer, Datum (YYYY-MM-DD), Bemerkung, Status
        const kdnr = pickField(r, ['kundennummer','kunden_nummer','customer_number','kundennr']) || '';
        const bemerkung = pickField(r, ['bemerkung','notiz','beschreibung','comment','notes']) || '';
        const lsDateRaw = pickField(r, ['datum','date','created_at','datum_erfasst']);
        const lsDate = formatYMD(lsDateRaw || '');
        tr.innerHTML = `
          <td>
            <input class="row-select" name="selected[]" value="${escapeHtml(id)}" data-id="${escapeHtml(id)}" type="checkbox">
          </td>
          <td>${escapeHtml(nummer)}</td>
          <td>${escapeHtml(kdnr)}</td>
          <td>${escapeHtml(lsDate)}</td>
          <td>${escapeHtml(bemerkung)}</td>
          <td>${escapeHtml(status)}</td>
        `;
      } else if (hasStufeCol) {
        // Match Mahnungen header order: Nummer, Kunde, Betrag, Stufe, Erstellt, Status
        tr.innerHTML = `
          <td>
            <input class="row-select" name="selected[]" value="${escapeHtml(id)}" data-id="${escapeHtml(id)}" type="checkbox">
          </td>
          <td>${escapeHtml(nummer)}</td>
          <td>${escapeHtml(kunde)}</td>
          <td class="text-end">${escapeHtml(amount)}</td>
          <td>${stufeBadge || ''}</td>
          <td>${escapeHtml(date)}</td>
          <td>${escapeHtml(status)}</td>
        `;
      } else {
        // Default order for legacy lists
        tr.innerHTML = `
          <td>
            <input class="row-select" name="selected[]" value="${escapeHtml(id)}" data-id="${escapeHtml(id)}" type="checkbox">
          </td>
          <td>${escapeHtml(nummer)}</td>
          <td>${escapeHtml(kunde)}</td>
          <td>${escapeHtml(amount)}</td>
          <td>${escapeHtml(status)}</td>
          <td>${escapeHtml(date)}</td>
        `;
      }

      tableBody.appendChild(tr);
    });

    // Update bulk action buttons state after rendering
    try { updateBulkUi(); } catch(e) {}

    if (typeof window.updateStats === 'function') {
      try { window.updateStats(); } catch(e) {}
    }
  }

  function updatePagination(page, per_page, total) {
    window._LIST_STATE.page     = page;
    window._LIST_STATE.per_page = per_page;
    const totalPages = Math.max(1, Math.ceil((total || 0) / per_page));
    if (pageInfo) pageInfo.textContent = `Seite ${page} / ${totalPages} — ${total || 0} Einträge`;
    if (prevBtn) prevBtn.disabled = page <= 1;
    if (nextBtn) nextBtn.disabled = page >= totalPages;
  }

  prevBtn && prevBtn.addEventListener('click', () => {
    if (window._LIST_STATE.page > 1) {
      window._LIST_STATE.page--;
      fetchData();
    }
  });
  nextBtn && nextBtn.addEventListener('click', () => {
    window._LIST_STATE.page++;
    fetchData();
  });

  searchInput && searchInput.addEventListener(
      'input',
      debounce(() => {
        window._LIST_STATE.q = (searchInput.value || '').trim();
        window._LIST_STATE.page = 1;
        fetchData();
      }, 350)
  );

  filterSelect && filterSelect.addEventListener('change', () => {
    window._LIST_STATE.status = filterSelect.value;
    window._LIST_STATE.page = 1;
    fetchData();
  });

  // Stage filter (Mahnstufe)
  if (filterStage) {
    // Init from existing value on load
    window._LIST_STATE.stage = (filterStage.value || '').trim();
    filterStage.addEventListener('change', () => {
      window._LIST_STATE.stage = (filterStage.value || '').trim();
      window._LIST_STATE.page = 1;
      fetchData();
    });
  }

  document.querySelectorAll('#list-table thead th[data-sort]').forEach(th => {
    th.style.cursor = 'pointer';
    th.addEventListener('click', () => {
      const s = th.getAttribute('data-sort');
      if (window._LIST_STATE.sort === s) {
        window._LIST_STATE.dir = (window._LIST_STATE.dir === 'asc') ? 'desc' : 'asc';
      } else {
        window._LIST_STATE.sort = s;
        window._LIST_STATE.dir  = 'asc';
      }
      fetchData();
    });
  });

  selectAll && selectAll.addEventListener('change', () => {
    document.querySelectorAll('.row-select').forEach(cb => cb.checked = selectAll.checked);
    const bulkEmailBtn = document.getElementById('bulk-email');
    if (bulkEmailBtn) bulkEmailBtn.disabled = !document.querySelector('.row-select:checked');
    const bulkDeleteBtn = document.getElementById('bulk-delete');
    if (bulkDeleteBtn) bulkDeleteBtn.disabled = !document.querySelector('.row-select:checked');
  });

  // Keep bulk buttons state in sync when individual row checkboxes change
  document.addEventListener('change', (ev) => {
    const t = ev.target;
    if (t && (t.classList && t.classList.contains('row-select'))) {
      const bulkEmailBtn = document.getElementById('bulk-email');
      if (bulkEmailBtn) bulkEmailBtn.disabled = !document.querySelector('.row-select:checked');
      const bulkDeleteBtn = document.getElementById('bulk-delete');
      if (bulkDeleteBtn) bulkDeleteBtn.disabled = !document.querySelector('.row-select:checked');
    }
  });

  document.addEventListener('click', async (ev) => {
    const target = ev.target.closest ? ev.target.closest('button, a, span') : ev.target;
    if (!target) return;

    if (target.matches('.btn-view')) {
      const id = target.getAttribute('data-id');
      showModal('Lade…');
      try {
        const r = await fetch(cfg.api_get + '?id=' + encodeURIComponent(id), {cache:'no-store'});
        const json = await r.json();
        let file = json.file_url || json.pdf_path || json.pdf || null;
        if (!file && cfg.pdf_url) {
          file = cfg.pdf_url + '?id=' + encodeURIComponent(id);
        }
        if (file) {
          const src = makeAbsolutePath(file);
          showModal(`<iframe src="${escapeHtml(src)}" style="width:100%;height:80vh;border:0"></iframe>`);
        } else {
          let html = '<div>';
          for (const k of Object.keys(json)) {
            html += `<div><strong>${escapeHtml(k)}:</strong> ${escapeHtml(json[k])}</div>`;
          }
          html += '</div>';
          showModal(html);
        }
      } catch (e) {
        showModal('Fehler beim Laden');
      }
    } else if (target.matches('.btn-print') || (target.closest && target.closest('.btn-print'))) {
      const btn = target.matches('.btn-print') ? target : target.closest('.btn-print');
      const src = btn.getAttribute('data-src');
      if (src) {
        const url = '/pages/print_pdf.php?src=' + encodeURIComponent(makeAbsolutePath(src));
        window.open(url, '_blank');
      }
    } else if (target.matches('.btn-email')) {
      const id = target.getAttribute('data-id');
      const recipient = prompt('Empfänger-E-Mail:');
      if (!recipient) return;
      try {
        const form = new URLSearchParams();
        form.set('id', id);
        form.set('to', recipient);
        const emailApi = cfg.api_email || (cfg.api_list ? cfg.api_list.replace('_list.php','_email.php') : '/pages/api/send_email.php');
        const res = await fetch(makeAbsolutePath(emailApi), { method:'POST', body: form });
        const json = await res.json();
        if (json.success) alert('E-Mail gesendet'); else alert('E-Mail fehlgeschlagen: ' + (json.error || 'unknown'));
      } catch (e) {
        alert('E-Mail fehlgeschlagen');
      }
    } else if (target.matches('.btn-delete')) {
      if (!confirm('Sicher löschen?')) return;
      const id = target.getAttribute('data-id');
      try {
        const form = new URLSearchParams();
        form.set('action','delete');
        form.append('ids[]', id);
        const res = await fetch(cfg.api_bulk, { method:'POST', body: form });
        const ct = res.headers.get('content-type') || '';
        let json = null;
        if (ct.includes('application/json')) {
          try { json = await res.json(); } catch(_) { json = null; }
        }
        if (!res.ok) {
          const msg = (json && (json.error||json.message)) ? (json.error||json.message) : `HTTP ${res.status}`;
          alert('Fehler beim Löschen: ' + msg);
          return;
        }
        if (json && json.success) { alert('Gelöscht'); fetchData(); } else {
          const msg = json && (json.error||json.message) ? (json.error||json.message) : 'unknown';
          alert('Fehler beim Löschen: ' + msg);
        }
      } catch (e) {
        console.error(e);
        alert('Fehler beim Löschen');
      }
    }
  });

  const bulkMarkBtn      = document.getElementById('bulk-mark');
  const bulkMarkPaidBtn  = document.getElementById('bulk-mark-paid');
  const bulkDeleteBtn    = document.getElementById('bulk-delete');
  bulkMarkBtn     && bulkMarkBtn.addEventListener('click', () => performBulk('mark_done'));
  bulkMarkPaidBtn && bulkMarkPaidBtn.addEventListener('click', () => performBulk('mark_paid'));
  bulkDeleteBtn   && bulkDeleteBtn.addEventListener('click', () => {
    if (!confirm('Sicher löschen?')) return;
    performBulk('delete');
  });

  async function performBulk(action) {
    const ids = Array.from(document.querySelectorAll('.row-select:checked'))
        .map(cb => cb.getAttribute('data-id'));
    if (!ids.length) { alert('Keine Zeilen ausgewählt'); return; }
    try {
      const form = new URLSearchParams();
      form.set('action', action);
      ids.forEach(id => form.append('ids[]', id));
      const r = await fetch(cfg.api_bulk, { method:'POST', body: form });
      const ct = r.headers.get('content-type') || '';
      let json = null;
      if (ct.includes('application/json')) {
        try { json = await r.json(); } catch (_) { json = null; }
      }
      if (!r.ok) {
        const msg = (json && (json.error||json.message)) ? (json.error||json.message) : `HTTP ${r.status}`;
        alert('Fehler: ' + msg);
        return;
      }
      if (json && json.success) { alert('Erfolgreich'); fetchData(); } else {
        const msg = json && (json.error||json.message) ? (json.error||json.message) : 'unknown';
        alert('Fehler: ' + msg);
      }
    } catch (e) {
      console.error(e);
      alert('Fehler');
    }
  }

  exportBtn && exportBtn.addEventListener('click', () => {
    const params = qs(Object.assign({}, window._LIST_STATE, window.LIST_EXTRA || {}));
    window.location = cfg.api_export + '?' + params;
  });

  function showModal(content) {
    let modal = document.getElementById('modal');
    if (!modal) return alert(typeof content === 'string' ? content : JSON.stringify(content));
    const body = document.getElementById('modal-body');
    body.innerHTML = typeof content === 'string'
        ? content
        : (content.outerHTML || JSON.stringify(content, null, 2));
    modal.setAttribute('aria-hidden','false');
    modal.style.display='flex';
  }

  function debounce(fn, ms) {
    let t;
    return function() {
      clearTimeout(t);
      t = setTimeout(fn, ms);
    };
  }

  window.fetchData = fetchData;

  fetchData();

})();
