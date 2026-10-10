<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy;

use Closure;
use InvalidArgumentException;
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
 * SimpleEconomy plugin powered by SimpleSQL.
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
	/** @var array<string, true> */
	private array $pendingCommandSaves = [];

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
		$this->saveDefaultConfig();

		// Config version check
		$configVersion = self::configInt($this->getConfig()->get("config-version", 0), "config-version", 0);
		if ($configVersion < self::CONFIG_VERSION) {
			$this->getLogger()->warning("Your config.yml is outdated (v$configVersion, latest: v" . self::CONFIG_VERSION . "). Please regenerate it.");
		}

		$this->defaultBalance = self::configInt($this->getConfig()->get("default-balance", 1000), "default-balance", 0);
		$this->topmoneyPerPage = self::configInt($this->getConfig()->get("topmoney-per-page", 10), "topmoney-per-page", 1);
		$this->leaderboardSize = self::configInt($this->getConfig()->get("leaderboard-size", 100), "leaderboard-size", 1);

		// Currency
		$currencyConfig = $this->getConfig()->get("currency", []);
		if (!is_array($currencyConfig)) {
			throw new InvalidArgumentException("Config 'currency' must be a mapping.");
		}
		$symbol = self::configString($currencyConfig["symbol"] ?? "$", "currency.symbol");
		$formatterMode = self::configString($currencyConfig["formatter"] ?? CurrencyFormatter::DEFAULT, "currency.formatter");
		if ($formatterMode !== CurrencyFormatter::DEFAULT && $formatterMode !== CurrencyFormatter::COMPACT) {
			throw new InvalidArgumentException("Config 'currency.formatter' must be 'default' or 'compact'.");
		}
		$this->formatter = new CurrencyFormatter($symbol, $formatterMode);

		// Language
		$language = self::configString($this->getConfig()->get("language", "eng"), "language");
		if (!in_array($language, ["eng", "vie", "kor", "rus", "spa", "ukr", "zho", "ind", "tur", "fra", "por", "deu", "jpn", "ita"], true)) {
			throw new InvalidArgumentException("Config 'language' must name a bundled language.");
		}
		$this->lang = new LangManager($this, $language);

		// SimpleSQL
		$databaseConfig = $this->getConfig()->get("database");
		if (!is_array($databaseConfig)) {
			throw new InvalidArgumentException("Config 'database' must be a mapping.");
		}
		$dbConfig = [];
		foreach ($databaseConfig as $key => $value) {
			if (!is_string($key)) {
				throw new InvalidArgumentException("Config 'database' must use string keys.");
			}
			$dbConfig[$key] = $value;
		}
		$this->simpleSQL = SimpleSQL::create(
			plugin: $this,
			dbConfig: $dbConfig,
		);
		self::$instance = $this;

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

	private static function configInt(mixed $value, string $key, int $minimum): int {
		if (!is_int($value) || $value < $minimum) {
			throw new InvalidArgumentException("Config '$key' must be an integer of at least $minimum.");
		}
		return $value;
	}

	private static function configString(mixed $value, string $key): string {
		if (!is_string($value)) {
			throw new InvalidArgumentException("Config '$key' must be a string.");
		}
		return $value;
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

			try {
				$this->updateBalanceCache($name, BalanceReader::read($session));
			} catch (InvalidBalanceException $e) {
				$this->getLogger()->error($e->getMessage());
				$this->simpleSQL->closeSession($name);
			}
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
		return BalanceReader::read($session);
	}

	/**
	 * Set a player's balance.
	 * Returns false if the player is offline or the transaction was cancelled by another plugin.
	 */
	public function setMoney(string $name, int $amount): bool {
		if ($amount < 0) {
			return false;
		}
		$lower = strtolower($name);
		if (isset($this->pendingCommandSaves[$lower])) {
			return false;
		}
		$session = $this->simpleSQL->getSession($lower);
		if ($session === null) {
			return false;
		}

		$oldBalance = BalanceReader::read($session);

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
		if ($amount < 0) {
			return false;
		}
		$lower = strtolower($name);
		if (isset($this->pendingCommandSaves[$lower])) {
			return false;
		}
		$session = $this->simpleSQL->getSession($lower);
		if ($session === null) {
			return false;
		}

		$oldBalance = BalanceReader::read($session);
		$newBalance = BalanceAmount::add($oldBalance, $amount);
		if ($newBalance === null) {
			return false;
		}

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
		if ($amount < 0) {
			return false;
		}
		$lower = strtolower($name);
		if (isset($this->pendingCommandSaves[$lower])) {
			return false;
		}
		$session = $this->simpleSQL->getSession($lower);
		if ($session === null) {
			return false;
		}

		$oldBalance = BalanceReader::read($session);
		$newBalance = BalanceAmount::reduce($oldBalance, $amount);
		if ($newBalance === null) {
			return false;
		}

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
			$callback($session !== null ? BalanceReader::read($session) : null);
			return;
		}

		// Currently loading - return from cache if available
		if ($this->simpleSQL->isLoading($lower)) {
			$callback($this->balanceCache[$lower] ?? null);
			return;
		}

		// Offline - open temporary session
		$this->simpleSQL->openSession($lower, function (Session $session) use ($lower, $callback): void {
			try {
				$callback($session->has("balance") ? BalanceReader::read($session) : null);
			} finally {
				$this->simpleSQL->closeSession($lower);
			}
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
		if (isset($this->pendingCommandSaves[$lower])) {
			$onError($this->lang->get("general.data-loading", ["player" => $name]));
			return;
		}

		// Already loaded (online player)
		if ($this->simpleSQL->hasSession($lower)) {
			$session = $this->simpleSQL->getSession($lower);
			if ($session !== null) {
				try {
					$onSession($session, false);
				} catch (InvalidBalanceException $e) {
					$this->getLogger()->error($e->getMessage());
					$onError($this->lang->get("general.data-access-error", ["player" => $name]));
				}
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
		$this->simpleSQL->openSession($lower, function (Session $session) use ($onSession, $onError, $lower, $name): void {
			try {
				$onSession($session, true);
			} catch (InvalidBalanceException $e) {
				$this->getLogger()->error($e->getMessage());
				$this->simpleSQL->closeSession($lower);
				$onError($this->lang->get("general.data-access-error", ["player" => $name]));
			}
		});
	}

	/**
	 * @param Closure(bool): void $onComplete
	 */
	public function saveCommandBalance(Session $session, string $name, int $oldBalance, int $newBalance, Closure $onComplete): void {
		$lower = strtolower($name);
		if (isset($this->pendingCommandSaves[$lower])) {
			$onComplete(false);
			return;
		}
		$this->pendingCommandSaves[$lower] = true;
		try {
			$session->set("balance", $newBalance);
			$mutationVersion = $session->_getMutationVersion();
			$session->save(function (bool $success) use ($session, $lower, $oldBalance, $newBalance, $mutationVersion, $onComplete): void {
				if ($success) {
					$this->updateBalanceCache($lower, $newBalance);
				} elseif (!$session->isClosed()) {
					if ($session->_getMutationVersion() === $mutationVersion) {
						$session->set("balance", $oldBalance);
					} else {
						$this->getLogger()->warning("Could not restore the balance for '{$lower}' after a failed save because the session changed again.");
					}
				}
				unset($this->pendingCommandSaves[$lower]);
				$onComplete($success);
			});
		} catch (\Throwable $error) {
			unset($this->pendingCommandSaves[$lower]);
			throw $error;
		}
	}

	/**
	 * Persist both sides of a payment before changing either visible balance.
	 * @param Closure(bool): void $onComplete
	 */
	public function saveTransfer(
		Session $senderSession,
		string $senderName,
		int $senderBalance,
		Session $targetSession,
		string $targetName,
		int $targetBalance,
		int $amount,
		Closure $onComplete,
	): void {
		$senderId = strtolower($senderName);
		$targetId = strtolower($targetName);
		if ($senderId === $targetId || $amount <= 0 ||
			isset($this->pendingCommandSaves[$senderId]) || isset($this->pendingCommandSaves[$targetId]) ||
			$senderSession->getId() !== $senderId || $targetSession->getId() !== $targetId ||
			BalanceReader::read($senderSession) !== $senderBalance || BalanceReader::read($targetSession) !== $targetBalance) {
			$onComplete(false);
			return;
		}
		$newSenderBalance = BalanceAmount::reduce($senderBalance, $amount);
		$newTargetBalance = BalanceAmount::add($targetBalance, $amount);
		if ($newSenderBalance === null || $newTargetBalance === null) {
			$onComplete(false);
			return;
		}
		$senderData = $senderSession->getAll();
		$targetData = $targetSession->getAll();
		$senderData["balance"] = $newSenderBalance;
		$targetData["balance"] = $newTargetBalance;
		$this->pendingCommandSaves[$senderId] = true;
		$this->pendingCommandSaves[$targetId] = true;
		try {
			$this->simpleSQL->saveSessionPair($senderSession, $senderData, $targetSession, $targetData,
				function (bool $success) use ($senderId, $targetId, $newSenderBalance, $newTargetBalance, $onComplete): void {
					unset($this->pendingCommandSaves[$senderId], $this->pendingCommandSaves[$targetId]);
					if ($success) {
						$this->updateBalanceCache($senderId, $newSenderBalance);
						$this->updateBalanceCache($targetId, $newTargetBalance);
					}
					$onComplete($success);
				});
		} catch (\Throwable $error) {
			unset($this->pendingCommandSaves[$senderId], $this->pendingCommandSaves[$targetId]);
			throw $error;
		}
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
