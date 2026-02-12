<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy\command;

use NhanAZ\SimpleEconomy\Main;
use NhanAZ\SimpleSQL\Session;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;

class MoneyCommand extends Command implements PluginOwned {
	use PluginOwnedTrait;

	public function __construct(
		private readonly Main $plugin,
	) {
		parent::__construct("money", "View your balance or another player's balance.", "/money [player]");
		$this->setPermission("simpleeconomy.command.money");
		$this->owningPlugin = $plugin;
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args): void {
		$lang = $this->plugin->getLang();

		if (count($args) >= 1) {
			$input = $args[0];

			// Prefix match online players
			$player = $this->plugin->resolvePlayer($input);
			if ($player !== null) {
				$this->showBalance($sender, $player->getName());
				return;
			}

			// Offline - temp session
			$this->plugin->withPlayerSession(
				$input,
				onSession: function (Session $session, bool $temporary) use ($sender, $input, $lang): void {
					if (!$session->has("balance")) {
						$sender->sendMessage($lang->get("general.no-economy-data", ["player" => $input]));
					} else {
						$balance = (int) $session->get("balance", 0);
						$rank = $this->plugin->getPlayerRank($input);
						$formatted = $this->plugin->formatMoney($balance);

						if ($rank !== null) {
							$sender->sendMessage($lang->get("money.other", [
								"player" => $input,
								"balance" => $formatted,
								"rank" => (string) $rank,
							]));
						} else {
							$sender->sendMessage($lang->get("money.other-unranked", [
								"player" => $input,
								"balance" => $formatted,
							]));
						}
					}
					if ($temporary) {
						$this->plugin->closeTempSession($input);
					}
				},
				onError: function (string $msg) use ($sender): void {
					$sender->sendMessage($msg);
				},
			);
		} elseif ($sender instanceof Player) {
			$this->showBalance($sender, $sender->getName());
		} else {
			$sender->sendMessage($lang->get("money.usage"));
		}
	}

	private function showBalance(CommandSender $sender, string $targetName): void {
		$lang = $this->plugin->getLang();
		$balance = $this->plugin->getMoney($targetName);

		if ($balance === null) {
			$sender->sendMessage($lang->get("money.error", ["player" => $targetName]));
			return;
		}

		$formatted = $this->plugin->formatMoney($balance);
		$rank = $this->plugin->getPlayerRank($targetName);
		$isSelf = $sender instanceof Player && strtolower($targetName) === strtolower($sender->getName());

		if ($isSelf) {
			$key = $rank !== null ? "money.self" : "money.self-unranked";
		} else {
			$key = $rank !== null ? "money.other" : "money.other-unranked";
		}

		$params = ["player" => $targetName, "balance" => $formatted];
		if ($rank !== null) {
			$params["rank"] = (string) $rank;
		}

		$sender->sendMessage($lang->get($key, $params));
	}
}
