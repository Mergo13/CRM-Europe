<?php
include '../includes/header.php';
try {
    require '../config/db.php';
} catch (Throwable $e) {
    $db_error = $e->getMessage();
}
global $pdo;

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (isset($db_error)) {
    echo "<div class='container mt-4'>
            <div class='alert alert-danger'>
                Datenbankverbindung fehlgeschlagen.
                <br><small>" . htmlspecialchars($db_error) . "</small>
            </div>
          </div>";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $lastId = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM clients")->fetchColumn();
        $kundennummer = 'KUND-' . str_pad(($lastId + 1), 4, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare("
            INSERT INTO clients (
                kundennummer, name, email, adresse, plz, ort, telefon, firma, atu
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $kundennummer,
            $_POST['name'] ?? '',
            $_POST['email'] ?? null,
            $_POST['adresse'] ?? null,
            $_POST['plz'] ?? null,
            $_POST['ort'] ?? null,
            $_POST['telefon'] ?? null,
            $_POST['firma'] ?? null,
            $_POST['atu'] ?? null
        ]);

        echo "<div class='container mt-4'>
                <div class='alert alert-success shadow-soft'>
                    ✅ Kunde erfolgreich gespeichert!
                    Kundennummer:
                    <strong>" . htmlspecialchars($kundennummer) . "</strong>
                </div>
              </div>";
    } catch (Throwable $e) {
        echo "<div class='container mt-4'>
                <div class='alert alert-danger shadow-soft'>
                    Fehler beim Speichern:
                    " . htmlspecialchars($e->getMessage()) . "
                </div>
              </div>";
    }
}
?>

<main class="container-fluid px-4 py-3" data-form-page>

    <!-- Page header -->
    <div class="mb-4">
        <h1 class="page-title">Kunde registrieren</h1>
        <p class="page-sub">Neuen Kunden im System anlegen</p>
    </div>

    <div class="row justify-content-center">
        <div class="col-xl-8 col-lg-10">

            <div class="ds-card">

                <form method="POST" class="row g-4">

                    <div class="col-md-6">
                        <label class="form-label">Name *</label>
                        <input type="text" name="name" class="form-control"
                               placeholder="Max Mustermann" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">E-Mail</label>
                        <input type="email" name="email" class="form-control"
                               placeholder="max@example.com">
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">Adresse</label>
                        <input type="text" name="adresse" class="form-control"
                               placeholder="Musterstraße 1">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">PLZ</label>
                        <input type="text" name="plz" class="form-control"
                               placeholder="1010">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Ort</label>
                        <input type="text" name="ort" class="form-control"
                               placeholder="Wien">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Telefon</label>
                        <input type="text" name="telefon" class="form-control"
                               placeholder="+43 660 1234567">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Firma</label>
                        <input type="text" name="firma" class="form-control"
                               placeholder="Musterfirma GmbH">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">ATU Nummer</label>
                        <input type="text" name="atu" class="form-control"
                               placeholder="ATU12345678">
                    </div>

                    <!-- Actions -->
                    <div class="col-12 d-flex justify-content-end gap-2 mt-3">
                        <button type="submit" class="btn btn-primary btn-touch">
                            <i class="bi bi-save"></i>
                            Kunde speichern
                        </button>
                    </div>

                </form>

            </div>
        </div>
    </div>

</main>

<?php include '../includes/footer.php'; ?>
