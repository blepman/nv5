<?php
declare(strict_types=1);

/**
 * NV5 sync library — delt mellom /admin/, /sis/ og /reise/.
 */

const NV5_OWNER = 'blepman';
const NV5_REPO = 'nv5-sis';
const NV5_UA = 'nv5-sis-server';
const NV5_SERVER_BRANCH = 'server';
const NV5_BOARD_BRANCH = 'main';
const NV5_SERVER_CHECK_INTERVAL = 3600;
const NV5_SHARED_CHECK_INTERVAL = 3600;
const NV5_ADMIN_CHECK_INTERVAL = 3600;
const NV5_REISE_CHECK_INTERVAL = 300;
const NV5_FORCE_SIS_COOLDOWN = 45;
const NV5_FORCE_REISE_COOLDOWN = 45;
const NV5_FORCE_SHARED_COOLDOWN = 45;
const NV5_FORCE_SERVER_COOLDOWN = 120;

function nv5_run(string $appId, string $appRoot): void
{
    $siteRoot = dirname($appRoot);
    $GLOBALS['nv5_site_root'] = $siteRoot;
    $GLOBALS['nv5_app_id'] = $appId;
    $stateDir = nv5_ensure_state_dir($siteRoot);
    nv5_migrate_and_scrub_webroot_state($appRoot, $stateDir);

    if ($appId === 'admin') {
        nv5_migrate_host_env_from_webroot($siteRoot);
        nv5_ensure_host_config($siteRoot);
        nv5_require_admin_auth($siteRoot, $stateDir);
    }

    $sync = nv5_parse_sync($appId);
    $syncKey = isset($_GET['key']) ? (string) $_GET['key'] : '';

    $boardCheckInterval = 300;
    if ($appId === 'sis' && isset($_COOKIE['nv5_github_interval']) && $_COOKIE['nv5_github_interval'] !== '') {
        $boardCheckInterval = nv5_normalize_board_interval((int) $_COOKIE['nv5_github_interval']);
    }

    $gate = nv5_gate_forced_sync($siteRoot, $stateDir, $sync, $syncKey);
    $sync = $gate['sync'];
    if ($gate['retry_after'] > 0) {
        header('Retry-After: ' . (string) $gate['retry_after']);
    }

    $paths = nv5_paths($siteRoot, $stateDir);
    $adminSyncError = null;

    try {
        if ($appId === 'admin') {
            try {
                nv5_run_sync_jobs($appId, $paths, $sync, $boardCheckInterval);
            } catch (Throwable $e) {
                $adminSyncError = $e->getMessage();
                nv5_log_sync_event($stateDir, 'admin_sync_error ' . $adminSyncError);
            }
            nv5_render_admin($paths, [
                'site_root' => $siteRoot,
                'state_dir' => $stateDir,
                'sync' => $sync,
                'retry_after' => $gate['retry_after'],
                'sync_error' => $adminSyncError,
            ]);
            return;
        }

        nv5_run_sync_jobs($appId, $paths, $sync, $boardCheckInterval);
        nv5_render_app($appId, $paths);
    } catch (Throwable $e) {
        $content = $paths['apps'][$appId]['content'] ?? '';
        if ($content !== '' && is_file($content . '/index.html')) {
            nv5_render_app($appId, $paths);
            return;
        }
        http_response_code(503);
        nv5_send_security_headers();
        header('Content-Type: text/html; charset=utf-8');
        $title = match ($appId) {
            'reise' => 'Reise',
            'admin' => 'NV5 Admin',
            default => 'SIS',
        };
        echo '<!DOCTYPE html><html lang="nb"><meta charset="utf-8"><title>' . $title . '</title>';
        echo '<h1>Appen er ikke klar</h1><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    }
}

/**
 * @param array{param:string,server:bool,shared:bool,admin:bool,sis:bool,reise:bool,all:bool} $sync
 */
function nv5_run_sync_jobs(string $appId, array $paths, array $sync, int $boardCheckInterval): void
{
    if ($sync['server']) {
        nv5_maybe_sync_server($paths, true);
    } elseif (nv5_should_sync_server($paths)) {
        nv5_maybe_sync_server($paths, false);
    }

    if ($sync['shared']) {
        nv5_maybe_sync_shared($paths, true);
    } elseif (nv5_should_sync_shared($paths)) {
        nv5_maybe_sync_shared($paths, false);
    }

    if ($sync['admin']) {
        nv5_maybe_sync_admin($paths, true);
    } elseif ($appId === 'admin' && nv5_should_sync_admin($paths)) {
        nv5_maybe_sync_admin($paths, false);
    }

    if ($sync['sis']) {
        nv5_maybe_sync_app('sis', $paths, $boardCheckInterval, true);
    } elseif ($appId === 'sis' && nv5_should_sync_app('sis', $paths, $boardCheckInterval)) {
        nv5_maybe_sync_app('sis', $paths, $boardCheckInterval, false);
    }

    if ($sync['reise']) {
        nv5_maybe_sync_app('reise', $paths, NV5_REISE_CHECK_INTERVAL, true);
    } elseif ($appId === 'reise' && nv5_should_sync_app('reise', $paths, NV5_REISE_CHECK_INTERVAL)) {
        nv5_maybe_sync_app('reise', $paths, NV5_REISE_CHECK_INTERVAL, false);
    }
}

/**
 * @return array{param:string,server:bool,shared:bool,admin:bool,sis:bool,reise:bool,all:bool}
 */
function nv5_parse_sync(string $appId): array
{
    $param = isset($_GET['sync']) ? strtolower(trim((string) $_GET['sync'])) : '';

    if ($appId === 'sis') {
        return [
            'param' => $param,
            'server' => false,
            'shared' => false,
            'admin' => false,
            'sis' => in_array($param, ['1', 'sis', 'main', 'board'], true),
            'reise' => false,
            'all' => false,
        ];
    }

    if ($appId === 'reise') {
        return [
            'param' => $param,
            'server' => false,
            'shared' => false,
            'admin' => false,
            'sis' => false,
            'reise' => in_array($param, ['1', 'reise'], true),
            'all' => false,
        ];
    }

    return [
        'param' => $param,
        'server' => in_array($param, ['server', 'env', 'all'], true),
        'shared' => in_array($param, ['shared', 'env', 'all'], true),
        'admin' => in_array($param, ['admin', 'env', 'all'], true),
        'sis' => in_array($param, ['sis', 'all'], true),
        'reise' => in_array($param, ['reise', 'all'], true),
        'all' => $param === 'all',
    ];
}

/**
 * @param array{param:string,server:bool,shared:bool,admin:bool,sis:bool,reise:bool,all:bool} $sync
 * @return array{sync:array<string,bool>,retry_after:int}
 */
function nv5_gate_forced_sync(string $siteRoot, string $stateDir, array $sync, string $providedKey): array
{
    $retryAfter = 0;
    $out = $sync;
    $now = time();
    $ipKey = hash('sha256', nv5_client_ip());

    $kinds = [
        'server' => ['flag' => $sync['server'], 'cooldown' => NV5_FORCE_SERVER_COOLDOWN, 'secret' => true],
        'shared' => ['flag' => $sync['shared'], 'cooldown' => NV5_FORCE_SHARED_COOLDOWN, 'secret' => false],
        'admin' => ['flag' => $sync['admin'], 'cooldown' => NV5_FORCE_SHARED_COOLDOWN, 'secret' => false],
        'sis' => ['flag' => $sync['sis'], 'cooldown' => NV5_FORCE_SIS_COOLDOWN, 'secret' => false],
        'reise' => ['flag' => $sync['reise'], 'cooldown' => NV5_FORCE_REISE_COOLDOWN, 'secret' => false],
    ];

    foreach ($kinds as $kind => $cfg) {
        if (!$cfg['flag']) {
            continue;
        }
        if ($cfg['secret']) {
            $secret = nv5_sync_server_secret($siteRoot, $stateDir);
            if ($secret !== '' && ($providedKey === '' || !hash_equals($secret, $providedKey))) {
                nv5_log_sync_event($stateDir, "deny kind={$kind} reason=bad_or_missing_key");
                $out[$kind] = false;
                $retryAfter = max($retryAfter, 60);
                continue;
            }
        }
        $stampFile = $stateDir . '/force-sync-' . $kind . '-' . $ipKey . '.stamp';
        $last = is_readable($stampFile) ? (int) trim((string) @file_get_contents($stampFile)) : 0;
        if ($last > 0 && ($now - $last) < $cfg['cooldown']) {
            $wait = max(1, $cfg['cooldown'] - ($now - $last));
            nv5_log_sync_event($stateDir, "deny kind={$kind} reason=rate_limit retry_after={$wait}");
            $out[$kind] = false;
            $retryAfter = max($retryAfter, $wait);
        } else {
            @file_put_contents($stampFile, (string) $now, LOCK_EX);
            nv5_log_sync_event($stateDir, "allow kind={$kind}");
        }
    }

    return ['sync' => $out, 'retry_after' => $retryAfter];
}

function nv5_paths(string $siteRoot, string $stateDir): array
{
    $apps = [
        'sis' => [
            'content' => $siteRoot . '/sis/content',
            'content_tmp' => $siteRoot . '/sis/content.tmp',
            'sha' => $stateDir . '/sis-sha',
            'check' => $stateDir . '/sis-check',
            'lock' => $stateDir . '/sis.lock',
            'legacy_sha' => $stateDir . '/board-sha',
            'legacy_check' => $stateDir . '/board-check',
            'legacy_lock' => $stateDir . '/board.lock',
        ],
        'reise' => [
            'content' => $siteRoot . '/reise/content',
            'content_tmp' => $siteRoot . '/reise/content.tmp',
            'sha' => $stateDir . '/reise-sha',
            'check' => $stateDir . '/reise-check',
            'lock' => $stateDir . '/reise.lock',
        ],
    ];

    nv5_migrate_legacy_board_state($apps['sis']);

    return [
        'site_root' => $siteRoot,
        'state_dir' => $stateDir,
        'server_sha' => $stateDir . '/server-sha',
        'server_check' => $stateDir . '/server-check',
        'server_lock' => $stateDir . '/server.lock',
        'shared_dir' => $siteRoot . '/shared',
        'shared_tmp' => $siteRoot . '/shared.tmp',
        'shared_sha' => $stateDir . '/shared-sha',
        'shared_check' => $stateDir . '/shared-check',
        'shared_lock' => $stateDir . '/shared.lock',
        'admin_content' => $siteRoot . '/admin/content',
        'admin_content_tmp' => $siteRoot . '/admin/content.tmp',
        'admin_sha' => $stateDir . '/admin-sha',
        'admin_check' => $stateDir . '/admin-check',
        'admin_lock' => $stateDir . '/admin.lock',
        'apps' => $apps,
    ];
}

function nv5_migrate_legacy_board_state(array &$sis): void
{
    foreach (
        [
            'legacy_sha' => 'sha',
            'legacy_check' => 'check',
            'legacy_lock' => 'lock',
        ] as $legacy => $target
    ) {
        $from = $sis[$legacy];
        $to = $sis[$target];
        if (is_file($from) && !is_file($to)) {
            @rename($from, $to);
        }
        unset($sis[$legacy]);
    }
}

function nv5_should_sync_server(array $paths): bool
{
    return !nv5_server_webroot_complete($paths['site_root'])
        || nv5_should_check_github(NV5_SERVER_CHECK_INTERVAL, $paths['server_sha'], $paths['server_check']);
}

function nv5_should_sync_shared(array $paths): bool
{
    return !is_file($paths['shared_dir'] . '/js/util.js')
        || nv5_should_check_github(NV5_SHARED_CHECK_INTERVAL, $paths['shared_sha'], $paths['shared_check']);
}

function nv5_should_sync_admin(array $paths): bool
{
    return !is_file($paths['admin_content'] . '/index.html')
        || nv5_should_check_github(NV5_ADMIN_CHECK_INTERVAL, $paths['admin_sha'], $paths['admin_check']);
}

function nv5_should_sync_app(string $appId, array $paths, int $interval): bool
{
    $app = $paths['apps'][$appId];
    $valid = nv5_app_content_valid($appId, $app['content']);
    return !$valid
        || nv5_should_check_github($interval, $app['content'] . '/index.html', $app['check']);
}

function nv5_maybe_sync_server(array $paths, bool $force): void
{
    nv5_try_sync_lock($paths['server_lock'], function () use ($paths, $force): void {
        nv5_sync_server_branch($paths['site_root'], $paths['server_sha'], $force);
        file_put_contents($paths['server_check'], (string) time());
    });
    nv5_ensure_host_config($paths['site_root']);
}

function nv5_maybe_sync_shared(array $paths, bool $force): void
{
    nv5_try_sync_lock($paths['shared_lock'], function () use ($paths, $force): void {
        nv5_sync_tree_from_github('shared', $paths['shared_dir'], $paths['shared_tmp'], $paths['shared_sha'], $force);
        file_put_contents($paths['shared_check'], (string) time());
    });
}

function nv5_maybe_sync_admin(array $paths, bool $force): void
{
    nv5_try_sync_lock($paths['admin_lock'], function () use ($paths, $force): void {
        nv5_sync_admin_from_github($paths, $force);
        file_put_contents($paths['admin_check'], (string) time());
    });
}

function nv5_maybe_sync_app(string $appId, array $paths, int $interval, bool $force): void
{
    $app = $paths['apps'][$appId];
    nv5_try_sync_lock($app['lock'], function () use ($appId, $app, $force): void {
        nv5_sync_app_from_github($appId, $app['content'], $app['content_tmp'], $app['sha'], $force);
        file_put_contents($app['check'], (string) time());
    });
}

function nv5_normalize_board_interval(int $seconds): int
{
    if ($seconds < 60) {
        return 60;
    }
    return min(86400, $seconds);
}

function nv5_ensure_state_dir(string $siteRoot): string
{
    $candidates = [
        sys_get_temp_dir() . '/nv5-sis-' . substr(hash('sha256', $siteRoot), 0, 16),
        dirname($siteRoot) . '/.nv5-sis-state-' . substr(hash('sha256', $siteRoot), 0, 8),
    ];
    foreach ($candidates as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            continue;
        }
        if (is_dir($dir) && is_writable($dir)) {
            return $dir;
        }
    }
    throw new RuntimeException('Kunne ikke lage state-mappe utenfor webroot');
}

