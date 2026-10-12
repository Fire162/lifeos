<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/db.php';
try { $db = life_os_db(); echo 'Schema version: ' . life_os_schema_version($db) . "\n"; }
catch (Throwable $error) { fwrite(STDERR, 'Migration failed: ' . get_class($error) . "\n"); exit(1); }
