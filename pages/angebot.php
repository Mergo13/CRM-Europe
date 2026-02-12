<?php
// pages/angebot.php — Neues Angebot erstellen mit Kunden- und Produktauswahl
error_reporting(E_ALL);
ini_set('display_errors', '1');

global $pdo;
require_once __DIR__ . '/../config/db.php';

$clients = [];
$products = [];
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

// Alle Staffelpreise laden: { produkt_id: [ {qty, price}, ... ] }
$priceTiers = [];
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
// Vorschau der Angebotsnummer (serverseitige Vorschau; tatsächliche Nummer generiert der Server beim Speichern)
try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM angebote WHERE datum = ?');
    $stmt->execute([$today]);
    $countToday = (int)$stmt->fetchColumn();
} catch (Throwable $e) { $countToday = 0; }
$defaultNum = 'A-' . date('dm') . '-' . str_pad((string)($countToday + 1), 4, '0', STR_PAD_LEFT);
?>
<?php
$pageTitle = 'Angebot erstellen';
include __DIR__ . '/../includes/header.php';
?>
<div class="container my-4">
    <div class="toolbar p-3 p-md-4 mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">
                <i class="bi bi-file-earmark-plus me-2" aria-hidden="true"></i>
                Neues Angebot
            </h1>
            <div class="d-flex gap-2">
                <a href="/pages/angeboten-list.php" class="btn btn-light">
                    <i class="bi bi-list" aria-hidden="true"></i>
                    Zur Liste
                </a>
            </div>
        </div>
    </div>

    <?php if ($db_error): ?>
        <div class="alert alert-danger">DB-Fehler: <?= htmlspecialchars($db_error) ?></div>
    <?php endif; ?>

    <form id="angebotForm" class="card shadow-sm">
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
                <div class="col-md-8">
                    <label class="form-label">Ort</label>
                    <input name="ort" class="form-control" placeholder="Ort">
                </div>
                <div class="col-md-6">
                    <label class="form-label">ATU</label>
                    <input name="atu" class="form-control" placeholder="USt-IdNr / ATU">
                </div>
            </div>
        </div>

        <div class="card-header bg-light fw-bold">Details</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Angebotsnummer (Vorschau)</label>
                    <input class="form-control" value="<?= htmlspecialchars($defaultNum) ?>" readonly>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Datum</label>
                    <input type="date" name="datum" class="form-control" value="<?= htmlspecialchars($today) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Gültig bis</label>
                    <input type="date" name="gueltig_bis" class="form-control" value="<?= htmlspecialchars(date('Y-m-d', strtotime($today.' +14 days'))) ?>">
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="offen" selected>Offen</option>
                        <option value="angenommen">Angenommen</option>
                        <option value="abgelehnt">Abgelehnt</option>
                    </select>
                    <div class="form-text">Wählen Sie den Status des Angebots.</div>
                </div>
            </div>
        </div>

        <div class="card-header bg-light fw-bold">Positionen</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle" id="posTable">
                    <thead>
                    <tr>
                        <th style="min-width:280px">Produkt</th>
                        <th style="width:120px">Menge</th>
                        <th style="width:160px">Einzelpreis (€)</th>
                        <th style="width:160px">Gesamt (€)</th>
                        <th style="width:80px">Aktion</th>
                    </tr>
                    </thead>
                    <tbody id="posBody">
                    </tbody>
                </table>
            </div>
            <button id="btnAddRow" type="button" class="btn btn-sm btn-success"><i class="bi bi-plus-circle"></i> Zeile hinzufügen</button>
        </div>
        <div class="card-header bg-light fw-bold">Hinweis / Bemerkung</div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Hinweis für den Kunden (optional)</label>
                <textarea name="hinweis" class="form-control" rows="3" placeholder="Dieser Hinweis erscheint im PDF unter der Summe."></textarea>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <div class="fw-bold">Summe: <span id="summe">0,00 €</span></div>
            <div>
                <button id="btnSave" type="submit" class="btn btn-primary">Angebot erzeugen</button>
            </div>
        </div>
    </form>
