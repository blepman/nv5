<?php
declare(strict_types=1);

/**
 * Engangs oppsett for nv5.haatetepe.no (haatetepe www/nv5-layout).
 *
 * 1. Last opp til nv5 site root (www/nv5 — mappen kan være tom).
 * 2. Åpne https://nv5.haatetepe.no/nv5-init.php
 * 3. Fyll inn admin-bruker og passord — fila slettes automatisk ved suksess.
 */

const NV5_INIT_OWNER = 'blepman';
const NV5_INIT_REPO = 'nv5-sis';
const NV5_INIT_SERVER_BRANCH = 'server';
const NV5_INIT_UA = 'nv5-init';
const NV5_INIT_RAW_BASE = 'https://raw.githubusercontent.com/'
    . NV5_INIT_OWNER . '/' . NV5_INIT_REPO . '/' . NV5_INIT_SERVER_BRANCH;

$siteRoot = __DIR__;
$force = isset($_GET['force']) && $_GET['force'] === '1';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

/**
 * @return list<string>
 */
function nv5_init_server_files(): array
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

function nv5_init_http_get(string $url): string
{
    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: ' . NV5_INIT_UA,
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
            throw new RuntimeException('GitHub HTTP ' . $status . ' — prøv igjen senere eller legg NV5_GITHUB_TOKEN i config etterpå.');
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

function nv5_init_write_atomic(string $path, string $data): void
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
function nv5_init_install_server_skeleton(string $siteRoot): array
{
    $written = [];
    foreach (nv5_init_server_files() as $relative) {
        $url = NV5_INIT_RAW_BASE . '/' . $relative;
        $dest = $siteRoot . '/' . $relative;
        $data = nv5_init_http_get($url);
        if ($data === '') {
            throw new RuntimeException('Tom fil fra GitHub: ' . $relative);
        }
        nv5_init_write_atomic($dest, $data);
        $written[] = $relative;
    }
    return $written;
}

function nv5_init_page(string $title, string $body, bool $ok = true): void
{
    $status = $ok ? 'ok' : 'err';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo '<!DOCTYPE html><html lang="nb"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<style>';
    echo 'body{font-family:system-ui,sans-serif;background:#0b1220;color:#e8edf7;max-width:32rem;margin:2rem auto;padding:0 1rem;line-height:1.5}';
    echo 'label{display:block;margin:.75rem 0 .25rem}input{width:100%;padding:.55rem .65rem;border-radius:.4rem;border:1px solid #2d4570;background:#121a2b;color:#e8edf7}';
    echo 'a{color:#6eb5ff}code{background:#121a2b;padding:.1em .35em;border-radius:.25rem}';
    echo '.ok{color:#7dffb3}.err{color:#ff8f8f}ul{padding-left:1.2rem}';
    echo '.btn{margin-top:1rem;padding:.65rem 1rem;background:#3d7eff;color:#fff;border:0;border-radius:.5rem;font-weight:600;cursor:pointer}';
    echo '</style></head><body class="' . $status . '">';
    echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo $body;
    echo '</body></html>';
}

function nv5_init_delete_self(): bool
{
    $self = __FILE__;
    if (!is_file($self)) {
        return true;
    }
    return @unlink($self);
}

function nv5_init_writable_error(string $siteRoot): ?string
{
    if (is_writable($siteRoot)) {
        return null;
    }
    $probe = $siteRoot . '/.nv5-init-write-test';
    if (@file_put_contents($probe, 'ok') !== false) {
        @unlink($probe);
        return null;
    }
    return 'Mappen er ikke skrivbar: ' . $siteRoot;
}

$writableError = nv5_init_writable_error($siteRoot);
if ($writableError !== null) {
    nv5_init_page(
        'Kan ikke skrive her',
        '<p class="err">' . htmlspecialchars($writableError, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>Last opp <code>nv5-init.php</code> til <strong>nv5 site root</strong> (<code>www/nv5/</code>), ikke inni <code>sis/</code> eller <code>admin/</code>.</p>',
        false
    );
    exit;
}

$lib = $siteRoot . '/nv5-lib/sync.php';
if (is_file($lib)) {
    require $lib;
    if (!$force && function_exists('nv5_admin_env_configured') && nv5_admin_env_configured($siteRoot)) {
        nv5_init_page(
            'Allerede konfigurert',
            '<p class="ok">Admin-passord er allerede satt i <code>env/env-nv5/admin/config.php</code>.</p>'
            . '<p><a href="/admin/">Gå til admin</a></p>'
            . '<p>For nytt oppsett: slett miljøfiler manuelt og last opp <code>nv5-init.php</code> på nytt med <code>?force=1</code>.</p>'
            . '<p><small>Denne fila bør ikke ligge i site root etter oppsett — slett den hvis den fortsatt finnes.</small></p>'
        );
        exit;
    }
}

if (!$isPost) {
    nv5_init_page(
        'NV5 oppsett',
        '<p>Sett opp miljøet på nytt: henter kode fra GitHub, lager <code>env/env-nv5/</code> ved siden av <code>www/</code>, og konfigurerer admin-innlogging.</p>'
        . '<p><small>Mappen kan være tom — <code>admin/</code>, <code>sis/</code> og resten opprettes under oppsettet.</small></p>'
        . '<form method="post" action="">'
        . '<label for="admin_user">Admin-bruker</label>'
        . '<input id="admin_user" name="admin_user" value="admin" autocomplete="username" required>'
        . '<label for="admin_password">Admin-passord</label>'
        . '<input id="admin_password" name="admin_password" type="password" autocomplete="new-password" minlength="8" required>'
        . '<label for="admin_password_confirm">Bekreft passord</label>'
        . '<input id="admin_password_confirm" name="admin_password_confirm" type="password" autocomplete="new-password" minlength="8" required>'
        . '<p><small>Passord lagres i <code>env/env-nv5/admin/config.php</code> (utenfor webroot).</small></p>'
        . '<button class="btn" type="submit">Start oppsett</button>'
        . '</form>'
    );
    exit;
}

$user = trim((string) ($_POST['admin_user'] ?? ''));
$pass = (string) ($_POST['admin_password'] ?? '');
$confirm = (string) ($_POST['admin_password_confirm'] ?? '');
$errors = [];

if ($user === '' || strlen($user) > 64) {
    $errors[] = 'Admin-bruker må være 1–64 tegn.';
}
if (strlen($pass) < 8) {
    $errors[] = 'Passord må være minst 8 tegn.';
}
if ($pass !== $confirm) {
    $errors[] = 'Passordene er ikke like.';
}

if ($errors !== []) {
    nv5_init_page(
        'Validering feilet',
        '<ul><li>' . implode('</li><li>', array_map(
            static fn(string $e): string => htmlspecialchars($e, ENT_QUOTES, 'UTF-8'),
            $errors
        )) . '</li></ul>'
        . '<p><a href="' . htmlspecialchars(basename(__FILE__), ENT_QUOTES, 'UTF-8') . '">Tilbake</a></p>',
        false
    );
    exit;
}

try {
    $log = [];
    $log[] = 'Installerer server-skjelett fra GitHub (' . NV5_INIT_SERVER_BRANCH . ')…';
    foreach (nv5_init_install_server_skeleton($siteRoot) as $file) {
        $log[] = '  ✓ ' . $file;
    }

    if (!is_file($lib)) {
        throw new RuntimeException('nv5-lib/sync.php ble ikke skrevet');
    }
    require $lib;

    if (!function_exists('nv5_bootstrap_install')) {
        throw new RuntimeException('Trenger nyere server-branch (nv5_bootstrap_install mangler)');
    }

    nv5_migrate_host_env_from_webroot($siteRoot);
    nv5_write_app_env_config($siteRoot, 'admin', [
        'NV5_ADMIN_USER' => $user,
        'NV5_ADMIN_PASSWORD' => $pass,
    ]);
    $envRoot = nv5_host_env_dir($siteRoot);
    if (!is_file($envRoot . '/config.php')) {
        nv5_ensure_host_config($siteRoot);
    }
    $log[] = '  ✓ env/env-nv5/admin/config.php';

    $log[] = 'Synker innhold fra main…';
    foreach (nv5_bootstrap_install($siteRoot)['steps'] as $step) {
        $log[] = '  ✓ ' . $step;
    }

    $deleted = nv5_init_delete_self();
    $items = '';
    foreach ($log as $line) {
        $items .= '<li>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</li>';
    }

    $deleteNote = $deleted
        ? '<p class="ok"><code>nv5-init.php</code> er slettet fra serveren.</p>'
        : '<p class="err">Kunne ikke slette <code>nv5-init.php</code> automatisk — fjern fila manuelt fra site root.</p>';

    nv5_init_page(
        'Oppsett fullført',
        '<p class="ok">NV5 er klart.</p>'
        . '<ul>' . $items . '</ul>'
        . $deleteNote
        . '<h2>Neste steg</h2>'
        . '<ol>'
        . '<li>Inkluder <code>nginx-nv5.conf</code> i vhost (hvis ikke gjort).</li>'
        . '<li>Logg inn på <a href="/admin/">/admin/</a> med brukeren du nettopp opprettet.</li>'
        . '<li>Valgfritt: legg <code>NV5_GITHUB_TOKEN</code> i <code>env/env-nv5/config.php</code> for sync uten rate limit.</li>'
        . '</ol>'
    );
} catch (Throwable $e) {
    nv5_init_page(
        'Oppsett feilet',
        '<p class="err">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><a href="' . htmlspecialchars(basename(__FILE__), ENT_QUOTES, 'UTF-8') . '">Prøv igjen</a></p>',
        false
    );
}