function nv5_client_ip(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== '' ? $ip : 'unknown';
}

function nv5_site_root_real(string $siteRoot): string
{
    $resolved = realpath($siteRoot);
    $path = $resolved !== false ? $resolved : $siteRoot;
    return rtrim(str_replace('\\', '/', $path), '/');
}

function nv5_resolve_www_dir(string $siteRoot): ?string
{
    $cursor = nv5_site_root_real($siteRoot);
    while ($cursor !== '' && $cursor !== '/') {
        if (basename($cursor) === 'www') {
            return $cursor;
        }
        $parent = dirname($cursor);
        if ($parent === $cursor) {
            break;
        }
        $cursor = $parent;
    }
    return null;
}

function nv5_webroot_env_dir(string $siteRoot): string
{
    return nv5_site_root_real($siteRoot) . '/env/env-nv5';
}

/**
 * @return list<string>
 */
function nv5_legacy_host_env_dirs(string $siteRoot): array
{
    $dirs = [nv5_webroot_env_dir($siteRoot)];
    $www = nv5_resolve_www_dir($siteRoot);
    if ($www !== null) {
        $dirs[] = $www . '/env/env-nv5';
    }
    $dirs = array_values(array_unique($dirs));
    $correct = nv5_host_env_dir($siteRoot);
    return array_values(array_filter($dirs, static fn(string $dir): bool => $dir !== $correct));
}

