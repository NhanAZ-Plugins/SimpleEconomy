<?php

declare(strict_types=1);

namespace NhanAZ\TransferProbe;

use NhanAZ\SimpleEconomy\Main as Economy;
use pocketmine\plugin\PluginBase;
use ReflectionProperty;
use RuntimeException;

final class Main extends PluginBase {
	protected function onEnable(): void {
		$economy = $this->getServer()->getPluginManager()->getPlugin("SimpleEconomy");
		if (!$economy instanceof Economy) throw new RuntimeException("SimpleEconomy was not enabled for the transfer probe");
		$property = new ReflectionProperty(Economy::class, "simpleSQL");
		$property->setAccessible(true);
		$manager = $property->getValue($economy);
		$mode = getenv("SIMPLEECONOMY_TRANSFER_PROBE_MODE") ?: "success";
		if (!in_array($mode, ["success", "failure"], true)) throw new RuntimeException("Unknown transfer probe mode");
		$manager->openSession("probesender", function ($sender) use ($manager, $economy, $mode): void {
			$manager->openSession("proberecipient", function ($recipient) use ($sender, $economy, $mode): void {
				if ($mode === "failure") {
					if ($sender->get("balance") !== 37 || $recipient->get("balance") !== 13) {
						throw new RuntimeException("Transfer probe accounts changed before the failure test");
					}
					$this->transfer($economy, $sender, $recipient, false);
					return;
				}
				$sender->set("balance", 42);
				$sender->save(function (bool $senderSaved) use ($sender, $recipient, $economy): void {
					if (!$senderSaved) throw new RuntimeException("Could not initialize transfer sender");
					$recipient->set("balance", 8);
					$recipient->save(function (bool $recipientSaved) use ($sender, $recipient, $economy): void {
						if (!$recipientSaved) throw new RuntimeException("Could not initialize transfer recipient");
						$this->transfer($economy, $sender, $recipient, true);
					});
				});
			}, function (string $error): void { throw new RuntimeException($error); });
		}, function (string $error): void { throw new RuntimeException($error); });
	}

	private function transfer(Economy $economy, object $sender, object $recipient, bool $expectSuccess): void {
		$economy->saveTransfer($sender, "ProbeSender", $expectSuccess ? 42 : 37, $recipient, "ProbeRecipient", $expectSuccess ? 8 : 13, 5,
			function (bool $success) use ($sender, $recipient, $expectSuccess): void {
				if ($success !== $expectSuccess || $sender->get("balance") !== 37 || $recipient->get("balance") !== 13) {
					throw new RuntimeException("Paired transfer state or SQL result was incorrect");
				}
				$this->getLogger()->info($expectSuccess ? "TRANSFER_PROBE_SUCCESS" : "TRANSFER_PROBE_ROLLBACK");
			});
	}
}
