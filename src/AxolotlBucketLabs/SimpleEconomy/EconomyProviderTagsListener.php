<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\SimpleEconomy;

use Ifera\ScoreHud\event\PlayerTagsUpdateEvent;
use Ifera\ScoreHud\event\TagsResolveEvent;
use Ifera\ScoreHud\scoreboard\ScoreTag;
use AxolotlBucketLabs\EconomyProviderAPI\EconomyProviderAPI as EconomyProviderRegistry;
use AxolotlBucketLabs\EconomyProviderAPI\EconomyProvider;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use Throwable;
use function in_array;
use function strtolower;

final class EconomyProviderTagsListener implements Listener {

	public const TAG_BALANCE = "simpleeconomy.provider.balance";
	public const TAG_RAW = "simpleeconomy.provider.raw";
	public const TAG_MONEY = "simpleeconomy.provider.money";
	public const TAG_FORMATTED = "simpleeconomy.provider.formatted";
	public const TAG_PROVIDER_NAME = "simpleeconomy.provider.name";
	public const TAG_PROVIDER_CURRENCY = "simpleeconomy.provider.currency";

	private ?EconomyProvider $provider = null;
	private ?string $providerName = null;
	private ?string $currency = null;
	private bool $noProviderWarningShown = false;
	private int $refreshTicks;

	/** @var array<string, int|float> */
	private array $balances = [];

	public function __construct(
		private readonly Main $plugin,
		int $refreshPeriod
	) {
		$this->refreshTicks = $refreshPeriod * 20;
	}

	public function start(): void {
		EconomyProviderRegistry::init();
		$this->plugin->getServer()->getPluginManager()->registerEvents($this, $this->plugin);
		$this->plugin->getScheduler()->scheduleRepeatingTask(
			new ClosureTask(function (): void {
				$this->refreshOnlineProviderBalances();
			}),
			$this->refreshTicks
		);

		$provider = $this->getEconomyProvider();
		if ($provider !== null) {
			$this->plugin->getLogger()->notice("Using economy provider " . $provider->getName() . ".");
		}
	}

	public function onPlayerJoin(PlayerJoinEvent $event): void {
		$player = $event->getPlayer();
		$this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($player): void {
			if ($player->isOnline()) {
				$this->refreshProviderBalance($player);
			}
		}), 20);
	}

	public function onPlayerQuit(PlayerQuitEvent $event): void {
		unset($this->balances[$this->getPlayerCacheKey($event->getPlayer())]);
	}

	public function onTagResolve(TagsResolveEvent $event): void {
		$tag = $event->getTag();
		$tagName = $tag->getName();
		if (!in_array($tagName, [
			self::TAG_BALANCE,
			self::TAG_RAW,
			self::TAG_MONEY,
			self::TAG_FORMATTED,
			self::TAG_PROVIDER_NAME,
			self::TAG_PROVIDER_CURRENCY,
		], true)) {
			return;
		}

		$this->getEconomyProvider();
		$player = $event->getPlayer();
		if ($this->isProviderBalanceTag($tagName) && !isset($this->balances[$this->getPlayerCacheKey($player)])) {
			$this->refreshProviderBalance($player);
		}

		$tag->setValue($this->getTagValue($tagName, $player));
	}

	private function refreshOnlineProviderBalances(): void {
		if ($this->getEconomyProvider() === null) {
			return;
		}

		foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
			$this->refreshProviderBalance($player);
		}
	}

	private function refreshProviderBalance(Player $player): void {
		$provider = $this->getEconomyProvider();
		if ($provider === null) {
			return;
		}

		try {
			$provider->getMoney($player, function (int|float $balance) use ($player): void {
				if (!$player->isOnline()) {
					return;
				}

				$this->balances[$this->getPlayerCacheKey($player)] = $balance;
				$this->updateProviderTags($player);
			});
		} catch (Throwable $error) {
			$this->plugin->getLogger()->debug("Economy provider balance refresh failed: " . $error->getMessage());
		}
	}

	private function updateProviderTags(Player $player): void {
		(new PlayerTagsUpdateEvent($player, [
			new ScoreTag(self::TAG_BALANCE, $this->getTagValue(self::TAG_BALANCE, $player)),
			new ScoreTag(self::TAG_RAW, $this->getTagValue(self::TAG_RAW, $player)),
			new ScoreTag(self::TAG_MONEY, $this->getTagValue(self::TAG_MONEY, $player)),
			new ScoreTag(self::TAG_FORMATTED, $this->getTagValue(self::TAG_FORMATTED, $player)),
			new ScoreTag(self::TAG_PROVIDER_NAME, $this->getTagValue(self::TAG_PROVIDER_NAME, $player)),
			new ScoreTag(self::TAG_PROVIDER_CURRENCY, $this->getTagValue(self::TAG_PROVIDER_CURRENCY, $player)),
		]))->call();
	}

	private function getTagValue(string $tagName, Player $player): string {
		$balance = $this->balances[$this->getPlayerCacheKey($player)] ?? null;

		return match ($tagName) {
			self::TAG_BALANCE, self::TAG_RAW => $balance === null ? "N/A" : ProviderBalanceFormatter::raw($balance),
			self::TAG_MONEY, self::TAG_FORMATTED => $balance === null ? "N/A" : ProviderBalanceFormatter::display($balance, $this->currency ?? ""),
			self::TAG_PROVIDER_NAME => $this->providerName ?? "N/A",
			self::TAG_PROVIDER_CURRENCY => $this->currency ?? "N/A",
			default => "",
		};
	}

	private function getEconomyProvider(): ?EconomyProvider {
		if ($this->provider !== null) {
			return $this->provider;
		}

		try {
			$provider = EconomyProviderRegistry::detectProvider();
		} catch (Throwable $error) {
			$this->plugin->getLogger()->warning("Economy provider detection failed: " . $error->getMessage());
			return null;
		}

		if ($provider === null) {
			if (!$this->noProviderWarningShown) {
				$this->plugin->getLogger()->warning("No economy provider is available. Provider tags will show N/A until one is enabled.");
				$this->noProviderWarningShown = true;
			}
			return null;
		}

		$this->provider = $provider;
		$this->providerName = $provider->getName();
		$this->currency = $provider->getCurrencySymbol();
		return $provider;
	}

	private function isProviderBalanceTag(string $tagName): bool {
		return in_array($tagName, [
			self::TAG_BALANCE,
			self::TAG_RAW,
			self::TAG_MONEY,
			self::TAG_FORMATTED,
		], true);
	}

	private function getPlayerCacheKey(Player $player): string {
		return strtolower($player->getName());
	}
}
