<?php
// pages/mahnungen-list.php
// Enhanced reminders list with improved quick actions and modern design
// Requires: config/db.php, assets/js/lists.shared.js, assets/css/lists.css

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');


global $pdo;
// Load open invoices for the dropdown
require_once __DIR__ . '/../config/db.php';
// Only fetch required fields and only unpaid/open invoices
$stmt = $pdo->query("SELECT id, rechnungsnummer FROM rechnungen WHERE status IS NULL OR status NOT IN ('bezahlt','paid')");
$rechnungen = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Mahnungen — Enhanced Liste</title>

    <script>(function(){try{var t=localStorage.getItem('theme');if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link href="../public/css/theme.css" rel="stylesheet">

    <!-- Bootstrap CSS for enhanced styling -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Your existing CSS -->
    <link rel="stylesheet" href="/assets/css/lists.css">
    <link rel="stylesheet" href="<?= esc(asset_url('public/css/list-enhancements.css')) ?>" />

    <!-- Custom enhancements -->
    <style>
        .toolbar {
            background: linear-gradient(135deg, #dc3545 0%, #e74c3c 100%);
            color: white;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 16px rgba(220, 53, 69, 0.15);
        }

        .toolbar h1 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .quick-actions {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        }

        .action-btn {
            border: none;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-weight: 500;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .action-btn-primary {
            background: linear-gradient(135deg, #dc3545, #e74c3c);
            color: white;
        }

        .action-btn-success {
            background: linear-gradient(135deg, #198754, #20c997);
            color: white;
        }

        .action-btn-warning {
            background: linear-gradient(135deg, #fd7e14, #ffc107);
            color: white;
        }

        .action-btn-danger {
            background: linear-gradient(135deg, #6f42c1, #e83e8c);
            color: white;
        }

        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            text-align: center;
        }

        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            color: #dc3545;
            margin: 0;
        }

        .stats-label {
            color: #6c757d;
            font-size: 0.875rem;
            margin: 0;
        }

        #list-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stage-0 { background: #cff4fc; color: #055160; }
        .stage-1 { background: #fff3cd; color: #856404; }
        .stage-2 { background: #ffeaa7; color: #6c5ce7; }
        .stage-3 { background: #f8d7da; color: #58151c; }
        .status-sent { background: #d1e7dd; color: #0a3622; }
        .status-pending { background: #e2e3e5; color: #383d41; }

        .bulk-actions {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: white;
            border-radius: 12px;
            padding: 1rem;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .bulk-actions.visible {
            transform: translateY(0);
            opacity: 1;
        }

        .notification {
            position: fixed;
            top: 2rem;
            right: 2rem;
            max-width: 400px;
            z-index: 1050;
        }
        /* Show all bulk/CSV controls (previously hidden) */
        /* Controls are now visible; no forced hiding here. */
    </style>
    <!-- Design system overrides: unify with global theme.css and touch targets -->
    <style>
      .toolbar { background: var(--color-surface); color: var(--color-text); border:1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }
      .quick-actions { background: var(--color-surface); color: inherit; border:1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }
      .stats-card { background: var(--color-surface); color: inherit; border:1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }
      .stats-number { color: var(--color-primary); }
      #list-table { background: var(--color-surface); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }
      .bulk-actions { background: var(--color-surface); color: inherit; border:1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: var(--shadow-md); }
      /* Touch-friendly buttons */
      .action-btn { min-height:44px; border:1px solid var(--color-border); background: var(--color-surface-2); color: inherit; border-radius: var(--radius-sm); }
      .action-btn:hover { background: color-mix(in srgb, var(--color-surface) 92%, #000); }
      .action-btn:focus-visible { outline: none; box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-primary) 30%, transparent); }
      .action-btn-primary { background: var(--color-primary) !important; color: var(--color-primary-contrast) !important; border-color: var(--color-primary) !important; }
      .action-btn-success { background: var(--color-success) !important; color: var(--color-primary-contrast) !important; border-color: var(--color-success) !important; }
      .action-btn-warning { background: var(--color-warning) !important; color: var(--color-text) !important; border-color: var(--color-warning) !important; }
      .action-btn-danger  { background: var(--color-danger) !important; color: var(--color-primary-contrast) !important; border-color: var(--color-danger) !important; }
      .btn, .btn-sm { min-height:44px; }
    </style>
</head>
<body>
<main class="container-fluid px-4 py-3" data-list-page>
    <!-- Enhanced Header -->
    <header class="toolbar p-4">
        <div class="d-flex justify-content-between align-items-center">
            <h1>
                <i class="bi bi-bell"></i>
                Mahnungen verwalten
            </h1>
            <div class="d-flex gap-2">
                <button class="btn btn-light" id="refreshData">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
                <button class="btn btn-light" id="autoGenerate">
                    <i class="bi bi-magic"></i> Auto-Generierung
                </button>
                <a href="mahnung.php" class="btn btn-light">
                    <i class="bi bi-plus-circle"></i> Neue Mahnung
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
                <div class="stats-number" id="statsStage1">0</div>
                <div class="stats-label">1. Mahnung</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="stats-number" id="statsStage2">0</div>
                <div class="stats-label">2. letzte Mahnung</div>
            </div>
        </div>
    </div>

    <!-- Enhanced Controls -->
    <div class="quick-actions">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <div class="d-flex gap-2 flex-wrap">
                    <input id="search" type="search" class="form-control" placeholder="Suche nach Rechnung, Kunde oder Betrag" style="max-width: 300px;" />
                    <select id="filter-stage" class="form-select" style="max-width: 150px;">
                        <option value="">Alle Stufen</option>
                        <option value="0">Zahlungserinnerung</option>
                        <option value="1">1. Mahnung</option>
                        <option value="2">2. letzte Mahnung</option>
                    </select>
                    <select id="filter-status" class="form-select" style="max-width: 150px;">
                        <option value="">Alle Status</option>
                        <option value="offen">Offen</option>
                        <option value="bezahlt">Bezahlt</option>
                        <option value="inkasso">Inkasso</option>
                    </select>
                </div>
            </div>
            <div class="col-lg-6 text-end">
                <div class="btn-group">
                    <button class="action-btn action-btn-primary" id="export-csv">
                        <i class="bi bi-file-earmark-spreadsheet"></i> CSV Export
                    </button>
                    <button class="action-btn action-btn-success" id="bulk-send">
                        <i class="bi bi-envelope"></i> Versenden
                    </button>
                    <button class="action-btn action-btn-warning" id="bulk-escalate">
                        <i class="bi bi-arrow-up-circle"></i> Eskalieren
                    </button>
                    <button class="action-btn action-btn-danger" id="bulk-delete" disabled>
                        <i class="bi bi-trash"></i> Löschen
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Table Section -->
    <section class="card border-0">
        <div class="card-body p-0">
            <table id="list-table" class="table table-hover mb-0">
                <thead class="table-light">
                <tr>
                    <th style="width: 40px;">
                        <input id="select-all" type="checkbox" class="form-check-input">
                    </th>
                    <th data-sort="rechnung_nummer">
                        Rechnung <i class="bi bi-arrow-down-up text-muted"></i>
                    </th>
                    <th data-sort="kunde">
                        Kunde <i class="bi bi-arrow-down-up text-muted"></i>
                    </th>
                    <th data-sort="betrag" class="text-end" style="cursor: pointer;">
                        Betrag <i class="bi bi-arrow-down-up text-muted"></i>
                    </th>
                    <th data-sort="stufe" style="cursor: pointer;">
                        Mahnstufe <i class="bi bi-arrow-down-up text-muted"></i>
                    </th>
                    <th data-sort="created_at">
                        Erstellt <i class="bi bi-arrow-down-up text-muted"></i>
                    </th>
                    <th data-sort="status" style="cursor: pointer;">
                        Status <i class="bi bi-arrow-down-up text-muted"></i>
                    </th>
                    <th style="width: 200px;">Aktionen</th>
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
                <div class="text-muted small d-flex gap-3 align-items-center">
                    <span id="total-count">0 Einträge</span>
                    <span id="amount-sum" class="fw-semibold">Betrag Summe: 0.00 €</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Enhanced Bulk Actions (Floating) -->
    <div class="bulk-actions" id="floatingActions">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold text-danger" id="selectedCount">0 ausgewählt</span>
            <div class="vr"></div>
            <button id="bulk-print" class="btn btn-info btn-sm">
                <i class="bi bi-printer"></i> Drucken
            </button>
            <button id="bulk-escalate-stage" class="btn btn-warning btn-sm">
                <i class="bi bi-arrow-up-circle"></i> Nächste Stufe
            </button>
            <button id="bulk-mark-sent" class="btn btn-success btn-sm">
                <i class="bi bi-check-circle"></i> Als versendet
            </button>
            <button class="btn btn-secondary btn-sm" onclick="clearSelection()">
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
                <h5 class="modal-title">Mahnung Details</h5>
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

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Enhanced LIST config for mahnungen (reminders)
    window.LIST_CONFIG = {
        api_list: '/pages/api/mahnungen_list.php',
        api_get: '/pages/api/mahnungen_get.php',
        api_bulk: '/pages/api/mahnungen_bulk.php',
        api_export: '/pages/api/mahnungen_export.php',
        api_send: '/pages/api/mahnungen_send.php',
        api_escalate: '/pages/api/mahnungen_escalate.php',
        api_email: '/pages/api/send_document_email.php',
        pdf_url: '/pages/mahnung_pdf.php',
        per_page: 25,
        default_sort: 'created_at'
    };

    // Stats updating function
    function updateStats() {
        const rows = document.querySelectorAll('#list-table tbody tr');
        let total = rows.length;
        let stage1 = 0, stage2 = 0;

        rows.forEach(row => {
            const stage = row.querySelector('[data-stage]')?.dataset.stage || '';
            if (stage === '1') stage1++;
            else if (stage === '2') stage2++;
        });

        // Compute per-page amount sum (prefer API data if available)
        let amountSum = 0;
        try {
            const json = window._LAST_LIST_JSON || {};
            const data = Array.isArray(json.data) ? json.data : [];
            if (data.length) {
                for (const r of data) {
                    const v = r.total_due ?? r.betrag ?? r.total ?? r.amount ?? r.summe ?? r.gesamt;
                    const n = Number(v);
                    if (Number.isFinite(n)) amountSum += n;
                }
            } else {
                // Fallback: parse from DOM (4th column amount text)
                rows.forEach(tr => {
                    const tds = tr.querySelectorAll('td');
                    // Column order with Mahnstufe present: [cb, nummer, kunde, betrag, stufe, datum, status]
                    const idx = tds.length >= 7 ? 3 : 3; // same index in both current layouts
                    const txt = (tds[idx] && tds[idx].textContent) ? tds[idx].textContent : '';
                    const num = parseFloat(String(txt).replace(/[^0-9.,-]/g, '').replace(/\./g, '').replace(',', '.'));
                    if (Number.isFinite(num)) amountSum += num;
                });
            }
        } catch (_) {}

        const fmt = (n) => (Number(n).toFixed(2) + ' €');

        const elTotal = document.getElementById('statsTotal');
        const elS1 = document.getElementById('statsStage1');
        const elS2 = document.getElementById('statsStage2');
        const elCount = document.getElementById('total-count');
        const elSum = document.getElementById('amount-sum');

        if (elTotal) elTotal.textContent = String(total);
        if (elS1) elS1.textContent = String(stage1);
        if (elS2) elS2.textContent = String(stage2);
        if (elCount) elCount.textContent = `${total} Einträge`;
        if (elSum) elSum.textContent = `Betrag Summe: ${fmt(amountSum)}`;
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

    // Enhanced bulk actions for Mahnungen
    document.getElementById('autoGenerate').addEventListener('click', function() {
        if (confirm('Automatische Mahnung-Generierung für überfällige Rechnungen starten?')) {
            fetch('/pages/auto_generate_mahnungen.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({})
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showNotification(`${data.generated_count || 0} neue Mahnung(en) generiert!`, 'success');
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        showNotification('Fehler: ' + (data.error || 'Auto-Generierung fehlgeschlagen'), 'danger');
                    }
                })
                .catch(e => showNotification('Fehler: ' + e.message, 'danger'));
        }
    });

    document.getElementById('bulk-escalate-stage').addEventListener('click', function() {
        const selected = Array.from(document.querySelectorAll('input[name="selected[]"]:checked')).map(cb => cb.value);
        if (!selected.length) {
            showNotification('Bitte wählen Sie mindestens eine Mahnung aus.', 'warning');
            return;
        }

        if (confirm(`${selected.length} nächsten Stufe ?`)) {
            fetch(window.LIST_CONFIG.api_escalate, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ids: selected})
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showNotification(`${selected.length} Mahnung(en) erfolgreich eskaliert!`, 'success');
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        showNotification('Fehler: ' + (data.error || 'Eskalation fehlgeschlagen'), 'danger');
                    }
                })
                .catch(e => showNotification('Fehler: ' + e.message, 'danger'));
        }
    });

    // NEW: bulk print handler (separate and self-contained)
    document.getElementById('bulk-print').addEventListener('click', function () {
        const selected = Array.from(document.querySelectorAll('input[name="selected[]"]:checked'))
            .map(cb => String(cb.value).replace(/[^0-9]/g, ''))
            .filter(v => v && v !== '0');

        if (!selected.length) {
            showNotification('Bitte wählen Sie mindestens eine Mahnung aus.', 'warning');
            return;
        }

        const url = '/pages/mahnungen_bulk_pdf.php?ids=' + encodeURIComponent(selected.join(','));
        const w = window.open(url, '_blank', 'noopener,noreferrer');
        if (!w) {
            showNotification('Popup blockiert. Bitte Popups erlauben.', 'warning');
        }
    });

    // Bulk send handler (defined once, not inside other handlers)
    document.getElementById('bulk-send').addEventListener('click', function() {
        const selected = Array.from(document.querySelectorAll('input[name="selected[]"]:checked')).map(cb => cb.value);
        if (!selected.length) {
            showNotification('Bitte wählen Sie mindestens eine Mahnung aus.', 'warning');
            return;
        }

        // First: send emails via unified endpoint using PHPMailer
        fetch(window.LIST_CONFIG.api_email, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({doc_type: 'mahnung', ids: selected})
        })
            .then(async r => {
                const ct = (r.headers.get('content-type') || '').toLowerCase();
                const data = ct.includes('application/json') ? await r.json() : {success:false, error:'non_json_response', detail: await r.text().catch(()=> '')};
                if (!r.ok) {
                    let msg = data && (data.error || data.detail) ? (data.error + ': ' + (data.detail || '')) : `HTTP ${r.status}`;
                    if (String(msg).includes('smtp_config_missing')) {
                        msg = 'E-Mail-Versand nicht konfiguriert. Bitte config/smtp.php anlegen (Vorlage: config/smtp.php.dist) oder Mailpit (127.0.0.1:1025) verwenden.';
                    }
                    throw new Error(msg);
                }
                // Notify about send results
                const sent = Number(data.sent || 0);
                const failed = Array.isArray(data.failed) ? data.failed.length : 0;
                if (sent > 0 && failed === 0) {
                    showNotification(`${sent} E-Mail(s) versendet.`, 'success');
                } else if (sent > 0) {
                    showNotification(`${sent} gesendet, ${failed} fehlgeschlagen.`, 'warning');
                } else {
                    showNotification('Keine E-Mail versendet.', 'warning');
                }
                // Second: mark those IDs as sent in DB (best-effort)
                return fetch(window.LIST_CONFIG.api_send, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ids: selected})
                });
            })
            .then(r => r && r.ok ? r.json() : {success:false})
            .then(() => {
                setTimeout(() => location.reload(), 1500);
            })
            .catch(e => showNotification('Fehler: ' + (e && e.message ? e.message : String(e)), 'danger'));
    });

    document.getElementById('refreshData').addEventListener('click', function() {
        location.reload();
    });


    // Initialize enhanced features
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(updateStats, 1000);

        document.addEventListener('change', function(e) {
            if (e.target.matches('input[type="checkbox"]')) {
                updateFloatingActions();
            }
        });
    });
</script>

<!-- Your existing scripts -->
<script src="/assets/js/lists.shared.js"></script>
<script src="/assets/js/lists.shared.create.link.js"></script>
<script src="/assets/js/lists.fix-links.js"></script>

<?php if(function_exists('renderPdfModalAssets')) echo renderPdfModalAssets(); ?>

<script src="<?= esc(asset_url('public/js/list-enhancements.js')) ?>"></script>
<script src="/assets/js/lists.ui.shared.js"></script>

</body>
</html>