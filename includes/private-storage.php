<?php
declare(strict_types=1);

/** Private uploads are addressed by database-relative paths, never public URLs. */
function app_private_storage_canonical_path(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '' || str_contains($path, "\0") || !preg_match('~^(?:/|[A-Za-z]:/)~', $path)) {
        throw new RuntimeException('Private storage requires an absolute filesystem path.');
    }
    $suffix = [];
    $cursor = rtrim($path, '/');
    while (!file_exists($cursor) && !is_link($cursor)) {
        $parent = dirname($cursor);
        if ($parent === $cursor || in_array(basename($cursor), ['.', '..'], true)) {
            throw new RuntimeException('The private storage path could not be resolved.');
        }
        array_unshift($suffix, basename($cursor));
        $cursor = $parent;
    }
    $resolved = realpath($cursor);
    if ($resolved === false || !is_dir($resolved)) {
        throw new RuntimeException('The private storage directory could not be resolved.');
    }
    return rtrim(str_replace('\\', '/', $resolved), '/') . ($suffix ? '/' . implode('/', $suffix) : '');
}

function app_private_storage_contains(string $directory, string $path): bool
{
    $directory = rtrim(str_replace('\\', '/', $directory), '/');
    $path = rtrim(str_replace('\\', '/', $path), '/');
    if (DIRECTORY_SEPARATOR === '\\') {
        $directory = strtolower($directory);
        $path = strtolower($path);
    }
    return $directory === $path || str_starts_with($path, $directory . '/');
}

function app_private_storage_public_roots(): array
{
    $appRoot = realpath(RESTAURANT_APP_ROOT);
    if ($appRoot === false) throw new RuntimeException('The application directory could not be resolved.');
    $roots = [$appRoot];
    $configured = trim((string)(app_config()['storage']['public_root'] ?? ''));
    if ($configured !== '') {
        $roots[] = app_private_storage_canonical_path($configured);
    } else {
        // URL subdirectory depth gives CLI workers the same public boundary as HTTP.
        $urlPath = (string)(parse_url((string)(app_config()['app']['url'] ?? ''), PHP_URL_PATH) ?? '');
        $inferred = $appRoot;
        foreach (array_filter(explode('/', trim($urlPath, '/')), 'strlen') as $_segment) {
            $inferred = dirname($inferred);
        }
        $roots[] = $inferred;
    }
    $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot !== '' && is_dir($documentRoot)) $roots[] = (string)realpath($documentRoot);
    return array_values(array_unique($roots));
}

function app_private_storage_root(bool $create = true): string
{
    $appRoot = (string)realpath(RESTAURANT_APP_ROOT);
    $configured = trim((string)(app_config()['storage']['private_root'] ?? ''));
    // Stable across web requests and CLI processes. Subdirectory installs must configure
    // an external path when this sibling would still be inside their document root.
    $path = $configured !== '' ? $configured : dirname($appRoot) . '/gelato-private-storage/' . substr(hash('sha256', $appRoot), 0, 16);
    $root = app_private_storage_canonical_path($path);
    foreach (app_private_storage_public_roots() as $publicRoot) {
        if (app_private_storage_contains($publicRoot, $root) || app_private_storage_contains($root, $publicRoot)) {
            throw new RuntimeException('Set storage.private_root in config.php to a dedicated directory outside the website document root, for example /home/ACCOUNT/gelato-private/RESTAURANT.');
        }
    }
    if ($create) {
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('The private storage directory could not be created. Check storage.private_root and its parent permissions.');
        }
        $resolved = realpath($root);
        if ($resolved === false || str_replace('\\', '/', $resolved) !== $root) {
            throw new RuntimeException('The private storage directory changed during initialization.');
        }
        @chmod($root, 0700);
    }
    return $root;
}