</div>

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
    try {
      return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(x);
    } catch (e) {
      // Fallback for older browsers/environments
      return x.toFixed(2).replace('.', ',') + ' €';
    }
  }

  function recalcRow(tr){
    const qty = parseFloat(tr.querySelector('.menge').value || '0');
    const price = parseFloat(tr.querySelector('.preis').value || '0');
    const total = qty * price;
    tr.querySelector('.zeilensumme').textContent = (total||0).toFixed(2).replace('.', ',');
    return total;
  }

  function recalcTotal(){
    let total = 0;
    posBody.querySelectorAll('tr').forEach(tr => { total += recalcRow(tr); });
    sumEl.textContent = formatMoney(total);
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
        <input
          name="produkt_name[]"
          type="text"
          class="form-control mt-2 produkt-name"
          placeholder="Produktname (manuell)"
          style="display:none">
        <textarea
          name="beschreibung[]"
          class="form-control mt-2 beschreibung"
          placeholder="Beschreibung (für manuelle Position)"
          style="display:none"></textarea>
      </td>
      <td>
        <input name="menge[]" type="number" min="0" step=".5" class="form-control menge" value="1">
      </td>
      <td>
        <input type="number" name="preis[]" class="form-control preis" step="0.01">
      </td>
      <td class="text-end align-middle"><span class="zeilensumme">0,00</span> €</td>
      <td><button type="button" class="btn btn-sm btn-danger btn-remove" aria-label="Zeile entfernen"><i class="bi bi-x" aria-hidden="true"></i></button></td>
    `;
    posBody.appendChild(tr);
  }

  function escapeHtml(s){
    if (s==null) return '';
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));
  }

  // Initial one row
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

  function updatePrice(select){
    const tr = select.closest('tr');
    const priceInput = tr.querySelector('.preis');
    const productId = select.value;
    const nameInput = tr.querySelector('.produkt-name');
    const descInput = tr.querySelector('.beschreibung');

    // Always allow editing price
    if (priceInput) priceInput.readOnly = false;

    if (!productId){
      // No selection: hide manual fields, keep existing price untouched
      if (nameInput) { nameInput.style.display = 'none'; nameInput.value = ''; }
      if (descInput) { descInput.style.display = 'none'; descInput.value = ''; }
      recalcRow(tr); recalcTotal();
      return;
    }

    if (productId === 'manual'){
      // Manual entry: show product name + description; leave any existing price as-is
      if (nameInput) nameInput.style.display = '';
      if (descInput) descInput.style.display = '';
      recalcRow(tr); recalcTotal();
      return;
    }

    // Real product selected: hide manual inputs
    if (nameInput) { nameInput.style.display = 'none'; nameInput.value = ''; }
    if (descInput) { descInput.style.display = 'none'; descInput.value = ''; }

    // Set default price only once, if the field is empty. Use the option's data-price as initial default.
    if (priceInput && (priceInput.value === '' || priceInput.value == null)){
      const opt = select.options[select.selectedIndex];
      const def = opt && opt.dataset && opt.dataset.price ? parseFloat(opt.dataset.price) : null;
      if (def != null && !Number.isNaN(def)) {
        priceInput.value = def.toFixed(2);
      }
    }

    // Never make the field readonly; user can negotiate/change price
    recalcRow(tr); recalcTotal();
  }

  function calculateRowTotal(input){
    const tr = input.closest('tr');
    // Only multiply quantity × current price; never touch the price value itself
    recalcRow(tr); recalcTotal();
  }

  posBody.addEventListener('change', e => {
    const t = e.target;
    if (t.classList.contains('product')){
      updatePrice(t);
    } else if (t.classList.contains('menge') || t.classList.contains('preis')){
      calculateRowTotal(t);
    }
  });
  posBody.addEventListener('input', e => {
    const t = e.target;
    if (t.classList.contains('menge') || t.classList.contains('preis')){
      calculateRowTotal(t);
    }
  });
  posBody.addEventListener('click', e => {
    if (e.target.closest('.btn-remove')){
      e.preventDefault();
      const tr = e.target.closest('tr');
      tr.remove(); recalcTotal();
    }
  });

  toggleNewClient.addEventListener('click', function(){
    const visible = newClientBox.style.display !== 'none';
    newClientBox.style.display = visible ? 'none' : '';
    if (!visible){ clientSelect.value = ''; }
  });

  document.getElementById('angebotForm').addEventListener('submit', async function(ev){
    ev.preventDefault();
    // Validate at least one valid position (product or manual with description + price)
    const rows = Array.from(posBody.querySelectorAll('tr'));
    if (!rows.length){ alert('Bitte mindestens eine Position hinzufügen.'); return; }
    const hasValid = rows.some(tr => {
      const sel = tr.querySelector('.product');
      const val = (sel && sel.value) ? sel.value : '';
      if (val && val !== 'manual') return true; // real product
      if (val === 'manual'){
        const name = (tr.querySelector('.produkt-name')?.value||'').trim();
        const price = parseFloat(tr.querySelector('.preis')?.value||'0');
        return name !== '' && price > 0;
      }
      return false;
    });
    if (!hasValid){ alert('Bitte ein Produkt auswählen oder manuelle Position mit Produktname und Preis angeben.'); return; }

    const fd = new FormData(this);
    try{
        const res = await fetch('/pages/angebot_speichern.php', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: fd
        });

        const text = await res.text();   // ← always safe
        let json;

        try {
            json = JSON.parse(text);
        } catch (e) {
            console.error('SERVER RESPONSE:', text);
            alert('Serverfehler – siehe Konsole');
            return;
        }

        if (json && json.success){
        alert('Angebot gespeichert.');
        const url = json.pdf_url || ('/pages/angebot_pdf.php?id=' + (json.angebot_id || '') + '&force=1');
        if (url){ window.location.href = url; }
      } else {
        alert((json && json.error) ? json.error : 'Fehler beim Speichern.');
      }
    } catch (e){
      alert('Fehler: ' + (e && e.message ? e.message : e));
    }
  });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
