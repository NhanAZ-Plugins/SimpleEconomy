<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy;

/**
 * Formats monetary amounts with configurable styles.
 *
 * Supported formatters:
 *   - "default":  $1,000,000
 *   - "compact":  $1.5K, $2.3M, $1B, $4.2T
 */
class CurrencyFormatter {

	public const DEFAULT = "default";
	public const COMPACT = "compact";

	public function __construct(
		private readonly string $symbol,
		private readonly string $mode,
	) {}

	/**
	 * Format an amount using the configured style.
	 */
	public function format(int|float $amount): string {
		return match ($this->mode) {
			self::COMPACT => $this->compact((float) $amount),
			default => $this->commadot((float) $amount),
		};
	}

	/**
	 * Default format: $1,000,000
	 */
	private function commadot(float $amount): string {
		return $this->symbol . number_format($amount, 0, ".", ",");
	}

	/**
	 * Compact format: $1.5K, $2.3M, $1B, $4.2T
	 */
	private function compact(float $amount): string {
		$str = match (true) {
			$amount >= 1_000_000_000_000 => round($amount / 1_000_000_000_000, 1) . "T",
			$amount >= 1_000_000_000 => round($amount / 1_000_000_000, 1) . "B",
			$amount >= 1_000_000 => round($amount / 1_000_000, 1) . "M",
			$amount >= 1_000 => round($amount / 1_000, 1) . "K",
			default => number_format($amount, 0, ".", ","),
		};
		return $this->symbol . $str;
	}

	public function getSymbol(): string {
		return $this->symbol;
	}

	public function getMode(): string {
		return $this->mode;
	}
}
