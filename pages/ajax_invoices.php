<?php
require __DIR__ . '/../config/db.php';
global $pdo;
$range = $_GET['range'] ?? 'today';

$where = '';
if ($range === 'today') {
    $where = "datum = CURDATE()";
} elseif ($range === '30') {
    $where = "datum >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
} elseif ($range === 'year') {
    $where = "YEAR(datum) = YEAR(CURDATE())";
} else {
    exit;
}

$stmt = $pdo->query("
    SELECT id, rechnungsnummer, datum, gesamt, status
    FROM rechnungen
    WHERE $where
      AND status IN ('offen','bezahlt')
    ORDER BY datum DESC, id DESC
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo '<div class="p-3 text-muted">Keine Rechnungen gefunden.</div>';
    exit;
}
?>

<table class="table table-sm table-hover mb-0">
    <thead class="table-light">
    <tr>
        <th>Nr.</th>
        <th>Datum</th>
        <th class="text-end">Betrag</th>
        <th>Status</th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['rechnungsnummer']) ?></td>
            <td><?= date('d.m.Y', strtotime($r['datum'])) ?></td>
            <td class="text-end"><?= number_format($r['gesamt'], 2, ',', '.') ?> €</td>
            <td><?= ucfirst($r['status']) ?></td>
            <td class="text-end">
                <a href="/pages/rechnung_pdf.php?id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                    PDF
                </a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