function nv5_host_env_parent_accessible(string $siteRoot): bool
{
    if (trim((string) (getenv('NV5_ENV_DIR') ?: '')) !== '') {
        return true;
    }

    $envNv5 = nv5_host_env_dir($siteRoot);
    $envRoot = dirname($envNv5);
    $accountRoot = dirname($envRoot);
    if ($accountRoot === '' || $accountRoot === '/' || !is_dir($accountRoot)) {
        return false;
    }
    return is_dir($envRoot) || is_writable($accountRoot);
}

function nv5_host_env_dir(string $siteRoot): string
{
    $fromEnv = trim((string) (getenv('NV5_ENV_DIR') ?: ''));
    if ($fromEnv !== '') {
        return rtrim(str_replace('\\', '/', $fromEnv), '/');
    }

    $www = nv5_resolve_www_dir($siteRoot);
    if ($www !== null) {
        return dirname($www) . '/env/env-nv5';
    }

    $root = nv5_site_root_real($siteRoot);
    $parent = dirname($root);
    if ($parent !== $root && $parent !== '' && $parent !== '/') {
        return $parent . '/env/env-nv5';
    }

    return $parent . '/env/env-nv5';
}

function nv5_block_webroot_env_http(string $siteRoot): void
{
    $envDir = nv5_site_root_real($siteRoot) . '/env';
    if (!is_dir($envDir)) {
        return;
    }
    $htaccess = $envDir . '/.htaccess';
    if (is_file($htaccess)) {
        return;
    }
    $body = <<<'HTACCESS'
Require all denied

HTACCESS;
    @file_put_contents($htaccess, $body);
}

function nv5_migrate_host_env_from_webroot(string $siteRoot): void
{
    $correct = nv5_host_env_dir($siteRoot);
    if (!nv5_host_env_parent_accessible($siteRoot)) {
        nv5_block_webroot_env_http($siteRoot);
        return;
    }

    if (!is_dir(dirname($correct)) && !@mkdir(dirname($correct), 0755, true) && !is_dir(dirname($correct))) {
        return;
    }
    if (!is_dir($correct) && !@mkdir($correct, 0755, true) && !is_dir($correct)) {
        return;
    }

    $correctConfig = $correct . '/config.php';
    foreach (nv5_legacy_host_env_dirs($siteRoot) as $wrong) {
        if (!is_dir($wrong)) {
            continue;
        }
        $wrongConfig = $wrong . '/config.php';
        if (is_file($wrongConfig) && !is_file($correctConfig)) {
            if (!@rename($wrongConfig, $correctConfig)) {
                $data = file_get_contents($wrongConfig);
                if ($data !== false) {
                    nv5_write_atomic($correctConfig, $data);
                    @unlink($wrongConfig);
                }
            }
            @chmod($correctConfig, 0644);
        }
        if (is_dir($wrong)) {
            $entries = array_diff(scandir($wrong) ?: [], ['.', '..']);
            if ($entries === []) {
                @rmdir($wrong);
            }
        }
        $legacyEnv = dirname($wrong);
        if (is_dir($legacyEnv) && str_ends_with($legacyEnv, '/env')) {
            $left = array_diff(scandir($legacyEnv) ?: [], ['.', '..', '.htaccess']);
            if ($left === []) {
                @unlink($legacyEnv . '/.htaccess');
                @rmdir($legacyEnv);
            }
        }
    }

    nv5_block_webroot_env_http($siteRoot);
}

/**
 * @return array<string, string>
 */
function nv5_load_env_config_file(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }
    $loaded = require $path;
    if (!is_array($loaded)) {
        return [];
    }
    $normalized = [];
    foreach ($loaded as $key => $value) {
        if (!is_string($key) || (!is_string($value) && !is_int($value) && !is_float($value))) {
            continue;
        }
        $normalized[$key] = trim((string) $value);
    }
    return $normalized;
}

function nv5_app_env_dir(string $siteRoot, string $appId): string
{
    return nv5_host_env_dir($siteRoot) . '/' . $appId;
}

/**
 * @return array<string, string>
 */
function nv5_host_env_merged_config(string $siteRoot, ?string $appId = null): array
{
    static $cache = [];
    $appId = $appId ?? (string) ($GLOBALS['nv5_app_id'] ?? '');
    $cacheKey = nv5_host_env_dir($siteRoot) . '|' . $appId;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $merged = nv5_load_env_config_file(nv5_host_env_dir($siteRoot) . '/config.php');
    if ($appId !== '') {
        $merged = array_merge($merged, nv5_load_env_config_file(nv5_app_env_dir($siteRoot, $appId) . '/config.php'));
    }

    $cache[$cacheKey] = $merged;
    return $merged;
}

/**
 * @return array<string, string>
 */
function nv5_host_env_config(string $siteRoot, ?string $appId = null): array
{
    return nv5_host_env_merged_config($siteRoot, $appId);
}

function nv5_host_env(string $siteRoot, string $name, ?string $appId = null): string
{
    $fromEnv = trim((string) (getenv($name) ?: ''));
    if ($fromEnv !== '') {
        return $fromEnv;
    }

    $fromConfig = nv5_host_env_merged_config($siteRoot, $appId)[$name] ?? '';
    if ($fromConfig !== '') {
        return $fromConfig;
    }

    $file = nv5_host_env_dir($siteRoot) . '/' . $name;
    if (is_readable($file)) {
        return trim((string) file_get_contents($file));
    }
    return '';
}

/**
 * @param array<string, string> $values
 */
function nv5_export_env_config_php(array $values): string
{
    return "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($values, true) . ";\n";
}

/**
 * @param array<string, string> $values
 */
function nv5_write_env_config_file(string $path, array $values): void
{
    nv5_write_atomic($path, nv5_export_env_config_php($values));
    @chmod($path, 0644);
}

/**
 * @param array<string, string> $values
 */
