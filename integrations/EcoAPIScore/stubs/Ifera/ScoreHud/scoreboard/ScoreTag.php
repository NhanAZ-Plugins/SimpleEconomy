<?php
declare(strict_types=1);

namespace Ifera\ScoreHud\scoreboard;

final class ScoreTag{
	public function __construct(string $name, string $value = ""){}
	public function getName(): string{
		return "";
	}
	public function setValue(string $value): void{}
}
