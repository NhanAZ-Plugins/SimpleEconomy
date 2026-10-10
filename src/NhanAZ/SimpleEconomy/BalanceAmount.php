<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy;

final class BalanceAmount {

	public static function parseCommand(string $raw): ?int {
		if (preg_match('/\A\+?([0-9]+)(?:\.[0-9]+)?\z/', trim($raw), $matches) !== 1) {
			return null;
		}
		$digits = ltrim($matches[1], '0');
		$value = filter_var($digits === '' ? '0' : $digits, FILTER_VALIDATE_INT);
		return is_int($value) && $value >= 0 ? $value : null;
	}

	public static function add(int $balance, int $amount): ?int {
		if ($balance < 0 || $amount < 0 || $amount > PHP_INT_MAX - $balance) {
			return null;
		}
		return $balance + $amount;
	}

	public static function reduce(int $balance, int $amount): ?int {
		if ($balance < 0 || $amount < 0 || $amount > $balance) {
			return null;
		}
		return $balance - $amount;
	}
}
