<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy;

use Ifera\ScoreHud\event\PlayerTagsUpdateEvent;
use Ifera\ScoreHud\event\TagsResolveEvent;
use Ifera\ScoreHud\scoreboard\ScoreTag;
use NhanAZ\SimpleEconomy\event\TransactionSuccessEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\player\Player;

/**
 * ScoreHud integration - provides economy tags for scoreboards.
 *
 * This listener is ONLY registered when ScoreHud is installed (softdepend).
 * Do NOT instantiate this class if ScoreHud is not present.
 *
 * Available tags:
 *   {simpleeconomy.balance}  - formatted balance (e.g. "$1,000")
 *   {simpleeconomy.rank}     - leaderboard position (e.g. "3")
 *   {simpleeconomy.raw}      - raw balance number (e.g. "1000")
 */
class ScoreHudListener implements Listener {

	public const TAG_BALANCE = "simpleeconomy.balance";
	public const TAG_RANK = "simpleeconomy.rank";
	public const TAG_RAW = "simpleeconomy.raw";

	public function __construct(private readonly Main $plugin) {}

	/**
	 * Push tag updates for a specific player.
	 */
	private function updateTags(Player $player): void {
		$balance = $this->plugin->getMoney($player->getName());
		if ($balance === null) {
			return;
		}

		$rank = $this->plugin->getPlayerRank($player->getName());

		$event = new PlayerTagsUpdateEvent($player, [
			new ScoreTag(self::TAG_BALANCE, $this->plugin->formatMoney($balance)),
			new ScoreTag(self::TAG_RANK, $rank !== null ? (string) $rank : "-"),
			new ScoreTag(self::TAG_RAW, (string) $balance),
		]);
		$event->call();
	}

	/**
	 * Update tags when a player joins and their session is ready.
	 *
	 * We use a delayed task because the session may not be loaded yet at join time.
	 */
	public function onPlayerJoin(PlayerJoinEvent $event): void {
		$player = $event->getPlayer();

		// Delay to allow SimpleSQL session to load first
		$this->plugin->getScheduler()->scheduleDelayedTask(
			new \pocketmine\scheduler\ClosureTask(function () use ($player): void {
				if ($player->isOnline()) {
					$this->updateTags($player);
				}
			}),
			20 // 1 second delay
		);
	}

	/**
	 * Update tags when any transaction succeeds.
	 */
	public function onTransactionSuccess(TransactionSuccessEvent $event): void {
		$player = $this->plugin->getServer()->getPlayerExact($event->playerName);
		if ($player !== null) {
			$this->updateTags($player);
		}
	}

	/**
	 * Resolve tags on demand when ScoreHud requests them.
	 */
	public function onTagResolve(TagsResolveEvent $event): void {
		$player = $event->getPlayer();
		$tag = $event->getTag();

		$balance = $this->plugin->getMoney($player->getName());

		match ($tag->getName()) {
			self::TAG_BALANCE => $tag->setValue($balance !== null ? $this->plugin->formatMoney($balance) : "N/A"),
			self::TAG_RANK => $tag->setValue(($rank = $this->plugin->getPlayerRank($player->getName())) !== null ? (string) $rank : "-"),
			self::TAG_RAW => $tag->setValue($balance !== null ? (string) $balance : "0"),
			default => null,
		};
	}
}
