from __future__ import annotations

import json
import os
import shutil
import sqlite3
import subprocess
import tempfile
import time
from contextlib import closing
from pathlib import Path


server = Path(os.environ["SERVER_PHAR"])
plugin = Path(os.environ["PLUGIN_PHAR"])
example = Path(os.environ["EXAMPLE_PHAR"])
probe = Path(os.environ["PROBE_PHAR"])
if not server.is_file() or not plugin.is_file() or not example.is_file() or not probe.is_file():
    raise RuntimeError("Pinned server and verified producer, example and probe PHARs are required")


def run_server(root: Path, data: Path, plugins: Path, log: Path, commands: list[tuple[str, float]], probe_mode: str) -> tuple[bool, int, str]:
    with log.open("w", encoding="utf-8") as output:
        process = subprocess.Popen(
            ["php", str(server), "--no-wizard", "--disable-ansi", f"--data={data}", f"--plugins={plugins}"],
            cwd=root,
            stdin=subprocess.PIPE,
            stdout=output,
            stderr=subprocess.STDOUT,
            text=True,
            env={**os.environ, "SIMPLEECONOMY_TRANSFER_PROBE_MODE": probe_mode},
        )
        try:
            deadline = time.monotonic() + 90
            while time.monotonic() < deadline and process.poll() is None:
                if "Done (" in log.read_text(encoding="utf-8", errors="replace"):
                    break
                time.sleep(0.25)
            ready = "Done (" in log.read_text(encoding="utf-8", errors="replace")
            if ready and process.stdin is not None:
                for command, pause in commands:
                    process.stdin.write(command + "\n")
                    process.stdin.flush()
                    time.sleep(pause)
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
    return ready, exit_code, log.read_text(encoding="utf-8", errors="replace")

with tempfile.TemporaryDirectory(prefix="simpleeconomy-smoke-", dir=os.environ.get("RUNNER_TEMP")) as temporary:
    root = Path(temporary)
    data = root / "data"
    plugins = root / "plugins"
    data.mkdir()
    plugins.mkdir()
    shutil.copy2(plugin, plugins / "SimpleEconomy.phar")
    shutil.copy2(example, plugins / "SimpleEconomyExample.phar")
    shutil.copy2(probe, plugins / "SimpleEconomyTransferProbe.phar")
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
    ready, exit_code, content = run_server(root, data, plugins, log, [
        ("setmoney OfflineTester 42", 5),
        ("addmoney OfflineTester 5", 5),
        ("reducemoney OfflineTester 5", 5),
        ("setmoney OfflineTester -1", 0),
        ("addmoney OfflineTester 9223372036854775807", 0),
        ("reducemoney OfflineTester -1", 0),
        ("money OfflineTester", 0),
        ("wallet", 0),
        ("richest", 0),
        ("reward OfflineTester 1", 0),
        ("fine OfflineTester 1", 2),
    ], "success")
    errors = [line for line in content.splitlines() if "/ERROR]:" in line or "/CRITICAL]:" in line]
    databases = list(root.rglob("economy.sqlite"))
    rows: list[tuple[str, str, int]] = []
    for database in databases:
        with closing(sqlite3.connect(database)) as connection:
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
        "transfer_success": "TRANSFER_PROBE_SUCCESS" in content,
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
        or not evidence["transfer_success"]
        or not all(evidence["example_commands"].values())
        or exit_code != 0
        or errors
        or not any(name == "offlinetester" and json.loads(value).get("balance") == 42 for name, value, _ in rows)
        or not any(name == "probesender" and json.loads(value).get("balance") == 37 for name, value, _ in rows)
        or not any(name == "proberecipient" and json.loads(value).get("balance") == 13 for name, value, _ in rows)
        or 42 not in mirror_balances
        or "OfflineTester" not in content
    ):
        print(content[-12000:])
        raise RuntimeError("Isolated SimpleEconomy SQL and YAML smoke test failed")

    if len(databases) != 1:
        raise RuntimeError("Expected one isolated SQLite database")
    baseline_row = next((row for row in rows if row[0] == "offlinetester"), None)
    baseline_probe_rows = sorted(row for row in rows if row[0] in {"probesender", "proberecipient"})
    if baseline_row is None:
        raise RuntimeError("Expected the offline test account in SQLite")
    with closing(sqlite3.connect(databases[0])) as connection:
        connection.execute(
            "CREATE TRIGGER smoke_reject_balance BEFORE UPDATE ON simplesql_data "
            "WHEN NEW.id = 'offlinetester' BEGIN SELECT RAISE(ABORT, 'intentional fault probe'); END"
        )
        connection.execute(
            "CREATE TRIGGER smoke_reject_transfer BEFORE INSERT ON simplesql_data "
            "WHEN NEW.id = 'proberecipient' BEGIN SELECT RAISE(ABORT, 'intentional transfer fault probe'); END"
        )
        connection.commit()

    failure_ready, failure_exit, failure_content = run_server(root, data, plugins, root / "failure-server.log", [
        ("topmoney", 1),
        ("setmoney OfflineTester 999", 5),
        ("addmoney OfflineTester 5", 5),
        ("reducemoney OfflineTester 5", 5),
        ("topmoney", 1),
        ("money OfflineTester", 1),
    ], "failure")
    command_output = [line for line in failure_content.splitlines() if line.startswith("Command output |")]
    unexpected_errors = [
        line for line in failure_content.splitlines()
        if "/CRITICAL]:" in line or "/EMERGENCY]:" in line or (
            "/ERROR]:" in line
            and "intentional fault probe" not in line
            and "intentional transfer fault probe" not in line
            and "unsaved data was written" not in line
        )
    ]
    with closing(sqlite3.connect(databases[0])) as connection:
        failure_rows = connection.execute(
            "SELECT id, data, revision FROM simplesql_data WHERE id = 'offlinetester'"
        ).fetchall()
        failure_probe_rows = connection.execute(
            "SELECT id, data, revision FROM simplesql_data WHERE id IN ('probesender', 'proberecipient') ORDER BY id"
        ).fetchall()
    failure_evidence = {
        "ready": failure_ready,
        "exit_code": failure_exit,
        "command_output": command_output,
        "database_rows": failure_rows,
        "transfer_rollback": "TRANSFER_PROBE_ROLLBACK" in failure_content,
        "probe_rows": failure_probe_rows,
        "unexpected_errors": unexpected_errors,
    }
    print(json.dumps({"failure_probe": failure_evidence}, indent=2))
    if (
        not failure_ready
        or failure_exit != 0
        or not failure_evidence["transfer_rollback"]
        or unexpected_errors
        or sum("Failed to save data for 'OfflineTester'." in line for line in command_output) != 3
        or not any("#1 offlinetester - $42" in line for line in command_output)
        or not any("OfflineTester's balance: $42" in line for line in command_output)
        or any("$999" in line for line in command_output)
        or failure_rows != [baseline_row]
        or failure_probe_rows != baseline_probe_rows
    ):
        print(failure_content[-12000:])
        raise RuntimeError("A failed SQL save appeared as a committed balance")
