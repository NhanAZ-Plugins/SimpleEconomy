# SimpleEconomyExample

A small plugin that demonstrates the SimpleEconomy API with wallet, reward, fine, and leaderboard commands.

## Requirements and installation

The example requires Axolotl-PM plugin API `5.0.0` and an enabled copy of SimpleEconomy.

Download the `SimpleEconomyExample` artifact from a successful [build workflow run](https://github.com/AxolotlBucketLabs/SimpleEconomy/actions/workflows/build.yml), place its PHAR in the server's `plugins/` directory, and restart the server. The example is built from this repository and is not published as a separate release.

## Commands

| Command | Description | Permission |
|---|---|---|
| `/wallet` | View your balance. | Everyone |
| `/reward <player> <amount>` | Add money to a player. | OP |
| `/fine <player> <amount>` | Remove money from a player. | OP |
| `/richest` | View the five highest balances. | Everyone |

## Using the API

Declare SimpleEconomy as a dependency in your `plugin.yml`:

```yaml
depend: SimpleEconomy
```

Get the plugin instance and read the balance of an online player:

```php
use AxolotlBucketLabs\SimpleEconomy\Main as SimpleEconomy;

$economy = SimpleEconomy::getInstance();
if ($economy === null) {
    return;
}

$balance = $economy->getMoney($player->getName());
if ($balance !== null) {
    $formatted = $economy->formatMoney($balance);
}
```

Balance reads can raise `InvalidBalanceException` if stored data is malformed. Handle it before taking follow-up actions or writing another balance.

Use a confirmed method when the caller needs to know whether SQL saved the change:

```php
$economy->addMoneyConfirmed($player->getName(), 500, function (bool $saved) use ($player): void {
    $player->sendMessage($saved ? "Reward saved." : "Could not save the reward.");
});
```

The callback reports `false` if no session is loaded, the amount cannot be applied, another balance save is pending, an event listener cancels the change, or SQL cannot save it. `reduceMoneyConfirmed()` also reports `false` when the balance is too low. A successful callback means SQL persistence was confirmed by readback. The YAML mirror is queued separately.

`getMoneyAsync()` can read balances for offline players. It passes `null` when no balance record exists. `getTopBalances()` and `getPlayerRank()` read the leaderboard cache.

Do not implement a transfer as two separate balance updates. They are not atomic. Use SimpleEconomy's `/pay` command or an atomic transaction supported by your storage layer.

## Transaction events

`TransactionSubmitEvent` fires before a transaction and can cancel it. `TransactionSuccessEvent` is read-only. Synchronous balance methods dispatch it after updating the in-memory session, before the asynchronous SQL save finishes. Confirmed API methods and administrative commands dispatch it after SQL confirms the write.

The example plugin includes working [command handlers](src/AxolotlBucketLabs/SimpleEconomyExample/Main.php) and [event listeners](src/AxolotlBucketLabs/SimpleEconomyExample/EventListener.php).

## License

This example is distributed under the [MIT License](LICENSE).
