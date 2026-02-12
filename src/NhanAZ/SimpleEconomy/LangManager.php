<?php

declare(strict_types=1);

namespace NhanAZ\SimpleEconomy;

use pocketmine\plugin\PluginBase;

/**
 * Simple multi-language manager.
 *
 * Loads messages from YAML files in resources/lang/.
 * Supports placeholder replacement via {key} syntax.
 */
class LangManager {

	/** @var array<string, string> */
	private array $messages = [];

	private string $language;

	public function __construct(PluginBase $plugin, string $language) {
		$this->language = $language;

		// Save all language files to data folder
		foreach (["eng", "vie", "kor", "rus", "spa", "ukr", "zho", "ind", "tur", "fra", "por", "deu", "jpn", "ita"] as $lang) {
			$plugin->saveResource("lang/$lang.yml", false);
		}

		// Load selected language
		$langFile = $plugin->getDataFolder() . "lang/$language.yml";
		if (!file_exists($langFile)) {
			$plugin->getLogger()->warning("Language file '$language.yml' not found, falling back to 'eng'.");
			$langFile = $plugin->getDataFolder() . "lang/eng.yml";
			$this->language = "eng";
		}

		$data = @yaml_parse_file($langFile);
		if (is_array($data)) {
			$this->messages = $this->flatten($data);
		}
	}

	/**
	 * Get a translated message with placeholder replacement.
	 *
	 * @param array<string, string> $params Placeholders like ["player" => "Steve", "amount" => "$100"]
	 */
	public function get(string $key, array $params = []): string {
		$msg = $this->messages[$key] ?? $key;
		foreach ($params as $k => $v) {
			$msg = str_replace("{" . $k . "}", (string) $v, $msg);
		}
		return $msg;
	}

	public function getLanguage(): string {
		return $this->language;
	}

	/**
	 * Flatten a nested array into dot-notation keys.
	 *
	 * @return array<string, string>
	 */
	private function flatten(array $data, string $prefix = ""): array {
		$result = [];
		foreach ($data as $key => $value) {
			$fullKey = $prefix === "" ? (string) $key : $prefix . "." . $key;
			if (is_array($value)) {
				$result = array_merge($result, $this->flatten($value, $fullKey));
			} else {
				$result[$fullKey] = (string) $value;
			}
		}
		return $result;
	}
}
