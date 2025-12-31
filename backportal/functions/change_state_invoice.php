<?php
require __DIR__ . "/../vendor/autoload.php";
require __DIR__ . "/../sap/functions.php";

use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__ . "/..");
$dotenv->load();

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input["docNum"], $input["isInvoice"])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "msg" => "Datos incompletos"]);
    exit;
}

$docNum = (int)$input["docNum"];
$isInvoice = (bool)$input["isInvoice"];

try {
    // 1️⃣ DocNum → DocEntry
    $docEntry = SAP::getDocEntryByDocNum($docNum, $isInvoice);

    // 2️⃣ PATCH por DocEntry
    $res = SAP::patchFiltroByDocEntry(
        $docEntry,
        $isInvoice,
        "0"
    );

    echo json_encode([
        "ok" => true,
        "docNum" => $docNum,
        "docEntry" => $docEntry,
        "tipo" => $isInvoice ? "Factura" : "Nota"
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "msg" => $e->getMessage()
    ]);
}
