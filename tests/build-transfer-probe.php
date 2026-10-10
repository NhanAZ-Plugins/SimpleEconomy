<?php

declare(strict_types=1);

$output = $argv[1] ?? throw new RuntimeException("Pass the transfer probe PHAR output path");
$source = __DIR__ . "/transfer-probe";
$phar = new Phar($output);
$phar->startBuffering();
$phar->addFile($source . "/plugin.yml", "plugin.yml");
$phar->addFile($source . "/src/NhanAZ/TransferProbe/Main.php", "src/NhanAZ/TransferProbe/Main.php");
$phar->setStub("<?php __HALT_COMPILER();");
$phar->stopBuffering();
if (!isset($phar["plugin.yml"], $phar["src/NhanAZ/TransferProbe/Main.php"])) {
	throw new RuntimeException("Transfer probe PHAR is incomplete");
}
echo "Built isolated transfer probe: " . hash_file("sha256", $output) . "\n";
