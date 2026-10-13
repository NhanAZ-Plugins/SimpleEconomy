from __future__ import annotations

import hashlib
import json
import os
import shutil
import subprocess
import tempfile
import time
from pathlib import Path


source = Path(os.environ["AXOLOTL_SOURCE"]).resolve()
plugin = Path(os.environ["PLUGIN_PHAR"]).resolve()
expected_plugin_sha256 = os.environ["PLUGIN_SHA256"]
expected_server_commit = "b16cc69974d4e805d21e6328ab77c0e17cd7116d"

if not (source / "src" / "PocketMine.php").is_file() or not (source / "vendor" / "autoload.php").is_file():
    raise RuntimeError("Pinned Axolotl-PM API-floor source and Composer dependencies are required")
server_commit = subprocess.run(
    ["git", "-C", str(source), "rev-parse", "HEAD"],
    check=True,
    capture_output=True,
    text=True,
).stdout.strip()
if server_commit != expected_server_commit:
    raise RuntimeError(f"Expected Axolotl-PM 5.0.0 source {expected_server_commit}, got {server_commit}")
plugin_sha256 = hashlib.sha256(plugin.read_bytes()).hexdigest()
if plugin_sha256 != expected_plugin_sha256:
    raise RuntimeError(f"Build output hash mismatch: expected {expected_plugin_sha256}, got {plugin_sha256}")

with tempfile.TemporaryDirectory(prefix="simpleeconomy-api-floor-", dir=os.environ.get("RUNNER_TEMP")) as temporary:
    root = Path(temporary)
    data = root / "data"
    plugins = root / "plugins"
    data.mkdir()
    plugins.mkdir()
    shutil.copy2(plugin, plugins / "SimpleEconomy.phar")
    (data / "server.properties").write_text(
        "language=eng\nserver-ip=127.0.0.1\nserver-port=19144\nenable-ipv6=off\n"
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
            [
                "php",
                str(source / "src" / "PocketMine.php"),
                "--no-wizard",
                "--disable-ansi",
                f"--data={data}",
                f"--plugins={plugins}",
            ],
            cwd=source,
            stdin=subprocess.PIPE,
            stdout=output,
            stderr=subprocess.STDOUT,
            text=True,
        )
        try:
            deadline = time.monotonic() + 120
            while time.monotonic() < deadline and process.poll() is None:
                content = log.read_text(encoding="utf-8", errors="replace")
                if "Done (" in content:
                    break
                if "/CRITICAL]:" in content or "/EMERGENCY]:" in content:
                    break
                time.sleep(0.25)

            content = log.read_text(encoding="utf-8", errors="replace")
            ready = "Done (" in content
            plugin_enabled = "Enabling SimpleEconomy v" in content and ready
            if process.poll() is None and ready and process.stdin is not None:
                process.stdin.write("stop\n")
                process.stdin.flush()
                try:
                    exit_code = process.wait(timeout=30)
                except subprocess.TimeoutExpired:
                    process.kill()
                    exit_code = process.wait()
            else:
                if process.poll() is None:
                    process.kill()
                exit_code = process.wait()
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()

    content = log.read_text(encoding="utf-8", errors="replace")
    errors = [
        line
        for line in content.splitlines()
        if any(marker in line for marker in ("/ERROR]:", "/CRITICAL]:", "/EMERGENCY]:"))
    ]
    evidence = {
        "server_commit": server_commit,
        "plugin_sha256": plugin_sha256,
        "server_ready": ready,
        "plugin_enabled": plugin_enabled,
        "exit_code": exit_code,
        "errors": errors,
    }
    print(json.dumps(evidence, separators=(",", ":")), flush=True)
    if not ready or not plugin_enabled or exit_code != 0 or errors:
        raise RuntimeError(f"Axolotl-PM 5.0.0 startup smoke failed:\n{content[-12000:]}")
