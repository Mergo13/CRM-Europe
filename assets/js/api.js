// assets/js/api.js

(function(global){
  const API = {
    angeboten: {
      list:    '/pages/api/angeboten_list.php',
      get:     '/pages/api/angeboten_get.php',
      create:  '/pages/api/angeboten_create.php',
      bulk:    '/pages/api/angeboten_bulk.php',
      email:   '/pages/api/angeboten_email.php',
      export:  '/pages/api/angeboten_export.php'
    },
    rechnungen: {
      list:    '/pages/api/rechnungen_list.php',
      get:     '/pages/api/rechnungen_get.php',
      create:  '/pages/api/rechnungen_create.php',
      bulk:    '/pages/api/rechnungen_bulk.php',
      email:   '/pages/api/rechnungen_email.php',
      export:  '/pages/api/rechnungen_export.php',
      monthly: '/pages/api/invoices_monthly.php'
    },
    lieferscheine: {
      list:    '/pages/api/lieferscheinen_list.php',
      get:     '/pages/api/lieferscheinen_get.php',
      create:  '/pages/api/lieferscheinen_create.php',
      bulk:    '/pages/api/lieferscheinen_bulk.php',
      email:   '/pages/api/lieferscheinen_email.php',
      export:  '/pages/api/lieferscheinen_export.php'
    },
    mahnungen: {
      list:    '/pages/api/mahnungen_list.php',
      get:     '/pages/api/mahnungen_get.php',
      create:  '/pages/api/mahnungen_create.php',
      bulk:    '/pages/api/mahnungen_bulk.php',
      email:   '/pages/api/mahnungen_email.php',
      export:  '/pages/api/mahnungen_export.php'
    },
    misc: {
      search:      '/pages/api/search.php',
      exportPdf:   '/pages/api/export_pdf.php',
      files:       '/pages/api/files.php',
      calendar:    '/pages/api/calendar_events.php',
      recentEvents:'/pages/api/recent_events.php',
      productsImport: '/pages/api/products_import_csv.php',
      sendToMahnung: '/pages/api/send_to_mahnung.php',
      generateData:  '/pages/api/generate_data.php',
      realtime:      '/pages/api/ga_realtime.php'
    }
  };

  // Helper for GET and POST
  function fetchGet(url, params={}) {
    const qs = new URLSearchParams(params).toString();
    return fetch(qs ? `${url}?${qs}` : url).then(r => r.json());
  }
  function fetchPost(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(data)
    }).then(r => r.json());
  }

  // Expose globally for dashboard.php and -list pages
  if (!global.API) global.API = API; else try { global.API = Object.assign({}, API, global.API); } catch(_) {}
  if (!global.fetchGet) global.fetchGet = fetchGet;
  if (!global.fetchPost) global.fetchPost = fetchPost;

})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));