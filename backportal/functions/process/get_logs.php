<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

$logFile = __DIR__ . "/../../storage/logs/firma_documentos.log";

if (!file_exists($logFile)) {
    echo json_encode([]);
    exit;
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

// últimos 100 eventos
$lines = array_slice($lines, -200);

$data = array_map(fn($l) => json_decode($l, true), $lines);

echo json_encode($data);
