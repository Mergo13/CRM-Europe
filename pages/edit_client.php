<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/init.php';
global $pdo;

function esc($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errors = [];
$client = null;

if ($id <= 0) {
    include '../includes/header.php';
    echo "<div class='container mt-4'><div class='alert alert-danger'>Ungültige Client-ID.</div></div>";
    include '../includes/footer.php';
    exit;
}

// Fetch client data
try {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        include '../includes/header.php';
        echo "<div class='container mt-4'><div class='alert alert-danger'>Kunde nicht gefunden.</div></div>";
        include '../includes/footer.php';
        exit;
    }
} catch (PDOException $e) {
    die("Datenbankfehler: " . esc($e->getMessage()));
}

// Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $adresse = trim($_POST['adresse'] ?? '');
    $plz = trim($_POST['plz'] ?? '');
    $ort = trim($_POST['ort'] ?? '');
    $telefon = trim($_POST['telefon'] ?? '');
    $firma = trim($_POST['firma'] ?? '');
    $atu = trim($_POST['atu'] ?? ''); // Although not explicitly in requirement list, it's in register_client and likely needed

    // Basic validation
    if (empty($name)) {
        $errors[] = "Name ist ein Pflichtfeld.";
    }
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Ungültige E-Mail-Adresse.";
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE clients SET
                    name = ?,
                    email = ?,
                    adresse = ?,
                    plz = ?,
                    ort = ?,
                    telefon = ?,
                    firma = ?,
                    atu = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $name,
                $email ?: null,
                $adresse ?: null,
                $plz ?: null,
                $ort ?: null,
                $telefon ?: null,
                $firma ?: null,
                $atu ?: null,
                $id
            ]);

            header("Location: clients-list.php?success=1");
            exit;
        } catch (PDOException $e) {
            $errors[] = "Fehler beim Aktualisieren: " . esc($e->getMessage());
        }
    }
}

// Pre-fill form values (either from POST if validation failed, or from DB)
$display_name = $_POST['name'] ?? $client['name'];
$display_email = $_POST['email'] ?? $client['email'];
$display_adresse = $_POST['adresse'] ?? $client['adresse'];
$display_plz = $_POST['plz'] ?? $client['plz'];
$display_ort = $_POST['ort'] ?? $client['ort'];
$display_telefon = $_POST['telefon'] ?? $client['telefon'];
$display_firma = $_POST['firma'] ?? $client['firma'];
$display_atu = $_POST['atu'] ?? $client['atu'];

include '../includes/header.php';
?>

<main class="container-fluid px-4 py-3" data-form-page>

    <!-- Page header -->
    <div class="mb-4">
        <h1 class="page-title">Kunde bearbeiten</h1>
        <p class="page-sub">Kundendaten aktualisieren: <?= esc($client['kundennummer']) ?></p>
    </div>

    <div class="row justify-content-center">
        <div class="col-xl-8 col-lg-10">

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger shadow-soft">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= esc($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="ds-card">

                <form method="POST" class="row g-4">

                    <div class="col-md-6">
                        <label class="form-label">Name *</label>
                        <input type="text" name="name" class="form-control"
                               value="<?= esc($display_name) ?>"
                               placeholder="Max Mustermann" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">E-Mail</label>
                        <input type="email" name="email" class="form-control"
                               value="<?= esc($display_email ?? '') ?>"
                               placeholder="max@example.com">
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">Adresse</label>
                        <input type="text" name="adresse" class="form-control"
                               value="<?= esc($display_adresse ?? '') ?>"
                               placeholder="Musterstraße 1">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">PLZ</label>
                        <input type="text" name="plz" class="form-control"
                               value="<?= esc($display_plz ?? '') ?>"
                               placeholder="1010">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Ort</label>
                        <input type="text" name="ort" class="form-control"
                               value="<?= esc($display_ort ?? '') ?>"
                               placeholder="Wien">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Telefon</label>
                        <input type="text" name="telefon" class="form-control"
                               value="<?= esc($display_telefon ?? '') ?>"
                               placeholder="+43 660 1234567">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Firma</label>
                        <input type="text" name="firma" class="form-control"
                               value="<?= esc($display_firma ?? '') ?>"
                               placeholder="Musterfirma GmbH">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">ATU Nummer</label>
                        <input type="text" name="atu" class="form-control"
                               value="<?= esc($display_atu ?? '') ?>"
                               placeholder="ATU12345678">
                    </div>

                    <!-- Actions -->
                    <div class="col-12 d-flex justify-content-end gap-2 mt-3">
                        <a href="clients-list.php" class="btn btn-outline-secondary btn-touch">
                            Abbrechen
                        </a>
                        <button type="submit" class="btn btn-primary btn-touch">
                            <i class="bi bi-save"></i>
                            Änderungen speichern
                        </button>
                    </div>

                </form>

            </div>
        </div>
    </div>

</main>

<?php include '../includes/footer.php'; ?>
