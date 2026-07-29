# SimpleEconomy

A production-ready economy plugin for [PocketMine-MP](https://github.com/pmmp/PocketMine-MP), powered by [SimpleSQL](https://github.com/NhanAZ-Libraries/SimpleSQL).

Built for **server owners who want a working economy in minutes** and **developers who want an API that doesn't fight them**.

---

## Why another economy plugin?

I know [BedrockEconomy](https://github.com/cooldogepm/BedrockEconomy) exists, and it's a great plugin - recommended on Poggit, actively maintained. So why SimpleEconomy?

**Short answer:** Different philosophy, different strengths.

| | SimpleEconomy | BedrockEconomy |
|---|---|---|
| **Storage** | Hybrid SQL + YAML via SimpleSQL | Pure SQL (libasynql) |
| **API complexity** | 4 methods. That's it. | Full async ClosureAPI / fluent builders |
| **Setup** | Drop-in, works instantly | Requires understanding async patterns |
| **Offline player data** | Built-in (temporary sessions) | Requires manual SQL queries |
| **ScoreHud integration** | Built-in | Separate plugin required |
| **Multi-language** | 14 languages built-in | 4 languages |
| **Transaction events** | Cancellable events for third-party control | Custom event system |
| **Target audience** | Small-to-medium servers, non-dev admins | Advanced setups, dev-oriented |

**Why not PR into BedrockEconomy?** BedrockEconomy is architecturally pure-SQL. SimpleEconomy's hybrid SQL-YAML approach (via SimpleSQL) is a fundamentally different storage philosophy - this isn't a feature addition, it's a different way of thinking about player data. Both approaches have merit, but they can't coexist in one codebase without compromise.

**TL;DR:** BedrockEconomy is powerful and flexible. SimpleEconomy is simple and instant. Choose what fits your server.

---

## Features

- **6 commands** - `/money`, `/pay`, `/setmoney`, `/addmoney`, `/reducemoney`, `/topmoney`
- **Name prefix matching** - type `/money nh` and it finds `NhanAZ`
- **Offline player support** - check and modify balances of players who aren't online
- **Leaderboard** - paginated `/topmoney` with async cache rebuild
- **14 languages** - English, Vietnamese, Korean, Russian, Spanish, Ukrainian, Chinese, Indonesian, Turkish, French, Portuguese, German, Japanese, Italian
- **ScoreHud integration** - built-in scoreboard tags, no extra plugin needed
- **Transaction events** - other plugins can listen to, or even cancel, economy transactions
- **Currency formatting** - `$1,000,000` (default) or `$1.5M` (compact)
- **SQLite & MySQL** - switch with one line in config

---

## Installation

### From GitHub Actions (recommended)

Every commit is built as a standalone `.phar` by [DevTools](https://github.com/NhanAZ/DevTools). Open the repository's [Actions page](https://github.com/NhanAZ-Plugins/SimpleEconomy/actions/workflows/build.yml), choose a successful `DevTools Build` run, then download the `SimpleEconomy-<commit SHA>` artifact.

Before the downloadable PHAR is built, PHPStan level `4` runs independently against pinned Altay and Axolotl server sources. ScoreHud, SimpleSQL, and libasynql source are included for symbol discovery. The final build job starts only after the complete server matrix passes, so the workflow still uploads exactly one plugin artifact.

Extract `SimpleEconomy.phar` from the artifact and place it in your server's `plugins/` folder. The PHAR already contains the required virions.

The workflow uploads an artifact for 14 days. It does not create a tag or GitHub Release for every commit.

### From source

1. Clone this repository
2. Put the **SimpleSQL** and **libasynql** source packages directly inside `virions/`
3. Run the [DevTools GitHub Action](https://github.com/NhanAZ/DevTools/blob/v0.1.0/docs/github-actions.md), or use DevTools locally to build the project root

The exact CI dependency revisions are pinned in `.github/workflows/build.yml`, while `devtools.yml` declares the compatible virion versions.

The pinned libasynql source needs the narrow compatibility patch in `.github/patches/` before DevTools can safely shade it. The workflow checks that the patch still matches the pinned revision and fails instead of applying it ambiguously.

---

## Commands

| Command | Description | Permission | Default |
|---|---|---|---|
| `/money [player]` | Check your balance, or someone else's | `simpleeconomy.command.money` | Everyone |
| `/pay <player> <amount>` | Send money to another player | `simpleeconomy.command.pay` | Everyone |
| `/topmoney [page]` | View the richest players | `simpleeconomy.command.topmoney` | Everyone |
| `/setmoney <player> <amount>` | Set a player's balance | `simpleeconomy.command.setmoney` | OP |
| `/addmoney <player> <amount>` | Add money to a player | `simpleeconomy.command.addmoney` | OP |
| `/reducemoney <player> <amount>` | Remove money from a player | `simpleeconomy.command.reducemoney` | OP |

**Tip:** All commands support name prefix matching. If `Steve` is online, `/pay st 100` works.

**Tip:** Admin commands (`/setmoney`, `/addmoney`, `/reducemoney`) work on offline players too.

---

## Configuration

After first run, edit `plugin_data/SimpleEconomy/config.yml`:

```yaml
# Language (14 supported)
language: "eng"

# Starting balance for new players
default-balance: 1000

# Currency display
currency:
  symbol: "$"
  formatter: "default"  # or "compact" for $1.5K style

# Leaderboard
topmoney-per-page: 10
leaderboard-size: 100

# Database (sqlite or mysql)
database:
  type: sqlite
```

### Supported Languages

| Code | Language |
|---|---|
| `eng` | English |
| `vie` | Tiếng Việt |
| `kor` | 한국어 |
| `rus` | Русский |
| `spa` | Español |
| `ukr` | Українська |
| `zho` | 简体中文 |
| `ind` | Bahasa Indonesia |
| `tur` | Türkçe |
| `fra` | Français |
| `por` | Português |
| `deu` | Deutsch |
| `jpn` | 日本語 |
| `ita` | Italiano |

All language files are saved to `plugin_data/SimpleEconomy/lang/` - you can edit them freely.

---

## ScoreHud Integration

If [ScoreHud](https://github.com/Flavionsky/ScoreHud) is installed, SimpleEconomy automatically provides these scoreboard tags:

| Tag | Example | Description |
|---|---|---|
| `{simpleeconomy.balance}` | `$1,000` | Formatted balance |
| `{simpleeconomy.rank}` | `3` | Leaderboard position |
| `{simpleeconomy.raw}` | `1000` | Raw balance number |

No extra plugins or configuration needed. Just add the tags to your ScoreHud config.

---

## For Developers

> **Want a full working example?** Check out [SimpleEconomyExample](https://github.com/NhanAZ-Plugins/SimpleEconomyExample) - a complete plugin demonstrating how to use the SimpleEconomy API with real commands and event listeners.

### Quick Start - Using the API

```php
use NhanAZ\SimpleEconomy\Main as SimpleEconomy;

// Get the plugin instance
$eco = SimpleEconomy::getInstance();

// Check balance (online players)
$balance = $eco->getMoney("Steve");  // ?int - null if offline

// Modify balance (online players)
$eco->setMoney("Steve", 5000);    // bool - false if offline or cancelled
$eco->addMoney("Steve", 500);     // bool
$eco->reduceMoney("Steve", 200);  // bool - false if insufficient funds

// Format money using the server's configured style
$display = $eco->formatMoney(1500000);  // "$1,500,000" or "$1.5M"
```

That's the entire sync API. **4 methods.**

### Async API - Offline Players

```php
// Works for BOTH online and offline players
$eco->getMoneyAsync("Steve", function(?int $balance): void {
    if ($balance !== null) {
        // Steve has played before, balance is $balance
    } else {
        // Steve has never joined
    }
});
```

### Transaction Events

SimpleEconomy fires events that your plugin can listen to:

**`TransactionSubmitEvent`** - fired *before* a transaction executes. **Cancellable.**

```php
use NhanAZ\SimpleEconomy\event\TransactionSubmitEvent;
use NhanAZ\SimpleEconomy\event\TransactionEvent;

public function onTransaction(TransactionSubmitEvent $event): void {
    // Block payments over $10,000
    if ($event->type === TransactionEvent::TYPE_PAY && $event->getAmount() > 10000) {
        $event->cancel();
    }
}
```

**`TransactionSuccessEvent`** - fired *after* a transaction completes. Read-only.

```php
use NhanAZ\SimpleEconomy\event\TransactionSuccessEvent;

public function onSuccess(TransactionSuccessEvent $event): void {
    $this->getLogger()->info("{$event->playerName}: {$event->oldBalance} → {$event->newBalance}");
}
```

#### Event Properties

| Property | Type | Description |
|---|---|---|
| `$event->playerName` | `string` | The player involved |
| `$event->oldBalance` | `int` | Balance before the transaction |
| `$event->newBalance` | `int` | Balance after the transaction |
| `$event->type` | `string` | `"set"`, `"add"`, `"reduce"`, or `"pay"` |
| `$event->getAmount()` | `int` | Absolute difference between old and new |

### Offline Player Data Access

For commands or features that need to work on offline players:

```php
$eco->withPlayerSession("Steve", function(Session $session, bool $temporary) use ($eco): void {
    $balance = (int) $session->get("balance", 0);

    // Do something with the balance...

    // IMPORTANT: close temp sessions when done
    if ($temporary) {
        $eco->closeTempSession("Steve");
    }
}, function(string $error): void {
    // Handle error (e.g., data still loading)
});
```

### Leaderboard Data

```php
// Get top 10 players
$top = $eco->getTopBalances(limit: 10, offset: 0);
// Returns: [["name" => "steve", "balance" => 50000], ...]

// Get a player's rank
$rank = $eco->getPlayerRank("Steve");  // ?int - null if not in cache

// Total cached entries
$count = $eco->getBalanceCacheCount();
```

### Multi-Economy Provider Compatibility

SimpleEconomy is supported by multiple multi-economy libraries. We recommend using **EcoAPI** for the best experience.

| Library | Type | Config value | PR Status |
|---|---|---|---|
| [**EcoAPI**](https://github.com/NhanAZ-Libraries/EcoAPI) | Provider | `"simpleeconomy"` | ✅ Supported |
| [libPiggyEconomy](https://github.com/DaPigGuy/libPiggyEconomy) | Provider | `"simpleeconomy"` | ✅ Supported |
| [MoneyConnector](https://github.com/PJZ9n/MoneyConnector) | Connector | `"simpleeconomy"` | Planned |
| [Economizer](https://github.com/SpaceGameDev568/Economizer) | Transistor | `"SimpleEconomy"` | Planned |
| [libEco](https://github.com/David-pm-pl/libEco) | Auto-detect | - | Planned |
| [Capital](https://github.com/SOF3/Capital) | Migration source | `"simpleeconomy"` | Planned |

> **Recommended:** [EcoAPI](https://github.com/NhanAZ-Libraries/EcoAPI) is a simple, lightweight, and unified economy API library that supports auto-detection, async operations, custom providers, and multiple economy plugins with a single clean interface. It is the recommended choice for plugin developers who want broad economy plugin compatibility.

Once these libraries support SimpleEconomy, plugins using them (shop plugins, auction plugins, etc.) will automatically work with SimpleEconomy without any code changes on their end.

---

## Project Structure

```
SimpleEconomy/
├── .github/workflows/build.yml # Per-commit DevTools build
├── devtools.yml                # Required release virions
├── plugin.yml
├── LICENSE
├── resources/
│   ├── config.yml
│   ├── simplesql/
│   │   ├── mysql.sql
│   │   └── sqlite.sql
│   └── lang/
│       ├── eng.yml          # English
│       ├── vie.yml          # Tiếng Việt
│       ├── kor.yml          # 한국어
│       └── ... (14 languages)
└── src/NhanAZ/SimpleEconomy/
    ├── Main.php             # Core plugin + API
    ├── LangManager.php      # Multi-language system
    ├── CurrencyFormatter.php # $1,000 / $1K formatting
    ├── LeaderboardTask.php  # Async cache builder
    ├── ScoreHudListener.php # ScoreHud integration
    ├── command/
    │   ├── MoneyCommand.php
    │   ├── PayCommand.php
    │   ├── SetMoneyCommand.php
    │   ├── AddMoneyCommand.php
    │   ├── ReduceMoneyCommand.php
    │   └── TopMoneyCommand.php
    └── event/
        ├── TransactionEvent.php       # Base event
        ├── TransactionSubmitEvent.php  # Pre-transaction (cancellable)
        └── TransactionSuccessEvent.php # Post-transaction
```

---

## License

[MIT License](LICENSE) - do whatever you want with it.

---

## Credits

- **[DevTools](https://github.com/NhanAZ/DevTools)** - standalone PHAR builder and virion shader used by this repository's workflow
- **[SimpleSQL](https://github.com/NhanAZ-Libraries/SimpleSQL)** - the hybrid SQL-YAML engine that powers this plugin
- **[libasynql](https://github.com/poggit/libasynql)** - async SQL library for PocketMine-MP
- **[ScoreHud](https://github.com/Flavionsky/ScoreHud)** - scoreboard addon (optional integration)
