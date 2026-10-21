<?php
declare(strict_types=1);

$root = rtrim($argv[1], '/') . '/';
require $root . 'config.php';
require dirname(__DIR__) . '/package/system/library/installer.php';
use Opencart\System\Library\Extension\YueiSpanishPe\Installer;

if (($argv[2] ?? '') === '--worker') {
    $service = Installer::connect();
    echo "ready\n"; flush();
    fgets(STDIN);
    echo $service->install(1) . "\n";
    exit;
}
$pdo = new PDO('mysql:host=' . DB_HOSTNAME . ';port=' . DB_PORT . ';dbname=' . DB_DATABASE . ';charset=utf8mb4', DB_USERNAME, DB_PASSWORD,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
if (!preg_match('/\A[a-zA-Z0-9_]*\z/D', DB_PREFIX)) { throw new RuntimeException('Invalid test prefix'); }
$service = Installer::connect();
// A real write failure after other content families have been copied must roll back everything.
$pdo->prepare('CREATE TRIGGER `' . DB_PREFIX . 'test_fail` BEFORE INSERT ON `' . DB_PREFIX . 'product_description` FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'test rollback\'')->execute();
try {
    try {
        $service->install(1);
        throw new RuntimeException('Expected write failure');
    } catch (PDOException $error) {
        if ($service->language()) { throw new RuntimeException('Failed installation left a language record'); }
    }
} finally {
    $pdo->prepare('DROP TRIGGER `' . DB_PREFIX . 'test_fail`')->execute();
}
$workers = [];
for ($i = 0; $i < 2; $i++) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, $argv[1], '--worker'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process) || trim((string)fgets($pipes[1])) !== 'ready') { throw new RuntimeException('Worker failed to start'); }
    $workers[] = [$process, $pipes];
}
foreach ($workers as [$process, $pipes]) { fwrite($pipes[0], "go\n"); fclose($pipes[0]); }
$ids = [];
foreach ($workers as [$process, $pipes]) {
    $ids[] = trim(stream_get_contents($pipes[1]));
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { throw new RuntimeException('Installation worker failed: ' . $errors); }
}
if (!ctype_digit($ids[0]) || $ids[0] !== $ids[1] || (int)$ids[0] < 2) { throw new RuntimeException('Concurrent installs created different languages'); }
echo "Rollback and concurrent first installation: PASS\n";
