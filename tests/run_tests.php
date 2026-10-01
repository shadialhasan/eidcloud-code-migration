<?php

declare(strict_types=1);

// Zero-dependency pure PHP test runner
spl_autoload_register(function ($class) {
    $prefix = 'EidCloud\\CodeMigration\\';
    $baseDir = __DIR__ . '/../src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    if (str_starts_with($relativeClass, 'Tests\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($relativeClass, 6)) . '.php';
    } else {
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    }

    if (file_exists($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/CodeMigrationTest.php';

use EidCloud\CodeMigration\Tests\CodeMigrationTest;

echo "\033[1;36m==================================================\033[0m\n";
echo "\033[1;36m EidCloud Code Migration - Pure PHP Test Runner\033[0m\n";
echo "\033[1;36m==================================================\033[0m\n";

$suite = new CodeMigrationTest();
$success = $suite->run();

exit($success ? 0 : 1);
