<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy\command;

use NhanAZ\SimpleEconomy\event\TransactionEvent;
use NhanAZ\SimpleEconomy\event\TransactionSubmitEvent;
use NhanAZ\SimpleEconomy\event\TransactionSuccessEvent;
use NhanAZ\SimpleEconomy\Main;
use NhanAZ\SimpleSQL\Session;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;

class SetMoneyCommand extends Command implements PluginOwned {
	use PluginOwnedTrait;

	public function __construct(
		private readonly Main $plugin,
	) {
		parent::__construct("setmoney", "Set a player's balance (OP only).", "/setmoney <player> <amount>");
		$this->setPermission("simpleeconomy.command.setmoney");
		$this->owningPlugin = $plugin;
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args): void {
		$lang = $this->plugin->getLang();

		if (count($args) < 2) {
			$sender->sendMessage($lang->get("setmoney.usage"));
			return;
		}

		$amountRaw = $args[1];
		if (!is_numeric($amountRaw)) {
			$sender->sendMessage($lang->get("general.amount-not-number"));
			return;
		}

		$amount = (int) floor((float) $amountRaw);
		if ($amount < 0) {
			$sender->sendMessage($lang->get("general.amount-not-negative"));
			return;
		}

		$input = $args[0];
		$player = $this->plugin->resolvePlayer($input);
		$targetName = $player !== null ? $player->getName() : $input;

		$this->plugin->withPlayerSession(
			$targetName,
			onSession: function (Session $session, bool $temporary) use ($sender, $targetName, $amount, $lang): void {
				$oldBalance = (int) $session->get("balance", 0);

				// Fire pre-transaction event
				$submitEvent = new TransactionSubmitEvent($targetName, $oldBalance, $amount, TransactionEvent::TYPE_SET);
				$submitEvent->call();
				if ($submitEvent->isCancelled()) {
					$sender->sendMessage($lang->get("general.transaction-cancelled"));
					if ($temporary) {
						$this->plugin->closeTempSession($targetName);
					}
					return;
				}

				$session->set("balance", $amount);
				$this->plugin->updateBalanceCache(strtolower($targetName), $amount);

				$session->save(function (bool $success) use ($sender, $targetName, $oldBalance, $amount, $temporary, $lang): void {
					if ($success) {
						// Fire post-transaction event
						(new TransactionSuccessEvent($targetName, $oldBalance, $amount, TransactionEvent::TYPE_SET))->call();

						$sender->sendMessage($lang->get("setmoney.success", [
							"player" => $targetName,
							"old" => $this->plugin->formatMoney($oldBalance),
							"new" => $this->plugin->formatMoney($amount),
						]));

						$target = $this->plugin->getServer()->getPlayerExact($targetName);
						if ($target !== null && strtolower($target->getName()) !== strtolower($sender->getName())) {
							$target->sendMessage($lang->get("setmoney.notify", [
								"amount" => $this->plugin->formatMoney($amount),
							]));
						}
					} else {
						$sender->sendMessage($lang->get("general.save-failed", ["player" => $targetName]));
					}

					if ($temporary) {
						$this->plugin->closeTempSession($targetName);
					}
				});
			},
			onError: function (string $msg) use ($sender): void {
				$sender->sendMessage($msg);
			},
		);
	}
}
