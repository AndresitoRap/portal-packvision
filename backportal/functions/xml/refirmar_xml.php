<?php
require __DIR__ . "/../../vendor/autoload.php";

// ✅ CARGAR SAP (donde está la clase SAP)
require __DIR__ . "/../../sap/functions.php";

// ✅ CARGAR ATEB
require __DIR__ . "/../../ateb/generarcfd.php";

require __DIR__ . "/../../functions/process/logger.php";

use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__ . "/../../");
$dotenv->load();

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

// 🔥 CORS PREFLIGHT
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);



if (
    empty($input['xml']) ||
    empty($input['tipo']) ||
    empty($input['docEntry']) ||
    empty($input['prefijo'])
) {
    http_response_code(400);
    echo json_encode([
        "ok" => false,
        "msg" => "Datos incompletos"
    ]);
    exit;
}


$xml = trim($input['xml']);
$tipo = $input['tipo'];
$docEntry = (int) $input['docEntry'];
$prefijo = $input['prefijo'];

// 📌 EXTRAER FOLIO DESDE XML
$xmlObj = simplexml_load_string($xml);
if (!$xmlObj || !isset($xmlObj->E01['FolioFiscal'])) {
    echo json_encode(["ok" => false, "msg" => "No se pudo extraer folio del XML"]);
    exit;
}
$folio = (string) $xmlObj->E01['FolioFiscal'];



try {

    writeLog("log", [
        "docType" => $tipo,
        "DocEntry" => $docEntry,
        "msg" => ($tipo === "FACTURA"
            ? "🔄 Factura {$prefijo}{$folio} siendo re-firmada"
            : "🔄 Nota crédito {$prefijo}{$folio} siendo re-firmada"
        )
    ]);


    $resp = ATEB::procesarXMLManual(
        $xml,
        $tipo,
        $docEntry,
        $prefijo,
        $folio
    );

    if (!$resp["ok"]) {
        writeLog("error", [
            "docType" => $tipo,
            "DocEntry" => $docEntry,
            "msg" => ($tipo === "FACTURA"
                ? "❌ Error re-firmando factura {$prefijo}{$folio}: {$resp['mensaje']}"
                : "❌ Error re-firmando nota crédito {$prefijo}{$folio}: {$resp['mensaje']}"
            )
        ]);


        echo json_encode([
            "ok" => false,
            "msg" => $resp["mensaje"],
            "codigo" => $resp["codigo"]
        ]);

        error_log($resp["mensaje"], );
        exit;
    }

    // 🔄 Actualizar SAP
    SAP::change_U_Filtro_SAP(
        $docEntry,
        "1",
        $tipo === "FACTURA"
    );


    writeLog("success", [
        "docType" => $tipo,
        "DocEntry" => $docEntry,
        "msg" => ($tipo === "FACTURA"
            ? "✅ Factura {$prefijo}{$folio} re-firmada correctamente"
            : "✅ Nota crédito {$prefijo}{$folio} re-firmada correctamente"
        )
    ]);

    echo json_encode([
        "ok" => true,
        "msg" => "Documento re-firmado correctamente",
        "cufe" => $resp["cufe"] ?? null
    ]);


} catch (Exception $e) {



    echo json_encode([
        "ok" => false,
        "msg" => $e->getMessage()
    ]);
    error_log($e->getMessage());

}
