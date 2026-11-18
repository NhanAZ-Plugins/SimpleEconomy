<?php

/**
 * Demonstrates the SimpleEconomy API through plugin commands.
 */

declare(strict_types=1);

namespace AxolotlBucketLabs\SimpleEconomyExample;

use Closure;
use AxolotlBucketLabs\SimpleEconomy\Main as SimpleEconomy;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

class Main extends PluginBase {

	/**
	 * The SimpleEconomy plugin instance.
	 * We store it here so we don't have to call getInstance() every time.
	 */
	private SimpleEconomy $economy;

	protected function onEnable(): void {
		$economy = SimpleEconomy::getInstance();

		// Always check if SimpleEconomy is loaded
		if ($economy === null) {
			$this->getLogger()->error("SimpleEconomy is not loaded! Disabling...");
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}

		$this->economy = $economy;

		$this->getServer()->getPluginManager()->registerEvents(
			new EventListener($this),
			$this,
		);

		$this->getLogger()->info("SimpleEconomyExample enabled! Try /wallet, /reward, /fine, /richest");
	}

	/**
	 * Handle all commands in one place for simplicity.
	 * @param list<string> $args
	 */
	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		switch ($command->getName()) {
			case "wallet":
				return $this->handleWallet($sender);

			case "reward":
				return $this->handleReward($sender, $args);

			case "fine":
				return $this->handleFine($sender, $args);

			case "richest":
				return $this->handleRichest($sender);

			default:
				return false;
		}
	}

	/**
	 * Displays a player's balance using getMoney() and formatMoney().
	 */
	private function handleWallet(CommandSender $sender): bool {
		// This command can only be used by players (not console)
		if (!$sender instanceof Player) {
			$sender->sendMessage("This command can only be used in-game.");
			return true;
		}

		// getMoney() returns the player's balance, or null if offline/not found
		$balance = $this->economy->getMoney($sender->getName());

		if ($balance === null) {
			$sender->sendMessage(TextFormat::RED . "Could not load your balance. Try again later.");
			return true;
		}

		// formatMoney() formats the number with the server's currency symbol
		// Example: 1500000 → "$1,500,000" or "$1.5M" (depends on server config)
		$formatted = $this->economy->formatMoney($balance);

		$sender->sendMessage(TextFormat::GREEN . "Your balance: " . TextFormat::WHITE . $formatted);
		return true;
	}

	/**
	 * Gives money to a player and reports whether the SQL save succeeded.
	 *
	 * The callback receives true after SQL save and readback. It receives false if the player
	 * is offline, another plugin cancels the transaction, or the database rejects the save.
	 * @param list<string> $args
	 */
	private function handleReward(CommandSender $sender, array $args): bool {
		// Check usage: /reward <player> <amount>
		if (count($args) < 2) {
			$sender->sendMessage(TextFormat::YELLOW . "Usage: /reward <player> <amount>");
			return true;
		}

		$playerName = $args[0];
		$amount = filter_var($args[1], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);

		// Validate the amount
		if ($amount === false) {
			$sender->sendMessage(TextFormat::RED . "Amount must be greater than 0.");
			return true;
		}

		$this->economy->addMoneyConfirmed($playerName, $amount, function (bool $saved) use ($sender, $amount, $playerName): void {
			if ($saved) {
				$formatted = $this->economy->formatMoney($amount);
				$sender->sendMessage(TextFormat::GREEN . "Reward of " . $formatted . " for " . $playerName . " saved to SQL.");
			} else {
				$sender->sendMessage(TextFormat::RED . "Reward failed. The player may be offline, the transaction was cancelled, or the SQL save failed.");
			}
		});

		return true;
	}

	/**
	 * Takes money from a player and reports whether the SQL save succeeded.
	 *
	 * The callback receives true after SQL save and readback. It receives false if the player
	 * is offline, has insufficient funds, another plugin cancels the transaction, or the
	 * database rejects the save.
	 * @param list<string> $args
	 */
	private function handleFine(CommandSender $sender, array $args): bool {
		// Check usage: /fine <player> <amount>
		if (count($args) < 2) {
			$sender->sendMessage(TextFormat::YELLOW . "Usage: /fine <player> <amount>");
			return true;
		}

		$playerName = $args[0];
		$amount = filter_var($args[1], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);

		if ($amount === false) {
			$sender->sendMessage(TextFormat::RED . "Amount must be greater than 0.");
			return true;
		}

		$this->economy->reduceMoneyConfirmed($playerName, $amount, function (bool $saved) use ($sender, $amount, $playerName): void {
			if ($saved) {
				$formatted = $this->economy->formatMoney($amount);
				$sender->sendMessage(TextFormat::GREEN . "Fine of " . $formatted . " for " . $playerName . " saved to SQL.");
			} else {
				$sender->sendMessage(TextFormat::RED . "Fine failed. The player may be offline, lack funds, the transaction was cancelled, or the SQL save failed.");
			}
		});

		return true;
	}

	/**
	 * Displays the five highest balances.
	 */
	private function handleRichest(CommandSender $sender): bool {
		// Get top 5 players (you can change the limit)
		$topPlayers = $this->economy->getTopBalances(limit: 5, offset: 0);

		if (count($topPlayers) === 0) {
			$sender->sendMessage(TextFormat::YELLOW . "No players found on the leaderboard.");
			return true;
		}

		$sender->sendMessage(TextFormat::GOLD . "=== Top 5 Richest Players ===");

		$rank = 1;
		foreach ($topPlayers as $entry) {
			$name = $entry["name"];
			$balance = $this->economy->formatMoney($entry["balance"]);

			$sender->sendMessage(
				TextFormat::YELLOW . "#" . $rank . " " .
				TextFormat::WHITE . $name . ": " .
				TextFormat::GREEN . $balance
			);
			$rank++;
		}

		return true;
	}
}