function nv5_write_app_env_config(string $siteRoot, string $appId, array $values): void
{
    $dir = nv5_app_env_dir($siteRoot, $appId);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunne ikke lage ' . $dir);
    }
    @chmod($dir, 0755);
    nv5_write_env_config_file($dir . '/config.php', $values);
}

function nv5_admin_env_configured(string $siteRoot): bool
{
    if (trim((string) (getenv('NV5_ADMIN_PASSWORD') ?: '')) !== '') {
        return true;
    }
    $pass = nv5_host_env_merged_config($siteRoot, 'admin')['NV5_ADMIN_PASSWORD'] ?? '';
    return $pass !== '';
}

function nv5_ensure_host_config(string $siteRoot): void
{
    nv5_migrate_host_env_from_webroot($siteRoot);

    if (!nv5_host_env_parent_accessible($siteRoot) && trim((string) (getenv('NV5_ENV_DIR') ?: '')) === '') {
        return;
    }

    $dir = nv5_host_env_dir($siteRoot);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return;
    }
    @chmod($dir, 0755);

    $path = $dir . '/config.php';
    if (is_file($path)) {
        @chmod($path, 0644);
    } else {
        $template = <<<'PHP'
<?php
declare(strict_types=1);

return [
    // 'NV5_GITHUB_TOKEN' => 'ghp_…',
    // 'NV5_SYNC_SERVER_KEY' => 'valgfri-nøkkel-for-?sync=server',
];

PHP;
        try {
            nv5_write_atomic($path, $template);
            @chmod($path, 0644);
        } catch (Throwable $e) {
        }
    }

    foreach (['admin', 'sis', 'reise'] as $appId) {
        $appDir = nv5_app_env_dir($siteRoot, $appId);
        if (!is_dir($appDir)) {
            @mkdir($appDir, 0755, true);
        }
    }
}

function nv5_sync_server_secret(string $siteRoot, string $stateDir): string
{
    $secret = nv5_host_env($siteRoot, 'NV5_SYNC_SERVER_KEY');
    if ($secret !== '') {
        return $secret;
    }
    $file = $stateDir . '/sync-server-secret';
    if (is_readable($file)) {
        return trim((string) file_get_contents($file));
    }
    return '';
}

/**
 * @return array{user:string,pass:string}
 */
function nv5_admin_credentials(string $siteRoot, string $stateDir): array
{
    $user = nv5_host_env($siteRoot, 'NV5_ADMIN_USER', 'admin');
    if ($user === '') {
        $user = 'admin';
    }
    $pass = nv5_host_env($siteRoot, 'NV5_ADMIN_PASSWORD', 'admin');
    if ($pass === '') {
        $file = $stateDir . '/admin-password';
        if (is_readable($file)) {
            $pass = trim((string) file_get_contents($file));
        }
    }
    return ['user' => $user, 'pass' => $pass];
}

/**
 * @return array{active:bool,source:string,hint:string}
 */
function nv5_admin_password_status(string $siteRoot, string $stateDir): array
{
    if (trim((string) (getenv('NV5_ADMIN_PASSWORD') ?: '')) !== '') {
        return [
            'active' => true,
            'source' => 'miljøvariabel NV5_ADMIN_PASSWORD',
            'hint' => '',
        ];
    }

    $configPass = nv5_host_env_merged_config($siteRoot, 'admin')['NV5_ADMIN_PASSWORD'] ?? '';
    if ($configPass !== '') {
        return [
            'active' => true,
            'source' => 'env/env-nv5/admin/config.php',
            'hint' => '',
        ];
    }

    $legacyRootPass = nv5_load_env_config_file(nv5_host_env_dir($siteRoot) . '/config.php')['NV5_ADMIN_PASSWORD'] ?? '';
    if ($legacyRootPass !== '') {
        return [
            'active' => true,
            'source' => 'env/env-nv5/config.php (flytt til admin/config.php)',
            'hint' => '',
        ];
    }

    $adminConfig = nv5_app_env_dir($siteRoot, 'admin') . '/config.php';
    if (is_readable($adminConfig)) {
        return [
            'active' => false,
            'source' => '',
            'hint' => 'admin/config.php finnes — sett NV5_ADMIN_PASSWORD.',
        ];
    }

    $configPath = nv5_host_env_dir($siteRoot) . '/config.php';
    if (is_readable($configPath)) {
        return [
            'active' => false,
            'source' => '',
            'hint' => 'Kjør nv5-init.php for å sette admin-bruker og passord, eller opprett env/env-nv5/admin/config.php.',
        ];
    }

    $legacyDir = nv5_webroot_env_dir($siteRoot);
    if (is_dir($legacyDir)) {
        return [
            'active' => false,
            'source' => '',
            'hint' => 'env/env-nv5 ligger feil inni nv5-mappen. Riktig sted er env/env-nv5 ved siden av www (sync/admin flytter automatisk).',
        ];
    }

    $www = nv5_resolve_www_dir($siteRoot);
    if ($www !== null && is_dir($www . '/env/env-nv5')) {
        return [
            'active' => false,
            'source' => '',
            'hint' => 'env/env-nv5 ligger feil inni www/. Riktig sted er env/env-nv5 ved siden av www (søster til www, ikke under).',
        ];
    }

    if (!nv5_host_env_parent_accessible($siteRoot) && trim((string) (getenv('NV5_ENV_DIR') ?: '')) === '') {
        return [
            'active' => false,
            'source' => '',
            'hint' => 'PHP ser ikke mappen over www (chroot). Sett NV5_ENV_DIR til absolutt sti utenfor www, eller legg passord i state-mappen.',
        ];
    }

    $envFile = nv5_host_env_dir($siteRoot) . '/NV5_ADMIN_PASSWORD';
    if (is_readable($envFile)) {
        return [
            'active' => true,
            'source' => 'env/env-nv5/NV5_ADMIN_PASSWORD',
            'hint' => '',
        ];
    }

    $stateFile = $stateDir . '/admin-password';
    if (is_readable($stateFile)) {
        return [
            'active' => true,
            'source' => 'state/admin-password',
            'hint' => '',
        ];
    }

    $hint = is_dir(nv5_host_env_dir($siteRoot))
        ? 'env/env-nv5 finnes, men config.php mangler eller er ikke lesbar for PHP.'
        : 'env/env-nv5/config.php opprettes ved server-sync eller første admin-besøk.';

    return [
        'active' => false,
        'source' => '',
        'hint' => $hint,
    ];
}

/**
 * @param array{param:string,server:bool,shared:bool,admin:bool,sis:bool,reise:bool,all:bool} $sync
 */
function nv5_admin_sync_notice(string $siteRoot, string $stateDir, array $sync, int $retryAfter): string
{
    $param = $sync['param'];
    if ($param === '') {
        return '';
    }

    $parts = ['Sync-forespørsel <code>' . htmlspecialchars($param, ENT_QUOTES, 'UTF-8') . '</code> er behandlet.'];
    if ($retryAfter > 0) {
        $parts[] = 'Noe ble hoppet over (rate limit eller manglende nøkkel). Prøv igjen om '
            . (int) $retryAfter . ' s.';
    }

    if (in_array($param, ['server', 'env', 'all'], true)) {
        $secret = nv5_sync_server_secret($siteRoot, $stateDir);
        $providedKey = isset($_GET['key']) ? (string) $_GET['key'] : '';
        if ($secret !== '' && ($providedKey === '' || !hash_equals($secret, $providedKey))) {
            $parts[] = 'Server-sync krever riktig <code>?key=</code> (NV5_SYNC_SERVER_KEY).';
        } elseif (!$sync['server'] && $retryAfter > 0) {
            $parts[] = 'Server-sync ble ikke kjørt nå.';
        } else {
            $parts[] = 'Server er på siste GitHub-versjon (eller ble nettopp oppdatert).';
        }
    }

    return implode(' ', $parts);
}

