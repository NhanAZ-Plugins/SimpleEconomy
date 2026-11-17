<?php
declare(strict_types=1);

namespace NhanAZ\EcoAPI;

final class EcoAPI{
	public static function init(): void{}
	public static function detectProvider(): ?EconomyProvider{
		return null;
	}
}
