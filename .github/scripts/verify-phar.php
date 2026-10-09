<?php

declare(strict_types=1);

try {
    $path = $argv[1] ?? throw new RuntimeException('Pass the PHAR path and build metadata path.');
    $metadata = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    if (($metadata['schema_version'] ?? null) !== 1 || ($metadata['success'] ?? false) !== true || ($metadata['command'] ?? null) !== 'build') {
        throw new RuntimeException('Expected successful DevTools build metadata schema 1.');
    }
    $data = $metadata['data'];
    if (hash_file('sha256', $path) !== $data['sha256']) {
        throw new RuntimeException('PHAR bytes differ from the build result.');
    }
    $phar = new Phar($path);
    $root = dirname(__DIR__, 2);
    $assertBytes = static function (string $archivePath, string $sourcePath) use ($phar): void {
        $source = file_get_contents($sourcePath);
        $actual = isset($phar[$archivePath]) ? $phar[$archivePath]->getContent() : null;
        $text = basename($archivePath) === 'LICENSE' || in_array(strtolower(pathinfo($archivePath, PATHINFO_EXTENSION)), ['yml', 'yaml', 'sql'], true);
        if ($text && $actual !== null) {
            $source = str_replace("\r\n", "\n", $source);
            $actual = str_replace("\r\n", "\n", $actual);
        }
        if ($actual !== $source) {
            throw new RuntimeException("Missing or changed artifact content: {$archivePath}");
        }
    };
    foreach (['plugin.yml', 'LICENSE'] as $file) {
        $assertBytes($file, $root . '/' . $file);
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/resources', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $assertBytes($relative, $file->getPathname());
        }
    }
    $dependencies = array_column($data['dependencies'], 'version', 'name');
    if (($dependencies['SimpleSQL'] ?? null) !== '1.0.0' || ($dependencies['libasynql'] ?? null) !== '4.2.3' || count($dependencies) !== 2) {
        throw new RuntimeException('Expected the declared SimpleSQL and libasynql versions.');
    }
    foreach (['SimpleSQL' => 'NhanAZ\\SimpleSQL', 'libasynql' => 'poggit\\libasynql'] as $name => $antigen) {
        $assertBytes('META-INF/virions/' . $name . '/LICENSE', $root . '/virions/' . $name . '/LICENSE');
        $namespace = $data['shaded_namespaces'][$antigen] ?? null;
        if (!is_string($namespace) || $namespace === $antigen) {
            throw new RuntimeException("Missing private namespace for {$name}.");
        }
        $entry = 'src/' . str_replace('\\', '/', $namespace) . '/' . $name . '.php';
        if (!isset($phar[$entry]) || !str_contains($phar[$entry]->getContent(), 'namespace ' . $namespace . ';')) {
            throw new RuntimeException("Missing shaded API class: {$entry}");
        }
    }
    foreach (['sqlite.sql', 'mysql.sql'] as $file) {
        $assertBytes('resources/devtools-virions/SimpleSQL/simplesql/' . $file, $root . '/virions/SimpleSQL/resources/simplesql/' . $file);
    }
    fwrite(STDOUT, "Verified PHAR hash, plugin resources, SQL resources, both private virion APIs and licenses. Runtime SQL remains a separate check.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
