<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy\command;

use NhanAZ\SimpleEconomy\event\TransactionEvent;
use NhanAZ\SimpleEconomy\event\TransactionSubmitEvent;
use NhanAZ\SimpleEconomy\event\TransactionSuccessEvent;
use NhanAZ\SimpleEconomy\Main;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;

class PayCommand extends Command implements PluginOwned {
	use PluginOwnedTrait;

	public function __construct(
		private readonly Main $plugin,
	) {
		parent::__construct("pay", "Transfer money to another player.", "/pay <player> <amount>");
		$this->setPermission("simpleeconomy.command.pay");
		$this->owningPlugin = $plugin;
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args): void {
		$lang = $this->plugin->getLang();

		if (!$sender instanceof Player) {
			$sender->sendMessage($lang->get("general.only-ingame"));
			return;
		}

		if (count($args) < 2) {
			$sender->sendMessage($lang->get("pay.usage"));
			return;
		}

		// Resolve receiver
		$input = $args[0];
		$receiver = $this->plugin->resolvePlayer($input);
		if ($receiver === null) {
			$sender->sendMessage($lang->get("general.player-not-online", ["player" => $input]));
			return;
		}
		$targetName = $receiver->getName();

		// Validate amount
		$amountRaw = $args[1];
		if (!is_numeric($amountRaw)) {
			$sender->sendMessage($lang->get("general.amount-not-number"));
			return;
		}

		$amount = (int) floor((float) $amountRaw);
		if ($amount <= 0) {
			$sender->sendMessage($lang->get("general.amount-positive"));
			return;
		}

		// Self-pay
		if (strtolower($sender->getName()) === strtolower($targetName)) {
			$sender->sendMessage($lang->get("pay.no-self"));
			return;
		}

		// Check sessions
		$senderBalance = $this->plugin->getMoney($sender->getName());
		if ($senderBalance === null) {
			$sender->sendMessage($lang->get("pay.loading-self"));
			return;
		}

		$receiverBalance = $this->plugin->getMoney($targetName);
		if ($receiverBalance === null) {
			$sender->sendMessage($lang->get("pay.loading-target", ["player" => $targetName]));
			return;
		}

		// Sufficient funds
		if ($senderBalance < $amount) {
			$sender->sendMessage($lang->get("pay.insufficient", ["balance" => $this->plugin->formatMoney($senderBalance)]));
			return;
		}

		// Fire pre-transaction event (sender side - reduce)
		$submitEvent = new TransactionSubmitEvent(
			$sender->getName(), $senderBalance, $senderBalance - $amount, TransactionEvent::TYPE_PAY
		);
		$submitEvent->call();
		if ($submitEvent->isCancelled()) {
			$sender->sendMessage($lang->get("pay.cancelled"));
			return;
		}

		// Execute transfer
		$this->plugin->setMoney($sender->getName(), $senderBalance - $amount);
		$this->plugin->setMoney($targetName, $receiverBalance + $amount);

		// Fire post-transaction events
		(new TransactionSuccessEvent(
			$sender->getName(), $senderBalance, $senderBalance - $amount, TransactionEvent::TYPE_PAY
		))->call();
		(new TransactionSuccessEvent(
			$targetName, $receiverBalance, $receiverBalance + $amount, TransactionEvent::TYPE_PAY
		))->call();

		$formatted = $this->plugin->formatMoney($amount);
		$sender->sendMessage($lang->get("pay.sent", ["amount" => $formatted, "player" => $targetName]));

		if ($receiver->isOnline()) {
			$receiver->sendMessage($lang->get("pay.received", ["amount" => $formatted, "player" => $sender->getName()]));
		}
	}
}