function app_private_storage_relative(string $relative): string
{
    if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]*(?:/[A-Za-z0-9][A-Za-z0-9._-]*)*$~D', $relative)) {
        throw new InvalidArgumentException('The private file path is invalid.');
    }
    return $relative;
}

function app_private_storage_directory(string $root, string $relative): string
{
    $directory = $root;
    $parent = dirname(app_private_storage_relative($relative));
    if ($parent === '.') return $directory;
    foreach (explode('/', $parent) as $part) {
        $directory .= '/' . $part;
        if (is_link($directory)) throw new RuntimeException('Private storage cannot contain linked directories.');
        if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) {
            throw new RuntimeException('The private upload directory could not be created.');
        }
        $resolved = realpath($directory);
        if ($resolved === false || !app_private_storage_contains($root, $resolved)) {
            throw new RuntimeException('The private upload directory is outside its storage root.');
        }
        @chmod($directory, 0700);
    }
    return $directory;
}

function app_private_storage_existing(string $root, string $relative): ?string
{
    $relative = app_private_storage_relative($relative);
    $resolvedRoot = realpath($root);
    if ($resolvedRoot === false || is_link($root)) return null;
    $cursor = $resolvedRoot;
    foreach (explode('/', $relative) as $part) {
        $cursor .= '/' . $part;
        if (is_link($cursor)) return null;
    }
    $path = realpath($cursor);
    return $path !== false && is_file($path) && app_private_storage_contains($resolvedRoot, $path) ? $path : null;
}

/** Publish a complete immutable file; a collision must never overwrite an upload. */
function app_private_storage_write(string $relative, string $bytes): string
{
    $relative = app_private_storage_relative($relative);
    $root = app_private_storage_root();
    $directory = app_private_storage_directory($root, $relative);
    $target = $root . '/' . $relative;
    $lockPath = $root . '/.write.lock';
    if (is_link($lockPath)) throw new RuntimeException('The private storage lock is invalid.');
    $lock = fopen($lockPath, 'c+b');
    if ($lock === false) throw new RuntimeException('The private storage lock could not be opened.');
    @chmod($lockPath, 0600);
    $temporary = null;
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('The private storage lock could not be acquired.');
        if (file_exists($target) || is_link($target)) throw new RuntimeException('The private file already exists.');
        $temporary = $directory . '/.upload-' . bin2hex(random_bytes(16));
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new RuntimeException('The private file could not be completely stored.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $target)) throw new RuntimeException('The private upload could not be published.');
        $temporary = null;
        return $target;
    } finally {
        if ($temporary !== null) @unlink($temporary);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Only call after DB authorization, with a server-built namespace/organization prefix. */
function app_private_storage_read(string $relative, string $expectedPrefix): ?string
{
    try {
        $relative = app_private_storage_relative($relative);
        if (!str_starts_with($relative, app_private_storage_relative(rtrim($expectedPrefix, '/')) . '/')) return null;
        $root = app_private_storage_root();
        $current = app_private_storage_existing($root, $relative);
        if ($current !== null) return $current;

        // Pre-hardening uploads were written here because dirname() saw an unresolved
        // "includes/.." string. Root .htaccess denies this legacy tree on every request.
        $legacyRoot = (string)realpath(RESTAURANT_APP_ROOT) . '/includes/gelato-private-storage';
        $legacy = app_private_storage_existing($legacyRoot, $relative);
        if ($legacy === null) return null;
        $bytes = file_get_contents($legacy);
        if (!is_string($bytes)) throw new RuntimeException('The previous private file could not be read.');
        try {
            $current = app_private_storage_write($relative, $bytes);
        } catch (RuntimeException $e) {
            // Another authorized reader may have completed the same migration.
            $current = app_private_storage_existing($root, $relative);
            if ($current === null || !hash_equals(hash('sha256', $bytes), (string)hash_file('sha256', $current))) throw $e;
        }
        @unlink($legacy);
        return $current;
    } catch (InvalidArgumentException) {
        return null;
    }
}