function nv5_build_admin_notice(string $siteRoot, string $stateDir, array $sync, int $retryAfter, ?string $syncError = null): string
{
    $auth = nv5_admin_password_status($siteRoot, $stateDir);
    $lines = [];

    if ($auth['active']) {
        $lines[] = '<strong>Admin-passord:</strong> aktiv (' . htmlspecialchars($auth['source'], ENT_QUOTES, 'UTF-8') . ').';
    } else {
        $lines[] = '<strong>Admin-passord:</strong> ikke satt. '
            . htmlspecialchars($auth['hint'], ENT_QUOTES, 'UTF-8');
    }

    if ($syncError !== null && $syncError !== '') {
        $lines[] = '<strong>Sync-feil:</strong> ' . htmlspecialchars($syncError, ENT_QUOTES, 'UTF-8');
    }

    $syncLine = nv5_admin_sync_notice($siteRoot, $stateDir, $sync, $retryAfter);
    if ($syncLine !== '' && $syncError === null) {
        $lines[] = $syncLine;
    }

    $body = implode('<br>', $lines);
    return '<div class="admin__notice" role="status" style="margin:0 0 1.25rem;padding:.85rem 1rem;border-radius:.5rem;background:#152238;border:1px solid #2d4570;color:#dbe7ff;font-size:.95rem;line-height:1.55">'
        . $body
        . '</div>';
}

/**
 * @return array{user:string,pass:string}
 */
function nv5_http_basic_credentials(): array
{
    $user = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
    $pass = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
    if ($user === '' && $pass === '') {
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (str_starts_with(strtolower($auth), 'basic ')) {
            $decoded = base64_decode(substr($auth, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$user, $pass] = explode(':', $decoded, 2);
            }
        }
    }
    return ['user' => $user, 'pass' => $pass];
}

function nv5_require_admin_auth(string $siteRoot, string $stateDir): void
{
    $expected = nv5_admin_credentials($siteRoot, $stateDir);
    if ($expected['pass'] === '') {
        return;
    }

    $provided = nv5_http_basic_credentials();
    $ok = $provided['pass'] !== ''
        && hash_equals($expected['user'], $provided['user'])
        && hash_equals($expected['pass'], $provided['pass']);
    if ($ok) {
        return;
    }

    nv5_log_sync_event($stateDir, 'deny admin auth ip=' . nv5_client_ip());
    header('WWW-Authenticate: Basic realm="NV5 Admin", charset="UTF-8"');
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Autentisering kreves';
    exit;
}

function nv5_log_sync_event(string $stateDir, string $message): void
{
    try {
        $line = sprintf("[%s] ip=%s %s\n", gmdate('c'), nv5_client_ip(), $message);
        @file_put_contents($stateDir . '/sync-audit.log', $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
    }
}

/**
 * @return array<string, string>
 */
function nv5_legacy_webroot_state_map(): array
{
    return [
        '.last-sha' => 'board-sha',
        '.last-check' => 'board-check',
        '.sync.lock' => 'board.lock',
        '.server-sha' => 'server-sha',
        '.server-check' => 'server-check',
        '.server.lock' => 'server.lock',
    ];
}

/**
 * @return list<string>
 */
function nv5_server_sync_skip_web(): array
{
    return ['README.md', '.gitignore', 'index-initial.php', 'nv5-init.php'];
}

/**
 * @return list<string>
 */
function nv5_server_scrub_webroot_files(): array
{
    return ['index-initial.php'];
}

/**
 * @return list<string>
 */
function nv5_server_sync_required_files(): array
{
    return [
        '.htaccess',
        'nv5-lib/sync.php',
        'nv5-lib/.htaccess',
        'admin/index.php',
        'admin/.htaccess',
        'sis/index.php',
        'sis/.htaccess',
        'reise/index.php',
        'reise/.htaccess',
        'shared/.htaccess',
        'nginx-nv5.conf',
    ];
}

function nv5_server_webroot_complete(string $siteRoot): bool
{
    foreach (nv5_server_sync_required_files() as $name) {
        if (!is_file($siteRoot . '/' . $name)) {
            return false;
        }
    }
    return true;
}

function nv5_migrate_and_scrub_webroot_state(string $appRoot, string $stateDir): void
{
    foreach (nv5_legacy_webroot_state_map() as $oldName => $newName) {
        $from = $appRoot . '/' . $oldName;
        $to = $stateDir . '/' . $newName;
        if (is_file($from)) {
            if (!is_file($to)) {
                if (!@rename($from, $to)) {
                    @copy($from, $to);
                    @unlink($from);
                }
            } else {
                @unlink($from);
            }
        }
    }
    $siteRoot = dirname($appRoot);
    foreach (nv5_server_scrub_webroot_files() as $name) {
        $path = $siteRoot . '/' . $name;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * @param callable():void $fn
 */
function nv5_try_sync_lock(string $lockFile, callable $fn, bool $blocking = false): bool
{
    $fh = fopen($lockFile, 'c+');
    if ($fh === false) {
        return false;
    }
    $flags = $blocking ? LOCK_EX : (LOCK_EX | LOCK_NB);
    if (!flock($fh, $flags)) {
        fclose($fh);
        return false;
    }
    try {
        $fn();
        return true;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function nv5_should_check_github(int $intervalSeconds, string $readyMarker, string $checkFile): bool
{
    if (!is_file($readyMarker)) {
        return true;
    }
    if ($intervalSeconds <= 0 || !is_file($checkFile)) {
        return true;
    }
    return (time() - (int) filemtime($checkFile)) >= $intervalSeconds;
}

function nv5_github_get(string $url): string
{
    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: ' . NV5_UA,
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    $siteRoot = (string) ($GLOBALS['nv5_site_root'] ?? '');
    if ($siteRoot !== '') {
        $token = nv5_host_env($siteRoot, 'NV5_GITHUB_TOKEN');
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
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
            if ($status === 403) {
                throw new RuntimeException(
                    'GitHub HTTP 403 (rate limit). Legg NV5_GITHUB_TOKEN i env/env-nv5/config.php, eller prøv igjen senere.'
                );
            }
            throw new RuntimeException('GitHub HTTP ' . $status);
        }
        return $body;
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);
    $body = file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException('Kunne ikke hente ' . $url);
    }
    return $body;
}

function nv5_rm_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            nv5_rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function nv5_is_safe_relative_path(string $relative): bool
{
    if ($relative === '' || str_contains($relative, "\0")) {
        return false;
    }
    $relative = str_replace('\\', '/', $relative);
    if ($relative[0] === '/' || preg_match('#^[a-zA-Z]:#', $relative) === 1) {
        return false;
    }
    foreach (explode('/', $relative) as $part) {
        if ($part === '..') {
            return false;
        }
    }
    return true;
}

/**
 * @return list<string>
 */
function nv5_board_allowed_extensions(): array
{
    return ['html', 'css', 'js', 'woff2', 'png', 'webmanifest', 'json'];
}

function nv5_board_file_allowed(string $basename): bool
{
    if ($basename === '' || $basename[0] === '.') {
        return false;
    }
    $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
    return in_array($ext, nv5_board_allowed_extensions(), true);
}

function nv5_copy_tree(string $src, string $dst): void
{
    if (is_link($src)) {
        return;
    }
    if (!is_dir($dst) && !mkdir($dst, 0755, true) && !is_dir($dst)) {
        throw new RuntimeException('Kunne ikke lage ' . $dst);
    }
    foreach (scandir($src) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (!nv5_is_safe_relative_path($item)) {
            continue;
        }
        $from = $src . '/' . $item;
        $to = $dst . '/' . $item;
        if (is_link($from)) {
            continue;
        }
        if (is_dir($from)) {
            nv5_copy_tree($from, $to);
        } elseif (is_file($from)) {
            nv5_copy_atomic($from, $to);
        }
    }
}

function nv5_copy_board_tree(string $src, string $dst): void
{
    if (is_link($src) || !is_dir($src)) {
        throw new RuntimeException('Ugyldig kilde');
    }
    if (!is_dir($dst) && !mkdir($dst, 0755, true) && !is_dir($dst)) {
        throw new RuntimeException('Kunne ikke lage ' . $dst);
    }
    foreach (scandir($src) ?: [] as $item) {
        if ($item === '.' || $item === '..' || $item[0] === '.' || !nv5_is_safe_relative_path($item)) {
            continue;
        }
        $from = $src . '/' . $item;
        $to = $dst . '/' . $item;
        if (is_link($from)) {
            continue;
        }
        if (is_dir($from)) {
            nv5_copy_board_tree($from, $to);
            $children = array_values(array_filter(
                scandir($to) ?: [],
                static fn(string $n): bool => $n !== '.' && $n !== '..'
            ));
            if ($children === []) {
                @rmdir($to);
            }
        } elseif (is_file($from) && nv5_board_file_allowed($item)) {
            nv5_copy_atomic($from, $to);
        }
    }
}

function nv5_write_atomic(string $path, string $data): void
{
    $dir = dirname($path);
    $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($tmp, $data) === false) {
        throw new RuntimeException('Kunne ikke skrive ' . $tmp);
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Kunne ikke erstatte ' . $path);
    }
}

function nv5_copy_atomic(string $from, string $to): void
{
    if (is_link($from)) {
        throw new RuntimeException('Symlink avvist: ' . $from);
    }
    $data = file_get_contents($from);
    if ($data === false) {
        throw new RuntimeException('Kunne ikke lese ' . $from);
    }
    nv5_write_atomic($to, $data);
}

function nv5_extract_zip_safe(string $zipPath, string $extractDir): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Kunne ikke åpne zip');
    }
    if (!is_dir($extractDir) && !mkdir($extractDir, 0755, true) && !is_dir($extractDir)) {
        $zip->close();
        throw new RuntimeException('Kunne ikke lage ' . $extractDir);
    }
    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || $name === '') {
                continue;
            }
            $name = str_replace('\\', '/', $name);
            if (!nv5_is_safe_relative_path($name)) {
                throw new RuntimeException('Utrygg zip-sti');
            }
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX) {
                if ((($attr >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('Symlink i zip avvist');
                }
            }
            $target = $extractDir . '/' . $name;
            if (str_ends_with($name, '/')) {
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new RuntimeException('Kunne ikke lage mappe i zip');
                }
                continue;
            }
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                throw new RuntimeException('Kunne ikke lage mappe i zip');
            }
            $data = $zip->getFromIndex($i);
            if ($data === false) {
                throw new RuntimeException('Kunne ikke lese zip-post');
            }
            if (file_put_contents($target, $data) === false) {
                throw new RuntimeException('Kunne ikke skrive zip-post');
            }
        }
    } finally {
        $zip->close();
    }
}

