# SimpleEconomy

An Axolotl-PM economy plugin with SQL storage, a YAML mirror, player transfers, and 14 translations.

[![CI](https://github.com/AxolotlBucketLabs/SimpleEconomy/actions/workflows/build.yml/badge.svg)](https://github.com/AxolotlBucketLabs/SimpleEconomy/actions/workflows/build.yml) [![Latest release](https://img.shields.io/github/v/release/AxolotlBucketLabs/SimpleEconomy)](https://github.com/AxolotlBucketLabs/SimpleEconomy/releases/latest)
[![License](https://img.shields.io/github/license/AxolotlBucketLabs/SimpleEconomy)](https://github.com/AxolotlBucketLabs/SimpleEconomy/blob/main/LICENSE) [![Discord](https://img.shields.io/discord/1568295845374206052?label=Discord&logo=discord&logoColor=white&color=5865F2)](https://discord.gg/Pk4FrNvHnM)

## Features

- Store balances in SQLite or MySQL with a YAML mirror.
- Use six commands to check, transfer, and manage balances.
- Access online and offline balances through the plugin API.
- Add balance and provider tags to ScoreHud.
- Choose from 14 bundled translations and configure currency formatting.

## Requirements and installation

SimpleEconomy requires PHP `^8.4` and declares plugin API `5.0.0`. CI runtime checks use Axolotl-PM `5.51.1`.

Download `SimpleEconomy.phar` from the [latest release](https://github.com/AxolotlBucketLabs/SimpleEconomy/releases/latest), place it in your server's `plugins/` directory, and restart the server. The PHAR includes its required dependencies.

To build from source, install the Composer dependencies and follow the [build workflow](.github/workflows/build.yml). It documents the pinned DevTools setup used by CI.

## Commands

| Command | Description | Permission | Default |
|---|---|---|---|
| `/money [player]` | View your balance or another player's balance. | `simpleeconomy.command.money` | Everyone |
| `/pay <player> <amount>` | Transfer money to an online player. | `simpleeconomy.command.pay` | Everyone |
| `/topmoney [page]` | View the paginated leaderboard. | `simpleeconomy.command.topmoney` | Everyone |
| `/setmoney <player> <amount>` | Set a player's balance, including offline players. | `simpleeconomy.command.setmoney` | OP |
| `/addmoney <player> <amount>` | Add money to a player, including offline players. | `simpleeconomy.command.addmoney` | OP |
| `/reducemoney <player> <amount>` | Remove money from a player, including offline players. | `simpleeconomy.command.reducemoney` | OP |

Amounts are nonnegative integers. Decimal input is rounded down. Scientific notation and values outside PHP's integer range are rejected. `/pay`, `/addmoney`, and `/reducemoney` require an amount greater than zero. Payments also reject self-transfers, insufficient funds, and recipient balance overflow.

Player lookup accepts name prefixes. For example, `/money st` can match the online player `Steve`.

## Configuration

The plugin creates `plugin_data/SimpleEconomy/config.yml` on first run. See the [default configuration](resources/config.yml) for all available options.

Set `database.type` to `sqlite` or `mysql`. Invalid required settings stop startup instead of being silently converted. Leaderboard limits and the ScoreHud provider refresh period must be positive integers.

## Translations

Set the `language` option to one of these codes. Translation files are stored in `plugin_data/SimpleEconomy/lang/` and can be edited.

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

## ScoreHud integration

When ScoreHud is installed, SimpleEconomy registers its own balance tags and tags backed by the first available economy provider.

| Tag | Value |
|---|---|
| `{simpleeconomy.balance}` | Formatted SimpleEconomy balance. |
| `{simpleeconomy.rank}` | Leaderboard position. |
| `{simpleeconomy.raw}` | Unformatted SimpleEconomy balance. |
| `{simpleeconomy.provider.balance}` and `{simpleeconomy.provider.raw}` | Unformatted provider balance. |
| `{simpleeconomy.provider.money}` and `{simpleeconomy.provider.formatted}` | Provider balance formatted with its currency symbol. |
| `{simpleeconomy.provider.name}` | Selected economy provider. |
| `{simpleeconomy.provider.currency}` | Provider currency symbol. |

Provider balances refresh every five seconds by default. Change `scorehud.provider.refresh-period` in the configuration to adjust the interval. ScoreHud is required for these tags. The EconomyProviderAPI provider library is bundled with SimpleEconomy.

## Plugin API

The [SimpleEconomyExample](examples/SimpleEconomyExample/README.md) shows how to use balance methods and transaction events. Synchronous balance changes update the in-memory session before the SQL save completes. Use `setMoneyConfirmed()`, `addMoneyConfirmed()`, or `reduceMoneyConfirmed()` when the caller needs the SQL result.

Do not implement transfers as two separate balance updates. The `/pay` command saves both sides of a transfer together.

## License and credits

SimpleEconomy's code is distributed under the [MIT License](LICENSE). Bundled dependencies retain their own licenses.

The project uses [DevTools](https://github.com/AxolotlBucketLabs/DevTools) to build the PHAR, [SimpleSQL](https://github.com/AxolotlBucketLabs/SimpleSQL) and [libasynql](https://github.com/poggit/libasynql) for storage, [EconomyProviderAPI](https://github.com/AxolotlBucketLabs/EconomyProviderAPI) for economy provider integration, and [ScoreHud](https://github.com/AxolotlBucketLabs/ScoreHud) for scoreboard tags.
