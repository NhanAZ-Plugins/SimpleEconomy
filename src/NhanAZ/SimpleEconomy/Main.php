<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy;

use Closure;
use NhanAZ\SimpleEconomy\command\AddMoneyCommand;
use NhanAZ\SimpleEconomy\command\MoneyCommand;
use NhanAZ\SimpleEconomy\command\PayCommand;
use NhanAZ\SimpleEconomy\command\ReduceMoneyCommand;
use NhanAZ\SimpleEconomy\command\SetMoneyCommand;
use NhanAZ\SimpleEconomy\command\TopMoneyCommand;
use NhanAZ\SimpleEconomy\event\TransactionEvent;
use NhanAZ\SimpleEconomy\event\TransactionSubmitEvent;
use NhanAZ\SimpleEconomy\event\TransactionSuccessEvent;
use NhanAZ\SimpleSQL\Session;
use NhanAZ\SimpleSQL\SimpleSQL;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;

/**
 * SimpleEconomy - A production-ready economy plugin powered by SimpleSQL.
 *
 * Features:
 *   - Simple API for other plugins (getMoney, setMoney, addMoney, reduceMoney)
 *   - Async API for offline player data (getMoneyAsync)
 *   - Transaction events for third-party plugin integration
 *   - Leaderboard with async cache rebuild (S3 compliant)
 *   - Configurable currency formatter (default / compact)
 *   - Name prefix matching for commands
 *   - Offline player support via temporary sessions
 *   - Multi-language support (eng / vie)
 */
class Main extends PluginBase implements Listener {

	private const CONFIG_VERSION = 1;

	private static ?self $instance = null;

	private SimpleSQL $simpleSQL;
	private LangManager $lang;
	private CurrencyFormatter $formatter;
	private int $defaultBalance;
	private int $topmoneyPerPage;
	private int $leaderboardSize;

	/** @var array<string, int> lowercased name => balance, sorted desc, bounded by leaderboardSize */
	private array $balanceCache = [];

	// ──────────────────────────────────────────────
	//  Static accessor
	// ──────────────────────────────────────────────

	/**
	 * Get the SimpleEconomy plugin instance.
	 *
	 * Usage from another plugin:
	 *   $eco = SimpleEconomy::getInstance();
	 *   $balance = $eco?->getMoney("Steve");
	 */
	public static function getInstance(): ?self {
		return self::$instance;
	}

	// ──────────────────────────────────────────────
	//  Plugin lifecycle
	// ──────────────────────────────────────────────

	protected function onEnable(): void {
		self::$instance = $this;

		$this->saveDefaultConfig();

		// Config version check
		$configVersion = (int) $this->getConfig()->get("config-version", 0);
		if ($configVersion < self::CONFIG_VERSION) {
			$this->getLogger()->warning("Your config.yml is outdated (v$configVersion, latest: v" . self::CONFIG_VERSION . "). Please regenerate it.");
		}

		$this->defaultBalance = (int) $this->getConfig()->get("default-balance", 1000);
		$this->topmoneyPerPage = (int) $this->getConfig()->get("topmoney-per-page", 10);
		$this->leaderboardSize = (int) $this->getConfig()->get("leaderboard-size", 100);

		// Currency
		$currencyConfig = $this->getConfig()->get("currency", []);
		$symbol = (string) ($currencyConfig["symbol"] ?? "$");
		$formatterMode = (string) ($currencyConfig["formatter"] ?? CurrencyFormatter::DEFAULT);
		$this->formatter = new CurrencyFormatter($symbol, $formatterMode);

		// Language
		$language = (string) $this->getConfig()->get("language", "eng");
		$this->lang = new LangManager($this, $language);

		// SimpleSQL
		$this->simpleSQL = SimpleSQL::create(
			plugin: $this,
			dbConfig: $this->getConfig()->get("database"),
		);

		// Rebuild leaderboard cache asynchronously (S3 compliant)
		$this->getServer()->getAsyncPool()->submitTask(
			new LeaderboardTask($this->simpleSQL->getYamlDataPath(), $this->leaderboardSize)
		);

		// Events
		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		// ScoreHud integration (softdepend - only if ScoreHud is installed)
		if ($this->getServer()->getPluginManager()->getPlugin("ScoreHud") !== null) {
			$this->getServer()->getPluginManager()->registerEvents(new ScoreHudListener($this), $this);
		}

		// Commands - fallback prefix = plugin name (C2a)
		$map = $this->getServer()->getCommandMap();
		$map->register($this->getName(), new MoneyCommand($this));
		$map->register($this->getName(), new PayCommand($this));
		$map->register($this->getName(), new SetMoneyCommand($this));
		$map->register($this->getName(), new AddMoneyCommand($this));
		$map->register($this->getName(), new ReduceMoneyCommand($this));
		$map->register($this->getName(), new TopMoneyCommand($this));
	}

