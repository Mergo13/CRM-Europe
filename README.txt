CRM Full Update Package
-----------------------
Contents:
- assets/js/*.js (frontend scripts)
- pages/api/* (dynamic APIs for rechnungen, angeboten, mahnungen, lieferscheinen)

Install:
1. Copy JS files to your project's /assets/js/ and include them after your existing list scripts:
   <script src="/assets/js/lists.shared.js"></script>
   <script src="/assets/js/lists.actions.js"></script>
   <script src="/assets/js/lists.shared.create.link.js"></script>
   <script src="/assets/js/lists.fix-links.js"></script>
   <script src="/assets/js/lists.preview-fix.js"></script>

2. Copy pages/api/* to your project's /pages/api/

3. Ensure each list page sets window.LIST_CONFIG with at least:
   api_list, api_get, api_bulk, api_export, pdf_url, create_page (optional). Example:
   <script>
     window.LIST_CONFIG = {
       api_list: '/pages/api/rechnungen_list.php',
       api_get: '/pages/api/rechnungen_get.php',
       api_bulk: '/pages/api/rechnungen_bulk.php',
       api_export: '/pages/api/rechnungen_export.php',
       pdf_url: '/pages/rechnung_pdf.php',
       create_page: '/pages/rechnung.php',
       per_page: 25
     };
   </script>

4. Secure API endpoints (add auth) before production.

If you want, I can patch your list pages automatically — say the word and I'll generate patched files.
