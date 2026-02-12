<?php
// pages/angeboten.php - list for Angebote
require_once __DIR__ . '/../config/db.php';
?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Angebote — Liste</title>
  <link rel="stylesheet" href="/assets/css/lists.css">
</head>
<body>
  <main class="container">
    <header class="toolbar">
      <h1>Angebote</h1>
      <div class="controls">
        <input id="search" type="search" placeholder="Suche..." />
        <select id="filter-status">
          <option value="">Alle Status</option>
          <option value="open">Offen</option>
          <option value="done">Erledigt</option>
          <option value="cancelled">Storniert</option>
        </select>
        <button id="export-csv">Export CSV</button>
      </div>
    </header>

    <section>
      <table id="list-table" class="striped">
        <thead>
          <tr>
            <th><input id="select-all" type="checkbox"></th>
            <th data-sort="nummer">Nummer</th>
            <th data-sort="kunde">Kunde</th>
            <th data-sort="betrag">Betrag</th>
            <th data-sort="status">Status</th>
            <th data-sort="date">Datum</th>
            <th>Aktionen</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
      <div class="pagination">
        <button id="prev-page">← Vorherige</button>
        <span id="page-info">Seite 1</span>
        <button id="next-page">Nächste →</button>
      </div>

      <div class="bulk-actions" id="floatingActions">        <button id="bulk-mark">Als erledigt markieren</button>
        <button id="bulk-delete">Löschen</button>
      </div>
    </section>
  </main>

  <div id="modal" class="modal" aria-hidden="true">
    <div class="modal-content">
      <button id="modal-close" class="modal-close">✕</button>
      <div id="modal-body"></div>
    </div>
  </div>

  <script>
    window.LIST_CONFIG = {
      api_list: '/pages/api/angeboten_list.php',
      api_get: '/pages/api/angeboten_get.php',
      api_bulk: '/pages/api/angeboten_bulk.php',
      api_export: '/pages/api/angeboten_export.php',
      pdf_url: '/pages/angeboten_pdf.php',
      per_page: 25,
      default_sort: 'date'
    };
  </script>
  <script src="/assets/js/lists.shared.js"></script>
</body>
</html>
