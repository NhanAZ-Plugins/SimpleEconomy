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
    foreach (['src/AxolotlBucketLabs/SimpleEconomy/EconomyProviderTagsListener.php', 'src/AxolotlBucketLabs/SimpleEconomy/ProviderBalanceFormatter.php'] as $sourceFile) {
        if (!isset($phar[$sourceFile])) {
            throw new RuntimeException("Missing economy-provider source file: {$sourceFile}");
        }
    }
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
    $dependencies = [];
    foreach ($data['dependencies'] as $dependency) {
        $provenance = $dependency['provenance'] ?? null;
        $package = is_array($provenance) ? ($provenance['package'] ?? null) : null;
        if (!is_string($package)) {
            throw new RuntimeException('Every prepared Composer dependency must include package provenance.');
        }
        $dependencies[$package] = $dependency;
    }
    $expected = [
        'axolotlbucketlabs/economy-provider-api' => [
            'name' => 'axolotlbucketlabs.economy-provider-api',
            'composerVersion' => 'dev-main',
            'reference' => 'e2e1ed9344b1da1892aea025d7721a6f3196e111',
            'antigen' => 'AxolotlBucketLabs\\EconomyProviderAPI',
            'entryClass' => 'EconomyProviderAPI',
        ],
        'axolotlbucketlabs/simple-sql' => [
            'name' => 'axolotlbucketlabs.simple-sql',
            'composerVersion' => 'dev-main',
            'reference' => 'a1763696c974a1fc02632b4804be1e982a6dd982',
            'antigen' => 'AxolotlBucketLabs\\SimpleSQL',
            'entryClass' => 'SimpleSQL',
        ],
        'sof3/await-generator' => [
            'name' => 'sof3.await-generator',
            'composerVersion' => 'dev-devtools-shading-compat',
            'reference' => '150476bea0cf0ffc6c2cd7d81932ad1ae0d3dd9c',
            'antigen' => 'SOFe\\AwaitGenerator',
            'entryClass' => 'Await',
        ],
        'sof3/libasynql' => [
            'name' => 'sof3.libasynql',
            'composerVersion' => 'dev-master',
            'reference' => '270f8ae93e3271206eadd42ca81862ab9a54f322',
            'antigen' => 'poggit\\libasynql',
            'entryClass' => 'libasynql',
        ],
    ];
    if (array_diff_key($dependencies, $expected) !== [] || array_diff_key($expected, $dependencies) !== []) {
        throw new RuntimeException('Expected the locked EconomyProviderAPI, SimpleSQL, await-generator and libasynql Composer dependencies.');
    }
    foreach ($expected as $package => $expectation) {
        $dependency = $dependencies[$package];
        $provenance = $dependency['provenance'] ?? null;
        $source = is_array($provenance) ? ($provenance['source'] ?? null) : null;
        $reference = is_array($source) ? ($source['reference'] ?? null) : null;
        if (($provenance['name'] ?? null) !== $expectation['name']
            || ($provenance['composerVersion'] ?? null) !== $expectation['composerVersion']
            || $reference !== $expectation['reference']
            || ($dependency['name'] ?? null) !== $expectation['name']
            || ($dependency['version'] ?? null) === null) {
            throw new RuntimeException("Unexpected Composer provenance for {$package}.");
        }
        $assertBytes('META-INF/virions/' . $expectation['name'] . '/LICENSE', $root . '/vendor/' . $package . '/LICENSE');
        $antigen = $expectation['antigen'];
        $namespace = $data['shaded_namespaces'][$antigen] ?? null;
        if (!is_string($namespace) || $namespace === $antigen) {
            throw new RuntimeException("Missing private namespace for {$package}.");
        }
        $entry = 'src/' . str_replace('\\', '/', $namespace) . '/' . $expectation['entryClass'] . '.php';
        if (!isset($phar[$entry]) || !str_contains($phar[$entry]->getContent(), 'namespace ' . $namespace . ';')) {
            throw new RuntimeException("Missing shaded API class for {$package}: {$entry}");
        }
    }
    foreach (['sqlite.sql', 'mysql.sql'] as $file) {
        $assertBytes('resources/simplesql/' . $file, $root . '/vendor/axolotlbucketlabs/simple-sql/resources/simplesql/' . $file);
    }
    fwrite(STDOUT, "Verified PHAR hash, plugin resources, SQL resources, all four shaded virion APIs and licenses. Runtime SQL remains a separate check.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
