<?php
declare(strict_types=1);

namespace Ifera\ScoreHud\event;

use Ifera\ScoreHud\scoreboard\ScoreTag;
use pocketmine\event\Event;
use pocketmine\player\Player;

class TagsResolveEvent extends Event{
	public function getPlayer(): Player{
		throw new \LogicException();
	}
	public function getTag(): ScoreTag{
		throw new \LogicException();
	}
}
