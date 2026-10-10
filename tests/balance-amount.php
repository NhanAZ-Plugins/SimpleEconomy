<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/NhanAZ/SimpleEconomy/BalanceAmount.php';

use NhanAZ\SimpleEconomy\BalanceAmount;

$inputs = [
	['1.9', 1],
	['0.9', 0],
	['  +001.50  ', 1],
	[(string) PHP_INT_MAX, PHP_INT_MAX],
	[(string) PHP_INT_MAX . '0', null],
	['1e309', null],
	['-1', null],
	['NaN', null],
];

foreach ($inputs as [$input, $expected]) {
	if (BalanceAmount::parseCommand($input) !== $expected) {
		throw new RuntimeException('Unexpected parsed amount for ' . var_export($input, true));
	}
}

$operations = [
	['add', 10, 5, 15],
	['add', PHP_INT_MAX, 1, null],
	['add', 10, -1, null],
	['reduce', 10, 5, 5],
	['reduce', 10, 11, null],
	['reduce', 10, -1, null],
];

foreach ($operations as [$method, $balance, $amount, $expected]) {
	if (BalanceAmount::$method($balance, $amount) !== $expected) {
		throw new RuntimeException("Unexpected $method result for $balance and $amount");
	}
}

echo 'PASS: ' . count($inputs) . ' input and ' . count($operations) . " arithmetic boundaries\n";
