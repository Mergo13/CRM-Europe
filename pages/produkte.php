<?php
// pages/produkte.php
declare(strict_types=1);

global $pdo;
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../config/db.php';
include '../includes/header.php';

// ---------------- FLASH MESSAGES ----------------
$flash = [];

// ========== Produkt anlegen ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_product'])) {
    $lastId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM produkte")->fetchColumn();
    $artikelnummer = 'P-' . str_pad((string)($lastId + 1), 4, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        INSERT INTO produkte (artikelnummer, name, beschreibung, mwst)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $artikelnummer,
        $_POST['name'],
        $_POST['beschreibung'] ?? null,
        $_POST['mwst'] ?? 20
    ]);

    $flash[] = [
        'type' => 'success',
        'text' => "✅ Produkt <strong>" . htmlspecialchars($_POST['name']) . "</strong> erfolgreich gespeichert!"
    ];
}

// ========== Produkt löschen ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_product'])) {
    $produktId = (int)$_POST['produkt_id'];

    // erst alle Staffelpreise löschen
    $stmt = $pdo->prepare("DELETE FROM produkt_preise WHERE produkt_id = ?");
    $stmt->execute([$produktId]);

    // dann das Produkt selbst
    $stmt = $pdo->prepare("DELETE FROM produkte WHERE id = ?");
    $stmt->execute([$produktId]);

    $flash[] = [
        'type' => 'danger',
        'text' => "🗑 Produkt und zugehörige Preisstaffeln wurden gelöscht."
    ];
}

// ========== Preisstaffel löschen ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_price'])) {
    $stmt = $pdo->prepare("DELETE FROM produkt_preise WHERE id = ?");
    $stmt->execute([ (int)$_POST['preis_id'] ]);

    $flash[] = [
        'type' => 'danger',
        'text' => "❌ Staffelpreis gelöscht."
    ];
}

// ========== Preisstaffel bearbeiten ==========
// ========== Preisstaffel bearbeiten ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_price'])) {
    $preisId    = (int)$_POST['preis_id'];
    $produktId  = (int)($_POST['produkt_id'] ?? 0);
    $menge      = (int)$_POST['menge'];
    $preis      = (float)$_POST['preis'];

    $stmt = $pdo->prepare("UPDATE produkt_preise SET menge = ?, preis = ? WHERE id = ?");
    $stmt->execute([
        $menge,
        $preis,
        $preisId,
    ]);

    // Wenn es die 1er-Menge ist, Basispreis im Produkt aktualisieren
    if ($produktId > 0 && $menge === 1) {
        $stmt = $pdo->prepare("UPDATE produkte SET preis = ? WHERE id = ?");
        $stmt->execute([$preis, $produktId]);
    }

    echo "<div class='container mt-3'><div class='alert alert-info shadow-sm'>✅ Staffelpreis aktualisiert ("
        . $menge . " Stk. = " . htmlspecialchars((string)$preis) . " €)</div></div>";
}


