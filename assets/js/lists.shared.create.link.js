// assets/js/lists.shared.create.link.js
// Adds a "Neu ▾" dropdown button linking to create pages for Rechnung, Angebot, Mahnung, Lieferschein.
(function () {
  'use strict';
  if (!window.LIST_CONFIG) window.LIST_CONFIG = {};

  const defaults = {
    rechnungen: '/pages/rechnung.php',
    angeboten: '/pages/angebot.php',
    mahnungen: '/pages/mahnung.php',
    lieferscheinen: '/pages/lieferschein.php'
  };

  const createPages = Object.assign({}, defaults, window.LIST_CONFIG.create_pages || {});

  function deriveModuleFromApi(apiPath) {
    if (!apiPath) return null;
    const m = apiPath.match(/\/([^\/]+)_list\.php$/);
    return m ? m[1] : null;
  }

  function buildDropdownItems() {
    const items = [];
    const module = deriveModuleFromApi(window.LIST_CONFIG.api_list);
    if (module && createPages[module]) {
      items.push({ label: 'Neu: ' + module, url: createPages[module] });
    }
    items.push({ label: 'Neue Rechnung', url: createPages.rechnungen });
    items.push({ label: 'Neues Angebot', url: createPages.angeboten });
    items.push({ label: 'Neue Mahnung', url: createPages.mahnungen });
    items.push({ label: 'Neuer Lieferschein', url: createPages.lieferscheinen });
    return items;
  }

  function injectCreateDropdown() {
    const toolbar = document.querySelector('.toolbar .controls');
    if (!toolbar || document.getElementById('create-dropdown')) return;

    const container = document.createElement('span');
    container.id = 'create-dropdown';
    container.style.position = 'relative';
    container.style.display = 'inline-block';

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.innerText = 'Neu ▾';
    btn.title = 'Erstellen';
    btn.style.marginRight = '8px';
    btn.style.background = '#eaf6ff';

    const menu = document.createElement('div');
    menu.style.position = 'absolute';
    menu.style.top = '100%';
    menu.style.left = '0';
    menu.style.background = '#fff';
    menu.style.border = '1px solid #ddd';
    menu.style.boxShadow = '0 4px 10px rgba(0,0,0,0.06)';
    menu.style.padding = '6px 0';
    menu.style.minWidth = '200px';
    menu.style.zIndex = '9999';
    menu.style.display = 'none';
    menu.style.borderRadius = '6px';

    const items = buildDropdownItems();
    items.forEach(it => {
      const a = document.createElement('a');
      a.href = it.url || '#';
      a.innerText = it.label || it.url;
      a.style.display = 'block';
      a.style.padding = '8px 12px';
      a.style.textDecoration = 'none';
      a.style.color = '#111';
      a.addEventListener('mouseenter', () => a.style.background = '#f6f7fb');
      a.addEventListener('mouseleave', () => a.style.background = '');
      a.addEventListener('click', function (ev) {
        if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button === 1) return;
        ev.preventDefault();
        window.open(it.url, '_blank');
        menu.style.display = 'none';
      });
      menu.appendChild(a);
    });

    btn.addEventListener('click', function () {
      menu.style.display = (menu.style.display === 'none') ? 'block' : 'none';
    });

    document.addEventListener('click', function (e) {
      if (!container.contains(e.target)) menu.style.display = 'none';
    });

    container.appendChild(btn);
    container.appendChild(menu);
    toolbar.insertBefore(container, toolbar.firstChild || toolbar);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', injectCreateDropdown);
  } else {
    injectCreateDropdown();
  }

})();