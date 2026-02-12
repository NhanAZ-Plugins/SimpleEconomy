<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy\command;

use NhanAZ\SimpleEconomy\Main;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;

class TopMoneyCommand extends Command implements PluginOwned {
	use PluginOwnedTrait;

	public function __construct(
		private readonly Main $plugin,
	) {
		parent::__construct("topmoney", "View the richest players leaderboard.", "/topmoney [page]");
		$this->setPermission("simpleeconomy.command.topmoney");
		$this->owningPlugin = $plugin;
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args): void {
		$lang = $this->plugin->getLang();
		$perPage = $this->plugin->getTopmoneyPerPage();

		$page = 1;
		if (count($args) >= 1 && is_numeric($args[0])) {
			$page = max(1, (int) $args[0]);
		}

		$total = $this->plugin->getBalanceCacheCount();
		if ($total === 0) {
			$sender->sendMessage($lang->get("topmoney.empty"));
			return;
		}

		$maxPage = max(1, (int) ceil($total / $perPage));
		$page = min($page, $maxPage);
		$offset = ($page - 1) * $perPage;

		$top = $this->plugin->getTopBalances($perPage, $offset);

		$sender->sendMessage($lang->get("topmoney.header", ["page" => (string) $page, "max" => (string) $maxPage]));

		foreach ($top as $i => $entry) {
			$rank = $offset + $i + 1;
			$name = $entry["name"];
			$balance = $this->plugin->formatMoney($entry["balance"]);
			$highlight = strtolower($name) === strtolower($sender->getName()) ? "§a" : "§f";

			$sender->sendMessage($lang->get("topmoney.entry", [
				"rank" => (string) $rank,
				"highlight" => $highlight,
				"player" => $name,
				"balance" => $balance,
			]));
		}

		$sender->sendMessage($lang->get("topmoney.footer"));
	}
}
