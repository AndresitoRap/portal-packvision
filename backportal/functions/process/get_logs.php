<?php
require_once __DIR__ . "/../../db/connection.php";
header("Content-Type: application/json");
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

$lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

try {
    $pdo = Connection::connect();

    $stmt = $pdo->prepare("
    SELECT
        id,
        DATE_FORMAT(creado_en, '%Y-%m-%d %H:%i:%s') AS time,
        tipo AS type,
        payload
    FROM ateb_logs
    ORDER BY id DESC
    LIMIT 200
");

$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$logs = array_map(function ($row) {
    return [
        "id" => (int)$row["id"],
        "time" => $row["time"],
        "type" => $row["type"],
        "payload" => json_decode($row["payload"], true)
    ];
}, array_reverse($rows)); // cronológico

echo json_encode([
    "ok" => true,
    "logs" => $logs
], JSON_UNESCAPED_UNICODE);


} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "msg" => $e->getMessage()
    ]);
}

