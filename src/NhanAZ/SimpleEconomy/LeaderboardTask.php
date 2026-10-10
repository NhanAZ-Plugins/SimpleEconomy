<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy;

use pocketmine\scheduler\AsyncTask;

/**
 * Asynchronous task that scans YAML mirror files to rebuild the leaderboard cache.
 *
 * Runs on an async worker thread to avoid blocking the main thread.
 * Only the top N entries (by balance) are kept to bound memory usage.
 */
class LeaderboardTask extends AsyncTask {

	public function __construct(
		private readonly string $yamlPath,
		private readonly int $limit,
	) {}

	public function onRun(): void {
		if (!is_dir($this->yamlPath)) {
			$this->setResult([]);
			return;
		}

		$entries = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->yamlPath, \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			/** @var \SplFileInfo $file */
			if ($file->getExtension() !== "yml") {
				continue;
			}

			$content = @file_get_contents($file->getPathname());
			if ($content === false) {
				continue;
			}

			$data = @yaml_parse($content);
			if (!is_array($data) || !isset($data["data"]) || !is_array($data["data"])) {
				continue;
			}
			$balance = $data["data"]["balance"] ?? null;
			if (!is_int($balance) || $balance < 0) {
				continue;
			}

			$name = strtolower(pathinfo($file->getFilename(), PATHINFO_FILENAME));
			$entries[$name] = $balance;

			// Keep array bounded: if over 2x limit, trim to limit
			if (count($entries) > $this->limit * 2) {
				arsort($entries);
				$entries = array_slice($entries, 0, $this->limit, true);
			}
		}

		// Final sort + trim
		arsort($entries);
		$entries = array_slice($entries, 0, $this->limit, true);

		$this->setResult($entries);
	}

	public function onCompletion(): void {
		$plugin = Main::getInstance();
		if ($plugin === null) {
			return;
		}

		$result = $this->getResult();
		if (!is_array($result)) {
			return;
		}

		foreach ($result as $name => $balance) {
			if (is_int($balance) && $balance >= 0) {
				$plugin->updateBalanceCache((string) $name, $balance);
			}
		}
	}
}
