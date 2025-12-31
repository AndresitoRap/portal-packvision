<?php
// OBTIENE LOS ARCHIVOS PDF QUE HAY EN LA CARPETA LOCAL
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Preflight OPTIONS
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

// Ruta ABSOLUTA a tu carpeta PDF
$PDF_FOLDER = __DIR__."/../pdf/";

// Verifica que exista
if (!is_dir($PDF_FOLDER)) {
    echo json_encode([
        "success" => false,
        "error" => "Carpeta PDF no encontrada: $PDF_FOLDER"
    ]);
    exit();
}

$archivos = scandir($PDF_FOLDER);

$facturas = [];
$notas = [];

// Recorrer todos los archivos PDF
foreach ($archivos as $file) {

    if (!str_ends_with(strtolower($file), ".pdf")) continue;

    // ---------------------
    // FACTURAS
    // ---------------------
    if (str_starts_with($file, "FACTURA_")) {

        // Quitar FACTURA_ y .pdf
        $nombre = str_replace(["FACTURA_", ".pdf"], "", $file);

        // Prefijo = FEPR / FEOC / FEON / ...
        // Folio = números
        preg_match("/([A-Za-z]+)(\d+)/", $nombre, $m);

        if ($m) {
            $facturas[] = [
                "tipo" => "FACTURA",
                "prefijo" => $m[1],
                "folio" => $m[2],
                "archivo" => $file,
                "url" => "http://localhost:8000/pdf/" . $file
            ];
        }
    }

    // ---------------------
    // NOTAS CRÉDITO
    // ---------------------
    if (str_starts_with($file, "NOTA_CREDITO_")) {

        $nombre = str_replace(["NOTA_CREDITO_", ".pdf"], "", $file);

        preg_match("/([A-Za-z]+)(\d+)/", $nombre, $m);

        if ($m) {
            $notas[] = [
                "tipo" => "NOTA_CREDITO",
                "prefijo" => $m[1],
                "folio" => $m[2],
                "archivo" => $file,
                "url" => "http://localhost:8000/pdf/" . $file
            ];
        }
    }
}

// Respuesta final
echo json_encode([
    "success" => true,
    "facturas" => $facturas,
    "notas" => $notas
]);
