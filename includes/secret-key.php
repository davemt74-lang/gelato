<?php
declare(strict_types=1);

/** All first-use callers share a lock and recheck before creating any key bytes. */
function app_secret_key_from_file(string $directory): string
{
    if (!function_exists('sodium_crypto_secretbox')) {
        throw new RuntimeException('The PHP Sodium extension is required to store API keys securely.');
    }
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The application could not create its key storage directory.');
    }
    @chmod($directory, 0700);
    $path = $directory . '/application.key';
    $lockPath = $directory . '/.application-key.lock';
    if (is_link($lockPath)) throw new RuntimeException('The application encryption key lock is invalid.');
    $lock = fopen($lockPath, 'c+b');
    if ($lock === false) throw new RuntimeException('The application encryption key lock could not be opened.');
    @chmod($lockPath, 0600);
    $temporary = null;
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('The application encryption key lock could not be acquired.');
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) {
            $generated = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            $temporary = $directory . '/.application-key-' . bin2hex(random_bytes(16));
            if (file_put_contents($temporary, $generated, LOCK_EX) !== strlen($generated)) {
                throw new RuntimeException('The application encryption key could not be created.');
            }
            @chmod($temporary, 0600);
            if (!rename($temporary, $path)) throw new RuntimeException('The application encryption key could not be published.');
            $temporary = null;
        }
        // Invalid or unreadable existing keys are failures, never permission to rotate.
        $encoded = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($encoded) ? base64_decode(trim($encoded), true) : false;
        if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('The application encryption key is invalid. Restore the existing key from backup; it has not been replaced.');
        }
        return $decoded;
    } finally {
        if ($temporary !== null) @unlink($temporary);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
