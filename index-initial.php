<?php
declare(strict_types=1);

/**
 * Eldre engangs bootstrap (uten admin-passordskjema).
 *
 * Anbefalt: bruk nv5-init.php i stedet — setter admin-bruker/passord og sletter seg selv.
 *
 * 1. Last opp denne fila til site root (mappen som inneholder sis/).
 * 2. Åpne https://nv5.haatetepe.no/index-initial.php?run=1
 * 3. Slett fila når alt er grønt.
 */

const NV5_INITIAL_OWNER = 'blepman';
const NV5_INITIAL_REPO = 'nv5-sis';
const NV5_INITIAL_SERVER_BRANCH = 'server';
const NV5_INITIAL_UA = 'nv5-initial-bootstrap';
const NV5_INITIAL_RAW_BASE = 'https://raw.githubusercontent.com/'
    . NV5_INITIAL_OWNER . '/' . NV5_INITIAL_REPO . '/' . NV5_INITIAL_SERVER_BRANCH;

$siteRoot = __DIR__;
$run = isset($_GET['run']) && $_GET['run'] === '1';
$force = isset($_GET['force']) && $_GET['force'] === '1';

/**
 * @return list<string>
 */
function nv5_initial_server_files(): array
{
    return [
        '.htaccess',
        'nv5-lib/sync.php',
        'nv5-lib/.htaccess',
        'admin/index.php',
        'admin/.htaccess',
        'sis/index.php',
        'sis/.htaccess',
        'sis/lib/sync.php',
        'reise/index.php',
        'reise/.htaccess',
        'shared/.htaccess',
        'nginx-nv5.conf',
    ];
}

function nv5_initial_http_get(string $url): string
{
    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: ' . NV5_INITIAL_UA,
    ];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('cURL: ' . $error);
        }
        if ($status >= 400) {
            throw new RuntimeException('HTTP ' . $status . ' for ' . $url);
        }
        return $body;
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);
    $body = file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException('Kunne ikke hente ' . $url);
    }
    return $body;
}

function nv5_initial_write_atomic(string $path, string $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunne ikke lage mappe: ' . $dir);
    }
    $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($tmp, $data) === false) {
        throw new RuntimeException('Kunne ikke skrive ' . $tmp);
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Kunne ikke erstatte ' . $path);
    }
}

/**
 * @return list<string>
 */
function nv5_initial_install_server_skeleton(string $siteRoot): array
{
    $written = [];
    foreach (nv5_initial_server_files() as $relative) {
        $url = NV5_INITIAL_RAW_BASE . '/' . $relative;
        $dest = $siteRoot . '/' . $relative;
        $data = nv5_initial_http_get($url);
        if ($data === '') {
            throw new RuntimeException('Tom fil fra GitHub: ' . $relative);
        }
        nv5_initial_write_atomic($dest, $data);
        $written[] = $relative;
    }
    return $written;
}

function nv5_initial_already_complete(string $siteRoot): bool
{
    foreach (nv5_initial_server_files() as $relative) {
        if (!is_file($siteRoot . '/' . $relative)) {
            return false;
        }
    }
    return is_file($siteRoot . '/shared/js/util.js')
        && is_file($siteRoot . '/sis/content/index.html')
        && is_file($siteRoot . '/admin/content/index.html');
}

function nv5_initial_page(string $title, string $body, bool $ok = true): void
{
    $status = $ok ? 'ok' : 'err';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo '<!DOCTYPE html><html lang="nb"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<style>';
    echo 'body{font-family:system-ui,sans-serif;background:#0b1220;color:#e8edf7;max-width:40rem;margin:2rem auto;padding:0 1rem;line-height:1.5}';
    echo 'a{color:#6eb5ff}code{background:#121a2b;padding:.1em .35em;border-radius:.25rem}';
    echo '.ok{color:#7dffb3}.err{color:#ff8f8f}ul{padding-left:1.2rem}';
    echo '.btn{display:inline-block;margin-top:1rem;padding:.6rem 1rem;background:#3d7eff;color:#fff;text-decoration:none;border-radius:.5rem;font-weight:600}';
    echo '</style></head><body class="' . $status . '">';
    echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo $body;
    echo '</body></html>';
}

