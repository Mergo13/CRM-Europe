<?php

// pages/partials/csv_import_widget.php
//
// Reusable CSV Import Widget
// Usage on any page:
// ---------------------------------------
// $csv_import_url = "api/products_import_csv.php"; // or absolute /pages/api/...
// $csv_api_token  = ""; // optional
// include __DIR__ . "/partials/csv_import_widget.php";
// ---------------------------------------

if (!isset($csv_import_url)) {
    // Better to use a relative path since produkte.php is in /pages
    $csv_import_url = "api/products_import_csv.php";
}

if (!isset($csv_api_token)) {
    $csv_api_token = "";
}
?>

<div class="csv-import-widget mb-3"
     data-import-url="<?= htmlspecialchars($csv_import_url) ?>"
     data-api-token="<?= htmlspecialchars($csv_api_token) ?>">

    <input type="file" class="csv-input" accept=".csv" style="display:none">

    <button type="button" class="btn btn-outline-primary csv-choose-btn">
        📁 CSV auswählen
    </button>

    <button type="button" class="btn btn-success csv-upload-btn ms-2">
        📤 CSV importieren
    </button>

    <span class="csv-file-name text-muted small ms-2">
        Keine Datei ausgewählt
    </span>

    <div class="csv-result mt-2"></div>
</div>

<script>
    (function () {
        // Bind only to *this* widget instance rendered just above this script
        const script = document.currentScript;
        if (!script) return;
        const widget = script.previousElementSibling;
        if (!widget || !widget.classList.contains('csv-import-widget')) return;

        const fileInput  = widget.querySelector('.csv-input');
        const chooseBtn  = widget.querySelector('.csv-choose-btn');
        const uploadBtn  = widget.querySelector('.csv-upload-btn');
        const fileNameEl = widget.querySelector('.csv-file-name');
        const resultBox  = widget.querySelector('.csv-result');

        const importUrl  = widget.dataset.importUrl;
        const apiToken   = widget.dataset.apiToken;

        if (!fileInput || !chooseBtn || !uploadBtn || !fileNameEl || !resultBox || !importUrl) {
            return;
        }

        // "CSV auswählen" → open file picker
        chooseBtn.addEventListener('click', function () {
            fileInput.click();
        });

        // show selected filename
        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files.length > 0) {
                fileNameEl.textContent = fileInput.files[0].name;
            } else {
                fileNameEl.textContent = 'Keine Datei ausgewählt';
            }
        });

        // "CSV importieren" → send via fetch
        uploadBtn.addEventListener('click', async function () {
            resultBox.innerHTML = '';

            if (!fileInput.files || fileInput.files.length === 0) {
                resultBox.innerHTML =
                    '<div class="alert alert-warning">Bitte wählen Sie zuerst eine CSV-Datei aus.</div>';
                return;
            }

            const formData = new FormData();
            formData.append('csv_file', fileInput.files[0]);

            const options = {
                method: 'POST',
                body: formData
            };

            if (apiToken) {
                options.headers = { 'X-API-TOKEN': apiToken };
            }

            uploadBtn.disabled = true;
            const origText = uploadBtn.textContent;
            uploadBtn.textContent = 'Import läuft …';

            resultBox.innerHTML =
                '<div class="alert alert-info">Import wird durchgeführt, bitte warten …</div>';

            try {
                const res = await fetch(importUrl, options);
                const data = await res.json().catch(() => null);

                if (!data) {
                    resultBox.innerHTML =
                        '<div class="alert alert-danger">Antwort ist kein gültiges JSON.</div>';
                    return;
                }

                if (!data.success) {
                    resultBox.innerHTML =
                        '<div class="alert alert-danger">Fehler beim Import: ' +
                        (data.error || 'Unbekannter Fehler') + '</div>';

                    if (data.errors && data.errors.length) {
                        resultBox.innerHTML +=
                            '<pre class="small mt-2">' +
                            JSON.stringify(data.errors, null, 2) +
                            '</pre>';
                    }
                    return;
                }

                resultBox.innerHTML =
                    '<div class="alert alert-success">' +
                    '✔ Import abgeschlossen!<br>' +
                    'Neue Produkte: <strong>' + data.imported + '</strong><br>' +
                    'Aktualisiert: <strong>' + data.updated + '</strong><br>' +
                    'Fehler: <strong>' + data.errors_count + '</strong>' +
                    '</div>';

                if (data.errors && data.errors.length) {
                    resultBox.innerHTML +=
                        '<pre class="small mt-2">' +
                        JSON.stringify(data.errors, null, 2) +
                        '</pre>';
                }

            } catch (err) {
                console.error(err);
                resultBox.innerHTML =
                    '<div class="alert alert-danger">Fehler: ' + err.message + '</div>';
            } finally {
                uploadBtn.disabled = false;
                uploadBtn.textContent = origText;
            }
        });
    })();
</script>
