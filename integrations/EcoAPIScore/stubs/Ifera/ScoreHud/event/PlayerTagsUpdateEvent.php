<?php
declare(strict_types=1);

namespace Ifera\ScoreHud\event;

use Ifera\ScoreHud\scoreboard\ScoreTag;
use pocketmine\event\Event;
use pocketmine\player\Player;

class PlayerTagsUpdateEvent extends Event{
	/** @param list<ScoreTag> $tags */
	public function __construct(Player $player, array $tags){}
	public function call(): void{}
}
