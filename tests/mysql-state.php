<?php

declare(strict_types=1);

$action = $argv[1] ?? throw new RuntimeException("Pass read, legacy-read, fault, seed-legacy, or create-legacy-database");
$user = getenv("SIMPLEECONOMY_MYSQL_USER") ?: throw new RuntimeException("Missing MySQL user");
$password = getenv("SIMPLEECONOMY_MYSQL_PASSWORD");
if ($password === false) {
	throw new RuntimeException("Missing MySQL password");
}
$database = getenv("SIMPLEECONOMY_MYSQL_DATABASE") ?: "simple_economy";
if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $database)) {
	throw new RuntimeException("Invalid MySQL database name");
}
if ($action === "create-legacy-database") {
	$server = new PDO("mysql:host=127.0.0.1;port=3306;charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$server->exec("CREATE DATABASE IF NOT EXISTS simple_economy_legacy CHARACTER SET utf8mb4");
	echo "Created isolated legacy migration database\n";
	exit(0);
}
$db = new PDO(
	"mysql:host=127.0.0.1;port=3306;dbname=" . $database . ";charset=utf8mb4",
	$user,
	$password,
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$tableCount = (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'simplesql_data'")->fetchColumn();
if ($action === "seed-legacy") {
	if ($database !== "simple_economy_legacy" || $tableCount !== 0) {
		throw new RuntimeException("Refusing to seed outside the empty isolated legacy database");
	}
	$db->exec("CREATE TABLE simplesql_data (id VARCHAR(128) NOT NULL PRIMARY KEY, data LONGTEXT NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 0)");
	$insert = $db->prepare("INSERT INTO simplesql_data (id, data, revision) VALUES ('codexlegacy', :data, 1)");
	$insert->execute(["data" => json_encode(["balance" => 1234], JSON_THROW_ON_ERROR)]);
	echo "Seeded the v1.0.0 MySQL table shape with one synthetic account\n";
	exit(0);
}
$engine = $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'simplesql_data'")->fetchColumn();
if ($engine !== "InnoDB") {
	throw new RuntimeException("Migration and paired-write probes require an existing InnoDB table");
}
if ($action === "fault") {
	$db->exec("CREATE TRIGGER reject_transfer_row BEFORE INSERT ON simplesql_data FOR EACH ROW BEGIN IF NEW.id = 'proberecipient' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'intentional transfer fault probe'; END IF; END");
	$db->beginTransaction();
	try {
		$db->exec("INSERT INTO simplesql_data (id, data, revision) VALUES ('proberecipient', '{\"balance\":13}', 1) ON DUPLICATE KEY UPDATE data = VALUES(data), revision = VALUES(revision)");
		$db->rollBack();
		throw new RuntimeException("The MySQL fault trigger did not reject the recipient upsert");
	} catch (PDOException $error) {
		$db->rollBack();
		if (!str_contains($error->getMessage(), "intentional transfer fault probe")) {
			throw $error;
		}
	}
	echo "Installed second-row MySQL fault trigger\n";
} elseif ($action === "read") {
	$rows = $db->query("SELECT id, data, revision FROM simplesql_data WHERE id IN ('probesender', 'proberecipient') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
	echo json_encode($rows, JSON_THROW_ON_ERROR) . "\n";
} elseif ($action === "legacy-read") {
	if ($database !== "simple_economy_legacy") {
		throw new RuntimeException("Legacy reads are restricted to the isolated migration database");
	}
	$rows = $db->query("SELECT id, data, revision FROM simplesql_data WHERE id = 'codexlegacy'")->fetchAll(PDO::FETCH_ASSOC);
	echo json_encode($rows, JSON_THROW_ON_ERROR) . "\n";
} else {
	throw new RuntimeException("Unknown MySQL state action");
}