/**
 * @return list<string>
 */
function nv5_server_sync_preserve_in(string $name): array
{
    if (in_array($name, ['sis', 'reise', 'admin'], true)) {
        return ['content', 'content.tmp', 'content.old'];
    }
    if ($name === 'shared') {
        return [];
    }
    return [];
}

function nv5_sync_server_app_dir(string $src, string $dst, string $name): void
{
    $preserve = array_fill_keys(nv5_server_sync_preserve_in($name), true);
    if (!is_dir($dst) && !mkdir($dst, 0755, true) && !is_dir($dst)) {
        throw new RuntimeException('Kunne ikke lage ' . $dst);
    }
    foreach (scandir($src) ?: [] as $item) {
        if ($item === '.' || $item === '..' || isset($preserve[$item]) || !nv5_is_safe_relative_path($item)) {
            continue;
        }
        $from = $src . '/' . $item;
        $to = $dst . '/' . $item;
        if (is_link($from)) {
            continue;
        }
        if (is_dir($from)) {
            nv5_copy_tree($from, $to);
        } elseif (is_file($from)) {
            nv5_copy_atomic($from, $to);
        }
    }
}

function nv5_sync_server_branch(string $siteRoot, string $shaFile, bool $force = false): void
{
    $o = rawurlencode(NV5_OWNER);
    $r = rawurlencode(NV5_REPO);
    $b = rawurlencode(NV5_SERVER_BRANCH);

    $meta = json_decode(nv5_github_get("https://api.github.com/repos/{$o}/{$r}/commits/{$b}"), true);
    $remote = is_array($meta) ? (string) ($meta['sha'] ?? '') : '';
    if ($remote === '') {
        throw new RuntimeException('Fant ikke commit på server');
    }

    $local = is_file($shaFile) ? trim((string) file_get_contents($shaFile)) : '';
    if (!$force && $remote === $local && nv5_server_webroot_complete($siteRoot)) {
        nv5_ensure_host_config($siteRoot);
        return;
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ext-zip mangler');
    }

    $zipData = nv5_github_get("https://api.github.com/repos/{$o}/{$r}/zipball/{$b}");
    $zipPath = sys_get_temp_dir() . '/nv5-s-' . bin2hex(random_bytes(6)) . '.zip';
    $extract = sys_get_temp_dir() . '/nv5-sx-' . bin2hex(random_bytes(6));
    file_put_contents($zipPath, $zipData);

    try {
        nv5_extract_zip_safe($zipPath, $extract);
        $entries = array_values(array_filter(scandir($extract) ?: [], fn($n) => $n !== '.' && $n !== '..'));
        if (count($entries) !== 1 || !nv5_is_safe_relative_path($entries[0])) {
            throw new RuntimeException('Uventet server-zip-struktur');
        }
        $source = $extract . '/' . $entries[0];
        if (is_link($source) || !is_dir($source)) {
            throw new RuntimeException('Ugyldig server-zip-rot');
        }

        $skip = array_fill_keys(nv5_server_sync_skip_web(), true);
        foreach (scandir($source) ?: [] as $item) {
            if ($item === '.' || $item === '..' || isset($skip[$item]) || !nv5_is_safe_relative_path($item)) {
                continue;
            }
            $from = $source . '/' . $item;
            $to = $siteRoot . '/' . $item;
            if (is_link($from)) {
                continue;
            }
            if (is_dir($from) && in_array($item, ['sis', 'reise', 'admin'], true)) {
                nv5_sync_server_app_dir($from, $to, $item);
            } elseif (is_dir($from)) {
                nv5_copy_tree($from, $to);
            } elseif (is_file($from)) {
                nv5_copy_atomic($from, $to);
            }
        }

        foreach (nv5_server_sync_required_files() as $required) {
            if (!is_file($siteRoot . '/' . $required)) {
                throw new RuntimeException('Server-sync mangler: ' . $required);
            }
        }

        file_put_contents($shaFile, $remote . "\n");
        nv5_log_sync_event(dirname($shaFile), 'server_sync sha=' . substr($remote, 0, 12));
        nv5_ensure_host_config($siteRoot);
    } finally {
        @unlink($zipPath);
        nv5_rm_tree($extract);
    }
}

