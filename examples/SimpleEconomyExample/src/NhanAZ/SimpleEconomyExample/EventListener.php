<?php

/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║                   Event Listener Examples                      ║
 * ║                                                                ║
 * ║  SimpleEconomy fires events when money changes. You can        ║
 * ║  listen to these events to react to transactions.              ║
 * ║                                                                ║
 * ║  There are 2 types of events:                                  ║
 * ║                                                                ║
 * ║  1. TransactionSubmitEvent (BEFORE transaction)                ║
 * ║     → You can CANCEL it to prevent the transaction             ║
 * ║                                                                ║
 * ║  2. TransactionSuccessEvent (AFTER transaction)                ║
 * ║     → Transaction already happened, you can only read data     ║
 * ╚══════════════════════════════════════════════════════════════════╝
 */

declare(strict_types=1);

namespace NhanAZ\SimpleEconomyExample;

use NhanAZ\SimpleEconomy\event\TransactionEvent;
use NhanAZ\SimpleEconomy\event\TransactionSubmitEvent;
use NhanAZ\SimpleEconomy\event\TransactionSuccessEvent;
use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

class EventListener implements Listener {

	public function __construct(
		private PluginBase $plugin,
	) {}

	// ══════════════════════════════════════════════════
	//  BASIC: Log all transactions
	// ══════════════════════════════════════════════════

	/**
	 * ┌─────────────────────────────────────────────────────────┐
	 * │  Example 1: Listen to TransactionSuccessEvent           │
	 * │  This fires AFTER a transaction is completed.           │
	 * └─────────────────────────────────────────────────────────┘
	 *
	 * Event properties you can read:
	 *   $event->playerName  → Name of the player (string)
	 *   $event->oldBalance  → Balance BEFORE the transaction (int)
	 *   $event->newBalance  → Balance AFTER the transaction (int)
	 *   $event->type        → Type: "set", "add", "reduce", or "pay" (string)
	 *   $event->getAmount() → The difference between old and new balance (int)
	 *
	 */
	public function onTransactionSuccess(TransactionSuccessEvent $event): void {
		// Log every successful transaction to the console
		$this->plugin->getLogger()->info(
			"[Transaction] " .
			$event->playerName .
			": " . $event->oldBalance .
			" → " . $event->newBalance .
			" (type: " . $event->type .
			", amount: " . $event->getAmount() . ")"
		);
	}

	// ══════════════════════════════════════════════════
	//  BASIC: Block transactions based on conditions
	// ══════════════════════════════════════════════════

	/**
	 * ┌─────────────────────────────────────────────────────────┐
	 * │  Example 2: Listen to TransactionSubmitEvent            │
	 * │  This fires BEFORE a transaction is executed.           │
	 * │  You can call $event->cancel() to block it!            │
	 * └─────────────────────────────────────────────────────────┘
	 *
	 * Same properties as TransactionSuccessEvent, plus:
	 *   $event->cancel()      → Cancel the transaction (it won't happen)
	 *   $event->isCancelled() → Check if already cancelled by another plugin
	 *
	 * Practical example: Block payments larger than 100,000
	 */
	public function onTransactionSubmit(TransactionSubmitEvent $event): void {
		// Only check "pay" type transactions (player-to-player transfers)
		if ($event->type !== TransactionEvent::TYPE_PAY) {
			return;
		}

		// Block payments larger than 100,000
		$maxPayment = 100000;
		if ($event->getAmount() > $maxPayment) {
			$event->cancel();

			// Notify the player
			$player = $this->plugin->getServer()->getPlayerExact($event->playerName);
			if ($player !== null) {
				$player->sendMessage(
					TextFormat::RED . "Payment blocked! Maximum payment is " . number_format($maxPayment) . "."
				);
			}
		}
	}

}
