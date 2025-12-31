<?php
header("Content-Type: application/json");
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

$input = json_decode(file_get_contents("php://input"), true);
$password = $input['password'] ?? '';

$ADMIN_PASSWORD = $_ENV['ADMIN_PASSWORD'] ?? 'Packvision2025!';

if ($password !== $ADMIN_PASSWORD) {
    echo json_encode(["ok" => false]);
    exit;
}

echo json_encode(["ok" => true]);
