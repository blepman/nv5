<?php
declare(strict_types=1);

$lib = dirname(__DIR__) . '/nv5-lib/sync.php';
if (!is_file($lib)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="nb"><meta charset="utf-8"><title>NV5 Reise</title>';
    echo '<h1>Reise er ikke klar</h1><p>Kjør <code>/admin/?sync=env</code> først.</p>';
    exit;
}
require $lib;
nv5_run('reise', __DIR__);
