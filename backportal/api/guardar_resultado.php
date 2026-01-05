<?php

require_once __DIR__ . "/../db/connection.php";

header("Content-Type: application/json");

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// LOG de entrada
error_log("📥 guardar_resultado.php INVOCADO");

// Seguridad
$token = $_SERVER['HTTP_X_TOKEN'] ?? '';
if ($token !== 'PACKVISION_SECURE_2025') {
    error_log("⛔ Token inválido");
    http_response_code(403);
    echo json_encode(["ok" => false, "msg" => "Unauthorized"]);
    exit;
}

try {
    $raw = file_get_contents("php://input");
    error_log("RAW INPUT: " . $raw);

    $data = json_decode($raw, true);
    if (!$data) {
        throw new Exception("JSON inválido");
    }

    error_log("JSON OK: " . json_encode($data));

    $pdo = Connection::connect();

    $required = ['docEntry', 'docNum', 'tipo', 'cufe', 'prefijo', 'folio'];
    foreach ($required as $key) {
        if (!isset($data[$key])) {
            throw new Exception("Campo faltante: $key");
        }
    }

    $sql = "
        INSERT INTO ateb_cufe (
            doc_entry, doc_num, tipo, cufe, prefijo, folio
        ) VALUES (
            :docEntry, :docNum, :tipo, :cufe, :prefijo, :folio
        )
        ON DUPLICATE KEY UPDATE
            cufe = VALUES(cufe),
            prefijo = VALUES(prefijo),
            folio = VALUES(folio),
            fecha_creacion = NOW()
    ";

    error_log("SQL PREPARE");

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ":docEntry" => $data['docEntry'],
        ":docNum"   => $data['docNum'],
        ":tipo"     => $data['tipo'],
        ":cufe"     => $data['cufe'],
        ":prefijo"  => $data['prefijo'],
        ":folio"    => $data['folio'],
    ]);

    error_log("✅ INSERT OK");

    echo json_encode([
        "ok" => true,
        "msg" => "Registro guardado correctamente"
    ]);
    exit;

} catch (Throwable $e) {

    error_log("❌ guardar_resultado ERROR");
    error_log($e->getMessage());
    error_log($e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        "ok"  => false,
        "msg" => $e->getMessage()
    ]);
    exit;
}
