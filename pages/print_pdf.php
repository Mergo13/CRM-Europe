<?php
// pages/print_pdf.php
// Simple helper page to open a PDF URL in an iframe and trigger the browser print dialog.
// Usage: /pages/print_pdf.php?src=<encoded absolute-or-root-relative PDF URL>

$src = $_GET['src'] ?? '';
if ($src === '') {
    http_response_code(400);
    echo 'Missing src parameter';
    exit;
}

// Basic sanitization: allow only http(s) or root-relative paths
if (!(preg_match('~^https?://~i', $src) || str_starts_with($src, '/'))) {
    http_response_code(400);
    echo 'Invalid src parameter';
    exit;
}

?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>PDF Drucken</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  html, body { height:100%; margin:0; }
  #frame { width:100%; height:100%; border:0; }
</style>
</head>
<body>
<iframe id="frame" src="<?php echo htmlspecialchars($src, ENT_QUOTES, 'UTF-8'); ?>"></iframe>
<script>
  (function(){
    const f = document.getElementById('frame');
    // Try to trigger print once the iframe loads
    f.addEventListener('load', function(){
      try {
        if (f.contentWindow) {
          // delay a bit to allow PDF viewer to initialize
          setTimeout(function(){ f.contentWindow.focus(); f.contentWindow.print(); }, 500);
        } else {
          setTimeout(function(){ window.print(); }, 800);
        }
      } catch(e){
        // fallback: open native print
        setTimeout(function(){ window.print(); }, 800);
      }
    });
  })();
</script>
</body>
</html>
