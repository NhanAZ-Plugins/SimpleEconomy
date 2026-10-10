<?php

declare(strict_types=1);

$action = $argv[1] ?? throw new RuntimeException("Pass read or fault");
$user = getenv("SIMPLEECONOMY_MYSQL_USER") ?: throw new RuntimeException("Missing MySQL user");
$password = getenv("SIMPLEECONOMY_MYSQL_PASSWORD");
if ($password === false) {
	throw new RuntimeException("Missing MySQL password");
}
$db = new PDO(
	"mysql:host=127.0.0.1;port=3306;dbname=simple_economy;charset=utf8mb4",
	$user,
	$password,
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$engine = $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'simplesql_data'")->fetchColumn();
if ($engine !== "InnoDB") {
	throw new RuntimeException("Paired writes require an existing InnoDB table");
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
} else {
	throw new RuntimeException("Unknown MySQL state action");
}
