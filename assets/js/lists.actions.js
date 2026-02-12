// assets/js/lists.actions.js
// Adds quick-action behavior enhancements and fixes.
(function(){
  'use strict';
  function makeAbsolutePath(path){ if(!path) return ''; if(/^https?:\/\//i.test(path)||path.startsWith('/')) return path; return '/' + path.replace(/^\/+/, ''); }
  document.addEventListener('click', function(e){
    const a = e.target.closest && e.target.closest('a');
    if (a && (a.matches('.btn-download') || a.matches('.btn-preview'))) {
      const href = a.getAttribute('href')||'';
      if (!/^https?:\/\//i.test(href) && !href.startsWith('/')) {
        a.setAttribute('href', makeAbsolutePath(href));
      }
    }
  }, true);
})();