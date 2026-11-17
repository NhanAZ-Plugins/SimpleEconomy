<?php
declare(strict_types=1);

namespace NhanAZ\EcoAPIScore\util;

final class BalanceFormatter{

	public static function raw(int|float $balance): string{
		if(is_int($balance)){
			return (string) $balance;
		}

		if(!is_finite($balance)){
			return "0";
		}

		if(floor($balance) === $balance && abs($balance) <= PHP_INT_MAX){
			return (string) (int) $balance;
		}

		return rtrim(rtrim(number_format($balance, 2, ".", ""), "0"), ".");
	}

	public static function display(int|float $balance, string $currency): string{
		if(!is_finite((float) $balance)){
			return $currency . "0";
		}

		if(floor((float) $balance) === (float) $balance){
			return $currency . number_format($balance, 0, ".", ",");
		}

		$value = number_format($balance, 2, ".", ",");
		return $currency . rtrim(rtrim($value, "0"), ".");
	}
}
