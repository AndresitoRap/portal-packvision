<?php
require_once __DIR__ . "/../db/connection.php";

header("Content-Type: application/json");

// Seguridad
$token = $_SERVER['HTTP_X_TOKEN'] ?? '';
if ($token !== 'PACKVISION_SECURE_2025') {
    http_response_code(403);
    echo json_encode(["ok" => false, "msg" => "Unauthorized"]);
    exit;
}

try {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (!$data) {
        throw new Exception("JSON inválido");
    }

    $pdo = Connection::connect();

    $sql = "
        INSERT INTO ateb_logs (
            tipo, mensaje, payload, creado_en
        ) VALUES (
            :tipo, :mensaje, :payload, NOW()
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":tipo"    => $data['type'] ?? 'log',
        ":mensaje" => $data['msg'] ?? '',
        ":payload" => json_encode($data['payload'] ?? [], JSON_UNESCAPED_UNICODE)
    ]);

    echo json_encode(["ok" => true]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "msg" => $e->getMessage()
    ]);
}
