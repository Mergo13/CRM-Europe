Updated lists.shared.js written to assets/js/lists.shared.js.
This version maps common DB column names automatically:
- Nummer: rechnungsnummer, nummer, number
- Kunde: kunde, client_name, client_id (falls only id is present we show the id)
- Betrag: betrag, gesamt, total, amount, summe
- Date: created_at, date, datum, faelligkeit
- File: file_url (from API) or pdf_path, pdf, filename, file_path

Steps to use:
1. Replace your existing assets/js/lists.shared.js with this file.
2. Keep the API files you installed earlier (they return file_url when possible).
3. If you want client names instead of client_id, we can add a client lookup API to resolve names.
