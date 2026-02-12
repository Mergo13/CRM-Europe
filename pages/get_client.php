<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../includes/header.php';
require '../config/db.php';
global $pdo;
// Allowed fields to update
$fields = ['kundennummer','firma','name','email','adresse','plz','ort','telefon','atu'];

$errors = [];
$success = false;

// get id from GET or POST
$id = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['id'])) {
    $id = (int)$_POST['id'];
} else {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
}

if ($id <= 0) {
    echo "<div class='alert alert-warning'>Keine Client-ID übergeben.</div>";
    include '../includes/footer.php';
    exit;
}

// If POST - process update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect posted values (trim)
    $posted = [];
    foreach ($fields as $f) {
        $posted[$f] = isset($_POST[$f]) ? trim($_POST[$f]) : '';
    }

    // Validation
    if ($posted['name'] === '') $errors[] = 'Name ist erforderlich.';
    if ($posted['email'] === '') $errors[] = 'Email ist erforderlich.';
    if ($posted['email'] !== '' && !filter_var($posted['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Ungültige Email-Adresse.';

    if (empty($errors)) {
        try {
            $setParts = [];
            $params = [];
            foreach ($fields as $f) {
                $setParts[] = "`$f` = ?";
                $params[] = $posted[$f] === '' ? null : $posted[$f];
            }
            $params[] = $id;
            $sql = "UPDATE clients SET " . implode(", ", $setParts) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // Redirect to avoid resubmission and show updated=1
            header("Location: get_client.php?id={$id}&updated=1");
            exit;
        } catch (PDOException $e) {
            $errors[] = "Datenbankfehler: " . htmlspecialchars($e->getMessage());
        }
    } else {
        // keep posted values to refill form below
        $client = $posted;
        $client['id'] = $id;
    }
}

// If not populated from failed POST, load client from DB
if (!isset($client) || empty($client)) {
    try {
        $stmt = $pdo->prepare("SELECT id, " . implode(", ", $fields) . " FROM clients WHERE id = ?");
        $stmt->execute([$id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) {
            echo "<div class='alert alert-danger'>Kunde nicht gefunden!</div>";
            include '../includes/footer.php';
            exit;
        }
    } catch (PDOException $e) {
        echo "<div class='alert alert-danger'>DB-Fehler: " . htmlspecialchars($e->getMessage()) . "</div>";
        include '../includes/footer.php';
        exit;
    }
}

// Check for updated flag
$updatedFlag = isset($_GET['updated']);
?>

<div class="container mt-4">
    <div class="card shadow-sm p-4">
        <h2 class="mb-4 text-primary">Kundendetails</h2>

        <?php if ($updatedFlag): ?>
            <div class="alert alert-success">Kundendaten erfolgreich aktualisiert.</div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Display block (read-only) -->
        <div id="detailsView">
            <div class="row g-3">
                <div class="col-md-6"><strong>Kundennummer:</strong> <?= htmlspecialchars($client['kundennummer'] ?? '') ?></div>
                <div class="col-md-6"><strong>Name:</strong> <?= htmlspecialchars($client['name'] ?? '') ?></div>
                <div class="col-md-6"><strong>Email:</strong> <?= htmlspecialchars($client['email'] ?? '') ?></div>
                <div class="col-md-6"><strong>Telefon:</strong> <?= htmlspecialchars($client['telefon'] ?? '') ?></div>
                <div class="col-md-6"><strong>Adresse:</strong> <?= htmlspecialchars($client['adresse'] ?? '') ?></div>
                <div class="col-md-2"><strong>PLZ:</strong> <?= htmlspecialchars($client['plz'] ?? '') ?></div>
                <div class="col-md-4"><strong>Ort:</strong> <?= htmlspecialchars($client['ort'] ?? '') ?></div>
                <div class="col-md-6"><strong>Firma:</strong> <?= htmlspecialchars($client['firma'] ?? '') ?></div>
                <div class="col-md-6"><strong>ATU Nummer:</strong> <?= htmlspecialchars($client['atu'] ?? '') ?></div>
            </div>

            <div class="mt-4">
                <a href="clients.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Zurück zur Kundenliste</a>
                <button id="editBtn" class="btn btn-primary"><i class="bi bi-pencil"></i> Bearbeiten</button>
            </div>
        </div>

        <!-- Edit form (hidden by default) -->
        <div id="editForm" style="display:none; margin-top:20px;">
            <form method="post" novalidate>
                <input type="hidden" name="id" value="<?= htmlspecialchars($client['id']) ?>">

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Kundennummer</label>
                        <input type="text" name="kundennummer" class="form-control" value="<?= htmlspecialchars($client['kundennummer'] ?? '') ?>">
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">Firma</label>
                        <input type="text" name="firma" class="form-control" value="<?= htmlspecialchars($client['firma'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($client['name'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($client['email'] ?? '') ?>">
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">Adresse</label>
                        <input type="text" name="adresse" class="form-control" value="<?= htmlspecialchars($client['adresse'] ?? '') ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">PLZ</label>
                        <input type="text" name="plz" class="form-control" value="<?= htmlspecialchars($client['plz'] ?? '') ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Ort</label>
                        <input type="text" name="ort" class="form-control" value="<?= htmlspecialchars($client['ort'] ?? '') ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Telefon</label>
                        <input type="text" name="telefon" class="form-control" value="<?= htmlspecialchars($client['telefon'] ?? '') ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">ATU (USt-Id)</label>
                        <input type="text" name="atu" class="form-control" value="<?= htmlspecialchars($client['atu'] ?? '') ?>">
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-success">Speichern</button>
                    <button type="button" id="cancelEdit" class="btn btn-secondary">Abbrechen</button>
                </div>
            </form>
        </div>

    </div>
</div>

<script>
    // Toggle edit form
    document.addEventListener('DOMContentLoaded', function() {
        const editBtn = document.getElementById('editBtn');
        const editForm = document.getElementById('editForm');
        const detailsView = document.getElementById('detailsView');
        const cancelEdit = document.getElementById('cancelEdit');

        if (editBtn) {
            editBtn.addEventListener('click', function() {
                detailsView.style.display = 'none';
                editForm.style.display = 'block';
                // scroll to form
                editForm.scrollIntoView({behavior: 'smooth', block: 'start'});
            });
        }
        if (cancelEdit) {
            cancelEdit.addEventListener('click', function() {
                editForm.style.display = 'none';
                detailsView.style.display = 'block';
            });
        }
    });
</script>

<?php include '../includes/footer.php'; ?>
