<?php
// pages/kundenprofil.php — Kundenprofil: Alle Geschäftsdaten zu einem Kunden


declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
global $pdo;
require_once __DIR__ . '/../config/db.php';
include_once __DIR__ . '/../includes/header.php';

function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fmt_date($v): string {
    if ($v === null || $v === '') return '';
    $ts = strtotime((string)$v);
    if ($ts === false) return esc((string)$v);
    return date('Y-m-d', $ts);
}
function fmt_money($v): string {
    if ($v === null || $v === '') return '';
    $n = (float)$v;
    return number_format($n, 2, ',', '.') . ' €';
}
function norm_status($s): string {
    $s = strtolower(trim((string)$s));
    if ($s === 'open' || $s === 'offen') return 'offen';
    if (in_array($s, ['delivered','geliefert','done','erledigt'], true)) return 'geliefert';
    if (in_array($s, ['cancelled','canceled','storniert'], true)) return 'storniert';
    return $s;
}

$clientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($clientId <= 0) {
    echo "<div class='container mt-4'><div class='alert alert-warning'>Keine gültige Kunden-ID übergeben.</div></div>";
    include_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Client laden
try {
    $stmt = $pdo->prepare("SELECT id, kundennummer, name, firma, firmenname, email, telefon, adresse, plz, ort, atu FROM clients WHERE id = ? LIMIT 1");
    $stmt->execute([$clientId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $client = null;
}

if (!$client) {
    echo "<div class='container mt-4'><div class='alert alert-danger'>Kunde nicht gefunden.</div></div>";
    include_once __DIR__ . '/../includes/footer.php';
    exit;
}

$displayName = trim(($client['firmenname'] ?? '') !== '' ? (string)$client['firmenname'] : ((string)($client['firma'] ?? '')));
if ($displayName === '') { $displayName = (string)($client['name'] ?? ''); }

// Angebote laden
$angebote = [];
try {
    $stmt = $pdo->prepare("SELECT id, client_id, angebotsnummer, datum, gueltig_bis, status, betrag, pdf_path FROM angebote WHERE client_id = ? ORDER BY COALESCE(datum, '1970-01-01') DESC, id DESC");
    $stmt->execute([$clientId]);
    $angebote = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $angebote = []; }

// Angebot-Positionen je Angebot vorbereiten (lazy via map of angebot_id => rows)
$angebotPositionen = [];
if ($angebote) {
    $ids = array_map(fn($r) => (int)$r['id'], $angebote);
    // Use IN query if many
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $sql = "SELECT ap.angebot_id, ap.produkt_id, ap.menge, ap.einzelpreis, ap.gesamt, p.name AS produkt_name
                FROM angebot_positionen ap
                LEFT JOIN produkte p ON p.id = ap.produkt_id
                WHERE ap.angebot_id IN ($placeholders)
                ORDER BY ap.angebot_id ASC";
        $st = $pdo->prepare($sql);
        $st->execute($ids);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $aid = (int)$row['angebot_id'];
            if (!isset($angebotPositionen[$aid])) $angebotPositionen[$aid] = [];
            $angebotPositionen[$aid][] = $row;
        }
    } catch (Throwable $e) { /* ignore */ }
}

// Lieferscheine laden
$lieferscheine = [];
try {
    $stmt = $pdo->prepare("SELECT id, nummer, datum, status, bemerkung FROM lieferscheine WHERE client_id = ? ORDER BY COALESCE(datum, '1970-01-01') DESC, id DESC");
    $stmt->execute([$clientId]);
    $lieferscheine = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $lieferscheine = []; }

// Rechnungen laden

// Rechnungen laden
$rechnungen = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            r.id,
            MAX(r.client_id)        AS client_id,
            MAX(r.rechnungsnummer) AS rechnungsnummer,
            MAX(r.datum)            AS datum,
            MAX(r.faelligkeit)      AS faelligkeit,
            MAX(r.status)           AS status,
            MAX(r.gesamt)           AS gesamt,
            MAX(r.pdf_path)         AS pdf_path,
            COALESCE(SUM(rp.gesamt), 0) AS gesamt_calc
        FROM rechnungen r
        LEFT JOIN rechnungs_positionen rp ON rp.rechnung_id = r.id
        WHERE r.client_id = ?
        GROUP BY r.id
        ORDER BY COALESCE(MAX(r.datum), '1970-01-01') DESC, r.id DESC
    ");
    $stmt->execute([$clientId]);
    $rechnungen = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $rechnungen = []; }

