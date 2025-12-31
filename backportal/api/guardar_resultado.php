<?php
require_once __DIR__ . "/../db/connection.php";

header("Content-Type: application/json");

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(["ok" => false, "msg" => "JSON inválido"]);
    exit;
}

$pdo = Connection::connect();

$sql = "
INSERT INTO documentos_firma
(tipo, doc_entry, prefijo, folio, cufe, estado, mensaje_error, pdf_url)
VALUES (:tipo,:docEntry,:prefijo,:folio,:cufe,:estado,:mensaje,:pdf)
ON DUPLICATE KEY UPDATE
    estado = VALUES(estado),
    mensaje_error = VALUES(mensaje_error),
    pdf_url = VALUES(pdf_url),
    cufe = VALUES(cufe),
    updated_at = NOW()
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ":tipo"     => $data['tipo'],
    ":docEntry" => $data['docEntry'],
    ":prefijo"  => $data['prefijo'],
    ":folio"    => $data['folio'],
    ":cufe"     => $data['cufe'],
    ":estado"   => $data['estado'],
    ":mensaje"  => $data['mensaje_error'] ?? null,
    ":pdf"      => $data['pdf_url'] ?? null
]);

echo json_encode(["ok" => true]);
