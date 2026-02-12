<?php
// pages/api/convert_angebot_to_rechnung.php
// Thin proxy to the actual converter endpoint at ../convert_angebot_to_rechnung.php
// Keeps backward compatibility with callers using the /pages/api/... path.

declare(strict_types=1);

require_once __DIR__ . '/../convert_angebot_to_rechnung.php';
