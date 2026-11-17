<?php
declare(strict_types=1);

namespace NhanAZ\EcoAPI;

use Closure;
use pocketmine\player\Player;

interface EconomyProvider{
	public function getName(): string;
	public function getCurrencySymbol(): string;
	public static function isAvailable(): bool;
	public function getMoney(Player $player, Closure $callback): void;
}
