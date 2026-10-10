<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy;

use NhanAZ\SimpleSQL\Session;

final class BalanceReader {

	public static function read(Session $session): int {
		$value = $session->get("balance", 0);
		if (is_int($value) && $value >= 0) {
			return $value;
		}
		if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
			$digits = ltrim($value, "0");
			$parsed = filter_var($digits === "" ? "0" : $digits, FILTER_VALIDATE_INT);
			if (is_int($parsed) && $parsed >= 0) {
				return $parsed;
			}
		}
		throw new InvalidBalanceException("Invalid balance data for session '" . $session->getId() . "'.");
	}
}
