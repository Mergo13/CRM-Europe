<?php
// pages/rechnung.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/tax.php';

/* ---------------- DATA ---------------- */
$clients = [];
$products = [];
$priceTiers = [];
$selectedClient = null;

if (isset($pdo)) {

    if (!empty($_GET['client_id']) && is_numeric($_GET['client_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
        $stmt->execute([$_GET['client_id']]);
        $selectedClient = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $clients = $pdo->query("
        SELECT id, firma, name
        FROM clients
        ORDER BY firma, name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $products = $pdo->query("
        SELECT id, name, preis
        FROM produkte
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT produkt_id, menge, preis
        FROM produkt_preise
        ORDER BY produkt_id, menge
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $priceTiers[(int)$r['produkt_id']][] = [
            'qty' => (int)$r['menge'],
            'price' => (float)$r['preis']
        ];
    }
}

$today = date('Y-m-d');
$defaultRechnungsnummer = '';
if (isset($pdo)) {
    try {
        $year = date('Y', strtotime($today));
        $prefix = 'R-' . $year . '-';
        $stmt = $pdo->prepare("\n            SELECT MAX(CAST(SUBSTRING_INDEX(rechnungsnummer, '-', -1) AS UNSIGNED)) AS max_seq\n            FROM rechnungen\n            WHERE rechnungsnummer LIKE ?\n        ");
        $stmt->execute([$prefix . '%']);
        $seq = ((int)($stmt->fetchColumn() ?: 0)) + 1;
        $defaultRechnungsnummer = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        // Fallback if table missing or query fails
        $defaultRechnungsnummer = 'R-' . date('Y') . '-' . str_pad('1', 4, '0', STR_PAD_LEFT);
    }
} else {
    $defaultRechnungsnummer = 'R-' . date('Y') . '-' . str_pad('1', 4, '0', STR_PAD_LEFT);
}
?>

<?php include '../includes/header.php'; ?>

<div class="container mt-4">
    <h2>Neue Rechnung</h2>

    <div id="saveResult"></div>

    <form id="invoiceForm" action="rechnung_save.php" method="POST">

        <!-- ================= CLIENT ================= -->
        <div class="card mb-4">
            <div class="card-header">Kunde</div>
            <div class="card-body">

                <select class="form-select mb-3" onchange="toggleClientFields(this.value)">
                    <option value="select">Bestehender Kunde</option>
                    <option value="new">Neuer Kunde</option>
                </select>

                <div id="existing-client">
                    <input type="text" id="clientFilter" class="form-control form-control-sm mb-2" placeholder="Suchen…" aria-label="Kunde filtern">
                    <select name="client_id" id="client_id" class="form-select form-select-lg text-dark bg-white border-dark">
                        <option value="">— Kunde auswählen —</option>
                        <?php foreach ($clients as $c): $firma = trim((string)($c['firma'] ?? '')); $name = trim((string)($c['name'] ?? '')); $label = $firma !== '' && $name !== '' ? ($firma . ' — ' . $name) : ($firma !== '' ? $firma : $name); ?>
                            <option value="<?= (int)$c['id'] ?>" <?= ($selectedClient && (int)$selectedClient['id']===(int)$c['id'])?'selected':'' ?>>
                                <?= htmlspecialchars($label ?: ('Kunde #' . (int)$c['id'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="new-client" style="display:none">
                    <input name="firma" class="form-control mb-2" placeholder="Firma">
                    <input name="name" class="form-control mb-2" placeholder="Name">
                    <input name="email" class="form-control mb-2" placeholder="E-Mail">
                    <input name="adresse" class="form-control mb-2" placeholder="Adresse">
                </div>

            </div>
        </div>

        <!-- ================= INVOICE ================= -->
        <div class="card mb-4">
            <div class="card-header">Rechnung</div>
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label">Rechnungsnummer</label>
                    <input name="rechnungsnummer" class="form-control" value="<?= $defaultRechnungsnummer ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Datum</label>
                    <input type="date" name="datum" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
        </div>

        <!-- ================= PRODUCTS ================= -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between">
                Produkte
                <button type="button" class="btn btn-sm btn-success" onclick="addProductRow()">+ Produkt</button>
            </div>

            <div class="card-body">
                <table class="table table-bordered align-middle">
                    <thead>
                    <tr>
                        <th>Produkt</th>
                        <th width="100">Menge</th>
                        <th width="140">Einzelpreis (€)</th>
                        <th width="140">Gesamt (€)</th>
                        <th width="60"></th>
                    </tr>
                    </thead>
                    <tbody id="productRows"></tbody>
                </table>

                <div class="text-end">
                    <strong>Zwischensumme (netto): € <span id="totalAmount">0.00</span></strong>
                </div>
            </div>
        </div>

        <!-- ================= NOTE ================= -->
        <div class="card mb-4">
            <div class="card-header">Hinweis</div>
            <div class="card-body">
                <textarea name="hinweis" class="form-control" rows="3"></textarea>
            </div>
        </div>

        <button class="btn btn-primary">💾 Rechnung speichern</button>

    </form>
</div>

<script>
    const products = <?= json_encode($products, JSON_UNESCAPED_UNICODE) ?>;
    const priceTiers = <?= json_encode($priceTiers) ?>;

    /* ---------------- HELPERS ---------------- */
    function toggleClientFields(v){
        document.getElementById('new-client').style.display = v==='new'?'block':'none';
        document.getElementById('existing-client').style.display = v==='new'?'none':'block';
    }

    function getTierPrice(id, qty){
        let price = null;
        (priceTiers[id]||[]).forEach(t=>{
            if(qty>=t.qty) price=t.price;
        });
        const p = products.find(p=>String(p.id)===String(id));
        return price!==null ? price : (p?parseFloat(p.preis):0);
    }

    /* ---------------- PRODUCTS ---------------- */
    function addProductRow(){
        const tr = document.createElement('tr');
        tr.innerHTML = `
<td>
<select name="product_id[]" class="form-select mb-1" onchange="onProductChange(this)">
<option value="">-- Produkt wählen --</option>
<option value="manual">Manuell eingeben</option>
${products.map(p=>`<option value="${p.id}">${p.name}</option>`).join('')}
</select>
<input name="beschreibung[]" class="form-control mb-1" placeholder="Manueller Produktname" style="display:none">
<textarea name="beschreibung_text[]" class="form-control" rows="2" placeholder="Beschreibung (optional)" style="display:none"></textarea>
</td>
<td><input name="menge[]" type="number" class="form-control" value="1" min="1" oninput="calcRow(this)" onfocus="this.select()"></td>
<td><input name="preis[]" type="number" class="form-control" step="0.01" min="0" oninput="calcRow(this)" onfocus="this.select()"></td>
<td><input name="gesamt[]" class="form-control total-field" readonly></td>
<td><button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)">✖</button></td>
`;
        document.getElementById('productRows').appendChild(tr);
    }

    function onProductChange(sel){
        const row = sel.closest('tr');
        const name = row.querySelector('[name="beschreibung[]"]');
        const desc = row.querySelector('[name="beschreibung_text[]"]');
        const qty = row.querySelector('[name="menge[]"]');
        const price = row.querySelector('[name="preis[]"]');

        if(sel.value==='manual'){
            name.style.display = desc.style.display = 'block';
            price.value = '0.00';
        } else {
            name.style.display = desc.style.display = 'none';
            price.value = getTierPrice(sel.value, qty.value).toFixed(2);
        }
        calcRow(price);
    }

    function calcRow(el){
        const row = el.closest('tr');
        const q = parseFloat(row.querySelector('[name="menge[]"]').value)||0;
        const p = parseFloat(row.querySelector('[name="preis[]"]').value)||0;
        row.querySelector('[name="gesamt[]"]').value = (q*p).toFixed(2);
        updateTotal();
    }

    function updateTotal(){
        let t=0;
        document.querySelectorAll('.total-field').forEach(e=>t+=parseFloat(e.value)||0);
        document.getElementById('totalAmount').textContent=t.toFixed(2);
    }

    function removeRow(btn){
        btn.closest('tr').remove();
        updateTotal();
    }

    /* ---------------- AJAX SUBMIT ---------------- */
    document.addEventListener('DOMContentLoaded', function(){
        // Simple client filter for faster finding
        const cf = document.getElementById('clientFilter');
        const cs = document.getElementById('client_id');
        if (cf && cs) {
            cf.addEventListener('input', function(){
                const q = (this.value || '').toLowerCase();
                Array.from(cs.options).forEach((opt, idx) => {
                    if (idx === 0) { opt.hidden = false; return; }
                    const t = (opt.textContent || '').toLowerCase();
                    opt.hidden = q !== '' && !t.includes(q);
                });
            });
        }
        addProductRow();

        const form = document.getElementById('invoiceForm');
        const saveResult = document.getElementById('saveResult');

        form.addEventListener('submit', async function(e){
            e.preventDefault();
            saveResult.innerHTML = '';

            // Convert manual rows → proper backend format
            document.querySelectorAll('#productRows tr').forEach(row=>{
                const sel = row.querySelector('[name="product_id[]"]');
                const name = row.querySelector('[name="beschreibung[]"]');
                if(sel && sel.value==='manual'){
                    sel.value='';
                    name.name='beschreibung[]';
                }
            });

            const submitBtn = form.querySelector('button[type="submit"], button.btn-primary');
            const originalText = submitBtn ? submitBtn.textContent : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Speichern…';
            }

            try {
                const res = await fetch(form.action,{
                    method:'POST',
                    body:new FormData(form),
                    headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}
                });

                let data;
                try {
                    data = await res.json();
                } catch(parseErr){
                    throw new Error('Unerwartete Server-Antwort.');
                }

                if(res.ok && data && data.success){
                    // Inform user and open PDF immediately so they don't need to refresh
                    saveResult.innerHTML = `
<div class="alert alert-success d-flex justify-content-between align-items-center">
Rechnung gespeichert
<a class="btn btn-sm btn-primary" target="_blank" href="${data.pdf_url}">PDF öffnen</a>
</div>`;

                    // Optional: keep the new ID on the page (hidden field) if needed by other actions
                    let idInput = form.querySelector('input[name="rechnung_id"]');
                    if(!idInput){
                        idInput = document.createElement('input');
                        idInput.type = 'hidden';
                        idInput.name = 'rechnung_id';
                        form.appendChild(idInput);
                    }
                    idInput.value = String(data.rechnung_id || '');

                    // Open the generated PDF in a new tab
                    if (data.pdf_url) {
                        window.open(data.pdf_url, '_blank');
                    }
                } else {
                    const msg = (data && data.error) ? data.error : ('HTTP ' + res.status);
                    saveResult.innerHTML = `<div class="alert alert-danger">${msg}</div>`;
                }
            } catch(err){
                saveResult.innerHTML = `<div class="alert alert-danger">${(err && err.message) ? err.message : err}</div>`;
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                }
            }
        });
    });
</script>

<?php include '../includes/footer.php'; ?>
