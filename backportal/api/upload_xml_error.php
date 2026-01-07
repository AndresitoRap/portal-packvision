
<?php
header("Content-Type: application/json");

// 🔐 Seguridad
$token = $_SERVER['HTTP_X_TOKEN'] ?? '';
if ($token !== 'PACKVISION_SECURE_2025') {
    http_response_code(403);
    echo json_encode(["ok" => false, "msg" => "Unauthorized"]);
    exit;
}

if (!isset($_FILES['xml'])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "msg" => "Archivo XML no recibido"]);
    exit;
}

$uploadDir = __DIR__ . "/../storage/xml_errors/";
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$originalName = basename($_FILES['xml']['name']);
$destino = $uploadDir . $originalName;

if (!move_uploaded_file($_FILES['xml']['tmp_name'], $destino)) {
    http_response_code(500);
    echo json_encode(["ok" => false, "msg" => "No se pudo guardar el XML"]);
    exit;
}

$url = "https://portal.empaquespackvision.com/back_portal_facturacion/storage/xml_errors/" . $originalName;

echo json_encode([
    "ok"   => true,
    "url"  => $url,
    "file" => $originalName
]);
