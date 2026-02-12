<?php
declare(strict_types=1);

// Minimal settings page to edit creditor_name (Zahlungsempfänger) for SEPA/EPC QR
require_once __DIR__ . '/../config/db.php';

$pdo = $GLOBALS['pdo'] ?? ($pdo ?? null);
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Keine Datenbankverbindung';
    exit;
}

// Ensure base row exists
try {
    $pdo->exec("INSERT INTO settings_company (id, company_name) VALUES (1, 'Ihre Firma') ON DUPLICATE KEY UPDATE company_name = company_name");
} catch (Throwable $e) { /* ignore */ }

// Load current value
$cur = '';
try {
    $stmt = $pdo->query("SELECT creditor_name FROM settings_company WHERE id = 1");
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    $cur = (string)($row['creditor_name'] ?? '');
} catch (Throwable $e) {
    $cur = '';
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Bankeinstellungen – Zahlungsempfänger</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --bg: #f6f7f9; --card:#fff; --text:#222; --muted:#6b7280; --primary:#198754; --border:#e5e7eb;
        }
        body { margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Arial, "Apple Color Emoji","Segoe UI Emoji"; background: var(--bg); color: var(--text); }
        .wrap { max-width: 720px; margin: 40px auto; padding: 0 16px; }
        .card { background: var(--card); border:1px solid var(--border); border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,.06); }
        .card header { padding: 16px 20px; border-bottom:1px solid var(--border); }
        .card header h1 { font-size: 1.25rem; margin:0; }
        .card .body { padding: 20px; }
        label { display:block; font-weight:600; margin-bottom:8px; }
        input[type="text"] { width:100%; padding:12px 14px; border:1px solid var(--border); border-radius:10px; font-size:1rem; }
        .hint { color: var(--muted); font-size: .9rem; margin-top: 6px; }
        .row { display: grid; grid-template-columns: 1fr; gap: 16px; }
        .actions { display:flex; justify-content:flex-end; gap: 12px; margin-top: 20px; }
        .btn { border:1px solid var(--border); background:#fff; padding:10px 14px; border-radius:10px; cursor:pointer; font-weight:600; }
        .btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        .note { margin-top:12px; font-size:.95rem; color: var(--muted); }
        .alert { margin-top: 14px; padding: 10px 12px; border-radius: 10px; }
        .alert-success { background:#e7f5ee; color:#0a3622; border:1px solid #cfe9dc; }
        .alert-error { background:#fdecea; color:#611a15; border:1px solid #f5c2c0; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <header>
            <h1>Bankeinstellungen – Zahlungsempfänger</h1>
        </header>
        <div class="body">
            <form id="settingsForm">
                <div class="row">
                    <div>
                        <label for="creditor_name">Zahlungsempfänger (Creditor Name)</label>
                        <input type="text" id="creditor_name" name="creditor_name" maxlength="120" value="<?= htmlspecialchars($cur, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" placeholder="z. B. Vision L&T e.U.">
                        <div class="hint">Wird im SEPA/EPC-QR als Empfängername verwendet. Max. 70 Zeichen empfohlen.</div>
                    </div>
                </div>
                <div class="actions">
                    <button type="button" class="btn" onclick="location.href='/'">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
                <div id="msg" aria-live="polite"></div>
            </form>
            <p class="note">Hinweis: IBAN und BIC können separat im Firmensettings-Formular gepflegt werden.</p>
        </div>
    </div>
</div>

<script>
(function(){
    const form = document.getElementById('settingsForm');
    const msg = document.getElementById('msg');
    function show(status, text) {
        msg.innerHTML = '<div class="alert '+ (status ? 'alert-success' : 'alert-error') +'">'+ text +'</div>';
    }
    form.addEventListener('submit', async function(e){
        e.preventDefault();
        const creditor_name = (document.getElementById('creditor_name').value || '').trim();
        try {
            const res = await fetch('/pages/api/company_settings_save.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ creditor_name })
            });
            const data = await res.json().catch(()=>null);
            if (res.ok && data && data.success) {
                show(true, 'Gespeichert.');
            } else {
                show(false, 'Fehler beim Speichern' + (data && data.error ? ': ' + data.error : '.'));
            }
        } catch (err) {
            show(false, 'Netzwerkfehler: ' + (err && err.message ? err.message : String(err)));
        }
    });
})();
</script>
</body>
</html>
