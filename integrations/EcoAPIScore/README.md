# EcoAPIScore

EcoAPIScore adds generic economy tags to [ScoreHud](https://github.com/NhanAZ-Plugins/ScoreHud)
through [EcoAPI](https://github.com/NhanAZ-Libraries/EcoAPI). It selects the first
available EcoAPI provider and keeps a small per-player balance cache, so
ScoreHud's tag resolver never depends on a blocking economy request.

This is a separate integration plugin by design. ScoreHud remains usable without
EcoAPI or an economy provider, while this addon can evolve with the provider
library.

## Compatibility

- PocketMine-MP API 5 and current Axolotl-PM 5 releases.
- ScoreHud 8.0.2 or newer.
- EcoAPI 1.0.0 or newer.
- SimpleEconomy, EconomyAPI, BedrockEconomy and the EcoAPI XP provider.

The provider still needs to be installed and enabled. If no provider is
available, the plugin stays enabled and economy tags resolve to N/A until a
provider becomes available.

## Installation

1. Install ScoreHud.
2. Install the economy plugin you want to use.
3. Download the EcoAPIScore artifact from a successful [integration workflow](../../.github/workflows/ecoapi-score.yml) run.
4. Copy EcoAPIScore.phar to the server plugins directory.
5. Restart the server.

The built PHAR contains the EcoAPI virion. You do not need to install the
EcoAPI companion plugin when using this PHAR, although the companion plugin is
safe to keep installed.

## Tags

| Tag | Value |
| --- | --- |
| {ecoapi.balance} | Raw balance without a currency symbol |
| {ecoapi.raw} | Alias for ecoapi.balance |
| {ecoapi.money} | Formatted balance with currency symbol |
| {ecoapi.formatted} | Alias for ecoapi.money |
| {ecoapi.provider} | Active provider name, such as SimpleEconomy or XP |
| {ecoapi.currency} | Provider currency symbol |

Examples:

~~~yaml
default-board:
  - "§eEconomy"
  - "§8» §f{ecoapi.formatted}"
  - "§8» §7{ecoapi.provider}"
~~~

The existing SimpleEconomy-specific tags remain available when SimpleEconomy is
installed:

~~~text
{simpleeconomy.balance}
{simpleeconomy.raw}
{simpleeconomy.rank}
~~~

Use the SimpleEconomy tags when you need its leaderboard rank or its exact
configured formatter. Use EcoAPIScore tags when the scoreboard should work with
multiple economy providers.

## Refresh behavior

EcoAPI providers do not share a common balance-change event. EcoAPIScore
therefore uses three update paths:

- resolve the balance when ScoreHud first asks for a tag;
- refresh a player one second after joining;
- poll all online players using config/refresh-period seconds.

When a provider returns a balance, EcoAPIScore sends a
PlayerTagsUpdateEvent so the visible scoreboard can update without a relog.

Configure the polling interval in
plugin_data/EcoAPIScore/config.yml:

~~~yaml
refresh-period: 5
~~~

The cache is cleared when a player quits. Failed provider requests are logged at
debug level and leave the previous value untouched.

## Building

The build needs PHP 8.4, Composer, Axolotl-PM API 5 source for PHPStan and an
EcoAPI source checkout under `virions/EcoAPI`.

~~~powershell
cd integrations/EcoAPIScore
git clone https://github.com/NhanAZ-Libraries/EcoAPI.git virions/EcoAPI
composer install --prefer-dist --no-interaction
composer test
composer phpstan
~~~

The [integration workflow](../../.github/workflows/ecoapi-score.yml) checks out
the pinned EcoAPI source, runs PHPStan at level max, lints the source, builds
with DevTools and verifies that the shaded EcoAPI classes are present in the
PHAR.

## Why this is not inside ScoreHud

ScoreHud is a scoreboard engine. EcoAPI is a provider abstraction that can
select SimpleEconomy, EconomyAPI, BedrockEconomy or XP. Keeping this adapter
separate avoids adding economy dependencies to every ScoreHud installation and
lets provider compatibility evolve independently.

The old ScoreHudX EcoAPIScore addon is not this plugin. It targets the legacy
onebone EconomyAPI plugin and declares PocketMine-MP API 4. This plugin uses
NhanAZ-Libraries/EcoAPI.

## License

EcoAPIScore is released under the GNU General Public License v3.0 or later.
EcoAPI remains under its own Apache License 2.0 when shaded into the PHAR.
