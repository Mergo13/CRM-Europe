<?php
// pages/clients-list.php
// Modern CRM Clients List with QuickView, Actions, etc.

declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/init.php';
global $pdo;
function esc($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatDate($date): string {
    return $date ? date('d.m.Y', strtotime($date)) : '—';
}

// Fetch clients with statistics
try {
    // Probe whether the 'rechnungen' table exists using a safe try/catch
    $hasInvoices = true;
    try {
        $pdo->query('SELECT 1 FROM rechnungen LIMIT 1');
    } catch (Throwable $e) {
        $hasInvoices = false;
    }

    if ($hasInvoices) {
        $query = "
            SELECT
                c.*,
                /* totals via correlated subqueries to avoid ONLY_FULL_GROUP_BY issues */
                (SELECT COUNT(DISTINCT r1.id) FROM rechnungen r1 WHERE r1.client_id = c.id) AS total_rechnungen,
                (SELECT COUNT(DISTINCT r2.id) FROM rechnungen r2 WHERE r2.client_id = c.id AND r2.status = 'offen') AS open_rechnungen,
                COALESCE((SELECT SUM(COALESCE(r3.gesamt, r3.betrag, r3.total)) FROM rechnungen r3 WHERE r3.client_id = c.id AND r3.status = 'bezahlt'), 0) AS paid_amount,
                COALESCE((SELECT SUM(COALESCE(r4.gesamt, r4.betrag, r4.total)) FROM rechnungen r4 WHERE r4.client_id = c.id AND r4.status = 'offen'), 0) AS open_amount,
                (SELECT MAX(r5.datum) FROM rechnungen r5 WHERE r5.client_id = c.id) AS last_invoice_date
            FROM clients c
            ORDER BY c.id DESC
        ";
    } else {
        // Fallback without invoices table
        $query = "
            SELECT
                c.*,
                0 AS total_rechnungen,
                0 AS open_rechnungen,
                0.0 AS paid_amount,
                0.0 AS open_amount,
                NULL AS last_invoice_date
            FROM clients c
            ORDER BY c.id DESC
        ";
    }

    $stmt = $pdo->query($query);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalize missing keys just in case
    foreach ($clients as &$c) {
        $c['total_rechnungen'] = (int)($c['total_rechnungen'] ?? 0);
        $c['open_rechnungen'] = (int)($c['open_rechnungen'] ?? 0);
        $c['paid_amount'] = (float)($c['paid_amount'] ?? 0);
        $c['open_amount'] = (float)($c['open_amount'] ?? 0);
        $c['last_invoice_date'] = $c['last_invoice_date'] ?? null;
        $c['firma'] = $c['firma'] ?? '';
        $c['name'] = $c['name'] ?? '';
        $c['email'] = $c['email'] ?? '';
        $c['telefon'] = $c['telefon'] ?? '';
    }
    unset($c);

    // Calculate overall statistics
    $stats = [
        'total' => count($clients),
        'with_invoices' => count(array_filter($clients, fn($c) => (int)($c['total_rechnungen'] ?? 0) > 0)),
        'with_open' => count(array_filter($clients, fn($c) => (int)($c['open_rechnungen'] ?? 0) > 0)),
        'total_revenue' => array_sum(array_map(fn($x) => (float)($x['paid_amount'] ?? 0), $clients)),
        'open_amount' => array_sum(array_map(fn($x) => (float)($x['open_amount'] ?? 0), $clients)),
    ];

} catch (Exception $e) {
    $clients = [];
    $stats = ['total' => 0, 'with_invoices' => 0, 'with_open' => 0, 'total_revenue' => 0, 'open_amount' => 0];
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<div class="container py-4">
    <div class="page-title">Kunden</div>
    <div class="page-sub">Vollständige Übersicht und Verwaltung aller Kunden</div>

    <!-- Statistics -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="ds-card text-center">
                <div class="kpi-label">Kunden Gesamt</div>
                <div id="stat-total" class="kpi-value"><?= $stats['total'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="ds-card text-center">
                <div class="kpi-label">Mit Rechnungen</div>
                <div id="stat-with-invoices" class="kpi-value"><?= $stats['with_invoices'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="ds-card text-center">
                <div class="kpi-label">Mit offenen Forderungen</div>
                <div id="stat-with-open" class="kpi-value"><?= $stats['with_open'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="ds-card text-center">
                <div class="kpi-label">Offene Summe</div>
                <div id="stat-open-amount" class="kpi-value"><?= number_format($stats['open_amount'], 0, ',', '.') ?> €</div>
            </div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <small class="text-muted-strong">Letztes Update: <span id="lastUpdated">—</span></small>
        <div class="btn-group btn-group-touch" role="group">
            <button class="btn btn-outline-secondary btn-icon" id="manualRefresh" title="Neu laden"><i class="bi bi-arrow-clockwise"></i></button>
            <a href="register_client.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Neuer Kunde</a>
        </div>
    </div>

    <!-- Modern Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="clientsTable" class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width: 60px;"></th>
                            <th>Name / Firma</th>
                            <th>Kontakt</th>
                            <th class="text-end">Rechnungen</th>
                            <th class="text-end">Umsatz</th>
                            <th class="text-end">Offen</th>
                            <th>Letzte Rechnung</th>
                            <th style="width: 200px;">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clients as $client): ?>
                            <tr>
                                <td>
                                    <a href="client-profile.php?id=<?= (int)$client['id'] ?>" class="text-decoration-none d-inline-block">
                                        <div class="avatar-circle">
                                            <?= strtoupper(substr($client['firma'] ?: $client['name'] ?: 'K', 0, 1)) ?>
                                        </div>
                                    </a>
                                </td>
                                <td>
                                    <a href="client-profile.php?id=<?= (int)$client['id'] ?>" class="d-inline-block text-decoration-none text-reset">
                                        <div>
                                            <strong><?= esc($client['firma'] ?: $client['name']) ?></strong>
                                            <?php if ($client['firma'] && $client['name']): ?>
                                                <br><small class="text-muted"><?= esc($client['name']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                </td>
                                <td>
                                    <div>
                                        <i class="bi bi-envelope text-muted me-1"></i>
                                        <?= esc($client['email']) ?><br>
                                        <?php if ($client['telefon']): ?>
                                            <i class="bi bi-phone text-muted me-1"></i>
                                            <small><?= esc($client['telefon']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <strong><?= $client['total_rechnungen'] ?></strong>
                                    <?php if ($client['open_rechnungen'] > 0): ?>
                                        <br><small class="text-warning"><?= $client['open_rechnungen'] ?> offen</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <strong><?= number_format($client['paid_amount'], 2, ',', '.') ?> €</strong>
                                </td>
                                <td class="text-end">
                                    <?php if ($client['open_amount'] > 0): ?>
                                        <strong class="text-warning">
                                            <?= number_format((float)$client['open_amount'], 2, ',', '.') ?>
                                        </strong>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($client['last_invoice_date']): ?>
                                        <?= formatDate($client['last_invoice_date']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">Keine Rechnungen</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex">
                                        <button class="quick-action-btn action-view"
                                                onclick="openQuickView(<?= $client['id'] ?>)"
                                                title="Schnellansicht">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <a href="edit_client.php?id=<?= $client['id'] ?>"
                                           class="quick-action-btn action-edit"
                                           title="Bearbeiten">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="rechnung.php?client_id=<?= $client['id'] ?>"
                                           class="quick-action-btn action-invoice"
                                           title="Neue Rechnung">
                                            <i class="bi bi-receipt"></i>
                                        </a>
                                        <button class="quick-action-btn action-email"
                                                onclick="sendEmail(<?= $client['id'] ?>)"
                                                title="E-Mail">
                                            <i class="bi bi-envelope"></i>
                                        </button>
                                        <button class="quick-action-btn action-delete"
                                                onclick="deleteClient(<?= $client['id'] ?>)"
                                                title="Löschen">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- QuickView Modal -->
    <div class="modal fade" id="quickViewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-person me-2"></i>
                        Kunden Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="quickViewContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

    <script>
        // Helpers
        function formatCurrency(value) {
            const num = Number(value || 0);
            return num.toLocaleString('de-AT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' \u20AC';
        }
        function formatDateStr(value) {
            if (!value) return '<span class="text-muted">Keine Rechnungen</span>';
            const d = new Date(value);
            if (isNaN(d.getTime())) return value;
            return d.toLocaleDateString('de-AT');
        }
        function setText(id, text) {
            const el = document.getElementById(id);
            if (el) el.textContent = text;
        }
        function updateStats(stats, serverTime) {
            if (stats) {
                setText('stat-total', stats.total ?? 0);
                setText('stat-with-invoices', stats.with_invoices ?? 0);
                setText('stat-with-open', stats.with_open ?? 0);
                // Optional: only update if element exists
                setText('stat-total-revenue', (stats.total_revenue || 0).toLocaleString('de-AT') + ' \u20AC');
                setText('stat-open-amount', (stats.open_amount || 0).toLocaleString('de-AT') + ' \u20AC');
            }
            setText('lastUpdated', (serverTime || new Date().toLocaleString('de-AT')));
        }

        // Initialize DataTable with AJAX source (realtime)
        let dt;
        $(document).ready(function() {
            dt = $('#clientsTable').DataTable({
                ajax: {
                    url: 'api/clients_list.php',
                    data: function(d) {
                        // You can map DataTables search to q if desired
                        const val = $('#clientsTable_filter input').val();
                        if (val) d.q = val;
                        d.include_stats = 1;
                        d.per_page = d.length || 25; // page size from DataTables
                        d.page = Math.floor((d.start || 0) / (d.length || 25)) + 1;
                        // sort mapping (first order only)
                        if (Array.isArray(d.order) && d.order.length) {
                            const idx = d.order[0].column;
                            const dir = d.order[0].dir;
                            const map = { 0:'id',1:'firma',2:'email',3:'total_rechnungen',4:'paid_amount',5:'open_amount',6:'last_invoice_date' };
                            d.sort_by = map[idx] || 'id';
                            d.sort_dir = (dir === 'asc') ? 'asc' : 'desc';
                        }
                    },
                    dataSrc: function(json) {
                        if (!json || json.success === false) {
                            showNotification('Fehler beim Laden der Kundenliste', 'danger');
                            return [];
                        }
                        updateStats(json.stats, json.server_time);
                        return json.data || [];
                    }
                },
                processing: true,
                serverSide: false,
                deferRender: true,
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/de-DE.json' },
                order: [[1, 'asc']],
                pageLength: 25,
                columns: [
                    { data: null, orderable: false, render: function(row){
                        const initial = String((row.firma || row.name || 'K')).substring(0,1).toUpperCase();
                        return `<div class="client-avatar">${initial}</div>`;
                    }},
                    { data: null, render: function(row){
                        const title = (row.firma || row.name || '').toString();
                        const sub = (row.firma && row.name) ? `<br><small class=\"text-muted\">${row.name}</small>` : '';
                        return `<div><strong>${$('<div>').text(title).html()}</strong>${sub}</div>`;
                    }},
                    { data: null, render: function(row){
                        const email = row.email ? $('<div>').text(row.email).html() : '';
                        const tel = row.telefon ? `<br><small>${$('<div>').text(row.telefon).html()}</small>` : '';
                        return `<div><i class=\"bi bi-envelope text-muted me-1\"></i>${email}${tel}</div>`;
                    }},
                    { data: null, className:'text-end', render: function(row){
                        const total = Number(row.total_rechnungen || 0);
                        const open = Number(row.open_rechnungen || 0);
                        const openStr = open>0 ? `<br><small class=\"text-warning\">${open} offen</small>` : '';
                        return `<strong>${total}</strong>${openStr}`;
                    }},
                    { data: 'paid_amount', className:'text-end', render: function(val){
                        return `<strong>${formatCurrency(val)}</strong>`;
                    }},
                    { data: 'open_amount', className:'text-end', render: function(val){
                        const num = Number(val || 0);
                        return num>0 ? `<strong class=\"text-warning\">${num.toLocaleString('de-AT', {minimumFractionDigits:2, maximumFractionDigits:2})}</strong>` : '<span class="text-muted">—</span>';
                    }},
                    { data: 'last_invoice_date', render: function(val){
                        return formatDateStr(val);
                    }},
                    { data: null, orderable:false, render: function(row){
                        const id = Number(row.id);
                        return `
                        <div class=\"d-flex\">
                            <button class=\"quick-action-btn action-view\" onclick=\"openQuickView(${id})\" title=\"Schnellansicht\"><i class=\"bi bi-eye\"></i></button>
                            <a href=\"edit_client.php?id=${id}\" class=\"quick-action-btn action-edit\" title=\"Bearbeiten\"><i class=\"bi bi-pencil\"></i></a>
                            <a href=\"rechnung.php?client_id=${id}\" class=\"quick-action-btn action-invoice\" title=\"Neue Rechnung\"><i class=\"bi bi-receipt\"></i></a>
                            <button class=\"quick-action-btn action-email\" onclick=\"sendEmail(${id})\" title=\"E-Mail\"><i class=\"bi bi-envelope\"></i></button>
                            <button class=\"quick-action-btn action-delete\" onclick=\"deleteClient(${id})\" title=\"Löschen\"><i class=\"bi bi-trash\"></i></button>
                        </div>`;
                    }}
                ],
                columnDefs: [ { orderable: false, targets: [0, 7] } ]
            });

            // Manual refresh
            $('#manualRefresh').on('click', function(){ dt.ajax.reload(null, false); });

            // Auto-refresh every 20s
            setInterval(function(){ dt.ajax.reload(null, false); }, 20000);
        });

        function openQuickView(id) {
            const modalEl = document.getElementById('quickViewModal');
            const modal = new bootstrap.Modal(modalEl);
            const contentEl = document.getElementById('quickViewContent');

            contentEl.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Laden...</span></div>
                </div>
            `;
            modal.show();

            fetch(`api/client_quickview.php?id=${encodeURIComponent(id)}`, { credentials: 'same-origin' })
                .then(r => {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.text();
                })
                .then(html => {
                    contentEl.innerHTML = html;
                })
                .catch(err => {
                    console.error(err);
                    contentEl.innerHTML = '<div class="alert alert-danger">Fehler beim Laden der Schnellansicht.</div>';
                });
        }

        function sendEmail(id) {
            showNotification('E-Mail-Fenster wird geöffnet...', 'info');
        }

        function deleteClient(id) {
            if (!confirm('Diesen Kunden wirklich löschen? Alle zugehörigen Rechnungen werden ebenfalls gelöscht!')) return;

            showNotification('Kunde wird gelöscht...', 'info');

            fetch('api/client_delete.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ id: id })
            })
                .then(async (response) => {
                    if (!response.ok) {
                        const text = await response.text();
                        throw new Error(`HTTP ${response.status}: ${text}`);
                    }
                    return response.json();
                })
                .then((data) => {
                    if (data && data.success) {
                        showNotification('Kunde gelöscht!', 'success');
                        // Better UX with DataTables:
                        if (window.dt) {
                            dt.ajax.reload(null, false);
                        } else {
                            location.reload();
                        }
                    } else {
                        showNotification((data && data.error) ? data.error : 'Fehler beim Löschen', 'danger');
                    }
                })
                .catch((err) => {
                    console.error(err);
                    showNotification('Löschen fehlgeschlagen (Details in der Konsole).', 'danger');
                });
        }

        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `alert alert-${type} position-fixed`;
            notification.style.top = '20px';
            notification.style.right = '20px';
            notification.style.zIndex = '9999';
            notification.textContent = message;
            document.body.appendChild(notification);

            setTimeout(() => notification.remove(), 3000);
        }
    </script>
    <script>
    // PDF Export for Clients
    (function(){
      const btn = document.getElementById('export-pdf');
      if(!btn) return;
      btn.addEventListener('click', function(){
        const params = new URLSearchParams();
        params.set('type', 'clients');
        // No built-in filters on this page aside from search in table (server-rendered), so just export all
        window.open('/pages/api/export_pdf.php?' + params.toString(), '_blank');
      });
    })();
    </script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
