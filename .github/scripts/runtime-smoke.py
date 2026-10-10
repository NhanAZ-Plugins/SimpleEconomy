from __future__ import annotations

import json
import os
import shutil
import sqlite3
import subprocess
import tempfile
import time
from pathlib import Path


server = Path(os.environ["SERVER_PHAR"])
plugin = Path(os.environ["PLUGIN_PHAR"])
example = Path(os.environ["EXAMPLE_PHAR"])
if not server.is_file() or not plugin.is_file() or not example.is_file():
    raise RuntimeError("Pinned server and verified producer and example PHARs are required")

with tempfile.TemporaryDirectory(prefix="simpleeconomy-smoke-", dir=os.environ.get("RUNNER_TEMP")) as temporary:
    root = Path(temporary)
    data = root / "data"
    plugins = root / "plugins"
    data.mkdir()
    plugins.mkdir()
    shutil.copy2(plugin, plugins / "SimpleEconomy.phar")
    shutil.copy2(example, plugins / "SimpleEconomyExample.phar")
    (data / "server.properties").write_text(
        "language=eng\nserver-ip=127.0.0.1\nserver-port=0\nenable-ipv6=off\n"
        "enable-query=off\nxbox-auth=off\nlevel-type=FLAT\nview-distance=2\nmax-players=1\n",
        encoding="utf-8",
    )
    (data / "pocketmine.yml").write_text(
        "settings:\n  async-workers: 2\n  enable-dev-builds: true\n  send-usage: false\n"
        "auto-report:\n  enabled: false\nauto-updater:\n  enabled: false\n"
        "network:\n  upnp-forwarding: false\n",
        encoding="utf-8",
    )

    log = root / "server.log"
    with log.open("w", encoding="utf-8") as output:
        process = subprocess.Popen(
            ["php", str(server), "--no-wizard", "--disable-ansi", f"--data={data}", f"--plugins={plugins}"],
            cwd=root,
            stdin=subprocess.PIPE,
            stdout=output,
            stderr=subprocess.STDOUT,
            text=True,
        )
        try:
            deadline = time.monotonic() + 90
            while time.monotonic() < deadline and process.poll() is None:
                if "Done (" in log.read_text(encoding="utf-8", errors="replace"):
                    break
                time.sleep(0.25)
            ready = "Done (" in log.read_text(encoding="utf-8", errors="replace")
            if ready and process.stdin is not None:
                process.stdin.write("setmoney OfflineTester 42\n")
                process.stdin.flush()
                time.sleep(5)
                process.stdin.write("setmoney OfflineTester -1\n")
                process.stdin.write("addmoney OfflineTester 9223372036854775807\n")
                process.stdin.write("reducemoney OfflineTester -1\n")
                process.stdin.write("money OfflineTester\n")
                process.stdin.write("wallet\n")
                process.stdin.write("richest\n")
                process.stdin.write("reward OfflineTester 1\n")
                process.stdin.write("fine OfflineTester 1\n")
                process.stdin.flush()
                time.sleep(2)
            if process.poll() is None and process.stdin is not None:
                process.stdin.write("stop\n")
                process.stdin.flush()
            try:
                exit_code = process.wait(timeout=30)
            except subprocess.TimeoutExpired:
                process.kill()
                exit_code = process.wait()
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()

    content = log.read_text(encoding="utf-8", errors="replace")
    errors = [line for line in content.splitlines() if "/ERROR]:" in line or "/CRITICAL]:" in line]
    databases = list(root.rglob("economy.sqlite"))
    rows: list[tuple[str, str, int]] = []
    for database in databases:
        with sqlite3.connect(database) as connection:
            rows.extend(connection.execute("SELECT id, data, revision FROM simplesql_data").fetchall())
    mirrors = list(root.rglob("offlinetester.yml"))
    mirror_balances = []
    for mirror in mirrors:
        result = subprocess.run(
            ["php", "-r", "$data = yaml_parse_file($argv[1]); echo json_encode($data);", str(mirror)],
            check=True,
            capture_output=True,
            text=True,
        )
        mirror_balances.append(json.loads(result.stdout).get("data", {}).get("balance"))

    evidence = {
        "ready": ready,
        "plugin_enabled": "Enabling SimpleEconomy v" in content,
        "example_enabled": "Enabling SimpleEconomyExample v" in content,
        "example_disabled": "Disabling SimpleEconomyExample v" in content,
        "example_commands": {
            "wallet": "This command can only be used in-game." in content,
            "richest": "No players found on the leaderboard." in content or "=== Top 5 Richest Players ===" in content,
            "reward": "Failed! Is the player online?" in content,
            "fine": "Failed! Player may be offline or doesn't have enough money." in content,
        },
        "exit_code": exit_code,
        "database_rows": rows,
        "mirror_balances": mirror_balances,
        "errors": errors,
    }
    print(json.dumps(evidence, indent=2))
    if (
        not ready
        or not evidence["plugin_enabled"]
        or not evidence["example_enabled"]
        or not evidence["example_disabled"]
        or not all(evidence["example_commands"].values())
        or exit_code != 0
        or errors
        or not any(name == "offlinetester" and json.loads(value).get("balance") == 42 for name, value, _ in rows)
        or 42 not in mirror_balances
        or "OfflineTester" not in content
    ):
        print(content[-12000:])
        raise RuntimeError("Isolated SimpleEconomy SQL and YAML smoke test failed")
