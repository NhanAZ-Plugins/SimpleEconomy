<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/NhanAZ/EcoAPIScore/util/BalanceFormatter.php';

use NhanAZ\EcoAPIScore\util\BalanceFormatter;

$cases = [
	[1000, "1000", "$1,000"],
	[42.25, "42.25", "$42.25"],
	[1000.50, "1000.5", "€1,000.5"],
];

foreach($cases as [$balance, $raw, $display]){
	$currency = str_starts_with($display, "€") ? "€" : "$";
	if(BalanceFormatter::raw($balance) !== $raw || BalanceFormatter::display($balance, $currency) !== $display){
		throw new RuntimeException("Unexpected formatting for " . (string) $balance);
	}
}

if(BalanceFormatter::display(INF, "$") !== "$0"){
	throw new RuntimeException("Non-finite balances must be safe.");
}

fwrite(STDOUT, "EcoAPIScore formatting tests passed.\n");
