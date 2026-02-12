(function(){
  const storageKey = 'ui.theme';
  const root = document.documentElement;

  function getStoredTheme(){
    try { return localStorage.getItem(storageKey) || null; } catch(e){ return null; }
  }
  function storeTheme(v){
    try { localStorage.setItem(storageKey, v); } catch(e){}
  }
  function systemPrefersDark(){
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }
  function applyTheme(mode){
    if (mode === 'dark') {
      root.setAttribute('data-theme','dark');
    } else {
      root.removeAttribute('data-theme');
    }
    updateToggleIcon();
  }
  function currentTheme(){
    return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
  }
  function updateToggleIcon(){
    const btn = document.getElementById('themeToggle');
    if(!btn) return;
    const label = btn.querySelector('.label');
    if(label){ label.textContent = currentTheme() === 'dark' ? 'Dark' : 'Light'; }
  }

  // Init
  const initial = getStoredTheme() || (systemPrefersDark() ? 'dark' : 'light');
  applyTheme(initial);

  // Toggle listener
  window.addEventListener('DOMContentLoaded', function(){
    const btn = document.getElementById('themeToggle');
    if(!btn) return;
    btn.addEventListener('click', function(){
      const next = currentTheme() === 'dark' ? 'light' : 'dark';
      applyTheme(next);
      storeTheme(next);
    });
  });

  // React to system changes when user hasn't explicitly set?
  try {
    const mql = window.matchMedia('(prefers-color-scheme: dark)');
    mql.addEventListener ? mql.addEventListener('change', e => {
      const stored = getStoredTheme();
      if(!stored){ applyTheme(e.matches ? 'dark' : 'light'); }
    }) : mql.addListener && mql.addListener(function(e){
      const stored = getStoredTheme();
      if(!stored){ applyTheme(e.matches ? 'dark' : 'light'); }
    });
  } catch(e){}
})();