	protected function onDisable(): void {
		self::$instance = null;
		if (isset($this->simpleSQL)) {
			$this->simpleSQL->close();
		}
	}

	// ──────────────────────────────────────────────
	//  Event handlers
	// ──────────────────────────────────────────────

	public function onJoin(PlayerJoinEvent $event): void {
		$player = $event->getPlayer();
		$name = strtolower($player->getName());

		$this->simpleSQL->openSession($name, function (Session $session) use ($name): void {
			if (!$session->has("balance")) {
				$session->set("balance", $this->defaultBalance);
				$session->save();
			}

			$balance = (int) $session->get("balance", 0);
			$this->updateBalanceCache($name, $balance);
		});
	}

	public function onQuit(PlayerQuitEvent $event): void {
		$name = strtolower($event->getPlayer()->getName());
		$this->simpleSQL->closeSession($name);
	}

	// ──────────────────────────────────────────────
	//  Public API - Synchronous (online players only)
	// ──────────────────────────────────────────────

	/**
	 * Get a player's balance.
	 * Returns null if the player is offline or session is not loaded.
	 */
	public function getMoney(string $name): ?int {
		$session = $this->simpleSQL->getSession(strtolower($name));
		if ($session === null) {
			return null;
		}
		return (int) $session->get("balance", 0);
	}

	/**
	 * Set a player's balance.
	 * Returns false if the player is offline or the transaction was cancelled by another plugin.
	 */
	public function setMoney(string $name, int $amount): bool {
		$lower = strtolower($name);
		$session = $this->simpleSQL->getSession($lower);
		if ($session === null) {
			return false;
		}

		$oldBalance = (int) $session->get("balance", 0);

		// Fire pre-transaction event
		$submitEvent = new TransactionSubmitEvent($name, $oldBalance, $amount, TransactionEvent::TYPE_SET);
		$submitEvent->call();
		if ($submitEvent->isCancelled()) {
			return false;
		}

		$session->set("balance", $amount);
		$session->save();
		$this->updateBalanceCache($lower, $amount);

		// Fire post-transaction event
		(new TransactionSuccessEvent($name, $oldBalance, $amount, TransactionEvent::TYPE_SET))->call();

		return true;
	}

	/**
	 * Add money to a player's balance.
	 * Returns false if the player is offline or the transaction was cancelled.
	 */
	public function addMoney(string $name, int $amount): bool {
		$lower = strtolower($name);
		$session = $this->simpleSQL->getSession($lower);
		if ($session === null) {
			return false;
		}

		$oldBalance = (int) $session->get("balance", 0);
		$newBalance = $oldBalance + $amount;

		$submitEvent = new TransactionSubmitEvent($name, $oldBalance, $newBalance, TransactionEvent::TYPE_ADD);
		$submitEvent->call();
		if ($submitEvent->isCancelled()) {
			return false;
		}

		$session->set("balance", $newBalance);
		$session->save();
		$this->updateBalanceCache($lower, $newBalance);

		(new TransactionSuccessEvent($name, $oldBalance, $newBalance, TransactionEvent::TYPE_ADD))->call();

		return true;
	}

	/**
	 * Reduce money from a player's balance.
	 * Returns false if the player is offline, has insufficient funds, or the transaction was cancelled.
	 */
	public function reduceMoney(string $name, int $amount): bool {
		$lower = strtolower($name);
		$session = $this->simpleSQL->getSession($lower);
		if ($session === null) {
			return false;
		}

		$oldBalance = (int) $session->get("balance", 0);
		if ($oldBalance < $amount) {
			return false;
		}

		$newBalance = $oldBalance - $amount;

		$submitEvent = new TransactionSubmitEvent($name, $oldBalance, $newBalance, TransactionEvent::TYPE_REDUCE);
		$submitEvent->call();
		if ($submitEvent->isCancelled()) {
			return false;
		}

		$session->set("balance", $newBalance);
		$session->save();
		$this->updateBalanceCache($lower, $newBalance);

		(new TransactionSuccessEvent($name, $oldBalance, $newBalance, TransactionEvent::TYPE_REDUCE))->call();

		return true;
	}

	// ──────────────────────────────────────────────
	//  Public API - Asynchronous (online + offline)
	// ──────────────────────────────────────────────

	/**
	 * Get a player's balance asynchronously. Works for both online and offline players.
	 *
	 * @param Closure(?int): void $callback - receives the balance, or null if the player has never played.
	 */
	public function getMoneyAsync(string $name, Closure $callback): void {
		$lower = strtolower($name);

		// Online - instant
		if ($this->simpleSQL->hasSession($lower)) {
			$session = $this->simpleSQL->getSession($lower);
			$callback($session !== null ? (int) $session->get("balance", 0) : null);
			return;
		}

		// Currently loading - return from cache if available
		if ($this->simpleSQL->isLoading($lower)) {
			$callback($this->balanceCache[$lower] ?? null);
			return;
		}

		// Offline - open temporary session
		$this->simpleSQL->openSession($lower, function (Session $session) use ($lower, $callback): void {
			$balance = $session->has("balance") ? (int) $session->get("balance", 0) : null;
			$callback($balance);
			$this->simpleSQL->closeSession($lower);
		});
	}

