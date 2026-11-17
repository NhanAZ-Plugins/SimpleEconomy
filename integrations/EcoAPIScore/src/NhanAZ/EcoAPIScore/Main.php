<?php
declare(strict_types=1);

namespace NhanAZ\EcoAPIScore;

use Ifera\ScoreHud\event\PlayerTagsUpdateEvent;
use Ifera\ScoreHud\event\TagsResolveEvent;
use Ifera\ScoreHud\scoreboard\ScoreTag;
use NhanAZ\EcoAPI\EcoAPI;
use NhanAZ\EcoAPI\EconomyProvider;
use NhanAZ\EcoAPIScore\util\BalanceFormatter;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use Throwable;
use function strtolower;

final class Main extends PluginBase implements Listener{

	public const TAG_BALANCE = "ecoapi.balance";
	public const TAG_RAW = "ecoapi.raw";
	public const TAG_MONEY = "ecoapi.money";
	public const TAG_FORMATTED = "ecoapi.formatted";
	public const TAG_PROVIDER = "ecoapi.provider";
	public const TAG_CURRENCY = "ecoapi.currency";

	private ?EconomyProvider $provider = null;
	private ?string $providerName = null;
	private ?string $currency = null;
	private bool $noProviderWarningShown = false;
	private int $refreshTicks = 100;

	/** @var array<string, int|float> */
	private array $balances = [];

	protected function onEnable(): void{
		$this->saveDefaultConfig();

		$period = $this->getConfig()->get("refresh-period", 5);
		if(!is_int($period) || $period < 1){
			$this->getLogger()->warning("Config 'refresh-period' must be a positive integer. Using 5 seconds.");
			$period = 5;
		}
		$this->refreshTicks = $period * 20;

		EcoAPI::init();
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getScheduler()->scheduleRepeatingTask(
			new ClosureTask(function(): void{
				$this->refreshOnlinePlayers();
			}),
			$this->refreshTicks
		);

		$provider = $this->ensureProvider();
		if($provider !== null){
			$this->getLogger()->notice("Using EcoAPI provider " . $provider->getName() . ".");
		}
	}

	public function onPlayerJoin(PlayerJoinEvent $event): void{
		$player = $event->getPlayer();
		$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player): void{
			if($player->isOnline()){
				$this->refreshPlayer($player);
			}
		}), 20);
	}

	public function onPlayerQuit(PlayerQuitEvent $event): void{
		unset($this->balances[$this->playerKey($event->getPlayer())]);
	}

	public function onTagResolve(TagsResolveEvent $event): void{
		$tagName = $event->getTag()->getName();
		if(!str_starts_with($tagName, "ecoapi.")){
			return;
		}

		$this->ensureProvider();
		$player = $event->getPlayer();
		if($this->isBalanceTag($tagName) && !array_key_exists($this->playerKey($player), $this->balances)){
			$this->refreshPlayer($player);
		}

		$event->getTag()->setValue($this->valueFor($tagName, $player));
	}

	private function refreshOnlinePlayers(): void{
		if($this->ensureProvider() === null){
			return;
		}

		foreach($this->getServer()->getOnlinePlayers() as $player){
			$this->refreshPlayer($player);
		}
	}

	private function refreshPlayer(Player $player): void{
		$provider = $this->ensureProvider();
		if($provider === null){
			return;
		}

		try{
			$provider->getMoney($player, function(int|float $balance) use ($player): void{
				$this->balances[$this->playerKey($player)] = $balance;
				if($player->isOnline()){
					$this->pushTags($player);
				}
			});
		}catch(Throwable $error){
			$this->getLogger()->debug("EcoAPI balance refresh failed: " . $error->getMessage());
		}
	}

	private function pushTags(Player $player): void{
		(new PlayerTagsUpdateEvent($player, [
			new ScoreTag(self::TAG_BALANCE, $this->valueFor(self::TAG_BALANCE, $player)),
			new ScoreTag(self::TAG_RAW, $this->valueFor(self::TAG_RAW, $player)),
			new ScoreTag(self::TAG_MONEY, $this->valueFor(self::TAG_MONEY, $player)),
			new ScoreTag(self::TAG_FORMATTED, $this->valueFor(self::TAG_FORMATTED, $player)),
			new ScoreTag(self::TAG_PROVIDER, $this->valueFor(self::TAG_PROVIDER, $player)),
			new ScoreTag(self::TAG_CURRENCY, $this->valueFor(self::TAG_CURRENCY, $player)),
		]))->call();
	}

	private function valueFor(string $tagName, Player $player): string{
		$balance = $this->balances[$this->playerKey($player)] ?? null;

		return match($tagName){
			self::TAG_BALANCE, self::TAG_RAW => $balance === null ? "N/A" : BalanceFormatter::raw($balance),
			self::TAG_MONEY, self::TAG_FORMATTED => $balance === null ? "N/A" : BalanceFormatter::display($balance, $this->currency ?? ""),
			self::TAG_PROVIDER => $this->providerName ?? "N/A",
			self::TAG_CURRENCY => $this->currency ?? "N/A",
			default => "",
		};
	}

	private function ensureProvider(): ?EconomyProvider{
		if($this->provider !== null){
			return $this->provider;
		}

		try{
			$provider = EcoAPI::detectProvider();
		}catch(Throwable $error){
			$this->getLogger()->warning("EcoAPI provider detection failed: " . $error->getMessage());
			return null;
		}

		if($provider === null){
			if(!$this->noProviderWarningShown){
				$this->getLogger()->warning("No EcoAPI economy provider is available. ecoapi.* tags will show N/A until one is enabled.");
				$this->noProviderWarningShown = true;
			}
			return null;
		}

		$this->provider = $provider;
		$this->providerName = $provider->getName();
		$this->currency = $provider->getCurrencySymbol();
		return $provider;
	}

	private function isBalanceTag(string $tagName): bool{
		return in_array($tagName, [
			self::TAG_BALANCE,
			self::TAG_RAW,
			self::TAG_MONEY,
			self::TAG_FORMATTED,
		], true);
	}

	private function playerKey(Player $player): string{
		return strtolower($player->getName());
	}
}
