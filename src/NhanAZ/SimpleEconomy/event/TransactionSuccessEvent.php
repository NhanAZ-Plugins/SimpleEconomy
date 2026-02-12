<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy\event;

/**
 * Called AFTER a transaction has been successfully completed.
 *
 * This event is read-only - the transaction has already happened.
 * Use this for logging, notifications, or syncing with external systems.
 *
 * Example usage from another plugin:
 *
 *   public function onTransactionSuccess(TransactionSuccessEvent $event): void {
 *       $logger->info("{$event->playerName}: {$event->oldBalance} -> {$event->newBalance}");
 *   }
 */
class TransactionSuccessEvent extends TransactionEvent {
}
