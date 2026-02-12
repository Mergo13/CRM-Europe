<?php
// pages/api/client_quickview.php
// Returns an HTML snippet for the QuickView modal with client details and recent history

declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../../config/db.php';

global $pdo;

function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fmt_date($v): string {
    if (!$v) return '—';
    $ts = strtotime((string)$v);
    if ($ts === false) return esc((string)$v);
    return date('d.m.Y', $ts);
}
function fmt_money($v): string {
    $n = (float)($v ?? 0);
    return number_format($n, 2, ',', '.') . ' €';
}

$id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo '<div class="alert alert-warning">Ungültige Kunden-ID.</div>';
    exit;
}

// Load client
try {
    $stmt = $pdo->prepare("SELECT id, kundennummer, firma, firmenname, name, email, telefon, adresse, plz, ort, atu FROM clients WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { $client = null; }

if (!$client) {
    http_response_code(404);
    echo '<div class="alert alert-danger">Kunde nicht gefunden.</div>';
    exit;
}

$displayName = trim((string)($client['firmenname'] ?? ''));
if ($displayName === '') $displayName = trim((string)($client['firma'] ?? ''));
if ($displayName === '') $displayName = trim((string)($client['name'] ?? ''));

// Load recent history (limit to keep modal concise)
$rechnungen = $angebote = $lieferscheine = [];

try {
    $stmt = $pdo->prepare("SELECT id, rechnungsnummer, datum, faelligkeit, status, COALESCE(gesamt, betrag, total) AS betrag, pdf_path FROM rechnungen WHERE client_id = ? ORDER BY COALESCE(datum,'1970-01-01') DESC, id DESC LIMIT 10");
    $stmt->execute([$id]);
    $rechnungen = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $rechnungen = []; }

try {
    $stmt = $pdo->prepare("SELECT id, angebotsnummer, datum, gueltig_bis, status, betrag, pdf_path FROM angebote WHERE client_id = ? ORDER BY COALESCE(datum,'1970-01-01') DESC, id DESC LIMIT 10");
    $stmt->execute([$id]);
    $angebote = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $angebote = []; }

try {
    $stmt = $pdo->prepare("SELECT id, nummer, datum, status FROM lieferscheine WHERE client_id = ? ORDER BY COALESCE(datum,'1970-01-01') DESC, id DESC LIMIT 10");
    $stmt->execute([$id]);
    $lieferscheine = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $lieferscheine = []; }

// Totals / stats (computed across ALL invoices for this client)
$totalRechnungen = 0;
$openRechnungen = 0;
$sumPaid = 0.0;
$sumOpen = 0.0;
try {
    $stmt = $pdo->prepare("SELECT 
        COUNT(*) AS total_count,
        SUM(CASE WHEN LOWER(status) IN ('offen','open') THEN 1 ELSE 0 END) AS open_count,
        COALESCE(SUM(CASE WHEN LOWER(status) IN ('bezahlt','paid','erledigt') THEN COALESCE(gesamt, betrag, total) ELSE 0 END),0) AS paid_sum,
        COALESCE(SUM(CASE WHEN LOWER(status) IN ('offen','open') THEN COALESCE(gesamt, betrag, total) ELSE 0 END),0) AS open_sum
        FROM rechnungen WHERE client_id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($row) {
        $totalRechnungen = (int)$row['total_count'];
        $openRechnungen = (int)$row['open_count'];
        $sumPaid = (float)$row['paid_sum'];
        $sumOpen = (float)$row['open_sum'];
    }
} catch (Throwable $e) {
    // Fallback: infer from the limited recent list (best-effort)
    $totalRechnungen = count($rechnungen);
    foreach ($rechnungen as $r) {
        $status = strtolower(trim((string)($r['status'] ?? '')));
        $amount = (float)($r['betrag'] ?? 0);
        if (in_array($status, ['bezahlt','paid','erledigt'])) $sumPaid += $amount; else $sumOpen += $amount;
        if (in_array($status, ['offen','open'])) $openRechnungen++;
    }
}

// HTML snippet
?>
<div class="container-fluid">
  <div class="row g-3">
    <div class="col-12">
      <div class="d-flex align-items-center gap-3">
        <div style="width:48px;height:48px;border-radius:50%;background:#6f42c1;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;">
          <?= esc(strtoupper(substr($displayName !== '' ? $displayName : ($client['email'] ?? 'K'), 0, 1))) ?>
        </div>
        <div class="flex-grow-1">
          <div class="h5 mb-0"><?= esc($displayName !== '' ? $displayName : 'Unbenannter Kunde') ?></div>
          <div class="text-muted small">Kundennummer: <?= esc($client['kundennummer'] ?? '—') ?></div>
        </div>
        <div class="text-nowrap">
          <a class="btn btn-sm btn-primary" href="/pages/kundenprofil.php?id=<?= (int)$client['id'] ?>"><i class="bi bi-person-badge me-1"></i> Vollständiges Profil</a>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6">
      <div class="card">
        <div class="card-body">
          <div class="fw-semibold text-uppercase text-muted small mb-2">Kontakt</div>
          <div class="mb-2"><span class="text-muted small">E-Mail</span><div><?= !empty($client['email']) ? '<a href="mailto:' . esc($client['email']) . '">' . esc($client['email']) . '</a>' : '—' ?></div></div>
          <div class="mb-2"><span class="text-muted small">Telefon</span><div><?= !empty($client['telefon']) ? esc($client['telefon']) : '—' ?></div></div>
          <div class="mb-0"><span class="text-muted small">ATU</span><div><?= !empty($client['atu']) ? esc($client['atu']) : '—' ?></div></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6">
      <div class="card">
        <div class="card-body">
          <div class="fw-semibold text-uppercase text-muted small mb-2">Adresse</div>
          <div class="mb-2"><span class="text-muted small">Straße</span><div><?= !empty($client['adresse']) ? esc($client['adresse']) : '—' ?></div></div>
          <div class="row">
            <div class="col-5"><span class="text-muted small">PLZ</span><div><?= !empty($client['plz']) ? esc($client['plz']) : '—' ?></div></div>
            <div class="col-7"><span class="text-muted small">Ort</span><div><?= !empty($client['ort']) ? esc($client['ort']) : '—' ?></div></div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="fw-semibold text-uppercase text-muted small">Rechnungen (neueste 10)</div>
            <div class="small text-muted">Gesamt: <?= (int)$totalRechnungen ?> | Offen: <?= (int)$openRechnungen ?> | Bezahlt: <?= esc(fmt_money($sumPaid)) ?> | Offen Betrag: <?= esc(fmt_money($sumOpen)) ?></div>
          </div>
          <?php if (!$rechnungen): ?>
            <div class="text-muted">Keine Rechnungen vorhanden.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>#</th><th>Datum</th><th>Fällig</th><th>Status</th><th class="text-end">Betrag</th></tr></thead>
                <tbody>
                  <?php foreach ($rechnungen as $r): ?>
                    <tr>
                      <?php $pdf = $r['pdf_path'] ?? null; $pdfUrl = $pdf ? (str_starts_with((string)$pdf, '/') ? $pdf : '/' . ltrim((string)$pdf, '/')) : '/pages/rechnung_pdf.php?id=' . (int)$r['id']; ?>
                      <td><a href="<?= esc($pdfUrl) ?>" class="text-decoration-none" target="_blank" rel="noopener"><?= esc($r['rechnungsnummer'] ?? ('R' . (int)$r['id'])) ?></a></td>
                      <td><?= esc(fmt_date($r['datum'] ?? '')) ?></td>
                      <td><?= esc(fmt_date($r['faelligkeit'] ?? '')) ?></td>
                      <td><?= esc($r['status'] ?? '') ?></td>
                      <td class="text-end"><?= esc(fmt_money($r['betrag'] ?? 0)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card">
        <div class="card-body">
          <div class="fw-semibold text-uppercase text-muted small mb-2">Angebote (neueste 10)</div>
          <?php if (!$angebote): ?>
            <div class="text-muted">Keine Angebote vorhanden.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>#</th><th>Datum</th><th>Gültig bis</th><th>Status</th><th class="text-end">Betrag</th></tr></thead>
                <tbody>
                  <?php foreach ($angebote as $a): ?>
                    <tr>
                      <?php $pdf = $a['pdf_path'] ?? null; $pdfUrl = $pdf ? (str_starts_with((string)$pdf, '/') ? $pdf : '/' . ltrim((string)$pdf, '/')) : '/pages/angebot_pdf.php?id=' . (int)$a['id']; ?>
                      <td><a href="<?= esc($pdfUrl) ?>" class="text-decoration-none" target="_blank" rel="noopener"><?= esc($a['angebotsnummer'] ?? ('A' . (int)$a['id'])) ?></a></td>
                      <td><?= esc(fmt_date($a['datum'] ?? '')) ?></td>
                      <td><?= esc(fmt_date($a['gueltig_bis'] ?? '')) ?></td>
                      <td><?= esc($a['status'] ?? '') ?></td>
                      <td class="text-end"><?= esc(fmt_money($a['betrag'] ?? 0)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card">
        <div class="card-body">
          <div class="fw-semibold text-uppercase text-muted small mb-2">Lieferscheine (neueste 10)</div>
          <?php if (!$lieferscheine): ?>
            <div class="text-muted">Keine Lieferscheine vorhanden.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>#</th><th>Datum</th><th>Status</th></tr></thead>
                <tbody>
                  <?php foreach ($lieferscheine as $l): ?>
                    <tr>
                      <?php $pdfUrl = '/pages/lieferschein_pdf.php?id=' . (int)$l['id']; ?>
                      <td><a href="<?= esc($pdfUrl) ?>" class="text-decoration-none" target="_blank" rel="noopener"><?= esc($l['nummer'] ?? ('L' . (int)$l['id'])) ?></a></td>
                      <td><?= esc(fmt_date($l['datum'] ?? '')) ?></td>
                      <td><?= esc($l['status'] ?? '') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>
</div>
