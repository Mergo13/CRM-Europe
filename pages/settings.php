<?php
// pages/settings.php
// UI to edit company settings used for PDF header/footer and SEPA QR.

declare(strict_types=1);

// Small helpers
if (!function_exists('esc')) {
    function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('asset_url')) {
    function asset_url($path = '') { return '/' . ltrim($path, '/'); }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Einstellungen – Firmendaten</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .toolbar{background:linear-gradient(135deg,#0d6efd,#6f42c1);color:#fff;border-radius:12px}
        .form-section{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
        .muted{color:#6c757d}
    </style>
</head>
<body class="bg-light">
<div class="container my-4">
    <div class="p-4 mb-4 toolbar d-flex justify-content-between align-items-center">
        <h2 class="m-0"><i class="bi bi-gear me-2"></i>Einstellungen – Firmendaten</h2>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-light btn-sm" href="/index.php" title="Home"><i class="bi bi-house"></i></a>
            <a class="btn btn-outline-light btn-sm" href="/pages/profile.php" title="Benutzerkonto"><i class="bi bi-person"></i></a>
            <button class="btn btn-outline-light btn-sm" id="btnBackup" type="button" title="Daten sichern (Backup)"><i class="bi bi-download"></i></button>
            <label class="btn btn-outline-light btn-sm mb-0" title="Daten wiederherstellen (Recovery)">
                <i class="bi bi-upload"></i>
                <input id="restoreInput" type="file" accept="application/json" style="display:none">
            </label>
        </div>
    </div>

    <div id="result"></div>

    <form id="settingsForm" class="form-section p-4">
        <div class="row g-3">

            <!-- Company / Legal -->
            <div class="col-md-6">
                <label class="form-label">Firmenname (Anzeige)</label>
                <input type="text" class="form-control" name="company_name" required>
                <div class="form-text">Wird im PDF-Kopf angezeigt.</div>
            </div>

            <div class="col-md-6">
                <label class="form-label">
                    Zahlungsempfänger (SEPA QR)
                </label>
                <input type="text" class="form-control" name="creditor_name" required>
                <div class="form-text">
                    Wichtig für SEPA QR-Code. Keine Sonderzeichen wie <code>&amp;</code> oder Umlaute.
                    Wird automatisch EPC-konform gespeichert.
                </div>
            </div>

            <!-- Contact -->
            <div class="col-md-6">
                <label class="form-label">E-Mail</label>
                <input type="email" class="form-control" name="email">
            </div>

            <div class="col-md-6">
                <label class="form-label">Telefon</label>
                <input type="text" class="form-control" name="phone">
            </div>

            <!-- Address -->
            <div class="col-md-6">
                <label class="form-label">Adresse (Zeile 1)</label>
                <input type="text" class="form-control" name="address_line1">
            </div>
            <div class="col-md-6">
                <label class="form-label">Adresse (Zeile 2)</label>
                <input type="text" class="form-control" name="address_line2">
            </div>

            <!-- Business -->
            <div class="col-md-4">
                <label class="form-label">Webseite</label>
                <input type="text" class="form-control" name="website">
            </div>
            <div class="col-md-4">
                <label class="form-label">USt-IdNr. (VAT)</label>
                <input type="text" class="form-control" name="vat">
            </div>

            <!-- Bank -->
            <div class="col-md-6">
                <label class="form-label">IBAN</label>
                <input type="text" class="form-control" name="iban" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">BIC</label>
                <input type="text" class="form-control" name="bic" required>
            </div>

            <div class="col-md-6">
                <label class="form-label">Bankname</label>
                <input type="text" class="form-control" name="bank_name" placeholder="z. B. Erste Bank und Sparkassen">
            </div>

            <!-- Logo -->
            <div class="col-md-8">
                <label class="form-label">Logo Pfad (optional)</label>
                <div class="input-group">
                    <input id="logoPathInput" type="text" class="form-control" name="logo_path"
                           placeholder="/assets/logo.png oder /public/uploads/logo.png">
                    <button class="btn btn-outline-secondary" type="button" id="btnUploadLogo">
                        <i class="bi bi-upload"></i> Datei hochladen
                    </button>
                </div>
                <div class="form-text">Wird im PDF-Kopf verwendet.</div>
                <div class="mt-2 d-flex align-items-center gap-3">
                    <img id="logoPreview" src="" alt="Logo Vorschau"
                         style="max-height:60px; display:none; border:1px solid #ddd; padding:4px; background:#fff; border-radius:6px;">
                    <input id="logoUploadInput" type="file" accept="image/*"
                           class="form-control form-control-sm" style="max-width:320px; display:none;">
                </div>
            </div>

        </div>

        <div class="d-flex justify-content-end gap-2 mt-4">
            <button type="button" id="btnReload" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-clockwise"></i> Neu laden
            </button>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save2"></i> Speichern
            </button>
        </div>
    </form>

    <p class="mt-3 muted small">
        Hinweis: Diese Daten werden in <code>settings_company</code> (id=1) gespeichert.
        Sobald Zahlungsempfänger, IBAN und BIC gesetzt sind, ist der SEPA QR-Code automatisch aktiv.
    </p>
</div>

<script>
    async function loadSettings(){
        try{
            const r = await fetch('/pages/api/company_settings_get.php', {cache:'no-store'});
            const json = await r.json();
            if (!json.success) throw new Error(json.error || 'Fehler beim Laden');
            const d = json.data || {};
            const f = document.getElementById('settingsForm');

            f.company_name.value   = d.company_name   || '';
            f.creditor_name.value = d.creditor_name  || '';
            f.address_line1.value = d.address_line1  || '';
            f.address_line2.value = d.address_line2  || '';
            f.phone.value         = d.phone          || '';
            f.email.value         = d.email          || '';
            f.website.value       = d.website        || '';
            f.vat.value           = d.vat            || '';
            f.iban.value          = d.iban           || '';
            f.bic.value           = d.bic            || '';
            f.bank_name.value     = d.bank_name      || '';
            f.logo_path.value     = d.logo_path      || '';

            const preview = document.getElementById('logoPreview');
            if (d.logo_path) {
                preview.src = d.logo_path;
                preview.style.display = 'inline-block';
            } else {
                preview.style.display = 'none';
            }
        } catch(e){
            showMsg('danger', 'Fehler: ' + e.message);
        }
    }

    async function saveSettings(ev){
        ev.preventDefault();
        const f = document.getElementById('settingsForm');
        const data = Object.fromEntries(new FormData(f).entries());

        try{
            const r = await fetch('/pages/api/company_settings_save.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify(data)
            });
            const json = await r.json();
            if (!json.success) throw new Error(json.error || 'Speichern fehlgeschlagen');
            showMsg('success', 'Gespeichert. SEPA QR-Code ist nun automatisch verfügbar.');
        }catch(e){
            showMsg('danger', 'Fehler: ' + e.message);
        }
    }

    function showMsg(type, msg){
        document.getElementById('result').innerHTML =
            `<div class="alert alert-${type} alert-dismissible fade show">
            ${msg}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
         </div>`;
    }

    // Backup/Restore handlers
    async function triggerBackup(){
        try {
            const r = await fetch('/pages/api/backup_export.php', { credentials: 'include' });
            if (!r.ok) throw new Error('HTTP ' + r.status);
            const blob = await r.blob();
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            const stamp = new Date().toISOString().replace(/[:.]/g,'-');
            a.href = url;
            a.download = `rechnung-app-backup-${stamp}.json`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
            showMsg('success', 'Backup wurde erstellt und heruntergeladen.');
        } catch (e) {
            showMsg('danger', 'Backup fehlgeschlagen: ' + e.message);
        }
    }

    async function triggerRestore(file){
        if (!file) return;
        if (!confirm('Backup wiederherstellen? Bestehende Daten können überschrieben werden.')) return;
        const fd = new FormData();
        fd.append('backup', file);
        try {
            const r = await fetch('/pages/api/backup_import.php', { method: 'POST', body: fd, credentials: 'include' });
            const json = await r.json();
            if (!json.success) throw new Error(json.error || 'Wiederherstellung fehlgeschlagen');
            showMsg('success', 'Wiederherstellung abgeschlossen.');
            await loadSettings();
        } catch (e) {
            showMsg('danger', 'Wiederherstellung fehlgeschlagen: ' + e.message);
        } finally {
            document.getElementById('restoreInput').value = '';
        }
    }

    document.getElementById('settingsForm').addEventListener('submit', saveSettings);
    document.getElementById('btnReload').addEventListener('click', loadSettings);
    document.getElementById('btnBackup')?.addEventListener('click', triggerBackup);
    document.getElementById('restoreInput')?.addEventListener('change', (ev)=>{ triggerRestore(ev.target.files?.[0]); });
    document.addEventListener('DOMContentLoaded', loadSettings);
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
