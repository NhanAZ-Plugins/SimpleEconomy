from __future__ import annotations

import json
import os
import shutil
import subprocess
import tempfile
import time
from pathlib import Path


server = Path(os.environ["SERVER_PHAR"])
plugin = Path(os.environ["PLUGIN_PHAR"])
probe = Path(os.environ["PROBE_PHAR"])
for required in (server, plugin, probe):
    if not required.is_file():
        raise RuntimeError(f"Missing verified PHAR: {required}")


def database(action: str) -> object:
    result = subprocess.run(
        ["php", "tests/mysql-state.php", action],
        check=True,
        capture_output=True,
        text=True,
    )
    return json.loads(result.stdout) if action == "read" else result.stdout.strip()


def run_server(root: Path, data: Path, plugins: Path, mode: str) -> str:
    marker = "TRANSFER_PROBE_SUCCESS" if mode == "success" else "TRANSFER_PROBE_ROLLBACK"
    log = root / f"mysql-{mode}.log"
    with log.open("w", encoding="utf-8") as output:
        process = subprocess.Popen(
            ["php", str(server), "--no-wizard", "--disable-ansi", f"--data={data}", f"--plugins={plugins}"],
            cwd=root,
            stdin=subprocess.PIPE,
            stdout=output,
            stderr=subprocess.STDOUT,
            text=True,
            env={**os.environ, "SIMPLEECONOMY_TRANSFER_PROBE_MODE": mode},
        )
        try:
            deadline = time.monotonic() + 90
            while time.monotonic() < deadline and process.poll() is None:
                content = log.read_text(encoding="utf-8", errors="replace")
                if "Done (" in content and marker in content:
                    break
                time.sleep(0.25)
            content = log.read_text(encoding="utf-8", errors="replace")
            if "Done (" not in content or marker not in content:
                raise RuntimeError(f"MySQL {mode} server did not reach ready and probe state:\n{content[-12000:]}")
            if process.stdin is not None:
                process.stdin.write("stop\n")
                process.stdin.flush()
            if process.wait(timeout=30) != 0:
                raise RuntimeError(f"MySQL {mode} server did not stop cleanly")
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()
    content = log.read_text(encoding="utf-8", errors="replace")
    unexpected = [
        line for line in content.splitlines()
        if "/CRITICAL]:" in line or "/EMERGENCY]:" in line or (
            "/ERROR]:" in line and "intentional transfer fault probe" not in line
        )
    ]
    if unexpected:
        raise RuntimeError(f"Unexpected MySQL {mode} server errors: {unexpected}")
    return content


with tempfile.TemporaryDirectory(prefix="simpleeconomy-mysql-", dir=os.environ.get("RUNNER_TEMP")) as temporary:
    root = Path(temporary)
    data = root / "data"
    plugins = root / "plugins"
    config_dir = plugins / "SimpleEconomy"
    data.mkdir()
    config_dir.mkdir(parents=True)
    shutil.copy2(plugin, plugins / "SimpleEconomy.phar")
    shutil.copy2(probe, plugins / "SimpleEconomyTransferProbe.phar")
    (config_dir / "config.yml").write_text(
        "config-version: 1\nlanguage: eng\ndatabase:\n  type: mysql\n"
        "  mysql:\n    host: 127.0.0.1\n    username: root\n"
        "    password: simplesql-ci-only\n    schema: simple_economy\n  worker-limit: 1\n",
        encoding="utf-8",
    )
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

    run_server(root, data, plugins, "success")
    before = database("read")
    balances = {row["id"]: json.loads(row["data"])["balance"] for row in before}
    if balances != {"proberecipient": 13, "probesender": 37}:
        raise RuntimeError(f"MySQL paired success did not persist both balances: {before}")

    database("fault")
    run_server(root, data, plugins, "failure")
    after = database("read")
    if after != before:
        raise RuntimeError(f"MySQL paired failure changed a row: before={before}, after={after}")
    print(json.dumps({"mysql_engine": "InnoDB", "success_balances": balances, "second_row_fault_rollback": True}))
