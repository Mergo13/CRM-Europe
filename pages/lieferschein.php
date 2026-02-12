<?php
// pages/lieferschein.php — Neuen Lieferschein erstellen (funktional wie rechnung.php/angebot.php)
error_reporting(E_ALL);
ini_set('display_errors', '1');

global $pdo;
require_once __DIR__ . '/../config/db.php';

$clients = [];
$products = [];
$priceTiers = [];
$db_error = null;

try {
    // Clients laden (Firma + Name zur Anzeige)
    $clients = $pdo->query("SELECT id, firma, name FROM clients ORDER BY firma, name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $db_error = $e->getMessage(); $clients = []; }

try {
    // Produkte mit Basispreis; wenn p.preis NULL, nimm kleinste Staffel aus produkt_preise
    $products = $pdo->query("SELECT p.id, p.name,
                   COALESCE(
                       p.preis,
                       (SELECT pp.preis FROM produkt_preise pp WHERE pp.produkt_id = p.id ORDER BY pp.menge ASC LIMIT 1)
                   ) AS preis
            FROM produkte p
            ORDER BY p.name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $products = []; }

try {
    $stmt = $pdo->query("SELECT produkt_id, menge, preis FROM produkt_preise ORDER BY produkt_id ASC, menge ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $pid = (int)$r['produkt_id'];
        if (!isset($priceTiers[$pid])) { $priceTiers[$pid] = []; }
        $priceTiers[$pid][] = [
            'qty' => (int)$r['menge'],
            'price' => (float)$r['preis'],
        ];
    }
} catch (Throwable $e) { $priceTiers = []; }

$today = date('Y-m-d');
// Vorschau der Lieferscheinnummer: L-ddmm-#### (serverseitige Vorschau)
try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM lieferscheine WHERE datum = ?');
    $stmt->execute([$today]);
    $countToday = (int)$stmt->fetchColumn();
} catch (Throwable $e) { $countToday = 0; }
$defaultNum = 'L-' . date('dm') . '-' . str_pad((string)($countToday + 1), 4, '0', STR_PAD_LEFT);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lieferschein erstellen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<?php include '../includes/header.php';?>
<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="text-success"><i class="bi bi-truck me-2"></i>Neuen Lieferschein erstellen</h2>
        <div>
            <a href="/pages/lieferschein-list.php" class="btn btn-outline-secondary"><i class="bi bi-list"></i> Zur Liste</a>
        </div>
    </div>

    <?php if ($db_error): ?>
        <div class="alert alert-danger">DB-Fehler: <?= htmlspecialchars($db_error) ?></div>
    <?php endif; ?>

    <form id="lieferscheinForm" action="lieferschein_speichern.php" method="POST" class="card shadow-sm">
        <div class="card-header bg-light fw-bold">Kunde</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="client_id" class="form-label">Bestehenden Kunden wählen</label>
                    <input type="text" id="clientFilter" class="form-control form-control-sm mb-2" placeholder="Suchen…" aria-label="Kunde filtern">
                    <select name="client_id" id="client_id" class="form-select form-select-lg text-dark bg-white border-dark">
                        <option value="">— Kunde auswählen —</option>
                        <?php foreach ($clients as $c): $firma = trim((string)($c['firma'] ?? '')); $name = trim((string)($c['name'] ?? '')); $label = $firma !== '' && $name !== '' ? ($firma . ' — ' . $name) : ($firma !== '' ? $firma : $name); ?>
                            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($label ?: ('Kunde #' . (int)$c['id'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Oder neuen Kunden anlegen:</div>
                </div>
                <div class="col-md-6 align-self-end">
                    <button id="toggleNewClient" type="button" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-person-plus"></i> Neuen Kunden erfassen
                    </button>
                </div>
            </div>
            <div id="newClientBox" class="row g-3 mt-2" style="display:none">
                <div class="col-md-6">
                    <label class="form-label">Firma</label>
                    <input name="firma" class="form-control" placeholder="Firma">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Name</label>
                    <input name="name" class="form-control" placeholder="Name">
                </div>
                <div class="col-md-6">
                    <label class="form-label">E-Mail</label>
                    <input name="email" type="email" class="form-control" placeholder="E-Mail">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Telefon</label>
                    <input name="telefon" class="form-control" placeholder="Telefon">
                </div>
                <div class="col-md-12">
                    <label class="form-label">Adresse</label>
                    <input name="adresse" class="form-control" placeholder="Adresse">
                </div>
                <div class="col-md-4">
                    <label class="form-label">PLZ</label>
                    <input name="plz" class="form-control" placeholder="PLZ">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Ort</label>
                    <input name="ort" class="form-control" placeholder="Ort">
                </div>
                <div class="col-md-4">
                    <label class="form-label">ATU</label>
                    <input name="atu" class="form-control" placeholder="ATU">
                </div>
            </div>
        </div>

        <div class="card-header bg-light fw-bold">Lieferscheindaten</div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label">Lieferscheinnummer (automatisch)</label>
                <input type="text" name="nummer" class="form-control" value="<?= htmlspecialchars($defaultNum) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Erstellungsdatum</label>
                <input type="date" name="datum" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Lieferdatum</label>
                <input type="date" name="lieferdatum" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Bestellnummer</label>
                <input type="text" name="bestellnummer" class="form-control" placeholder="Kunden-Bestellnummer (optional)">
            </div>
            <div class="col-md-6">
                <label class="form-label">Lieferadresse-ID</label>
                <input type="number" name="lieferadresse_id" class="form-control" placeholder="Interne Adresse-ID (optional)">
            </div>
            <div class="col-12">
                <label class="form-label">Bemerkung</label>
                <textarea name="bemerkung" class="form-control" rows="2" placeholder="Optionale Bemerkung"></textarea>
            </div>
        </div>

        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <strong>Positionen</strong>
            <button type="button" id="btnAddRow" class="btn btn-sm btn-success">+ Position</button>
        </div>
        <div class="card-body">
            <table class="table table-bordered align-middle">
                <thead>
                <tr><th>Produkt</th><th style="width:120px">Menge</th><th style="width:80px">Aktion</th></tr>
                </thead>
                <tbody id="posBody"></tbody>
            </table>
        </div>

        <div class="card-footer d-flex justify-content-end gap-2">
            <button type="submit" class="btn btn-primary">💾 Lieferschein speichern</button>
        </div>
    </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const products = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE); ?>;
  const priceTiers = <?php echo json_encode($priceTiers); ?>;

  const posBody = document.getElementById('posBody');
  const sumEl = document.getElementById('summe');
  const clientSelect = document.getElementById('client_id');
  const clientFilter = document.getElementById('clientFilter');
  const newClientBox = document.getElementById('newClientBox');
  const toggleNewClient = document.getElementById('toggleNewClient');

  // Lightweight filter for client select
  if (clientFilter && clientSelect) {
    clientFilter.addEventListener('input', function(){
      const q = (this.value || '').toLowerCase();
      Array.from(clientSelect.options).forEach((opt, idx) => {
        if (idx === 0) { opt.hidden = false; return; }
        const t = (opt.textContent || '').toLowerCase();
        opt.hidden = q !== '' && !t.includes(q);
      });
    });
  }

  function formatMoney(n){
    const x = Number(n||0);
    return x.toFixed(2).replace('.', ',') + ' €';
  }

  function recalcRow(tr){
    // Lieferschein führt keine Preise; nur Mengen.
    return 0;
  }

  function recalcTotal(){
    // Keine Summenberechnung erforderlich für Lieferschein.
    return;
  }

  function productOptionsHTML(){
    let html = '<option value="">— Produkt wählen —</option>';
    html += '<option value="manual">Manuell eingeben</option>';
    for (const p of products){
      const price = (p.preis==null?0:Number(p.preis));
      html += `<option value="${String(p.id)}" data-price="${price}">${escapeHtml(p.name||('Produkt #'+p.id))}</option>`;
    }
    return html;
  }

  function addRow(){
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>
        <select name="product_id[]" class="form-select product">
          ${productOptionsHTML()}
        </select>
      </td>
      <td>
        <input name="menge[]" type="number" min="0" step="1" class="form-control menge" value="1">
      </td>
      <td><button type="button" class="btn btn-sm btn-danger btn-remove"><i class="bi bi-x"></i></button></td>
    `;
    posBody.appendChild(tr);
  }

  function escapeHtml(s){
    if (s==null) return '';
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));
  }

  // Initial eine Zeile
  addRow();
  recalcTotal();

  document.getElementById('btnAddRow').addEventListener('click', addRow);

  function getTierPrice(productId, qty){
    qty = parseFloat(qty)||0;
    const key1 = String(productId);
    const key2 = Number(productId);
    const tiers = (priceTiers && (priceTiers[key1] || priceTiers[key2])) || [];
    let price = null;
    for (let i=0;i<tiers.length;i++){
      if (qty >= (tiers[i].qty||0)) price = parseFloat(tiers[i].price)||0; else break;
    }
    if (price===null){
      const p = (products||[]).find(p => String(p.id)===String(productId));
      if (p && p.preis!=null) return parseFloat(p.preis)||0;
      return 0;
    }
    return price;
  }

  // Lieferschein enthält keine Preise mehr – Preis-Handler sind No-Ops, um JS-Fehler zu vermeiden
  function updatePrice(select){
    return; // bewusst leer
  }

  function calculateRowTotal(input){
    return; // bewusst leer
  }

  // Events delegieren
  posBody.addEventListener('change', function(e){
    if (e.target && e.target.matches('select.product')){
      updatePrice(e.target);
    }
    if (e.target && e.target.matches('input.menge')){
      const tr = e.target.closest('tr');
      const sel = tr.querySelector('select.product');
      if (sel && sel.value){
        const tierPrice = getTierPrice(sel.value, parseFloat(e.target.value)||0);
        const priceInput = tr.querySelector('.preis');
        priceInput.value = tierPrice.toFixed(2);
      }
      recalcRow(tr); recalcTotal();
    }
  });

  posBody.addEventListener('click', function(e){
    if (e.target && (e.target.classList.contains('btn-remove') || e.target.closest('.btn-remove'))){
      const btn = e.target.closest('button');
      const tr = btn.closest('tr');
      tr.remove();
      recalcTotal();
    }
  });

  // Toggle neuer Kunde
  if (toggleNewClient) {
    toggleNewClient.addEventListener('click', function(){
      const visible = newClientBox.style.display !== 'none';
      newClientBox.style.display = visible ? 'none' : 'flex';
    });
  }

})();
</script>
</body>
</html>
