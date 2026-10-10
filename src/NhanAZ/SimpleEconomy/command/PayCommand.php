<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy\command;

use NhanAZ\SimpleEconomy\Main;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginOwned;
use pocketmine\plugin\PluginOwnedTrait;
use pocketmine\utils\TextFormat;

class PayCommand extends Command implements PluginOwned {
    use PluginOwnedTrait;

    public function __construct(
        private readonly Main $plugin,
    ) {
        parent::__construct("pay", "Transfers are unavailable until atomic persistence is implemented.", "/pay <player> <amount>");
        $this->setPermission("simpleeconomy.command.pay");
        $this->owningPlugin = $plugin;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): void {
        $message = $this->plugin->getLang()->getLanguage() === "vie"
            ? "Chuyển tiền tạm ngưng cho đến khi hai số dư được lưu nguyên tử."
            : "Transfers are unavailable until both balances can be saved atomically.";
        $sender->sendMessage(TextFormat::RED . $message);
    }
}
