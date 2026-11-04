<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function hostedReleaseIdentity(string $path): array
{
    $zip = new ZipArchive();
    if (!is_file($path) || $zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
        throw new RuntimeException('Provide a valid hosted ZIP.');
    }
    try {
        $version = trim((string)$zip->getFromName('GELATO_VERSION.txt', 100));
        if (!preg_match('/^(?:0|[1-9][0-9]{0,8})\.(?:0|[1-9][0-9]{0,8})\.(?:0|[1-9][0-9]{0,8})$/D', $version)
            || $zip->locateName('scripts/food-monster-provision.php') === false
            || $zip->locateName('food-monster-hosted-health.php') === false) {
            throw new RuntimeException('The archive is missing the stable hosted release files.');
        }
        return [$version, trim((string)$zip->getFromName('DEPLOY_SHA.txt', 100))];
    } finally {
        $zip->close();
    }
}

function hostedReleaseSignature(string $path, string $version, string $secret): array
{
    $sha = hash_file('sha256', $path);
    if ($sha === false) {
        throw new RuntimeException('Could not calculate the hosted ZIP checksum.');
    }
    $payload = "Gelato Hosted Release v1\n{$version}\n{$sha}\n";
    $signature = sodium_crypto_sign_detached($payload, $secret);
    if (!sodium_crypto_sign_verify_detached($signature, $payload, sodium_crypto_sign_publickey_from_secretkey($secret))) {
        throw new RuntimeException('The configured Ed25519 secret key could not produce a verifiable signature.');
    }
    return [$sha, base64_encode($signature)];
}

function writeHostedReleaseBundle(string $source, string $dir, string $expectedVersion, string $notesPath, string $expectedCommit, string $secret): void
{
    $contents = is_file($notesPath) ? file_get_contents($notesPath, false, null, 0, 40001) : false;
    $notes = is_string($contents) ? trim(str_replace(["\r\n", "\r"], "\n", $contents)) : '';
    if ($notes === '' || strlen((string)$contents) > 40000 || !preg_match('//u', $notes)
        || preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $notes) || preg_match_all('/./us', $notes) > 10000) {
        throw new RuntimeException('Provide nonempty UTF-8 release notes of up to 10,000 characters.');
    }
    if (!preg_match('/^[a-f0-9]{40}$/D', $expectedCommit)) {
        throw new RuntimeException('Provide the exact 40-character source commit.');
    }
    if (!@mkdir($dir, 0700)) {
        throw new RuntimeException('Provide a new output directory in an existing writable parent.');
    }
    $archiveName = 'gelato-hosted.zip';
    $archive = $dir . '/' . $archiveName;
    $files = [];
    try {
        if (!is_file($source) || !@copy($source, $archive)) {
            throw new RuntimeException('Could not copy the hosted ZIP into the bundle.');
        }
        [$version, $commit] = hostedReleaseIdentity($archive);
        if ($version !== $expectedVersion || $commit !== $expectedCommit) {
            throw new RuntimeException('The hosted ZIP version or source commit differs from the requested release.');
        }
        [$checksum, $signature] = hostedReleaseSignature($archive, $version, $secret);
        $files = [
            'gelato-hosted.sha256' => $checksum . "  $archiveName\n",
            'gelato-hosted.sig' => $signature . "\n",
            'release-notes.txt' => $notes . "\n",
            'release.json' => json_encode([
                'version' => $version,
                'channel' => 'stable',
                'source_commit' => $commit,
                'package' => $archiveName,
                'sha256' => $checksum,
                'public_key_sha256' => hash('sha256', sodium_crypto_sign_publickey_from_secretkey($secret)),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n",
        ];
        foreach ($files as $name => $content) {
            if (@file_put_contents($dir . '/' . $name, $content) !== strlen($content)) {
                throw new RuntimeException('Could not write the hosted release bundle.');
            }
        }
    } catch (Throwable $error) {
        foreach ([$archiveName, ...array_keys($files)] as $name) {
            @unlink($dir . '/' . $name);
        }
        @rmdir($dir);
        throw $error;
    }
}

$path = $argv[1] ?? '';
$bundle = $argc === 7 && $argv[2] === '--bundle';
if ($argc !== 2 && !$bundle) {
    fwrite(STDERR, "Usage: php scripts/sign-hosted-release.php ZIP [--bundle NEW_DIR VERSION NOTES_FILE SOURCE_COMMIT]\n");
    exit(1);
}
if (!extension_loaded('sodium') || !class_exists(ZipArchive::class)) {
    fwrite(STDERR, "Hosted release signing requires PHP Sodium and ZipArchive.\n");
    exit(1);
}
$secret = base64_decode((string)getenv('GELATO_HOSTED_SIGNING_KEY'), true);
if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "Provide a base64 Ed25519 secret key in GELATO_HOSTED_SIGNING_KEY.\n");
    exit(1);
}
$status = 0;
try {
    if ($bundle) {
        writeHostedReleaseBundle($path, $argv[3], $argv[4], $argv[5], $argv[6], $secret);
    } else {
        [$version] = hostedReleaseIdentity($path);
        [, $signature] = hostedReleaseSignature($path, $version, $secret);
        echo $signature, PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    $status = 1;
} finally {
    sodium_memzero($secret);
}
exit($status);
