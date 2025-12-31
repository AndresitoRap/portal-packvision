<?php
require __DIR__ . "/../vendor/autoload.php";
require __DIR__ . "/../sap/functions.php";  // 👈 IMPORTANTE: incluir tu clase SAP

use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__ . "/..");
$dotenv->load();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

function respond($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$folio = $_GET["folio"] ?? "";
$tipo = $_GET["tipo"] ?? "";

if (!$folio)
    respond(["error" => "Falta folio"]);
if (!in_array($tipo, ["factura", "nota"]))
    respond(["error" => "Tipo inválido (use factura o nota)"]);


// ---------------------------
// 1️⃣ CONSULTAR DOCUMENTO EN SAP CON TU CLASE
// ---------------------------
try {

    // Tipo de documento
    $isInvoice = $tipo === "factura" ? "Invoices" : "CreditNotes";

    // Obtener documento por DocNum con filtro OData
    $url = $_ENV["URLSAP"] . "$isInvoice?\$filter=DocNum%20eq%20$folio";
    $response = SAP::requestWithSession($url);

    $json = json_decode($response, true);

    if (!isset($json["value"][0])) {
        respond(["error" => "Documento no encontrado"]);
    }

    $doc = $json["value"][0];


    // ---------------------------
    // 2️⃣ OBTENER NOMBRE DEL VENDEDOR
    // ---------------------------
    $vendedores = SAP::getSalesPersonsCached();


    // convertir a mapa para lookup rápido
    $map = [];
    foreach ($vendedores as $v) {
        $map[$v["SalesEmployeeCode"]] = $v["SalesEmployeeName"];
    }

    $code = $doc["SalesPersonCode"] ?? null;
    $nombreVendedor = $map[$code] ?? "Desconocido";


    // ---------------------------
    // 3️⃣ ARMAR RESPUESTA PARA EL FRONT
    // ---------------------------
    $resp = [
        "DocNum" => $doc["DocNum"] ?? null,
        "Series" => $doc["Series"] ?? null,
        "Folio" => $doc["Folio"] ?? null,
        "CardCode" => $doc["CardCode"] ?? null,
        "CardName" => $doc["CardName"] ?? null,
        "FederalTaxID" => $doc["FederalTaxID"] ?? null,
        "SalesPerson" => $nombreVendedor,
        "DocCurrency" => $doc["DocCurrency"] ?? null,
        "DocTotal" => $doc["DocTotal"] ?? null,
        "DocDate" => $doc["DocDate"] ?? null,
    ];

    respond(["success" => true, "documento" => $resp]);

} catch (Exception $e) {
    respond(["success" => false, "error" => $e->getMessage()]);
}
