<?php

// Load open invoices for the dropdown (unpaid only)
global $pdo;
require_once __DIR__ . '/../config/db.php';
$stmt = $pdo->query("SELECT id, rechnungsnummer FROM rechnungen WHERE status IS NULL OR status NOT IN ('bezahlt','paid')");
$rechnungen = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mahnung/Zahlungserinnerung erstellen</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  .toolbar{background:linear-gradient(135deg,#6610f2,#6f42c1);color:#fff;border-radius:12px}
</style>
</head>
<body>
<div class="container my-4">
  <div class="p-4 mb-4 toolbar d-flex justify-content-between align-items-center">
    <h2 class="m-0"><i class="bi bi-bell me-2"></i>Mahnung / Zahlungserinnerung</h2>
    <div>
      <a href="/pages/mahnungen-list.php" class="btn btn-light"><i class="bi bi-list"></i> Zur Liste</a>
    </div>
  </div>

  <div id="result"></div>

  <div class="alert alert-secondary">Hinweis: Mahnungen werden täglich auch automatisch per CRON erzeugt. Hier können Sie bei Bedarf manuell eine Erinnerung/Mahnung anlegen.</div>

  <form id="mForm" class="card shadow-sm" onsubmit="return submitMahnung(event)">
    <div class="card-body">
      <div class="row g-3">
        <!-- Rechnungsauswahl -->
        <div class="col-md-6">
            <label class="form-label">Rechnung</label>
            <select required name="rechnungsnummer" class="form-select">
                <option value="">Bitte wählen…</option>
                <?php foreach ($rechnungen as $r): ?>
                    <option value="<?= htmlspecialchars($r['rechnungsnummer']) ?>">
                        <?= htmlspecialchars($r['rechnungsnummer']) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Stufe</label>
          <select name="stufe" class="form-select">
            <option value="0">Zahlungserinnerung</option>
            <option value="1">1. Mahnung</option>
            <option value="2">Letzte Mahnung</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Frist (Tage)</label>
          <input name="due_days" type="number" min="3" value="7" class="form-control">
        </div>

        <div class="col-12">
          <label class="form-label">Text (optional)</label>
          <textarea name="text" class="form-control" rows="2" placeholder="Leer lassen, um Standardtext zu verwenden"></textarea>
        </div>

        <div class="col-12 form-check mt-2">
          <input class="form-check-input" type="checkbox" value="1" id="sendNow" name="send_now">
          <label class="form-check-label" for="sendNow">PDF nach Erstellung per E‑Mail versenden (falls E‑Mail vorhanden)</label>
        </div>
      </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
      <button type="reset" class="btn btn-outline-secondary">Zurücksetzen</button>
      <button type="submit" class="btn btn-primary"><i class="bi bi-bell"></i> Erstellen</button>
    </div>
  </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
async function submitMahnung(e){
  e.preventDefault();
  const form = document.getElementById('mForm');
  const fd = new FormData(form);
  try {
    const res = await fetch('/pages/mahnung_speichern.php', { method:'POST', body: fd });
    const json = await res.json();
    const box = document.getElementById('result');
    if (json && json.success) {
      // On success, redirect to the Mahnungen list automatically (as requested)
      const target = '/pages/mahnungen-list.php';
      // Optionally pass a small hint for UI toast
      const q = new URLSearchParams();
      q.set('created', '1');
      if (json.mahnung_id) q.set('id', String(json.mahnung_id));
      window.location.href = target + '?' + q.toString();
      return;
    } else {
      box.innerHTML = `<div class="alert alert-danger">Fehler: ${(json && json.error) ? json.error : 'Unbekannt'}</div>`;
    }
  } catch(err){
    document.getElementById('result').innerHTML = `<div class="alert alert-danger">Fehler: ${err.message}</div>`;
  }
  return false;
}
</script>
</body>
</html>
