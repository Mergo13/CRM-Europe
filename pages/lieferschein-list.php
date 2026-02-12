<?php
// pages/lieferschein-list.php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/db.php';

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
    <title>Lieferscheine — Liste</title>

    <script>(function(){try{var t=localStorage.getItem('theme');if(!t){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link href="../public/css/theme.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="/assets/css/lists.css">
    <link rel="stylesheet" href="<?= esc(asset_url('public/css/list-enhancements.css')) ?>" />

    <style>
        .toolbar {
            background: linear-gradient(135deg, #0d6efd 0%, #0dcaf0 100%);
            color: white;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 16px rgba(13, 110, 253, 0.15);
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
        .action-btn-primary { background: linear-gradient(135deg, #0d6efd, #0dcaf0); color: white; }
        .action-btn-success { background: linear-gradient(135deg, #198754, #20c997); color: white; }
        .action-btn-warning { background: linear-gradient(135deg, #fd7e14, #ffc107); color: white; }
        .action-btn-danger  { background: linear-gradient(135deg, #6f42c1, #e83e8c); color: white; }

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
            color: #0d6efd;
            margin: 0;
        }
        .stats-label { color: #6c757d; font-size: 0.875rem; margin: 0; }

        #list-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        }
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
        .bulk-actions.visible { transform: translateY(0); opacity: 1; }

        .notification {
            position: fixed;
            top: 2rem;
            right: 2rem;
            max-width: 400px;
            z-index: 1050;
        }
    </style>
</head>
<body>
<main class="container-fluid px-4 py-3" data-list-page>
    <header class="toolbar p-4">
        <div class="d-flex justify-content-between align-items-center">
            <h1>
                <i class="bi bi-truck"></i>
                Lieferscheine verwalten
            </h1>
            <div class="d-flex gap-2">
                <button class="btn btn-light" id="refreshData" type="button" title="Neu laden">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
                <a href="lieferschein.php" class="btn btn-light">
                    <i class="bi bi-plus-circle"></i> Neuer Lieferschein
                </a>
            </div>
        </div>
    </header>

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
                <div class="stats-number" id="statsDelivered">0</div>
                <div class="stats-label">Geliefert</div>
            </div>
        </div>
    </div>

    <div class="quick-actions">
        <div class="row align-items-center">
            <div class="col-lg-7">
                <div class="d-flex gap-2 flex-wrap">
                    <input id="search" type="search" class="form-control" placeholder="Suche nach Nummer, Kundennummer oder Bemerkung" style="max-width: 360px;" />

                    <select id="filter-status" class="form-select" style="max-width: 180px;">
                        <option value="">Alle Status</option>
                        <option value="offen">Offen</option>
                        <option value="geliefert">Geliefert</option>
                        <option value="storniert">Storniert</option>
                    </select>
                </div>
            </div>
            <div class="col-lg-5 text-end">
                <div class="btn-group">
                    <button class="action-btn action-btn-primary" id="export-csv" type="button">
                        <i class="bi bi-file-earmark-spreadsheet"></i> CSV Export
                    </button>
                    <button class="action-btn action-btn-success" id="bulk-print" type="button">
                        <i class="bi bi-printer"></i> Drucken
                    </button>
                    <button class="action-btn action-btn-success" id="bulk-delivered" type="button">
                        <i class="bi bi-check2-circle"></i> Geliefert
                    </button>
                    <button class="action-btn action-btn-danger" id="bulk-delete" type="button">
                        <i class="bi bi-trash"></i> Löschen
                    </button>
                </div>
            </div>
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
                    <th data-sort="nummer">Lieferschein <i class="bi bi-arrow-down-up text-muted"></i></th>
                    <th data-sort="kundennummer">Kundennummer <i class="bi bi-arrow-down-up text-muted"></i></th>
                    <th data-sort="datum">Datum <i class="bi bi-arrow-down-up text-muted"></i></th>
                    <th data-sort="bemerkung">Bemerkung <i class="bi bi-arrow-down-up text-muted"></i></th>
                    <th style="width: 160px;">Status</th>
                </tr>
                </thead>
                <tbody>
                <?php
                // Server-side initial render of Lieferscheine (fallback when JS/API not available)
                try {
                    $pdo = $GLOBALS['pdo'] ?? (isset($pdo) ? $pdo : null);
                    if (!($pdo instanceof PDO)) { throw new Exception('DB not available'); }
                    // Try to include status; if column missing, fall back without it
                    try {
                        $stmt = $pdo->query("SELECT id, nummer, kundennummer, datum, bemerkung, status FROM lieferscheine ORDER BY status ASC, COALESCE(datum, '1970-01-01') DESC, id DESC LIMIT 200");
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Throwable $e1) {
                        $stmt = $pdo->query("SELECT id, nummer, kundennummer, datum, bemerkung FROM lieferscheine ORDER BY COALESCE(datum, '1970-01-01') DESC, id DESC LIMIT 200");
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                } catch (Throwable $e) {
                    $rows = [];
                }
                if (!empty($rows)):
                    foreach ($rows as $row):
                        $id   = (int)($row['id'] ?? 0);
                        $nr   = $row['nummer'] ?? $id;
                        $kdnr = $row['kundennummer'] ?? '';
                        $dat  = !empty($row['datum']) ? date('Y-m-d', strtotime((string)$row['datum'])) : '';
                        $bem  = $row['bemerkung'] ?? '';
                        $pdfUrl = '/pages/lieferschein_pdf.php?id=' . $id;
                        // Use DB status when present; map legacy English to German; fallback to 'offen'
                        $rawStatus = isset($row['status']) && $row['status'] !== '' ? (string)$row['status'] : 'offen';
                        $ls = strtolower(trim($rawStatus));
                        $status = match($ls){
                            'open','offen' => 'offen',
                            'delivered','geliefert' => 'geliefert',
                            'cancelled','storniert' => 'storniert',
                            default => 'offen'
                        };
                ?>
                    <tr data-id="<?= $id ?>" data-status="<?= esc($status) ?>">
                        <td><input type="checkbox" class="form-check-input row-select" name="selected[]" data-id="<?= $id ?>" value="<?= $id ?>"></td>
                        <td><?= esc($nr) ?></td>
                        <td><?= esc($kdnr) ?></td>
                        <td><?= esc($dat) ?></td>
                        <td><?= esc($bem) ?></td>
                        <td><?= esc($status) ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Keine Lieferscheine vorhanden.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <div class="d-flex justify-content-between align-items-center p-3 bg-light">
                <div class="pagination mb-0">
                    <button id="prev-page" class="btn btn-outline-primary btn-sm" type="button">
                        <i class="bi bi-chevron-left"></i> Vorherige
                    </button>
                    <span id="page-info" class="mx-3 align-self-center">Seite 1</span>
                    <button id="next-page" class="btn btn-outline-primary btn-sm" type="button">
                        Nächste <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
                <div class="text-muted small">
                    <span id="total-count">0 Einträge</span>
                </div>
            </div>
        </div>
    </section>

    <div class="bulk-actions" id="floatingActions">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold text-primary" id="selectedCount">0 ausgewählt</span>
            <div class="vr"></div>
            <button id="bulk-print-floating" class="btn btn-info btn-sm" type="button">
                <i class="bi bi-printer"></i> Drucken
            </button>
            <button class="btn btn-secondary btn-sm" type="button" onclick="clearSelection()">
                <i class="bi bi-x-circle"></i> Abbrechen
            </button>
        </div>
    </div>
</main>

<div id="modal" class="modal fade" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Lieferschein Details</h5>
                <button id="modal-close" type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div id="modal-body" class="modal-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
            </div>
        </div>
    </div>
</div>

<div id="notificationContainer" class="notification"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    window.LIST_CONFIG = {
        api_list: '/pages/api/lieferscheinen_list.php',
        api_get: '/pages/api/lieferscheinen_get.php',
        api_bulk: '/pages/api/lieferscheinen_bulk.php',
        api_export: '/pages/api/lieferscheinen_export.php',
        pdf_url: '/pages/lieferschein_pdf.php',
        per_page: 25,
        default_sort: 'datum'
    };

    window.LIST_EXTRA = window.LIST_EXTRA || {};

    function syncFiltersToListExtra() {
        const statusEl  = document.getElementById('filter-status');
        window.LIST_EXTRA.status = statusEl ? statusEl.value : '';

        delete window.LIST_EXTRA.country_code;
        delete window.LIST_EXTRA.firma_id;
    }

    function normalizeStatus(val){
        const s = String(val||'').trim().toLowerCase();
        if (s === 'open' || s === 'offen') return 'offen';
        if (s === 'delivered' || s === 'geliefert') return 'geliefert';
        if (s === 'cancelled' || s === 'storniert') return 'storniert';
        return 'offen';
    }

    function updateStats() {
        const rows = document.querySelectorAll('#list-table tbody tr');
        const realRows = Array.from(rows).filter(r => !r.querySelector('td[colspan]'));
        const visibleRows = realRows.filter(r => r.style.display !== 'none');

        const total = visibleRows.length;
        let offen = 0;
        let geliefert = 0;

        visibleRows.forEach(row => {
            const status = String(row.getAttribute('data-status') || '').trim().toLowerCase();
            const normalized = normalizeStatus(status);

            if (normalized === 'offen') offen++;
            if (normalized === 'geliefert') geliefert++;
        });

        const elTotal = document.getElementById('statsTotal');
        if (elTotal) elTotal.textContent = String(total);

        const elOpen = document.getElementById('statsOpen');
        if (elOpen) elOpen.textContent = String(offen);

        const elDelivered = document.getElementById('statsDelivered');
        if (elDelivered) elDelivered.textContent = String(geliefert);

        const elCount = document.getElementById('total-count');
        if (elCount) elCount.textContent = `${total} Einträge`;
    }

    function updateFloatingActions() {
        const selected = document.querySelectorAll('input.row-select:checked');
        const floatingActions = document.getElementById('floatingActions');
        const selectedCount = document.getElementById('selectedCount');

        const bulkDelete = document.getElementById('bulk-delete');
        const bulkPrint = document.getElementById('bulk-print');
        const bulkDelivered = document.getElementById('bulk-delivered');

        const hasSelection = selected.length > 0;

        if (hasSelection) {
            floatingActions?.classList.add('visible');
            if (selectedCount) selectedCount.textContent = `${selected.length} ausgewählt`;
        } else {
            floatingActions?.classList.remove('visible');
        }

        if (bulkDelete) bulkDelete.disabled = !hasSelection;
        if (bulkPrint) bulkPrint.disabled = !hasSelection;
        if (bulkDelivered) bulkDelivered.disabled = !hasSelection;
    }

    function clearSelection() {
        document.querySelectorAll('input.row-select').forEach(cb => cb.checked = false);
        const selAll = document.getElementById('select-all');
        if (selAll) selAll.checked = false;
        updateFloatingActions();
    }

    document.getElementById('refreshData')?.addEventListener('click', function() {
        location.reload();
    });

    function markDelivered(id){
        fetch('/pages/api/lieferscheinen_bulk.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body: new URLSearchParams({ action: 'mark_delivered', ids: String(id) })
        }).then(r=>r.json()).then(json => {
            if (json && json.success) {
                const row = document.querySelector(`#list-table tbody tr[data-id="${id}"]`);
                if (row) { row.setAttribute('data-status','geliefert'); }
                updateStats();
                notify('Status aktualisiert', 'Der Lieferschein wurde als geliefert markiert.', 'success');
            } else {
                notify('Fehler', 'Konnte Status nicht aktualisieren.', 'danger');
            }
        }).catch(()=>notify('Fehler','Netzwerkfehler beim Aktualisieren.','danger'));
    }

    function notify(title, message, type){
        const c = document.getElementById('notificationContainer');
        if (!c) return;
        const div = document.createElement('div');
        div.className = `alert alert-${type||'info'} alert-dismissible fade show`;
        div.innerHTML = `<strong>${title}:</strong> ${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
        c.appendChild(div);
        setTimeout(()=>{ try{ div.remove(); }catch(e){} }, 4000);
    }

    document.addEventListener('DOMContentLoaded', function() {
        syncFiltersToListExtra();

        function applyClientFilters(){
        const statusVal = (document.getElementById('filter-status')?.value || '').trim();
            const q = (document.getElementById('search')?.value || '').toLowerCase();
            const rows = document.querySelectorAll('#list-table tbody tr');
            rows.forEach(tr => {
                const tds = tr.querySelectorAll('td');
                const text = Array.from(tds).map(td => (td.textContent||'').toLowerCase()).join(' ');
                const rowStatus = String(tr.getAttribute('data-status')||'');
                let ok = true;
                if (statusVal){ ok = ok && (normalizeStatus(rowStatus) === normalizeStatus(statusVal)); }
                if (q){ ok = ok && text.indexOf(q) !== -1; }
                tr.style.display = ok ? '' : 'none';
            });
            updateStats();
        }

        // Select-all toggles all row checkboxes and updates actions
        const selectAll = document.getElementById('select-all');
        if (selectAll) {
            selectAll.addEventListener('change', function(){
                const checked = !!this.checked;
                document.querySelectorAll('input.row-select').forEach(cb => { cb.checked = checked; });
                updateFloatingActions();
            });
        }

        document.getElementById('filter-status')?.addEventListener('change', function(){
            syncFiltersToListExtra();
            if (typeof fetchData === 'function') fetchData();
            else applyClientFilters();
        });

        document.getElementById('search')?.addEventListener('input', function(){
            syncFiltersToListExtra();
            applyClientFilters();
        });

        setTimeout(updateStats, 1200);
        // Initialize buttons state on load
        updateFloatingActions();

        const tbody = document.querySelector('#list-table tbody');
        if (tbody && 'MutationObserver' in window) {
            const obs = new MutationObserver(() => {
                updateStats();
                updateFloatingActions();
            });
            obs.observe(tbody, { childList: true, subtree: true });
        }

        document.addEventListener('change', function(e) {
            if (e.target && e.target.matches('input[type="checkbox"]')) {
                updateFloatingActions();
            }
        });

        function openSequential(urls, delayMs){
            let i = 0;
            function openNext(){
                if (i >= urls.length) return;
                window.open(urls[i], '_blank');
                i++;
                setTimeout(openNext, delayMs);
            }
            openNext();
        }

        function collectSelectedIds(){
            const ids = [];
            document.querySelectorAll('input.row-select:checked').forEach(cb => {
                const tr = cb.closest('tr');
                const id = tr?.getAttribute('data-id') || cb.dataset.id;
                if (id) ids.push(id);
            });
            return ids;
        }

        async function bulkMarkDeliveredSelected(){
            const ids = collectSelectedIds();
            if (ids.length === 0) {
                notify('Hinweis', 'Bitte wählen Sie mindestens eine Zeile aus.', 'warning');
                return;
            }
            try {
                const res = await fetch('/pages/api/lieferscheinen_bulk.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: new URLSearchParams({ action: 'mark_delivered', ids: ids.join(',') })
                });
                const json = await res.json();
                if (json && json.success) {
                    ids.forEach(id => {
                        const row = document.querySelector(`#list-table tbody tr[data-id="${id}"]`);
                        if (row) row.setAttribute('data-status','geliefert');
                    });
                    updateStats();
                    notify('Geliefert', 'Ausgewählte Lieferscheine wurden als geliefert markiert.', 'success');
                } else {
                    notify('Fehler', 'Konnte Status nicht aktualisieren.', 'danger');
                }
            } catch (e) {
                notify('Fehler', 'Netzwerkfehler beim Aktualisieren.', 'danger');
            }
        }

        // CSV Export der aktuell sichtbaren Zeilen (berücksichtigt Filter)
        function exportCSV(){
            const rows = Array.from(document.querySelectorAll('#list-table tbody tr'))
                .filter(tr => tr.style.display !== 'none');
            const headers = ['Lieferschein','Kundennummer','Datum','Bemerkung','Status'];
            const data = [headers];
            rows.forEach(tr => {
                const tds = tr.querySelectorAll('td');
                const nummer = (tds[1]?.textContent || '').trim();
                const kundennr = (tds[2]?.textContent || '').trim();
                const datum = (tds[3]?.textContent || '').trim();
                const bemerkung = (tds[4]?.textContent || '').trim();
                const status = (tr.getAttribute('data-status') || '').trim();
                data.push([nummer,kundennr,datum,bemerkung,status]);
            });
            const csv = data.map(r => r.map(v => '"' + String(v).replace(/"/g,'""') + '"').join(';')).join('\r\n');
            const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            const d = new Date();
            a.download = `lieferscheine_${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}.csv`;
            document.body.appendChild(a);
            a.click();
            setTimeout(()=>{ URL.revokeObjectURL(url); a.remove(); }, 0);
        }

        // Shift+Click on Bemerkung header triggers delivered action; normal click reserved for sorting
        const hdrBem = document.querySelector('th[data-sort="bemerkung"]');
        if (hdrBem) {
            hdrBem.addEventListener('click', function(e){
                if (e.shiftKey) {
                    e.preventDefault();
                    bulkMarkDeliveredSelected();
                }
            });
        }

        document.getElementById('export-csv')?.addEventListener('click', exportCSV);

        document.getElementById('bulk-print')?.addEventListener('click', () => {
            const ids = collectSelectedIds();
            if (ids.length === 0) return;
            const urls = ids.map(id => `/pages/lieferschein_pdf.php?id=${id}`);
            openSequential(urls, 300);
        });

        // Use centralized delivered handler
        document.getElementById('bulk-delivered')?.addEventListener('click', bulkMarkDeliveredSelected);

        // Bulk delete handler
        document.getElementById('bulk-delete')?.addEventListener('click', async () => {
            const ids = collectSelectedIds();
            if (ids.length === 0) return;
            if (!confirm(`Wirklich ${ids.length} Eintrag(e) löschen?`)) return;
            try {
                const res = await fetch('/pages/api/lieferscheinen_bulk.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: new URLSearchParams({ action: 'delete', ids: ids.join(',') })
                });
                const json = await res.json();
                if (json && json.success) {
                    ids.forEach(id => {
                        const row = document.querySelector(`#list-table tbody tr[data-id="${id}"]`);
                        if (row) row.remove();
                    });
                    clearSelection();
                    updateStats();
                    notify('Gelöscht', 'Ausgewählte Lieferscheine wurden gelöscht.', 'success');
                } else {
                    notify('Fehler', 'Löschen fehlgeschlagen.', 'danger');
                }
            } catch (e) {
                notify('Fehler', 'Netzwerkfehler beim Löschen.', 'danger');
            }
        });

        document.getElementById('bulk-print-floating')?.addEventListener('click', () => {
            const ids = collectSelectedIds();
            if (ids.length === 0) return;
            const urls = ids.map(id => `/pages/lieferschein_pdf.php?id=${id}`);
            openSequential(urls, 300);
        });
    });
</script>

<script src="/assets/js/lists.shared.js"></script>
<script src="/assets/js/lists.shared.create.link.js"></script>
<script src="/assets/js/lists.fix-links.js"></script>

<?php if(function_exists('renderPdfModalAssets')) echo renderPdfModalAssets(); ?>

<script src="<?= esc(asset_url('public/js/list-enhancements.js')) ?>"></script>
<script src="/assets/js/lists.ui.shared.js"></script>

</body>
</html>