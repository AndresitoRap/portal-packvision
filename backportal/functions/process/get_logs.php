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


// header("Content-Type: application/json");
// header("Access-Control-Allow-Origin: *");

// $logFile = __DIR__ . "/../../storage/logs/firma_documentos.log";

// if (!file_exists($logFile)) {
//     echo json_encode([
//         "ok" => true,
//         "logs" => []
//     ]);
//     exit;
// }

// $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

// // últimos 200 eventos
// $lines = array_slice($lines, -200);

// // decodificar JSON línea por línea
// $logs = array_values(array_filter(array_map(function ($l) {
//     $j = json_decode($l, true);
//     return is_array($j) ? $j : null;
// }, $lines)));

// echo json_encode([
//     "ok" => true,
//     "logs" => $logs
// ], JSON_UNESCAPED_UNICODE);


