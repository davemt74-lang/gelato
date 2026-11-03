<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$path = $argv[1] ?? '';
$secret = base64_decode((string)getenv('GELATO_HOSTED_SIGNING_KEY'), true);
if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES || !is_file($path)) {
    fwrite(STDERR, "Provide the hosted ZIP path and a base64 Ed25519 secret key in GELATO_HOSTED_SIGNING_KEY.\n");
    exit(1);
}
$zip = new ZipArchive();
if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
    exit(1);
}
$version = trim((string)$zip->getFromName('GELATO_VERSION.txt', 100));
$hosted = $zip->locateName('scripts/food-monster-provision.php') !== false
    && $zip->locateName('food-monster-hosted-health.php') !== false;
$zip->close();
$sha = hash_file('sha256', $path);
if (!$hosted || $sha === false || !preg_match('/^(?:0|[1-9][0-9]{0,8})\.(?:0|[1-9][0-9]{0,8})\.(?:0|[1-9][0-9]{0,8})$/D', $version)) {
    fwrite(STDERR, "The archive is missing the stable hosted release files.\n");
    exit(1);
}
echo base64_encode(sodium_crypto_sign_detached("Gelato Hosted Release v1\n{$version}\n{$sha}\n", $secret)), PHP_EOL;
sodium_memzero($secret);
