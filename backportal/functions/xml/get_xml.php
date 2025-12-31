<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

$tipo = $_GET['tipo'] ?? null;
$docEntry = $_GET['docEntry'] ?? null;

if (!$tipo || !$docEntry) {
    http_response_code(400);
    echo json_encode(["ok" => false, "msg" => "Parámetros incompletos"]);
    exit;
}

$tipo = strtoupper($tipo);
$docEntry = (int) $docEntry;

$baseDir = __DIR__ . "/../../storage/xml_errors";
$file = "{$baseDir}/{$tipo}_{$docEntry}.xml";

if (!file_exists($file)) {
    http_response_code(404);
    echo json_encode(["ok" => false, "msg" => "XML no encontrado"]);
    exit;
}

echo json_encode([
    "ok" => true,
    "xml" => file_get_contents($file)
]);