	// ──────────────────────────────────────────────
	//  Helpers for commands
	// ──────────────────────────────────────────────

	/**
	 * Resolve an online player by name prefix (case-insensitive).
	 * Returns null if no match or ambiguous.
	 */
	public function resolvePlayer(string $input): ?Player {
		return $this->getServer()->getPlayerByPrefix($input);
	}

	/**
	 * Execute a callback with a player's session.
	 *
	 * - For online players: uses the existing session, $temporary = false.
	 * - For offline players: opens a temp session, $temporary = true.
	 *   Caller must call closeTempSession() when done with a temporary session.
	 *
	 * @param Closure(Session, bool $temporary): void $onSession
	 * @param Closure(string $errorMessage): void $onError
	 */
	public function withPlayerSession(string $name, Closure $onSession, Closure $onError): void {
		$lower = strtolower($name);

		// Already loaded (online player)
		if ($this->simpleSQL->hasSession($lower)) {
			$session = $this->simpleSQL->getSession($lower);
			if ($session !== null) {
				$onSession($session, false);
			} else {
				$onError($this->lang->get("general.data-access-error", ["player" => $name]));
			}
			return;
		}

		// Currently loading
		if ($this->simpleSQL->isLoading($lower)) {
			$onError($this->lang->get("general.data-loading", ["player" => $name]));
			return;
		}

		// Offline - open temporary session
		$this->simpleSQL->openSession($lower, function (Session $session) use ($onSession): void {
			$onSession($session, true);
		});
	}

	/**
	 * Close a temporary session, saving first if dirty.
	 */
	public function closeTempSession(string $name): void {
		$lower = strtolower($name);
		$session = $this->simpleSQL->getSession($lower);
		if ($session !== null && $session->isDirty()) {
			$session->save(function (bool $success) use ($lower): void {
				$this->simpleSQL->closeSession($lower);
			});
		} else {
			$this->simpleSQL->closeSession($lower);
		}
	}

	// ──────────────────────────────────────────────
	//  Leaderboard cache (bounded - S3 compliant)
	// ──────────────────────────────────────────────

	/**
	 * Update the balance cache for a player and re-sort.
	 * Cache is bounded by leaderboard-size config to prevent O(accounts) memory.
	 */
	public function updateBalanceCache(string $name, int $balance): void {
		$lower = strtolower($name);
		$this->balanceCache[$lower] = $balance;
		arsort($this->balanceCache);

		// Trim to leaderboard size
		if (count($this->balanceCache) > $this->leaderboardSize) {
			$this->balanceCache = array_slice($this->balanceCache, 0, $this->leaderboardSize, true);
		}
	}

	/**
	 * Get top balances for the leaderboard.
	 *
	 * @return array<int, array{name: string, balance: int}>
	 */
	public function getTopBalances(int $limit = 10, int $offset = 0): array {
		$result = [];
		$i = 0;
		foreach ($this->balanceCache as $name => $balance) {
			if ($i >= $offset + $limit) {
				break;
			}
			if ($i >= $offset) {
				$result[] = ["name" => (string) $name, "balance" => $balance];
			}
			$i++;
		}
		return $result;
	}

	/**
	 * Get total number of entries in the balance cache.
	 */
	public function getBalanceCacheCount(): int {
		return count($this->balanceCache);
	}

	/**
	 * Get a player's rank position on the leaderboard.
	 * Returns null if the player is not in the cache.
	 */
	public function getPlayerRank(string $name): ?int {
		$lower = strtolower($name);
		$rank = 1;
		foreach ($this->balanceCache as $key => $balance) {
			if ($key === $lower) {
				return $rank;
			}
			$rank++;
		}
		return null;
	}

	// ──────────────────────────────────────────────
	//  Accessors
	// ──────────────────────────────────────────────

	public function getSimpleSQL(): SimpleSQL {
		return $this->simpleSQL;
	}

	public function getLang(): LangManager {
		return $this->lang;
	}

	public function getFormatter(): CurrencyFormatter {
		return $this->formatter;
	}

	public function getDefaultBalance(): int {
		return $this->defaultBalance;
	}

	public function getTopmoneyPerPage(): int {
		return $this->topmoneyPerPage;
	}

	/**
	 * Format a monetary amount using the configured currency formatter.
	 */
	public function formatMoney(int|float $amount): string {
		return $this->formatter->format($amount);
	}
}
