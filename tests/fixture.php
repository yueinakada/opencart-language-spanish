<?php
declare(strict_types=1);

// This helper runs only against the disposable store created by CI.
$root = rtrim($argv[1], '/') . '/';
require $root . 'config.php';
require dirname(__DIR__) . '/package/system/library/installer.php';

use Opencart\System\Library\Extension\YueiSpanishPe\Installer;

$pdo = new PDO('mysql:host=' . DB_HOSTNAME . ';port=' . DB_PORT . ';dbname=' . DB_DATABASE . ';charset=utf8mb4', DB_USERNAME, DB_PASSWORD,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
if (!preg_match('/\A[a-zA-Z0-9_]*\z/D', DB_PREFIX)) { throw new RuntimeException('Invalid test prefix'); }
$service = new Installer($pdo, DB_PREFIX);
$language = $service->language();
$command = $argv[2] ?? 'status';
if ($command === 'edit') {
    $query = $pdo->prepare('UPDATE `' . DB_PREFIX . 'product_description` SET name = ? WHERE language_id = ? AND product_id = ?');
    $query->execute(['Producto de prueba conservado', $language['language_id'], 40]);
} elseif ($command === 'default') {
    // A second-store setting proves the guard checks all stores, not only store zero.
    $query = $pdo->prepare('INSERT INTO `' . DB_PREFIX . 'setting` (store_id, code, `key`, `value`, serialized) VALUES (7, ?, ?, ?, 0)');
    $query->execute(['config', 'config_language_catalog', 'es-pe']);
} elseif ($command === 'clear-default') {
    $query = $pdo->prepare('DELETE FROM `' . DB_PREFIX . 'setting` WHERE store_id = 7 AND `key` = ?');
    $query->execute(['config_language_catalog']);
} elseif ($command === 'remove-record') {
    // CI-only simulation of the merchant's explicit language removal; production never calls this helper.
    $query = $pdo->prepare('DELETE FROM `' . DB_PREFIX . 'language` WHERE code = ? AND extension = ?');
    $query->execute(['es-pe', Installer::PACKAGE]);
}
$language = $service->language();
$result = ['language' => $language, 'default' => $service->isDefault()];
$query = $pdo->prepare('SELECT extension_install_id FROM `' . DB_PREFIX . 'extension_install` WHERE code = ? ORDER BY extension_install_id DESC LIMIT 1');
$query->execute([Installer::PACKAGE]);
$result['package_id'] = (int)$query->fetchColumn();
if ($language) {
    $query = $pdo->prepare('SELECT COUNT(*) FROM `' . DB_PREFIX . 'product_description` WHERE language_id = ?');
    $query->execute([$language['language_id']]);
    $result['products'] = (int)$query->fetchColumn();
    $query = $pdo->prepare('SELECT name FROM `' . DB_PREFIX . 'product_description` WHERE language_id = ? AND product_id = ?');
    $query->execute([$language['language_id'], 40]);
    $result['product_name'] = $query->fetchColumn();
}
$query = $pdo->prepare('SELECT COUNT(*) FROM `' . DB_PREFIX . 'extension` WHERE extension = ? AND code = ?');
$query->execute([Installer::PACKAGE, Installer::PACKAGE]);
$result['registrations'] = (int)$query->fetchColumn();
$query = $pdo->prepare('SELECT COUNT(*) FROM `' . DB_PREFIX . 'event` WHERE code = ?');
$query->execute(['yuei_spanish_pe_guard']);
$result['guards'] = (int)$query->fetchColumn();
echo json_encode($result, JSON_THROW_ON_ERROR);
