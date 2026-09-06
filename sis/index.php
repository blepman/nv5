<?php
declare(strict_types=1);

$rootLib = dirname(__DIR__) . '/nv5-lib/sync.php';
$localLib = __DIR__ . '/lib/sync.php';
if (is_file($rootLib)) {
    require $rootLib;
} elseif (is_file($localLib)) {
    require $localLib;
} else {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'NV5 sync-bibliotek mangler. Kjør engangs-oppsett fra server-branchen.';
    exit;
}

nv5_run('sis', __DIR__);