function nv5_fetch_main_source(): string
{
    $o = rawurlencode(NV5_OWNER);
    $r = rawurlencode(NV5_REPO);
    $b = rawurlencode(NV5_BOARD_BRANCH);
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ext-zip mangler');
    }
    $zipData = nv5_github_get("https://api.github.com/repos/{$o}/{$r}/zipball/{$b}");
    $zipPath = sys_get_temp_dir() . '/nv5-m-' . bin2hex(random_bytes(6)) . '.zip';
    $extract = sys_get_temp_dir() . '/nv5-mx-' . bin2hex(random_bytes(6));
    file_put_contents($zipPath, $zipData);
    try {
        nv5_extract_zip_safe($zipPath, $extract);
        $entries = array_values(array_filter(scandir($extract) ?: [], fn($n) => $n !== '.' && $n !== '..'));
        if (count($entries) !== 1) {
            throw new RuntimeException('Uventet main-zip-struktur');
        }
        return $extract . '/' . $entries[0];
    } finally {
        @unlink($zipPath);
    }
}

function nv5_finish_main_extract(string $extractRoot): void
{
    nv5_rm_tree($extractRoot);
}

function nv5_remote_main_sha(): string
{
    $o = rawurlencode(NV5_OWNER);
    $r = rawurlencode(NV5_REPO);
    $b = rawurlencode(NV5_BOARD_BRANCH);
    $meta = json_decode(nv5_github_get("https://api.github.com/repos/{$o}/{$r}/commits/{$b}"), true);
    $remote = is_array($meta) ? (string) ($meta['sha'] ?? '') : '';
    if ($remote === '') {
        throw new RuntimeException('Fant ikke commit på main');
    }
    return $remote;
}

function nv5_app_content_valid(string $appId, string $contentDir): bool
{
    $path = $contentDir . '/index.html';
    if (!is_file($path)) {
        return false;
    }
    $html = file_get_contents($path);
    if ($html === false) {
        return false;
    }
    if ($appId === 'sis') {
        return stripos($html, 'id="board"') !== false;
    }
    if ($appId === 'reise') {
        return stripos($html, 'id="tripForm"') !== false || stripos($html, 'class="reise"') !== false;
    }
    return true;
}

function nv5_sync_app_from_github(
    string $appId,
    string $content,
    string $tmp,
    string $shaFile,
    bool $force = false
): void {
    $remote = nv5_remote_main_sha();
    $local = is_file($shaFile) ? trim((string) file_get_contents($shaFile)) : '';
    if (!$force && $remote === $local && nv5_app_content_valid($appId, $content)) {
        return;
    }

    $extract = nv5_fetch_main_source();
    try {
        $appSource = $extract . '/' . $appId;
        if (!is_dir($appSource) || !is_file($appSource . '/index.html')) {
            throw new RuntimeException("main mangler {$appId}/index.html");
        }
        nv5_rm_tree($tmp);
        nv5_copy_board_tree($appSource, $tmp);
        if (!is_file($tmp . '/index.html')) {
            throw new RuntimeException("main mangler {$appId}/index.html etter sync");
        }
        $old = $content . '.old';
        nv5_rm_tree($old);
        if (is_dir($content)) {
            rename($content, $old);
        }
        rename($tmp, $content);
        nv5_rm_tree($old);
        file_put_contents($shaFile, $remote . "\n");
    } finally {
        nv5_finish_main_extract($extract);
        nv5_rm_tree($tmp);
    }
}

function nv5_sync_tree_from_github(
    string $treeName,
    string $dest,
    string $tmp,
    string $shaFile,
    bool $force = false
): void {
    $remote = nv5_remote_main_sha();
    $local = is_file($shaFile) ? trim((string) file_get_contents($shaFile)) : '';
    $marker = $dest . '/js/util.js';
    if ($treeName === 'shared' && !$force && $remote === $local && is_file($marker)) {
        return;
    }

    $extract = nv5_fetch_main_source();
    try {
        $source = $extract . '/' . $treeName;
        if (!is_dir($source)) {
            throw new RuntimeException("main mangler {$treeName}/");
        }
        nv5_rm_tree($tmp);
        nv5_copy_board_tree($source, $tmp);
        $old = $dest . '.old';
        nv5_rm_tree($old);
        if (is_dir($dest)) {
            rename($dest, $old);
        }
        rename($tmp, $dest);
        nv5_rm_tree($old);
        file_put_contents($shaFile, $remote . "\n");
    } finally {
        nv5_finish_main_extract($extract);
        nv5_rm_tree($tmp);
    }
}

function nv5_sync_admin_from_github(array $paths, bool $force): void
{
    $remote = nv5_remote_main_sha();
    $local = is_file($paths['admin_sha']) ? trim((string) file_get_contents($paths['admin_sha'])) : '';
    if (!$force && $remote === $local && is_file($paths['admin_content'] . '/index.html')) {
        return;
    }

    $extract = nv5_fetch_main_source();
    $siteRoot = $paths['site_root'];
    try {
        $adminSource = $extract . '/admin';
        if (!is_dir($adminSource) || !is_file($adminSource . '/index.html')) {
            throw new RuntimeException('main mangler admin/index.html');
        }

        nv5_rm_tree($paths['admin_content_tmp']);
        if (!is_dir($paths['admin_content_tmp']) && !mkdir($paths['admin_content_tmp'], 0755, true)) {
            throw new RuntimeException('Kunne ikke lage admin content tmp');
        }
        nv5_copy_atomic($adminSource . '/index.html', $paths['admin_content_tmp'] . '/index.html');

        foreach (['css', 'js'] as $subdir) {
            $from = $adminSource . '/' . $subdir;
            $to = $siteRoot . '/admin/' . $subdir;
            if (is_dir($from)) {
                nv5_rm_tree($to . '.old');
                if (is_dir($to)) {
                    rename($to, $to . '.old');
                }
                nv5_copy_board_tree($from, $to);
                nv5_rm_tree($to . '.old');
            }
        }

        $old = $paths['admin_content'] . '.old';
        nv5_rm_tree($old);
        if (is_dir($paths['admin_content'])) {
            rename($paths['admin_content'], $old);
        }
        rename($paths['admin_content_tmp'], $paths['admin_content']);
        nv5_rm_tree($old);
        file_put_contents($paths['admin_sha'], $remote . "\n");
    } finally {
        nv5_finish_main_extract($extract);
        nv5_rm_tree($paths['admin_content_tmp']);
    }
}

