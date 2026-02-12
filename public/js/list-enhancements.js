// List enhancements: quick actions wiring and bulk actions
(function(){
  function qs(sel, ctx){ return (ctx||document).querySelector(sel); }
  function qsa(sel, ctx){ return Array.from((ctx||document).querySelectorAll(sel)); }
  // Add data-action wiring for buttons with data-action attribute (support existing markup)
  qsa('[data-action]').forEach(function(btn){
    if (btn._enhanced) return; btn._enhanced = true;
    btn.addEventListener('click', function(e){
      var id = this.dataset.id, act = this.dataset.action;
      if (!act) return;
      if (act === 'view') return window.location = this.dataset.href || ('/rechnung.php?id='+encodeURIComponent(id));
      if (act === 'edit') return window.location = this.dataset.href || ('/rechnung.php?id='+encodeURIComponent(id)+'&edit=1');
      if (act === 'pdf') return openPdfPreview(this.dataset.href || ('/pages/api/pdf.php?id='+encodeURIComponent(id)));
      if (act === 'duplicate') {
        if (!confirm('Duplicate this item?')) return;
        fetch(this.dataset.href || '/pages/api/duplicate.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id:id})})
          .then(r=>r.json()).then(j=>{ if (j && j.success) location.reload(); else alert(j && j.message || 'Error'); }).catch(()=>alert('Request failed'));
        return;
      }
      if (act === 'mark-paid') {
        if (!confirm('Mark as paid?')) return;
        fetch(this.dataset.href || '/pages/api/mark_paid.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id:id})})
          .then(r=>r.json()).then(j=>{ if (j && j.success) location.reload(); else alert(j && j.message || 'Error'); }).catch(()=>alert('Request failed'));
        return;
      }
      if (act === 'delete') {
        if (!confirm('Delete this item? This cannot be undone.')) return;
        fetch(this.dataset.href || '/pages/api/delete.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id:id})})
          .then(r=>r.json()).then(j=>{ if (j && j.success) location.reload(); else alert(j && j.message || 'Error'); }).catch(()=>alert('Request failed'));
        return;
      }
    });
  });
  // Bulk actions toolbar injection (if table/list present)
  function injectBulkToolbar() {
    if (document.getElementById('globalBulkToolbar')) return;
    var toolbar = document.createElement('div'); toolbar.id = 'globalBulkToolbar';
    toolbar.className = 'list-toolbar p-2 mb-3';
    toolbar.innerHTML = '<div style="display:flex;gap:.5rem;align-items:center"><button id="bulkActionBtn" class="btn btn-outline-secondary btn-sm" disabled>Bulk actions</button><button id="bulkExportBtn" class="btn btn-outline-primary btn-sm">Export CSV</button></div>';
    var container = document.querySelector('.content') || document.body;
    container.insertBefore(toolbar, container.firstChild);
    // wire selection support
    document.addEventListener('change', function(e){
      if (e.target && e.target.classList && e.target.classList.contains('rowSelect')) updateBulkState();
    }, true);
    var selectAll = document.getElementById('selectAll');
    if (selectAll) selectAll.addEventListener('change', function(){ qsa('.rowSelect').forEach(cb=>cb.checked=this.checked); updateBulkState(); });
    document.getElementById('bulkActionBtn').addEventListener('click', function(){
      var ids = qsa('.rowSelect:checked').map(x=>x.value);
      if (!ids.length) return alert('No items selected');
      var action = prompt('Bulk action (mark-paid / delete / export):','mark-paid');
      if (!action) return;
      if (action === 'export') {
        var rows = [['ID']].concat(ids.map(i=>[i]));
        var csv = rows.map(r=>r.map(c=>'"'+String(c).replace(/"/g,'""')+'"').join(',')).join('\n');
        var a=document.createElement('a'); a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'})); a.download='export.csv'; a.click(); URL.revokeObjectURL(a.href); return;
      }
      if (!confirm('Apply "'+action+'" to '+ids.length+' items?')) return;
      fetch('/pages/api/bulk.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:action, ids:ids})})
        .then(r=>r.json()).then(j=>{ if (j && j.success) location.reload(); else alert(j && j.message || 'Error'); }).catch(()=>alert('Request failed'));
    });
    document.getElementById('bulkExportBtn').addEventListener('click', function(){ var rows = [['ID']].concat(qsa('.rowSelect').map(x=>[x.value])); var csv = rows.map(r=>r.map(c=>'"'+String(c).replace(/"/g,'""')+'"').join(',')).join('\n'); var a=document.createElement('a'); a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'})); a.download='export.csv'; a.click(); URL.revokeObjectURL(a.href); });
    function updateBulkState(){ var any = qsa('.rowSelect:checked').length>0; document.getElementById('bulkActionBtn').disabled = !any; }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', injectBulkToolbar); else injectBulkToolbar();
})();