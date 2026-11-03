<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$fixture = sys_get_temp_dir() . '/gelato-private-storage-' . bin2hex(random_bytes(8));
mkdir($fixture . '/web/app/includes', 0700, true);
define('RESTAURANT_APP_ROOT', $fixture . '/web/app');
$GLOBALS['privateStorageConfig'] = ['app'=>['url'=>'https://example.test/app'], 'storage'=>[]];
function app_config(): array { return $GLOBALS['privateStorageConfig']; }
require __DIR__ . '/../includes/private-storage.php';
function ps_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function ps_reject(callable $callback, string $message): void {
    try { $callback(); } catch (RuntimeException|InvalidArgumentException) { return; }
    throw new RuntimeException($message);
}
function ps_remove(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path) as $entry) ps_remove($entry->getPathname());
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) unlink($path);
}
try {
    unset($_SERVER['DOCUMENT_ROOT']);
    ps_reject(static fn()=>app_private_storage_root(), 'CLI must reject a default inside the URL-inferred document root.');
    $_SERVER['DOCUMENT_ROOT']=$fixture.'/web';
    ps_reject(static fn()=>app_private_storage_root(), 'HTTP must reject the same unsafe default.');
    $GLOBALS['privateStorageConfig']['storage']['private_root']=$fixture.'/private/restaurant';
    $httpRoot=app_private_storage_root();
    unset($_SERVER['DOCUMENT_ROOT']);
    ps_assert($httpRoot===app_private_storage_root(), 'Web and CLI must resolve one stable private root.');
    ps_assert(!app_private_storage_contains($fixture.'/web',$httpRoot), 'Private uploads must be outside the webroot.');
    $relative='purchasing-documents/7/purchasing-0123456789.pdf';
    $stored=app_private_storage_write($relative,"%PDF-private-fixture\n");
    ps_assert(file_get_contents($stored)==="%PDF-private-fixture\n", 'Stored bytes must be unchanged.');
    ps_assert(app_private_storage_read($relative,'purchasing-documents/7')===$stored, 'Authorized namespace must retrieve the file.');
    ps_assert(app_private_storage_read($relative,'purchasing-documents/8')===null, 'Another organization prefix must not retrieve it.');
    ps_assert(app_private_storage_read($relative,'task-proofs/7')===null, 'Another upload namespace must not retrieve it.');
    ps_assert(app_private_storage_read('../web/private.pdf','purchasing-documents/7')===null, 'Traversal must be rejected.');
    ps_reject(static fn()=>app_private_storage_write($relative,'overwrite'), 'An existing upload must never be overwritten.');
    ps_assert(file_get_contents($stored)==="%PDF-private-fixture\n", 'Collision must preserve existing bytes.');
    ps_assert((fileperms($stored)&0777)===0600, 'Private file permissions must be 0600.');
    ps_assert((fileperms($httpRoot)&0777)===0700, 'Private root permissions must be 0700.');

    $legacy=RESTAURANT_APP_ROOT.'/includes/gelato-private-storage/task-proofs/7/legacy.jpg';
    mkdir(dirname($legacy),0700,true);file_put_contents($legacy,'legacy-proof');
    $migrated=app_private_storage_read('task-proofs/7/legacy.jpg','task-proofs/7');
    ps_assert($migrated!==null && file_get_contents($migrated)==='legacy-proof', 'Existing private uploads must migrate without data loss.');
    ps_assert(!file_exists($legacy), 'Completed migration must remove the previous private file.');
    ps_assert(app_private_storage_read('task-proofs/7/legacy.jpg','task-proofs/7')===$migrated, 'Migrated files must remain readable.');

    symlink($fixture.'/web',$httpRoot.'/linked');
    ps_reject(static fn()=>app_private_storage_write('linked/escape/file.txt','unsafe'), 'A directory symlink must not redirect writes into the website.');
    ps_assert(!file_exists($fixture.'/web/escape'), 'Rejected linked directory must not create public directories.');
    symlink($stored,$httpRoot.'/task-proofs/7/linked.jpg');
    ps_assert(app_private_storage_read('task-proofs/7/linked.jpg','task-proofs/7')===null, 'Linked files must not bypass namespace boundaries.');
    $GLOBALS['privateStorageConfig']['storage']['private_root']=$fixture.'/web/app/includes/private';
    ps_reject(static fn()=>app_private_storage_root(), 'Configured paths inside the application must be rejected.');
    $GLOBALS['privateStorageConfig']['storage']['private_root']=$fixture;
    ps_reject(static fn()=>app_private_storage_root(), 'A parent containing the website must not become the private root.');
    $GLOBALS['privateStorageConfig']['storage']['private_root']='relative/private';
    ps_reject(static fn()=>app_private_storage_root(), 'Relative configured roots must be rejected.');
    echo "private storage behavior passed\n";
} finally { ps_remove($fixture); }
