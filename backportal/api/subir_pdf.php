<?php
header("Content-Type: application/json");

// Seguridad
$token = $_SERVER['HTTP_X_TOKEN'] ?? '';
if ($token !== 'PACKVISION_SECURE_2025') {
    http_response_code(403);
    echo json_encode(["ok" => false, "msg" => "Unauthorized"]);
    exit;
}

if (!isset($_FILES['pdf'])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "msg" => "Archivo no recibido"]);
    exit;
}

$uploadDir = __DIR__ . "/../pdf/";
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// 🔑 ESTE ES EL PUNTO CLAVE
$originalName = basename($_FILES['pdf']['name']); 
$destino = $uploadDir . $originalName;

if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $destino)) {
    http_response_code(500);
    echo json_encode(["ok" => false, "msg" => "No se pudo guardar el PDF"]);
    exit;
}

// URL pública REAL del archivo
$url = "https://portal.empaquespackvision.com/back_portal_facturacion/pdf/" . $originalName;

echo json_encode([
    "ok"  => true,
    "url" => $url,
    "file"=> $originalName
]);