// ========== Preisstaffel hinzufügen ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_price'])) {
    $produktId = (int)$_POST['produkt_id'];
    $menge     = (int)$_POST['menge'];
    $preis     = (float)$_POST['preis'];

    // Wenn Menge = 1 → alten 1er-Preis ersetzen (nur einer pro Produkt)
    if ($menge === 1) {
        // gibt es schon einen 1er-Staffelpreis?
        $check = $pdo->prepare("SELECT id FROM produkt_preise WHERE produkt_id = ? AND menge = 1 LIMIT 1");
        $check->execute([$produktId]);
        $existingId = $check->fetchColumn();

        if ($existingId) {
            // bestehenden 1er-Eintrag updaten
            $upd = $pdo->prepare("UPDATE produkt_preise SET preis = ? WHERE id = ?");
            $upd->execute([$preis, (int)$existingId]);
        } else {
            // neuen 1er-Eintrag anlegen
            $ins = $pdo->prepare("
                INSERT INTO produkt_preise (produkt_id, menge, preis)
                VALUES (?, 1, ?)
            ");
            $ins->execute([$produktId, $preis]);
        }

        // Basispreis im Produkt immer auf 1er-Preis setzen
        $stmt = $pdo->prepare("UPDATE produkte SET preis = ? WHERE id = ?");
        $stmt->execute([$preis, $produktId]);
    } else {
        // normale Staffelpreise (andere Mengen) wie bisher einfügen
        $stmt = $pdo->prepare("
            INSERT INTO produkt_preise (produkt_id, menge, preis)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([
            $produktId,
            $menge,
            $preis
        ]);
    }

    $flash[] = [
        'type' => 'info',
        'text' => "💰 Preisstaffel gespeichert (" .
            $menge . " Stk. = " .
            number_format($preis, 2, ',', '.') . " €)"
    ];
}


// ========== Produkt bearbeiten ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_product'])) {
    $produktId = (int)($_POST['produkt_id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $beschreibung = isset($_POST['beschreibung']) ? trim((string)$_POST['beschreibung']) : null;
    $mwst = isset($_POST['mwst']) && $_POST['mwst'] !== '' ? (float)$_POST['mwst'] : 20.0;

    if ($produktId > 0 && $name !== '') {
        $stmt = $pdo->prepare("UPDATE produkte SET name = ?, beschreibung = ?, mwst = ? WHERE id = ?");
        $stmt->execute([$name, ($beschreibung !== '' ? $beschreibung : null), $mwst, $produktId]);
        $flash[] = [
            'type' => 'success',
            'text' => "✏️ Produkt aktualisiert: <strong>" . htmlspecialchars($name) . "</strong>"
        ];
    } else {
        $flash[] = [
            'type' => 'warning',
            'text' => 'Bitte einen gültigen Produktnamen angeben.'
        ];
    }
}

// ========== Produkte + Preise laden ==========
$produkte = $pdo->query("SELECT * FROM produkte ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Kompakte Produktliste mit Basispreis (p.preis oder kleinste Staffel)
$produkt_liste = $pdo->query("SELECT p.id, p.artikelnummer, p.name, p.mwst,
       COALESCE(
         p.preis,
         (SELECT pp.preis FROM produkt_preise pp WHERE pp.produkt_id = p.id ORDER BY pp.menge ASC LIMIT 1)
       ) AS basispreis
  FROM produkte p
  ORDER BY p.name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container py-4">

    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="text-primary mb-1">Produkte verwalten</h2>
            <p class="text-muted mb-0">Produkte, Preisstaffeln und CSV-Import verwalten.</p>
        </div>
    </div>

    <!-- Kompakte Produktliste -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <strong>Produktliste</strong>
            <span class="text-muted small">Basispreise inkl. Staffel-Start</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($produkt_liste)): ?>
                <div class="p-3 text-muted">Noch keine Produkte vorhanden.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:140px">Artikel-Nr.</th>
                                <th>Name</th>
                                <th style="width:160px" class="text-end">Preis (Basis)</th>
                                <th style="width:110px" class="text-end">MWSt %</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($produkt_liste as $row): ?>
                            <tr>
                                <td class="text-muted"><?= htmlspecialchars($row['artikelnummer'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['name'] ?? '') ?></td>
                                <td class="text-end">
                                    <?= number_format((float)($row['basispreis'] ?? 0), 2, ',', '.') ?> €
                                </td>
                                <td class="text-end"><?= htmlspecialchars((string)$row['mwst']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Flash messages -->
    <?php foreach ($flash as $msg): ?>
        <div class="alert alert-<?= $msg['type'] ?> shadow-sm mb-3">
            <?= $msg['text'] ?>
        </div>
    <?php endforeach; ?>

    <div class="row g-4">
        <!-- CSV Import Card -->
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-light">
                    <strong>📥 CSV Produktimport</strong>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-2">
                        Importiert Produkte aus einer CSV-Datei. Erwartete Spalten:
                    </p>
                    <pre class="bg-light p-2 rounded small mb-3">
sku,name,description,vat,price_1,price_5,price_10,price_25,price_50,price_100,price_200
                    </pre>

                    <?php
                    $csv_import_url = "api/products_import_csv.php";
                    $csv_api_token  = ""; // falls du einen API-Token nutzt
                    include __DIR__ . "/partials/csv_import_widget.php";
                    ?>
                </div>
            </div>
        </div>

        <!-- Neues Produkt Card -->
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-light">
                    <strong>Neues Produkt anlegen</strong>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="new_product" value="1">

                        <div class="col-md-6">
                            <label class="form-label">Produktname</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">MWSt (%)</label>
                            <input type="number" name="mwst" class="form-control" step="0.01" value="20">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Beschreibung</label>
                            <textarea name="beschreibung" rows="3" class="form-control"></textarea>
                        </div>

                        <div class="col-12 mt-2">
                            <button type="submit" class="btn btn-primary btn-touch shadow-sm">
                                💾 Speichern
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Liste der Produkte -->
    <div class="mt-5">
        <h4 class="mb-3">Bestehende Produkte</h4>

        <?php if (count($produkte) === 0): ?>
            <div class="alert alert-warning shadow-sm">Keine Produkte vorhanden.</div>
        <?php else: ?>
            <?php foreach ($produkte as $p): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <h5 class="mb-0">
                                    <?= htmlspecialchars($p['name'] ?? '') ?>
                                    <span class="text-muted">
                                        (<?= htmlspecialchars($p['artikelnummer'] ?? '') ?>)
                                    </span>
                                </h5>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-success">
                                    MWSt: <?= (float)$p['mwst'] ?>%
                                </span>
                                <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#editProduct<?= (int)$p['id'] ?>" aria-expanded="false" aria-controls="editProduct<?= (int)$p['id'] ?>" title="Bearbeiten">✏️</button>
                                <form method="POST"
                                      onsubmit="return confirm('Produkt wirklich löschen? Alle Preisstaffeln werden ebenfalls gelöscht!');">
                                    <input type="hidden" name="delete_product" value="1">
                                    <input type="hidden" name="produkt_id" value="<?= (int)$p['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm" title="Produkt löschen">🗑</button>
                                </form>
                            </div>
                        </div>

                        <div class="collapse" id="editProduct<?= (int)$p['id'] ?>">
                            <form method="POST" class="row g-2 mt-2">
                                <input type="hidden" name="edit_product" value="1">
                                <input type="hidden" name="produkt_id" value="<?= (int)$p['id'] ?>">
                                <div class="col-md-6">
                                    <label class="form-label">Produktname</label>
                                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($p['name'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">MWSt (%)</label>
                                    <input type="number" step="0.01" name="mwst" class="form-control" value="<?= htmlspecialchars((string)$p['mwst']) ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Beschreibung</label>
                                    <textarea name="beschreibung" rows="3" class="form-control"><?= htmlspecialchars($p['beschreibung'] ?? '') ?></textarea>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-success btn-sm">💾 Speichern</button>
                                </div>
                            </form>
                        </div>

                        <?php if (!empty($p['beschreibung'])): ?>
                            <p class="mt-2 mb-3"><?= nl2br(htmlspecialchars($p['beschreibung'] ?? '')) ?></p>
                        <?php endif; ?>

                        <!-- Preisstaffeln -->
                        <h6 class="mt-2">Preisstaffeln</h6>
                        <?php
                        $preiseStmt = $pdo->prepare("
                            SELECT * FROM produkt_preise
                            WHERE produkt_id = ?
                            ORDER BY menge ASC
                        ");
                        $preiseStmt->execute([$p['id']]);
                        $preiseList = $preiseStmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>

                        <?php if (count($preiseList) === 0): ?>
                            <p class="text-muted"><em>Keine Preise hinterlegt.</em></p>
                        <?php else: ?>
                            <div class="table-responsive mb-3">
                                <table class="table table-sm table-striped table-bordered mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th style="width: 140px;">Menge</th>
                                        <th style="width: 260px;">Preis (€)</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach($preiseList as $pr): ?>
                                        <tr>
                                            <td>
                                                <form method="POST" class="d-flex align-items-center gap-2">
                                                    <input type="hidden" name="preis_id" value="<?= (int)$pr['id'] ?>">
                                                    <input type="hidden" name="produkt_id" value="<?= (int)$p['id'] ?>">

                                                    <input type="number"
                                                           name="menge"
                                                           class="form-control form-control-sm"
                                                           style="max-width: 100px;"
                                                           min="1"
                                                           value="<?= (int)$pr['menge'] ?>"
                                                           required>
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm" style="max-width: 230px;">
                                                    <input type="number"
                                                           name="preis"
                                                           class="form-control form-control-sm"
                                                           step="0.01"
                                                           value="<?= htmlspecialchars((string)$pr['preis']) ?>"
                                                           required>
                                                    <span class="input-group-text">€</span>

                                                    <button type="submit"
                                                            name="edit_price"
                                                            value="1"
                                                            class="btn btn-outline-success btn-sm"
                                                            title="Speichern">
                                                        💾
                                                    </button>
                                                    <button type="submit"
                                                            name="delete_price"
                                                            value="1"
                                                            class="btn btn-outline-danger btn-sm"
                                                            title="Löschen"
                                                            onclick="return confirm('Diesen Staffelpreis wirklich löschen?');">
                                                        🗑
                                                    </button>
                                                </div>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <!-- Neue Preisstaffel -->
                        <form method="POST" class="row g-2 mt-3 align-items-end">
                            <input type="hidden" name="new_price" value="1">
                            <input type="hidden" name="produkt_id" value="<?= (int)$p['id'] ?>">

                            <div class="col-sm-4 col-md-3">
                                <label class="form-label">Menge</label>
                                <input type="number" name="menge" class="form-control" min="1" required>
                            </div>
                            <div class="col-sm-4 col-md-3">
                                <label class="form-label">Preis (€)</label>
                                <input type="number" name="preis" class="form-control" step="0.01" required>
                            </div>
                            <div class="col-sm-4 col-md-3">
                                <button type="submit" class="btn btn-info shadow-sm w-100 mt-4 mt-sm-0">
                                    💰 Hinzufügen
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
