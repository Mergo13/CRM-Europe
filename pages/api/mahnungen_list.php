<?php
// Shim endpoint delegating to generic documents_list for mahnungen
declare(strict_types=1);

$DOC_TYPE = 'mahnungen';
require __DIR__ . '/documents_list.php';