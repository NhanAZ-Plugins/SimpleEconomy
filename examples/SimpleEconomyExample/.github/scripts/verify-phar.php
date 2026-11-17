<?php

declare(strict_types=1);

try {
    $path = $argv[1] ?? throw new RuntimeException('Pass the PHAR path and build metadata path.');
    $metadataPath = $argv[2] ?? throw new RuntimeException('Pass the build metadata path.');
    $metadata = json_decode(file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
    if (($metadata['schema_version'] ?? null) !== 1 || ($metadata['success'] ?? false) !== true || ($metadata['command'] ?? null) !== 'build') {
        throw new RuntimeException('Expected successful DevTools build metadata schema 1.');
    }
    $expectedHash = $metadata['data']['sha256'] ?? null;
    if (!is_string($expectedHash) || hash_file('sha256', $path) !== $expectedHash) {
        throw new RuntimeException('PHAR bytes differ from the build result.');
    }

    $phar = new Phar($path);
    $root = dirname(__DIR__, 2);
    foreach (['plugin.yml', 'LICENSE', 'src/NhanAZ/SimpleEconomyExample/Main.php', 'src/NhanAZ/SimpleEconomyExample/EventListener.php'] as $relative) {
        $source = file_get_contents($root . '/' . $relative);
        if (!is_string($source)) {
            throw new RuntimeException("Cannot read source content: {$relative}");
        }
        $built = isset($phar[$relative]) ? $phar[$relative]->getContent() : null;
        if ($source !== $built && str_replace("\r\n", "\n", $source) !== str_replace("\r\n", "\n", $built ?? '')) {
            throw new RuntimeException("Missing or changed artifact content: {$relative}");
        }
    }
    fwrite(STDOUT, "Verified PHAR hash, manifest, license and both example source files.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
