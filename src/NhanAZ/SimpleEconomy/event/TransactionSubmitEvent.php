<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy\event;

use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;

/**
 * Called BEFORE a transaction is executed.
 *
 * Other plugins can listen to this event and cancel it to prevent the transaction.
 *
 * Example usage from another plugin:
 *
 *   public function onTransaction(TransactionSubmitEvent $event): void {
 *       if ($event->type === TransactionEvent::TYPE_PAY && $event->getAmount() > 10000) {
 *           $event->cancel();
 *       }
 *   }
 */
class TransactionSubmitEvent extends TransactionEvent implements Cancellable {
	use CancellableTrait;
}
