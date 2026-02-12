<?php
// pages/client-profile.php — Modern Kundenprofil

declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/init.php';

global $pdo;

function esc($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$clientId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

// Basic validation
if ($clientId <= 0) {
    http_response_code(400);
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<title>Ungültige Anfrage</title></head><body class="bg-light">';
    echo '<div class="container py-5"><div class="alert alert-warning">Keine gültige Kunden-ID übergeben.</div>';
    echo '<a class="btn btn-primary" href="clients-list.php"><i class="bi bi-people me-1"></i> Zur Kundenliste</a></div>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>';
    echo '</body></html>';
    exit;
}

// Load client from DB
$client = null;
try {
    $stmt = $pdo->prepare('SELECT id, kundennummer, firma, firmenname, name, email, telefon, adresse, plz, ort, atu FROM clients WHERE id = ? LIMIT 1');
    $stmt->execute([$clientId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $client = null;
}

if (!$client) {
    http_response_code(404);
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<title>Kunde nicht gefunden</title></head><body class="bg-light">';
    echo '<div class="container py-5"><div class="alert alert-danger">Kunde nicht gefunden.</div>';
    echo '<a class="btn btn-secondary" href="clients-list.php"><i class="bi bi-arrow-left me-1"></i> Zurück</a></div>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>';
    echo '</body></html>';
    exit;
}

$displayName = trim((string)($client['firmenname'] ?? ''));
if ($displayName === '') {
    $displayName = trim((string)($client['firma'] ?? ''));
}
if ($displayName === '') {
    $displayName = trim((string)($client['name'] ?? ''));
}

$kundennummer = (string)($client['kundennummer'] ?? '');
$email = (string)($client['email'] ?? '');
$telefon = (string)($client['telefon'] ?? '');
$atu = (string)($client['atu'] ?? '');
$adresse = (string)($client['adresse'] ?? '');
$plz = (string)($client['plz'] ?? '');
$ort = (string)($client['ort'] ?? '');

?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= esc($displayName) ?> — Kundenprofil</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #6f42c1 0%, #e83e8c 100%);
            --card-shadow: 0 4px 20px rgba(0,0,0,0.08);
            --border-radius: 12px;
        }
        body { background: #f8f9fa; font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
        .header-card {
            background: var(--primary-gradient);
            color: #fff;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
        }
        .avatar-xl {
            width: 64px; height: 64px;
            border-radius: 50%;
            background: rgba(255,255,255,0.15);
            display: inline-flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1.5rem;
        }
        .content-card { border: 0; border-radius: var(--border-radius); box-shadow: var(--card-shadow); }
        .muted { color: #6c757d; }
        .section-title { font-weight: 600; font-size: 0.95rem; letter-spacing: .02em; color: #6c757d; text-transform: uppercase; }
        .action-bar .btn { border-radius: 10px; }
    </style>
</head>
<body>
<div class="container-fluid px-4 py-4">
    <div class="header-card p-4 mb-4">
        <div class="d-flex align-items-center gap-3">
            <div class="avatar-xl">
                <?= esc(strtoupper(substr($displayName !== '' ? $displayName : ($email !== '' ? $email : 'K'), 0, 1))) ?>
            </div>
            <div class="flex-grow-1">
                <h1 class="h3 mb-1"><?= esc($displayName !== '' ? $displayName : 'Unbenannter Kunde') ?></h1>
                <div class="d-flex flex-wrap gap-3 align-items-center">
                    <span class="badge bg-light text-dark">
                        <i class="bi bi-hash me-1"></i> Kundennummer: <?= esc($kundennummer !== '' ? $kundennummer : '—') ?>
                    </span>
                    <?php if ($atu !== ''): ?>
                        <span class="badge bg-light text-dark"><i class="bi bi-building me-1"></i> ATU: <?= esc($atu) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="action-bar">
                <a href="rechnung.php?client_id=<?= (int)$client['id'] ?>" class="btn btn-light me-2"><i class="bi bi-receipt me-1"></i> Neue Rechnung</a>
                <a href="angebot.php?client_id=<?= (int)$client['id'] ?>" class="btn btn-warning me-2"><i class="bi bi-file-earmark-text me-1"></i> Neues Angebot</a>
                <a href="lieferschein.php?client_id=<?= (int)$client['id'] ?>" class="btn btn-outline-light"><i class="bi bi-truck me-1"></i> Neuer Lieferschein</a>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12 col-lg-6">
            <div class="card content-card h-100">
                <div class="card-body">
                    <div class="section-title mb-3">Kontakt</div>
                    <div class="mb-2">
                        <div class="muted small">E-Mail</div>
                        <div><?php if ($email !== ''): ?><a href="mailto:<?= esc($email) ?>"><?= esc($email) ?></a><?php else: ?>—<?php endif; ?></div>
                    </div>
                    <div class="mb-2">
                        <div class="muted small">Telefon</div>
                        <div><?= $telefon !== '' ? esc($telefon) : '—' ?></div>
                    </div>
                    <div class="mb-0">
                        <div class="muted small">ATU Nummer</div>
                        <div><?= $atu !== '' ? esc($atu) : '—' ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card content-card h-100">
                <div class="card-body">
                    <div class="section-title mb-3">Adresse</div>
                    <div class="mb-2">
                        <div class="muted small">Straße</div>
                        <div><?= $adresse !== '' ? esc($adresse) : '—' ?></div>
                    </div>
                    <div class="row">
                        <div class="col-sm-5 mb-2">
                            <div class="muted small">PLZ</div>
                            <div><?= $plz !== '' ? esc($plz) : '—' ?></div>
                        </div>
                        <div class="col-sm-7 mb-2">
                            <div class="muted small">Ort</div>
                            <div><?= $ort !== '' ? esc($ort) : '—' ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4 d-flex justify-content-between align-items-center">
        <a href="clients-list.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Zur Kundenliste</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
