<?php declare(strict_types=1);
// pages/rechnungen-list.php
// Enhanced invoices list with improved quick actions and modern design
// Requires: config/db.php, assets/js/lists.shared.js, assets/css/lists.css

require_once __DIR__ . '/../config/db.php';


error_reporting(E_ALL);
ini_set('display_errors', '1');



// Helper functions (if not already defined)
if (!function_exists('esc')) {
    function esc($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('asset_url')) {
    function asset_url($path = '') {
        return '/' . ltrim($path, '/');
    }
}
?>
<?php $pageTitle = 'Rechnungen Liste'; include __DIR__ . '/../includes/header.php'; ?>

<!-- Page-specific styles (kept inline for minimal change) -->
<style>
    .toolbar {
        display: flex;
        flex-direction: row;
        justify-content: space-between;
        background: linear-gradient(135deg, #198754 0%, #20c997 100%);
        color: white;
        border-radius: 12px;
        margin-bottom: 1.5rem;
        box-shadow: 0 4px 16px rgba(25, 135, 84, 0.15);
    }
    .toolbar h1 { margin: 0; display: flex; align-items: center; gap: 0.75rem; font-size: 1.5rem; font-weight: 600; }
    .quick-actions { background: white; border-radius: 12px; padding: 1rem; margin-bottom: 1.5rem; box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08); }
    .action-btn { border: none; border-radius: 8px; padding: 0.5rem 1rem; font-weight: 500; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 0.5rem; }
    .action-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15); }
    .action-btn-primary { background: linear-gradient(135deg, #198754, #20c997); color: white; }
    .action-btn-success { background: linear-gradient(135deg, #0d6efd, #2563eb); color: white; }
    .action-btn-warning { background: linear-gradient(135deg, #fd7e14, #ffc107); color: white; }
    .action-btn-danger { background: linear-gradient(135deg, #dc3545, #e74c3c); color: white; }
    .stats-card { background: white; border-radius: 12px; padding: 1rem; box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08); text-align: center; }
    .stats-number { font-size: 2rem; font-weight: 700; color: #198754; margin: 0; }
    .stats-label { color: #6c757d; font-size: 0.875rem; margin: 0; }
    #list-table { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08); }
    .status-badge { padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
    .status-paid { background: #d1e7dd; color: #0a3622; }
    .status-open { background: #fff3cd; color: #856404; }
    .status-overdue { background: #f8d7da; color: #58151c; }
    .status-draft { background: #e2e3e5; color: #383d41; }
    .bulk-actions { position: fixed; bottom: 2rem; left: 2rem; background: #ffffff; border-radius: 16px; padding: 1.2rem 1.6rem; box-shadow: 0 10px 25px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 1.2rem; opacity: 0; transform: translateY(120px); transition: all 0.35s ease; z-index: 2000; border: 1px solid rgba(0,0,0,0.08); }
    .bulk-actions.visible { transform: translateY(0); opacity: 1; }
    .bulk-actions .btn { font-size: 1rem; padding: 0.55rem 1rem; border-radius: 10px; font-weight: 600; }
    #selectedCount { font-size: 1.1rem; }
    .bulk-actions .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); transition: all 0.2s ease; }
    .bulk-actions .vr { height: 28px; margin: 0 8px; opacity: 0.3; }
    @media (max-width: 768px) {
        .bulk-actions { left: 50%; transform: translate(-50%, 120px); width: calc(100% - 2rem); justify-content: space-between; flex-wrap: wrap; padding: 1rem; }
    }
    .notification { position: fixed; top: 2rem; right: 2rem; max-width: 400px; z-index: 1050; }
    /* Quick actions visible: do not hide header bulk/CSV controls */
    /* Design system overrides */
    .toolbar { background: var(--color-surface); color: var(--color-text); border:1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }
    .quick-actions { background: var(--color-surface); color: inherit; border:1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }
    .stats-card { background: var(--color-surface); color: inherit; border:1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }
    .stats-number { color: var(--color-primary); }
    #list-table { background: var(--color-surface); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }
    .bulk-actions { background: var(--color-surface); color: inherit; border:1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: var(--shadow-md); }
    .action-btn { min-height:44px; border:1px solid var(--color-border); background: var(--color-surface-2); color: inherit; border-radius: var(--radius-sm); }
    .action-btn:hover { background: color-mix(in srgb, var(--color-surface) 92%, #000); }
    .action-btn:focus-visible { outline: none; box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-primary) 30%, transparent); }
    .action-btn-primary { background: var(--color-primary) !important; color: var(--color-primary-contrast) !important; border-color: var(--color-primary) !important; }
    .action-btn-success { background: var(--color-success) !important; color: var(--color-primary-contrast) !important; border-color: var(--color-success) !important; }
    .action-btn-warning { background: var(--color-warning) !important; color: var(--color-text) !important; border-color: var(--color-warning) !important; }
    .action-btn-danger { background: var(--color-danger) !important; color: var(--color-primary-contrast) !important; border-color: var(--color-danger) !important; }
    .btn, .btn-sm, .btn-outline-primary { min-height: 44px; }
    input[type="checkbox"], .form-check-input { width: 20px; height: 20px; }
</style>
<main class="container-fluid px-4 py-3" data-list-page>
    <!-- Enhanced Header -->
    <header class="toolbar p-4 d-flex justify-content-between flex">
        <div class="d-flex justify-content-between align-items-center">
            <h1>
                <i class="bi bi-receipt"></i>
                Rechnungen verwalten
            </h1>
            <div class="d-flex gap-2">
                <button class="btn btn-light" id="refreshData">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
                <a href="rechnung.php" class="btn btn-light">
                    <i class="bi bi-plus-circle"></i> Neue Rechnung
                </a>
            </div>
        </div>
    </header>

    <!-- Quick Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-number" id="statsTotal">0</div>
                <div class="stats-label">Gesamt</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-number" id="statsOpen">0</div>
                <div class="stats-label">Offen</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-number" id="statsPaid">0</div>
                <div class="stats-label">Bezahlt</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-number" id="statsOverdue">0</div>
                <div class="stats-label">Überfällig</div>
            </div>
        </div>
    </div>

    <!-- Enhanced Controls -->
    <div class="quick-actions">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <div class="d-flex gap-2 flex-wrap">
                    <input id="search" type="search" class="form-control"
                           placeholder="Suche nach Nummer, Kunde oder Betrag"
                           style="max-width: 300px;">
                    <!-- ... other controls ... -->
                </div>
            </div>

                    <select id="filter-status" class="form-select" style="max-width: 150px;">
                        <option value="">Alle Status</option>
                        <option value="bezahlt">Bezahlt</option>
                        <option value="offen">Offen</option>
                        <option value="überfällig">Überfällig</option>
                        <option value="entwurf">Entwurf</option>
                    </select>

                    <select id="filter-date" class="form-select" style="max-width: 180px;">
                        <option value="">Alle Zeiträume</option>
                        <option value="today">Heute</option>
                        <option value="week">Diese Woche</option>
                        <option value="month">Dieser Monat</option>
                        <option value="quarter">Quartal</option>
                    </select>
                </div>
            </div>
            <div class="col-lg-6 text-end">
                <div class="btn-group">
                    <button class="action-btn action-btn-primary" id="export-csv">
                        <i class="bi bi-file-earmark-spreadsheet"></i> CSV Export
                    </button>
                    <button class="action-btn action-btn-success" id="bulk-email" type="button" disabled>
                        <i class="bi bi-envelope"></i> E-Mail senden
                    </button>
                    <button class="action-btn action-btn-warning" id="bulk-mahnung">
                        <i class="bi bi-bell"></i> Mahnung erstellen
                    </button>
                    <button class="action-btn action-btn-danger" id="bulk-delete" disabled>
                        <i class="bi bi-trash"></i> Löschen
                    </button>
                </div>
            </div>


    <section class="card border-0">
        <div class="card-body p-0">
            <table id="list-table" class="table table-hover mb-0">
                <thead class="table-light">
                <tr>
                    <th style="width: 40px;">
                        <input id="select-all" type="checkbox" class="form-check-input">
                    </th>
                    <th data-sort="nummer">
                        Rechnungsnummer <i class="bi bi-arrow-down-up text-muted"></i>
                    </th>
                    <th data-sort="kunde">
                        Kunde <i class="bi bi-arrow-down-up text-muted"></i>
                    </th>
                    <th data-sort="betrag" class="text-end">
                        Betrag <i class="bi bi-arrow-down-up text-muted"></i>
                    </th>
                    <th data-sort="status">
                        Status <i class="bi bi-arrow-down-up text-muted"></i>
                    </th>
                    <th data-sort="faelligkeit">
                        Fälligkeit <i class="bi bi-arrow-down-up text-muted"></i>
                    </th>

                </tr>
                </thead>
                <tbody></tbody>
            </table>

            <!-- Enhanced Pagination -->
            <div class="d-flex justify-content-between align-items-center p-3 bg-light">
                <div class="pagination mb-0">
                    <button id="prev-page" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-chevron-left"></i> Vorherige
                    </button>
                    <span id="page-info" class="mx-3 align-self-center">Seite 1</span>
                    <button id="next-page" class="btn btn-outline-primary btn-sm">
                        Nächste <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
                <div class="text-muted small">
                    <span id="total-count">0 Einträge</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Enhanced Bulk Actions (Floating) -->
    <div class="bulk-actions" id="floatingActions">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold text-success" id="selectedCount">0 ausgewählt</span>
            <div class="vr"></div>
            <button id="bulk-mark-paid" class="btn btn-success btn-sm">
                <i class="bi bi-check-circle"></i> Als bezahlt
            </button>
            <button id="bulk-pdf" class="btn btn-info btn-sm">
                <i class="bi bi-file-pdf"></i> PDF datei
            </button>
            <button class="btn btn-danger btn-sm" onclick="clearSelection()">
                <i class="bi bi-x-circle"></i> Abbrechen
            </button>
        </div>
    </div>
</main>

<!-- Enhanced Modal -->
<div id="modal" class="modal fade" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Rechnung Details</h5>
                <button id="modal-close" type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div id="modal-body" class="modal-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
            </div>
        </div>
    </div>
</div>

<!-- Notification Container -->
<div id="notificationContainer" class="notification"></div>


<script>
    // Enhanced LIST config for rechnungen (invoices)
    window.LIST_CONFIG = {
        api_list: '/pages/api/rechnungen_list.php',
        api_get: '/pages/api/rechnungen_get.php',
        api_bulk: '/pages/api/rechnungen_bulk.php',
        api_export: '/pages/api/rechnungen_export.php',
        api_email: '/pages/api/send_document_email.php',
        api_mahnung: '/pages/api/mahnungen_bulk.php',
        pdf_url: '/pages/rechnung_pdf.php',
        per_page: 25,
        default_sort: 'nummer'
    };


    // Enhanced date filter behavior
    (function () {
        const select = document.getElementById('filter-date');
        if (!select) return;
        select.addEventListener('change', function () {
            window.LIST_EXTRA = window.LIST_EXTRA || {};
            window.LIST_EXTRA.date_filter = this.value;

            // always go back to page 1 on filter change
            window._LIST_STATE = window._LIST_STATE || {};
            window._LIST_STATE.page = 1;

            if (typeof fetchData === 'function') {
                fetchData();   // <– call the shared loader
            }
        });
    })();



    // Stats updating function
    function updateStats() {
        const rows = document.querySelectorAll('#list-table tbody tr');
        let total = 0, open = 0, paid = 0, overdue = 0;

        // Find which column really contains "Status" (find on thead)
        const thead = document.querySelector('#list-table thead');
        let statusIdx = -1;
        if (thead) {
            const headers = Array.from(thead.querySelectorAll('th'));
            statusIdx = headers.findIndex(th => (th.textContent || '').toLowerCase().includes('status'));
        }

        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (!cells.length) return;
            if (cells[0].hasAttribute('colspan')) return; // skip "keine Einträge"

            total++;

            // Prefer auto-detect status col, fallback to badge
            let statusText = '';
            if (statusIdx >= 0 && cells.length > statusIdx) {
                statusText = (cells[statusIdx].textContent || '').toLowerCase().trim();
            }
            if (!statusText) {
                const badge = row.querySelector('.status-badge');
                if (badge && badge.textContent) {
                    statusText = badge.textContent.toLowerCase().trim();
                }
            }
            if (!statusText) return;

            // Direct paid
            if (statusText.includes('bezahlt') || statusText.includes('paid')) {
                paid++;
            }
            // Overdue (mahnung means overdue)
            else if (
                statusText.includes('überfällig') ||
                statusText.includes('ueberfaellig') ||
                statusText.includes('overdue') ||
                statusText.includes('mahnung')
            ) {
                overdue++;
            }
            // Offen/open/draft
            else if (
                statusText.includes('offen')   ||
                statusText.includes('open')    ||
                statusText.includes('entwurf') ||
                statusText.includes('draft')
            ) {
                open++;
            }
            // else: ignore
        });

        const elTotal   = document.getElementById('statsTotal');
        const elOpen    = document.getElementById('statsOpen');
        const elPaid    = document.getElementById('statsPaid');
        const elOverdue = document.getElementById('statsOverdue');
        const elCount   = document.getElementById('total-count');

        if (elTotal)   elTotal.textContent   = total;
        if (elOpen)    elOpen.textContent    = open;
        if (elPaid)    elPaid.textContent    = paid;
        if (elOverdue) elOverdue.textContent = overdue;
        if (elCount)   elCount.textContent   = total + ' Einträge';
    }

    // Enhanced selection handling
    function updateFloatingActions() {
        const selected = document.querySelectorAll('input[name="selected[]"]:checked');
        const floatingActions = document.getElementById('floatingActions');
        const selectedCount = document.getElementById('selectedCount');

        if (selected.length > 0) {
            floatingActions.classList.add('visible');
            selectedCount.textContent = `${selected.length} ausgewählt`;
            document.getElementById('bulk-delete').disabled = false;
        } else {
            floatingActions.classList.remove('visible');
            document.getElementById('bulk-delete').disabled = true;
        }
    }

    // Clear selection
    function clearSelection() {
        document.querySelectorAll('input[name="selected[]"]').forEach(cb => cb.checked = false);
        document.getElementById('select-all').checked = false;
        updateFloatingActions();
    }

    // Enhanced notification system
    function showNotification(message, type = 'info', duration = 5000) {
        const container = document.getElementById('notificationContainer');
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} alert-dismissible fade show`;
        notification.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
        container.appendChild(notification);

        setTimeout(() => {
            notification.remove();
        }, duration);
    }

    // Enhanced bulk actions
    document.getElementById('bulk-mahnung').addEventListener('click', function() {
        const selected = Array.from(document.querySelectorAll('input[name="selected[]"]:checked')).map(cb => cb.value);
        if (!selected.length) {
            showNotification('Bitte wählen Sie mindestens eine Rechnung aus.', 'warning');
            return;
        }

        if (confirm(`Für ${selected.length} Rechnung(en) Mahnungen erstellen?`)) {
            fetch(window.LIST_CONFIG.api_mahnung, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({rechnung_ids: selected})
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showNotification(`${selected.length} Mahnung(en) erfolgreich erstellt!`, 'success');
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        showNotification('Fehler: ' + (data.error || 'Mahnung-Erstellung fehlgeschlagen'), 'danger');
                    }
                })
                .catch(e => showNotification('Fehler: ' + e.message, 'danger'));
        }
    });

    document.getElementById('bulk-email').addEventListener('click', async function() {
        const btn = this;
        const selected = Array.from(
            document.querySelectorAll('input[name="selected[]"]:checked')
        ).map(cb => cb.value);

        if (!selected.length) {
            showNotification('Bitte wählen Sie mindestens eine Rechnung aus.', 'warning');
            return;
        }

        // Optional: allow a one-time recipient override for all selected
        const override = prompt('Optional: Empfänger-Override für alle E-Mails (leer lassen, um Kunden-E-Mails zu verwenden)');
        const payload = { doc_type: 'rechnung', ids: selected };
        if (override && override.trim()) payload.email = override.trim();

        // Disable button during send to prevent double submits
        const origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-envelope"></i> Senden…';
        try {
            const res = await fetch(window.LIST_CONFIG.api_email, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            });

            const ct = (res.headers.get('content-type') || '').toLowerCase();
            let data = null;
            if (ct.includes('application/json')) {
                try { data = await res.json(); } catch (e) { /* fallthrough */ }
            }
            if (!res.ok) {
                const text = data ? JSON.stringify(data) : (await res.text().catch(()=>''));
                // Special hint for missing SMTP config
                if (text.includes('smtp_config_missing')) {
                    showNotification('E-Mail-Versand ist lokal nicht konfiguriert. Bitte config/smtp.php anlegen (siehe config/smtp.php.dist) oder Mailpit verwenden (localhost:1025).', 'warning', 10000);
                } else {
                    showNotification(`E-Mail-API Fehler: HTTP ${res.status} ${res.statusText} — ${text.substring(0,300)}`, 'danger', 8000);
                }
                return;
            }
            if (!data) {
                const txt = await res.text().catch(()=>'(keine Antwort)');
                showNotification('Antwort ist kein JSON. Details: ' + txt.substring(0,300), 'danger');
                return;
            }
            if (data.success) {
                const sent = Number(data.sent||0);
                const failed = Array.isArray(data.failed) ? data.failed.length : 0;
                const msg = sent > 0 && failed === 0
                    ? `${sent} E-Mail(s) versendet.`
                    : `${sent} gesendet, ${failed} fehlgeschlagen.`;
                showNotification(msg, sent>0 && failed===0 ? 'success' : 'warning');
            } else {
                // Provide clearer messages for common errors
                if (data.error === 'missing_ids') {
                    showNotification('Fehler: Keine IDs übermittelt.', 'danger');
                } else if (data.error === 'invalid_doc_type') {
                    showNotification('Fehler: Ungültiger Dokumenttyp.', 'danger');
                } else if (data.error === 'server' && String(data.detail||'').includes('smtp_config_missing')) {
                    showNotification('E-Mail-Versand nicht eingerichtet. Legen Sie config/smtp.php an (Vorlage: config/smtp.php.dist).', 'warning', 10000);
                } else {
                    const detail = data.error || (data.detail ? String(data.detail) : 'E-Mail-Versand fehlgeschlagen');
                    showNotification('Fehler: ' + detail, 'danger');
                }
            }
        } catch (e) {
            showNotification('Netzwerk-/Parserfehler: ' + (e && e.message ? e.message : String(e)), 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    });


    // Fetch global stats from API (independent of current paging/filtering)
    function fetchGlobalStats() {
        const url = new URL(window.LIST_CONFIG.api_list, window.location.origin);
        // Minimize payload; stats are global anyway
        url.searchParams.set('per_page', '1');
        url.searchParams.set('page', '1');
        return fetch(url.toString())
            .then(r => r.ok ? r.json() : Promise.reject(new Error('HTTP '+r.status)))
            .then(data => {
                if (data && data.stats) {
                    const s = data.stats;
                    const elTotal = document.getElementById('statsTotal');
                    const elPaid = document.getElementById('statsPaid');
                    const elOpen = document.getElementById('statsOpen');
                    const elOverdue = document.getElementById('statsOverdue');
                    const elCount = document.getElementById('total-count');
                    if (elTotal) elTotal.textContent = s.gesamt;
                    if (elPaid) elPaid.textContent = s.paid;
                    if (elOpen) elOpen.textContent = s.offen;
                    if (elOverdue) elOverdue.textContent = s.overdue;
                    if (elCount) elCount.textContent = s.gesamt + ' Einträge';
                }
            })
            .catch(() => {});
    }

    // initial fetch on load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fetchGlobalStats);
    } else {
        fetchGlobalStats();
    }

    // Bulk PDF: open each selected PDF in a new tab/window
    document.getElementById('bulk-pdf').addEventListener('click', function() {
        const selected = Array.from(document.querySelectorAll('input[name="selected[]"]:checked')).map(cb => cb.value);
        if (!selected.length) {
            showNotification('Bitte wählen Sie mindestens eine Rechnung aus.', 'warning');
            return;
        }
        const base = window.LIST_CONFIG.pdf_url || '/pages/rechnung_pdf.php';
        selected.forEach((id, idx) => {
            const url = base + '?id=' + encodeURIComponent(id);
            // Stagger windows slightly to avoid popup blockers
            setTimeout(() => window.open(url, '_blank'), idx * 150);
        });
        showNotification(`${selected.length} PDF-Ansicht(en) geöffnet.`, 'info');
    });


    // Initialize enhanced features
    document.addEventListener('DOMContentLoaded', function() {
        // initial delayed stats update in case data loads async
        setTimeout(updateStats, 1000);

        // observe table body for changes to keep stats in sync with live data
        const tbody = document.querySelector('#list-table tbody');
        if (tbody && 'MutationObserver' in window) {
            const obs = new MutationObserver(() => {
                // debounce rapid mutations
                clearTimeout(window.__statsDebounce);
                window.__statsDebounce = setTimeout(updateStats, 150);

            });
            obs.observe(tbody, { childList: true, subtree: false });
        }

        document.addEventListener('change', function(e) {
            if (e.target.matches('input[type="checkbox"]')) {
                updateFloatingActions();
            }
        });
    });

</script>

<!-- Your existing scripts -->
<script src="../assets/js/lists.shared.js"></script>
<script src="../assets/js/lists.shared.create.link.js"></script>
<script src="../assets/js/lists.fix-links.js"></script>

<?php if(function_exists('renderPdfModalAssets')) echo renderPdfModalAssets(); ?>

<script src="<?= esc(asset_url('public/js/list-enhancements.js')) ?>"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/lists.ui.shared.js"></script>

