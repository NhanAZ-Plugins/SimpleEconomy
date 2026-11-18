<?php

/**
 * Demonstrates listening to transactions and cancelling them before execution.
 */

declare(strict_types=1);

namespace AxolotlBucketLabs\SimpleEconomyExample;

use AxolotlBucketLabs\SimpleEconomy\event\TransactionEvent;
use AxolotlBucketLabs\SimpleEconomy\event\TransactionSubmitEvent;
use AxolotlBucketLabs\SimpleEconomy\event\TransactionSuccessEvent;
use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

class EventListener implements Listener {

	public function __construct(
		private PluginBase $plugin,
	) {}

	/**
	 * Logs completed transactions with the player name, balance changes, type, and amount.
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

	/**
	 * Cancels player payments above 100,000 before they are executed.
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
