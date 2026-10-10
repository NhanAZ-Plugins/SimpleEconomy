<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy\command;

use NhanAZ\SimpleEconomy\BalanceAmount;
use NhanAZ\SimpleEconomy\BalanceReader;
use NhanAZ\SimpleEconomy\event\TransactionEvent;
use NhanAZ\SimpleEconomy\event\TransactionSubmitEvent;
use NhanAZ\SimpleEconomy\event\TransactionSuccessEvent;
use NhanAZ\SimpleEconomy\Main;
use NhanAZ\SimpleSQL\Session;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;

class PayCommand extends Command implements PluginOwned {
    use PluginOwnedTrait;

	public function __construct(private readonly Main $plugin) {
		parent::__construct("pay", "Transfer money to another online player.", "/pay <player> <amount>");
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
		$receiver = $this->plugin->resolvePlayer($args[0]);
		if ($receiver === null || !$receiver->isOnline()) {
			$sender->sendMessage($lang->get("general.player-not-online", ["player" => $args[0]]));
			return;
		}
		$senderName = $sender->getName();
		$targetName = $receiver->getName();
		if (strtolower($senderName) === strtolower($targetName)) {
			$sender->sendMessage($lang->get("pay.no-self"));
			return;
		}
		$amountRaw = $args[1];
		if (is_numeric($amountRaw) && str_starts_with(trim($amountRaw), "-")) {
			$sender->sendMessage($lang->get("general.amount-positive"));
			return;
		}
		$amount = BalanceAmount::parseCommand($amountRaw);
		if ($amount === null) {
			$sender->sendMessage($lang->get("general.amount-not-number"));
			return;
		}
		if ($amount <= 0) {
			$sender->sendMessage($lang->get("general.amount-positive"));
			return;
		}

		$this->plugin->withPlayerSession($senderName,
			onSession: function (Session $senderSession, bool $temporary) use ($sender, $receiver, $senderName, $targetName, $amount, $lang): void {
				if ($temporary) {
					$this->plugin->closeTempSession($senderName);
					$sender->sendMessage($lang->get("pay.loading-self"));
					return;
				}
				$senderBalance = BalanceReader::read($senderSession);
				$newSenderBalance = BalanceAmount::reduce($senderBalance, $amount);
				if ($newSenderBalance === null) {
					$sender->sendMessage($lang->get("pay.insufficient", ["balance" => $this->plugin->formatMoney($senderBalance)]));
					return;
				}
				$this->plugin->withPlayerSession($targetName,
					onSession: function (Session $targetSession, bool $targetTemporary) use ($sender, $receiver, $senderSession, $senderName, $senderBalance, $newSenderBalance, $targetName, $amount, $lang): void {
						if ($targetTemporary || !$receiver->isOnline()) {
							if ($targetTemporary) $this->plugin->closeTempSession($targetName);
							$sender->sendMessage($lang->get("pay.loading-target", ["player" => $targetName]));
							return;
						}
						$targetBalance = BalanceReader::read($targetSession);
						$newTargetBalance = BalanceAmount::add($targetBalance, $amount);
						if ($newTargetBalance === null) {
							$sender->sendMessage($lang->get("general.amount-not-number"));
							return;
						}
						$submitEvent = new TransactionSubmitEvent($senderName, $senderBalance, $newSenderBalance, TransactionEvent::TYPE_PAY);
						$submitEvent->call();
						if ($submitEvent->isCancelled()) {
							$sender->sendMessage($lang->get("pay.cancelled"));
							return;
						}
						$this->plugin->saveTransfer($senderSession, $senderName, $senderBalance, $targetSession, $targetName, $targetBalance, $amount,
							function (bool $success) use ($sender, $receiver, $senderName, $senderBalance, $newSenderBalance, $targetName, $targetBalance, $newTargetBalance, $amount, $lang): void {
								if (!$success) {
									$sender->sendMessage($lang->get("general.save-failed", ["player" => $targetName]));
									return;
								}
								(new TransactionSuccessEvent($senderName, $senderBalance, $newSenderBalance, TransactionEvent::TYPE_PAY))->call();
								(new TransactionSuccessEvent($targetName, $targetBalance, $newTargetBalance, TransactionEvent::TYPE_PAY))->call();
								$formatted = $this->plugin->formatMoney($amount);
								if ($sender->isOnline()) $sender->sendMessage($lang->get("pay.sent", ["amount" => $formatted, "player" => $targetName]));
								if ($receiver->isOnline()) $receiver->sendMessage($lang->get("pay.received", ["amount" => $formatted, "player" => $senderName]));
							});
					},
					onError: function (string $message) use ($sender): void { $sender->sendMessage($message); },
				);
			},
			onError: function (string $message) use ($sender): void { $sender->sendMessage($message); },
		);
	}
}