// Mahnungen laden (über Rechnungen)
$mahnungen = [];
try {
    $sql = "SELECT m.id, m.rechnung_id, m.stufe, m.datum, m.total_due, m.days_overdue, m.pdf_path,
                   r.rechnungsnummer, r.client_id
            FROM mahnungen m
            INNER JOIN rechnungen r ON r.id = m.rechnung_id
            WHERE r.client_id = ?
            ORDER BY COALESCE(m.datum, '1970-01-01') DESC, m.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$clientId]);
    $mahnungen = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $mahnungen = []; }

?>
<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0 text-primary">Kundenprofil</h2>
        <div>
            <a href="/pages/clients.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Zur Liste</a>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <h4 class="mb-1"><?= esc($displayName ?: '—') ?></h4>
                    <div><strong>Kundennummer:</strong> <?= esc($client['kundennummer'] ?? '') ?></div>
                    <div><strong>ATU:</strong> <?= esc($client['atu'] ?? '') ?></div>
                </div>
                <div class="col-md-6">
                    <div><?= esc($client['adresse'] ?? '') ?></div>
                    <div><?= esc(($client['plz'] ?? '') . ' ' . ($client['ort'] ?? '')) ?></div>
                    <div><strong>Email:</strong> <a href="mailto:<?= esc($client['email'] ?? '') ?>"><?= esc($client['email'] ?? '') ?></a></div>
                    <div><strong>Telefon:</strong> <?= esc($client['telefon'] ?? '') ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Angebote -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light fw-bold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-file-earmark-text me-2"></i>Angebote</span>
            <span class="text-muted small"><?= count($angebote) ?> Einträge</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0 align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Nummer</th>
                        <th>Datum</th>
                        <th>Gültig bis</th>
                        <th>Status</th>
                        <th class="text-end">Betrag</th>
                        <th>PDF</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$angebote): ?>
                        <tr><td colspan="6" class="text-center text-muted">Keine Angebote vorhanden.</td></tr>
                    <?php else: foreach ($angebote as $a): ?>
                        <?php $aid = (int)$a['id']; $pos = $angebotPositionen[$aid] ?? []; ?>
                        <tr>
                            <td>
                                <button class="btn btn-sm btn-outline-secondary me-1" type="button" data-bs-toggle="collapse" data-bs-target="#pos-<?= $aid ?>" aria-expanded="false" aria-controls="pos-<?= $aid ?>">
                                    <i class="bi bi-caret-down-square"></i>
                                </button>
                                <?= esc($a['angebotsnummer'] ?? ('ANG-' . $aid)) ?>
                            </td>
                            <td><?= esc(fmt_date($a['datum'] ?? '')) ?></td>
                            <td><?= esc(fmt_date($a['gueltig_bis'] ?? '')) ?></td>
                            <td><?= esc($a['status'] ?? '') ?></td>
                            <td class="text-end"><?= esc(fmt_money($a['betrag'] ?? 0)) ?></td>
                            <td>
                                <?php $pdf = $a['pdf_path'] ?? null; $pdfUrl = $pdf ? (str_starts_with((string)$pdf, '/') ? $pdf : '/uploads/' . ltrim((string)$pdf, '/')) : null; ?>
                                <?php if ($pdfUrl): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= esc($pdfUrl) ?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr class="collapse bg-body-tertiary" id="pos-<?= $aid ?>">
                            <td colspan="6">
                                <?php if (!$pos): ?>
                                    <div class="text-muted">Keine Positionen vorhanden.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered mb-0">
                                            <thead>
                                            <tr>
                                                <th>Produkt</th>
                                                <th class="text-end">Menge</th>
                                                <th class="text-end">Einzelpreis</th>
                                                <th class="text-end">Betrag</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($pos as $p): ?>
                                                <tr>
                                                    <td><?= esc($p['produkt_name'] ?? ($p['produkt_id'] ? ('Produkt #' . (int)$p['produkt_id']) : '')) ?></td>
                                                    <td class="text-end"><?= esc((string)($p['menge'] ?? '')) ?></td>
                                                    <td class="text-end"><?= esc(fmt_money($p['einzelpreis'] ?? 0)) ?></td>
                                                    <td class="text-end"><?= esc(fmt_money($p['gesamt'] ?? 0)) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Lieferscheine -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light fw-bold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-truck me-2"></i>Lieferscheine</span>
            <span class="text-muted small"><?= count($lieferscheine) ?> Einträge</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0 align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Nummer</th>
                        <th>Datum</th>
                        <th>Status</th>
                        <th>Bemerkung</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$lieferscheine): ?>
                        <tr><td colspan="4" class="text-center text-muted">Keine Lieferscheine vorhanden.</td></tr>
                    <?php else: foreach ($lieferscheine as $ls): ?>
                        <tr>
                            <td><?= esc($ls['nummer'] ?? ('LS-' . (int)$ls['id'])) ?></td>
                            <td><?= esc(fmt_date($ls['datum'] ?? '')) ?></td>
                            <td><?= esc(norm_status($ls['status'] ?? '')) ?></td>
                            <td><?= esc($ls['bemerkung'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Rechnungen -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light fw-bold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-receipt me-2"></i>Rechnungen</span>
            <span class="text-muted small"><?= count($rechnungen) ?> Einträge</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0 align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Rechnungsnummer</th>
                        <th>Datum</th>
                        <th>Fälligkeit</th>
                        <th>Status</th>
                        <th class="text-end">Gesamt</th>
                        <th>PDF</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rechnungen): ?>
                        <tr><td colspan="6" class="text-center text-muted">Keine Rechnungen vorhanden.</td></tr>
                    <?php else: foreach ($rechnungen as $r): ?>
                        <tr>
                            <td><?= esc($r['rechnungsnummer'] ?? ('RE-' . (int)$r['id'])) ?></td>
                            <td><?= esc(fmt_date($r['datum'] ?? '')) ?></td>
                            <td><?= esc(fmt_date($r['faelligkeit'] ?? '')) ?></td>
                            <td><?= esc($r['status'] ?? '') ?></td>
                            <?php $realAmt = (isset($r['gesamt_calc']) && is_numeric($r['gesamt_calc']) && (float)$r['gesamt_calc'] > 0)
                                ? (float)$r['gesamt_calc'] : (float)($r['gesamt'] ?? 0); ?>
                            <td class="text-end"><?= esc(fmt_money($realAmt)) ?></td>
                            <td>
                                <?php $pdf = $r['pdf_path'] ?? null; $pdfUrl = $pdf ? (str_starts_with((string)$pdf, '/') ? $pdf : '/uploads/' . ltrim((string)$pdf, '/')) : null; ?>
                                <?php if ($pdfUrl): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= esc($pdfUrl) ?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Mahnungen -->
    <div class="card shadow-sm mb-5">
        <div class="card-header bg-light fw-bold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-exclamation-triangle me-2"></i>Mahnungen</span>
            <span class="text-muted small"><?= count($mahnungen) ?> Einträge</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0 align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Mahnstufe</th>
                        <th>Rechnungsnummer</th>
                        <th>Datum</th>
                        <th class="text-end">Tage überfällig</th>
                        <th class="text-end">Gesamtbetrag offen</th>
                        <th>PDF</th>
                        <th>E-Mail Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$mahnungen): ?>
                        <tr><td colspan="7" class="text-center text-muted">Keine Mahnungen vorhanden.</td></tr>
                    <?php else: foreach ($mahnungen as $m): ?>
                        <tr>
                            <td><?= esc((string)($m['stufe'] ?? '')) ?></td>
                            <td><?= esc($m['rechnungsnummer'] ?? ('RE-' . (int)$m['rechnung_id'])) ?></td>
                            <td><?= esc(fmt_date($m['datum'] ?? '')) ?></td>
                            <td class="text-end"><?= esc((string)($m['days_overdue'] ?? '')) ?></td>
                            <td class="text-end"><?= esc(fmt_money($m['total_due'] ?? 0)) ?></td>
                            <td>
                                <?php $pdf = $m['pdf_path'] ?? null; $pdfUrl = $pdf ? (str_starts_with((string)$pdf, '/') ? $pdf : '/uploads/' . ltrim((string)$pdf, '/')) : null; ?>
                                <?php if ($pdfUrl): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= esc($pdfUrl) ?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php // Try to detect an email status if present (optional column)
                                $emailStatus = $m['email_status'] ?? ($m['sent_status'] ?? null);
                                echo $emailStatus !== null && $emailStatus !== '' ? esc((string)$emailStatus) : '<span class="text-muted">—</span>';
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
