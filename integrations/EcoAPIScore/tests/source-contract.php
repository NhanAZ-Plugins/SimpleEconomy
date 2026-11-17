<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/plugin.yml');
$main = file_get_contents($root . '/src/NhanAZ/EcoAPIScore/Main.php');
$readme = file_get_contents($root . '/README.md');

if($plugin === false || $main === false || $readme === false){
	throw new RuntimeException('EcoAPIScore source contract files are missing.');
}

foreach([
	'name: EcoAPIScore',
	'main: NhanAZ\\EcoAPIScore\\Main',
	'depend:',
	'  - ScoreHud',
	'softdepend:',
] as $needle){
	if(!str_contains($plugin, $needle)){
		throw new RuntimeException("Missing plugin manifest entry: {$needle}");
	}
}

foreach([
	'NhanAZ\\EcoAPI\\EcoAPI',
	'Ifera\\ScoreHud\\event\\TagsResolveEvent',
	'ecoapi.balance',
	'ecoapi.formatted',
	'No EcoAPI economy provider is available',
] as $needle){
	if(!str_contains($main, $needle)){
		throw new RuntimeException("Missing source contract entry: {$needle}");
	}
}

foreach(['ecoapi.balance', 'ecoapi.formatted', 'SimpleEconomy', 'ScoreHud'] as $needle){
	if(!str_contains($readme, $needle)){
		throw new RuntimeException("Missing README contract entry: {$needle}");
	}
}

fwrite(STDOUT, "EcoAPIScore source contract passed.\n");
