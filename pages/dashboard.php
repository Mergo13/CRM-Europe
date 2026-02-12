<?php
$pageTitle = 'Dashboard';
require __DIR__ . '/../config/db.php';
include __DIR__ . '/../includes/header.php';

global $pdo;

// Defaults
$todayTotal = 0.0;
$last30Total = 0.0;
$yearTotal  = 0.0;
$openCount = 0;

try {
    // Umsatz heute
    $todayTotal = (float)$pdo->query("
        SELECT COALESCE(SUM(gesamt),0)
        FROM rechnungen
        WHERE datum = CURDATE()
          AND status IN ('offen','bezahlt')
    ")->fetchColumn();

    // Umsatz 30 Tage
    $last30Total = (float)$pdo->query("
        SELECT COALESCE(SUM(gesamt),0)
        FROM rechnungen
        WHERE datum >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          AND status IN ('offen','bezahlt')
    ")->fetchColumn();

    // Umsatz Jahr
    $yearTotal = (float)$pdo->query("
        SELECT COALESCE(SUM(gesamt),0)
        FROM rechnungen
        WHERE YEAR(datum) = YEAR(CURDATE())
          AND status IN ('offen','bezahlt')
    ")->fetchColumn();

    // Offen
    $openCount = (int)$pdo->query("
        SELECT COUNT(*)
        FROM rechnungen
        WHERE status = 'offen'
    ")->fetchColumn();

} catch (Throwable $e) {
    // fail silently on dashboard
}
?>


<div class="container py-4">
<div class="page-title">Dashboard</div>
<div class="page-sub">Magische Übersicht</div>

<!-- KPI CARDS -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="ds-card kpi-card" data-range="today">
            <div class="kpi-label">Umsatz heute</div>
            <div class="kpi-value"><?= number_format($todayTotal, 2, ',', '.') ?> €</div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card-dark kpi-card" data-range="30">
            <div class="kpi-label">30 Tage</div>
            <div class="kpi-value"><?= number_format($last30Total, 2, ',', '.') ?> €</div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card-dark kpi-card" data-range="year">
            <div class="kpi-label">Jahr</div>
            <div class="kpi-value"><?= number_format($yearTotal, 2, ',', '.') ?> €</div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="ds-card">
            <div class="kpi-label">Offen</div>
            <div class="kpi-value"><?= $openCount ?></div>
        </div>
    </div>
</div>

<!-- ACTIONS -->
<div class="mb-4">
    <div class="page-sub mb-2">Aktionen</div>
    <div class="action-grid">
        <div class="action-card" onclick="location.href='rechnung.php?create=1'">
            <div class="action-icon">🧾</div>
            <div class="action-title">Rechnung</div>
            <div class="action-sub">Neu</div>
        </div>

        <div class="action-card" onclick="location.href='angebot.php?create=1'">
            <div class="action-icon">📄</div>
            <div class="action-title">Angebot</div>
            <div class="action-sub">Neu</div>
        </div>

        <div class="action-card" onclick="location.href='lieferschein.php?create=1'">
            <div class="action-icon">🚚</div>
            <div class="action-title">Lieferschein</div>
            <div class="action-sub">Neu</div>
        </div>

        <div class="action-card" onclick="location.href='register_client.php?create=1'">
            <div class="action-icon">👤</div>
            <div class="action-title">Kunde</div>
            <div class="action-sub">Neu</div>
        </div>

        <div class="action-card" onclick="location.href='produkte.php?create=1'">
            <div class="action-icon">📦</div>
            <div class="action-title">Produkt</div>
            <div class="action-sub">Neu</div>
        </div>

        <div class="action-card" onclick="location.href='mahnung.php?create=1'">
            <div class="action-icon">⚠️</div>
            <div class="action-title">Mahnung</div>
            <div class="action-sub">Neu</div>
        </div>
    </div>
</div>

<!-- LISTS -->
<div class="mb-4">
    <div class="page-sub mb-2">Übersichten</div>
    <div class="action-grid">
        <div class="action-card" onclick="location.href='rechnungen-list.php'">
            <div class="action-icon">📚</div>
            <div class="action-title">Rechnungen</div>
        </div>

        <div class="action-card" onclick="location.href='angeboten-list.php'">
            <div class="action-icon">📑</div>
            <div class="action-title">Angebote</div>
        </div>

        <div class="action-card" onclick="location.href='clients-list.php'">
            <div class="action-icon">👥</div>
            <div class="action-title">Kunden</div>
        </div>

        <div class="action-card" onclick="location.href='mahnungen-list.php'">
            <div class="action-icon">⏰</div>
            <div class="action-title">Mahnungen</div>
        </div>

        <div class="action-card" onclick="location.href='lieferschein-list.php'">
            <div class="action-icon">📦</div>
            <div class="action-title">Lieferscheine</div>
        </div>
    </div>
</div>

<!-- AJAX INVOICE LIST -->
<div id="invoiceListWrap" class="mt-4" style="display:none;">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong id="invoiceListTitle"></strong>
            <button class="btn btn-sm btn-outline-secondary" onclick="hideInvoiceList()">✖</button>
        </div>
        <div class="card-body p-0">
            <div id="invoiceListContent" class="table-responsive"></div>
        </div>
    </div>
</div>

<!-- FAB -->
<a href="rechnung.php?create=1" class="fab text-decoration-none">
    <i class="bi bi-plus"></i>
</a>

<script>
    let activeRange = null;

    document.querySelectorAll('.kpi-card').forEach(card => {
        card.addEventListener('click', () => {
            const range = card.dataset.range;
            if (activeRange === range) {
                hideInvoiceList();
                return;
            }
            activeRange = range;
            loadInvoices(range);
        });
    });

    function hideInvoiceList() {
        document.getElementById('invoiceListWrap').style.display = 'none';
        document.getElementById('invoiceListContent').innerHTML = '';
        activeRange = null;
    }

    async function loadInvoices(range) {
        const wrap = document.getElementById('invoiceListWrap');
        const content = document.getElementById('invoiceListContent');
        const title = document.getElementById('invoiceListTitle');

        wrap.style.display = 'block';
        content.innerHTML = '<div class="p-3 text-muted">Lade Rechnungen…</div>';

        title.textContent =
            range === 'today' ? 'Rechnungen – Heute' :
                range === '30'    ? 'Rechnungen – Letzte 30 Tage' :
                    'Rechnungen – Dieses Jahr';

        try {
            const res = await fetch(`/pages/ajax_invoices.php?range=${range}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            content.innerHTML = await res.text();
        } catch {
            content.innerHTML = '<div class="p-3 text-danger">Fehler beim Laden.</div>';
        }
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
