<?php
require_once __DIR__ . "/../../db/connection.php";

header("Content-Type: application/json");

try {
    $pdo = Connection::connect();

    $stmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(creado_en, '%Y-%m-%d %H:%i:%s') AS time,
            tipo AS type,
            payload
        FROM ateb_logs
        ORDER BY creado_en DESC
        LIMIT 200
    ");

    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $logs = array_map(function ($row) {
        return [
            "time" => $row["time"],
            "type" => $row["type"],
            "payload" => json_decode($row["payload"], true)
        ];
    }, array_reverse($rows)); // cronológico

    echo json_encode($logs, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "msg" => $e->getMessage()
    ]);
}
