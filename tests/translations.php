<?php
declare(strict_types=1);

function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function strings(string $path): array {
    $_ = [];
    require $path;
    return $_;
}
function placeholders(string $value): array {
    preg_match_all('/%(?:[0-9]+\$)?[-+0 #]*(?:[0-9]+|\*)?(?:\.(?:[0-9]+|\*))?[bcdeEfFgGosuxX%]/', $value, $matches);
    return $matches[0];
}

$upstream = rtrim($argv[1] ?? '', '/') . '/upload/';
$root = dirname(__DIR__) . '/package/';
$files = [];
$entries = 0;
$allowedTokens = [T_OPEN_TAG, T_VARIABLE, T_CONSTANT_ENCAPSED_STRING, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
foreach (['admin', 'catalog', 'extension/opencart/admin', 'extension/opencart/catalog'] as $scope) {
    $directory = $upstream . $scope . '/language/en-gb';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $source) {
        if ($source->getExtension() !== 'php') { continue; }
        $relative = substr($source->getPathname(), strlen($directory) + 1);
        if (str_starts_with($scope, 'extension/opencart/')) {
            $target = $root . substr($scope, strlen('extension/opencart/')) . '/language/es-pe/extension/opencart/' . $relative;
        } else {
            $target = $root . $scope . '/language/es-pe/' . $relative;
        }
        check(is_file($target), 'Missing translation: ' . $target);
        $english = strings($source->getPathname());
        $spanish = strings($target);
        $missing = array_diff_key($english, $spanish);
        check(!$missing, 'Missing keys in ' . $target . ': ' . implode(', ', array_keys($missing)));
        foreach ($english as $key => $value) {
            check(is_string($spanish[$key]), 'Non-string translation: ' . $target . '/' . $key);
            check(placeholders($value) === placeholders($spanish[$key]), 'Placeholder mismatch: ' . $target . '/' . $key);
            check(preg_match('//u', $spanish[$key]) === 1, 'Invalid UTF-8: ' . $target . '/' . $key);
            check(!preg_match('/<script\b|javascript:|\bon(?:click|load|error)\s*=/i', $spanish[$key]), 'Unsafe markup: ' . $target . '/' . $key);
        }
        // Translation files contain literal data. Only the standard footer's date('Y') call is allowed.
        foreach (token_get_all(file_get_contents($target)) as $token) {
            if (!is_array($token)) { check(str_contains("[]=;.()", $token), 'Unexpected PHP syntax in ' . $target); continue; }
            if ($token[0] === T_VARIABLE) { check($token[1] === '$_', 'Unexpected variable in ' . $target); }
            if (!in_array($token[0], $allowedTokens, true)) {
                check($relative === 'common/footer.php' && $token[0] === T_STRING && $token[1] === 'date', 'Executable code in translation: ' . $target);
            }
        }
        $files[] = ['path' => substr($target, strlen($root)), 'keys' => count($english)];
        $entries += count($english);
    }
}
foreach (['admin', 'catalog'] as $scope) {
    $data = strings($root . $scope . '/language/es-pe/default.php');
    check($data['code'] === 'es-pe', 'Locale identifier');
    check($data['decimal_point'] === '.' && $data['thousand_point'] === ',', 'Peru number separators');
    check($data['date_format_short'] === 'd/m/Y', 'Peru date format');
    check(is_file($root . $scope . '/language/es-pe/es-pe.png'), 'Peru flag');
}
$ownEnglish = strings($root . 'admin/language/en-gb/language/yuei_spanish_pe.php');
$ownSpanish = strings($root . 'admin/language/es-pe/language/yuei_spanish_pe.php');
check(array_keys($ownEnglish) === array_keys($ownSpanish), 'Extension UI key parity');
foreach ($ownEnglish as $key => $value) { check(placeholders($value) === placeholders($ownSpanish[$key]), 'Extension placeholder: ' . $key); }
if (!is_dir(dirname(__DIR__) . '/dist')) { mkdir(dirname(__DIR__) . '/dist'); }
file_put_contents(dirname(__DIR__) . '/dist/coverage.json', json_encode(['opencart' => '4.1.0.4', 'locale' => 'es-pe', 'files' => count($files), 'keys' => $entries, 'missing_keys' => 0, 'placeholder_errors' => 0, 'detail' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo count($files) . " reference files, $entries keys; coverage, placeholders, UTF-8, PHP data and regional formats: PASS\n";
