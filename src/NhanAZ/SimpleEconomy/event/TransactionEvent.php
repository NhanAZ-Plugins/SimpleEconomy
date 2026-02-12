<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy\event;

use pocketmine\event\Event;

/**
 * Base event for all economy transactions.
 *
 * Provides read-only access to transaction details.
 * Listen to subclasses (TransactionSubmitEvent, TransactionSuccessEvent) instead.
 */
abstract class TransactionEvent extends Event {

	public const TYPE_SET = "set";
	public const TYPE_ADD = "add";
	public const TYPE_REDUCE = "reduce";
	public const TYPE_PAY = "pay";

	public function __construct(
		public readonly string $playerName,
		public readonly int $oldBalance,
		public readonly int $newBalance,
		public readonly string $type,
	) {}

	/**
	 * Get the difference between old and new balance.
	 */
	public function getAmount(): int {
		return abs($this->newBalance - $this->oldBalance);
	}
}