if (!$run) {
    $hint = '';
    if (nv5_initial_already_complete($siteRoot)) {
        $hint = '<p class="ok">Det ser ut som miljøet allerede er satt opp. '
            . 'Du kan slette denne fila, eller kjør på nytt med <code>?run=1&amp;force=1</code>.</p>';
    }
    nv5_initial_page(
        'NV5 initial bootstrap',
        '<p>Last opp denne fila til <strong>site root</strong> (mappen som inneholder <code>sis/</code>).</p>'
        . '<p>Kjøring henter server + shared + admin + SIS + Reise fra GitHub.</p>'
        . $hint
        . '<p><a class="btn" href="?run=1">Start bootstrap</a></p>'
        . '<p><small>Krever PHP med curl/zip. Slett <code>index-initial.php</code> etterpå.</small></p>'
    );
    exit;
}

if (!is_dir($siteRoot . '/sis')) {
    nv5_initial_page(
        'Feil mappe',
        '<p class="err">Fant ikke <code>sis/</code> i <code>'
        . htmlspecialchars($siteRoot, ENT_QUOTES, 'UTF-8')
        . '</code>.</p><p>Last opp til site root — mappen <em>over</em> <code>sis/</code>, ikke inni <code>sis/content/</code>.</p>',
        false
    );
    exit;
}

if (nv5_initial_already_complete($siteRoot) && !$force) {
    nv5_initial_page(
        'Allerede ferdig',
        '<p class="ok">NV5 ser allerede initialisert ut.</p>'
        . '<ul>'
        . '<li><a href="/admin/">Admin</a></li>'
        . '<li><a href="/sis/">SIS</a></li>'
        . '<li><a href="/reise/">Reise</a></li>'
        . '</ul>'
        . '<p>Slett <code>index-initial.php</code> fra serveren.</p>'
        . '<p><a class="btn" href="?run=1&amp;force=1">Kjør på nytt uansett</a></p>'
    );
    exit;
}

try {
    $log = [];
    $log[] = 'Installerer server-skjelett fra GitHub (' . NV5_INITIAL_SERVER_BRANCH . ')…';
    foreach (nv5_initial_install_server_skeleton($siteRoot) as $file) {
        $log[] = '  ✓ ' . $file;
    }

    $lib = $siteRoot . '/nv5-lib/sync.php';
    if (!is_file($lib)) {
        throw new RuntimeException('nv5-lib/sync.php ble ikke skrevet');
    }
    require $lib;

    if (!function_exists('nv5_bootstrap_install')) {
        throw new RuntimeException('nv5_bootstrap_install() mangler i sync.php — trenger nyere server-branch');
    }

    $log[] = 'Synker innhold fra main (env + apper)…';
    foreach (nv5_bootstrap_install($siteRoot)['steps'] as $step) {
        $log[] = '  ✓ ' . $step;
    }

    $items = '';
    foreach ($log as $line) {
        $items .= '<li>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</li>';
    }

    nv5_initial_page(
        'Bootstrap fullført',
        '<p class="ok">NV5 er initialisert.</p>'
        . '<ul>' . $items . '</ul>'
        . '<h2>Neste steg</h2>'
        . '<ol>'
        . '<li>Inkluder <code>nginx-nv5.conf</code> i HTTPS-vhost og reload nginx (hvis ikke gjort).</li>'
        . '<li>Test <a href="/admin/">/admin/</a>, <a href="/sis/">/sis/</a>, <a href="/reise/">/reise/</a>, '
        . '<a href="/shared/js/util.js">/shared/</a>.</li>'
        . '<li><strong>Slett</strong> <code>index-initial.php</code> fra serveren.</li>'
        . '</ol>'
        . '<p>Senere drift: <code>/admin/?sync=env</code>, <code>/sis/?sync=1</code>, <code>/reise/?sync=1</code>.</p>'
    );
} catch (Throwable $e) {
    nv5_initial_page(
        'Bootstrap feilet',
        '<p class="err">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><a class="btn" href="?run=1&amp;force=1">Prøv igjen</a></p>',
        false
    );
}
