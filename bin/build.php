#!/usr/bin/env php
<?php

declare(strict_types=1);

use Chiptune\Common\BuildError;
use Chiptune\Site\Builder;

$root = dirname(__DIR__);

if (!is_file($root . '/opt/packagist/autoload.php')) {
    fwrite(STDERR, "opt/packagist/ fehlt. Bitte zuerst „composer install“ ausführen.\n");
    exit(1);
}

require $root . '/opt/packagist/autoload.php';

$options = array_slice($argv, 1);
$known = ['--local-only', '--serve'];

foreach ($options as $option) {
    if (!in_array($option, $known, true)) {
        fwrite(STDERR, "Unbekannte Option: {$option}\n");
        exit(2);
    }
}

try {
    $code = new Builder($root, $root . '/tmp/public')->build(!in_array('--local-only', $options, true));
} catch (BuildError $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

if ($code !== 0 || !in_array('--serve', $options, true)) {
    exit($code);
}

echo "Vorschau auf http://localhost:8000 (Ctrl+C zum Beenden)\n";
passthru(escapeshellarg(PHP_BINARY) . ' -S localhost:8000 -t ' . escapeshellarg($root . '/tmp/public'));