function nv5_send_security_headers(): void
{
    header(
        "Content-Security-Policy: default-src 'self'; " .
        "base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; " .
        "script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
        "font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: https:; " .
        "connect-src 'self' https://api.entur.io; worker-src 'none'; manifest-src 'self'; " .
        'upgrade-insecure-requests'
    );
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: accelerometer=(), camera=(), display-capture=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function nv5_read_sha_file(string $shaFile): string
{
    return is_readable($shaFile) ? trim((string) file_get_contents($shaFile)) : '';
}

function nv5_asset_version(string $shaFile, string $content): string
{
    $sha = nv5_read_sha_file($shaFile);
    if ($sha !== '') {
        return substr($sha, 0, 12);
    }
    $index = $content . '/index.html';
    return is_file($index) ? (string) filemtime($index) : (string) time();
}

function nv5_inject_meta(string $html, array $metas): string
{
    $tags = '';
    foreach ($metas as $name => $value) {
        if ($value === '') {
            continue;
        }
        $tags .= '<meta name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" content="'
            . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">' . "\n    ";
    }
    if ($tags === '') {
        return $html;
    }
    $out = preg_replace('/<head[^>]*>/i', '$0' . "\n    " . rtrim($tags), $html, 1);
    return is_string($out) ? $out : $html;
}

function nv5_bust_asset_urls(string $html, string $version): string
{
    $v = rawurlencode($version);
    $out = preg_replace_callback(
        '/\b((?:href|src)=["\'])([^"\']+\.(?:css|js|webmanifest|woff2))(["\'])/i',
        static function (array $m) use ($v): string {
            $url = $m[2];
            if (str_contains($url, '://') || str_starts_with($url, '//')) {
                return $m[0];
            }
            if (preg_match('/([?&])v=[^&]*/', $url) === 1) {
                $url = (string) preg_replace('/([?&])v=[^&]*/', '$1v=' . $v, $url, 1);
            } else {
                $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . $v;
            }
            return $m[1] . $url . $m[3];
        },
        $html
    );
    return is_string($out) ? $out : $html;
}

function nv5_render_app(string $appId, array $paths): void
{
    $app = $paths['apps'][$appId];
    $content = $app['content'];
    $html = file_get_contents($content . '/index.html');
    if ($html === false) {
        throw new RuntimeException('Kunne ikke lese content/index.html');
    }

    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/' . $appId . '/index.php'));
    $baseHref = ($dir === '/' || $dir === '.') ? '/' . $appId . '/content/' : rtrim($dir, '/') . '/content/';
    $base = '<base href="' . htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8') . '">';
    if (stripos($html, '<base ') === false) {
        $html = preg_replace('/<head[^>]*>/i', '$0' . "\n    " . $base, $html, 1) ?? $html;
    }

    $sisSha = nv5_read_sha_file($paths['apps']['sis']['sha']);
    $metas = [
        'nv5-server-sha' => nv5_read_sha_file($paths['server_sha']),
        'nv5-shared-sha' => nv5_read_sha_file($paths['shared_sha']),
        'nv5-sis-sha' => $sisSha,
        'nv5-board-sha' => $sisSha,
        'nv5-reise-sha' => nv5_read_sha_file($paths['apps']['reise']['sha']),
    ];
    if ($appId === 'sis') {
        unset($metas['nv5-reise-sha']);
    }
    if ($appId === 'reise') {
        unset($metas['nv5-board-sha'], $metas['nv5-sis-sha']);
    }

    $html = nv5_inject_meta($html, $metas);
    $html = nv5_bust_asset_urls($html, nv5_asset_version($app['sha'], $content));

    nv5_send_security_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $html;
}

function nv5_render_admin(array $paths, array $context = []): void
{
    $htmlFile = $paths['admin_content'] . '/index.html';
    if (!is_file($htmlFile)) {
        throw new RuntimeException('Admin UI er ikke synket — kjør ?sync=admin eller ?sync=env');
    }
    $html = file_get_contents($htmlFile);
    if ($html === false) {
        throw new RuntimeException('Kunne ikke lese admin UI');
    }

    $base = '<base href="/admin/">';
    if (stripos($html, '<base ') === false) {
        $html = preg_replace('/<head[^>]*>/i', '$0' . "\n    " . $base, $html, 1) ?? $html;
    }

    $auth = nv5_admin_password_status(
        (string) ($context['site_root'] ?? $paths['site_root']),
        (string) ($context['state_dir'] ?? '')
    );
    $html = nv5_inject_meta($html, [
        'nv5-server-sha' => nv5_read_sha_file($paths['server_sha']),
        'nv5-shared-sha' => nv5_read_sha_file($paths['shared_sha']),
        'nv5-sis-sha' => nv5_read_sha_file($paths['apps']['sis']['sha']),
        'nv5-reise-sha' => nv5_read_sha_file($paths['apps']['reise']['sha']),
        'nv5-admin-auth' => $auth['active'] ? 'on' : 'off',
    ]);
    $html = nv5_bust_asset_urls($html, nv5_asset_version($paths['admin_sha'], $paths['admin_content']));

    if (($context['site_root'] ?? '') !== '' && ($context['state_dir'] ?? '') !== '') {
        $notice = nv5_build_admin_notice(
            (string) $context['site_root'],
            (string) $context['state_dir'],
            is_array($context['sync'] ?? null) ? $context['sync'] : ['param' => '', 'server' => false, 'shared' => false, 'admin' => false, 'sis' => false, 'reise' => false, 'all' => false],
            (int) ($context['retry_after'] ?? 0)
        );
        $replaced = preg_replace('/(<header class="admin__header">.*?<\/header>)/s', '$1' . "\n\n      " . $notice, $html, 1);
        if (is_string($replaced)) {
            $html = $replaced;
        }
    }

    nv5_send_security_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $html;
}

/**
 * Full forced sync for index-initial.php (ingen rate limit).
 *
 * @return array{steps:list<string>}
 */
function nv5_bootstrap_install(string $siteRoot): array
{
    $stateDir = nv5_ensure_state_dir($siteRoot);
    $paths = nv5_paths($siteRoot, $stateDir);
    $steps = [];

    nv5_maybe_sync_server($paths, true);
    $steps[] = 'server — PHP, nginx-conf, entry points';
    file_put_contents($paths['server_check'], (string) time());

    nv5_maybe_sync_shared($paths, true);
    $steps[] = 'shared — /shared/';
    file_put_contents($paths['shared_check'], (string) time());

    nv5_maybe_sync_admin($paths, true);
    $steps[] = 'admin — drift-UI';
    file_put_contents($paths['admin_check'], (string) time());

    nv5_maybe_sync_app('sis', $paths, 60, true);
    $steps[] = 'sis — tavle → /sis/content/';
    file_put_contents($paths['apps']['sis']['check'], (string) time());

    nv5_maybe_sync_app('reise', $paths, 60, true);
    $steps[] = 'reise — planlegger → /reise/content/';
    file_put_contents($paths['apps']['reise']['check'], (string) time());

    return ['steps' => $steps];
}
