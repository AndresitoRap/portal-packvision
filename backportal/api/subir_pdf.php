<?php
header("Content-Type: application/json");

$destino = __DIR__ . "/../pdf/";
@mkdir($destino, 0775, true);

if (!isset($_FILES['pdf'])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "msg" => "PDF no recibido"]);
    exit;
}

$nombre = uniqid("FACT_") . ".pdf";
move_uploaded_file($_FILES['pdf']['tmp_name'], $destino . $nombre);

echo json_encode([
    "ok" => true,
      "url" => "https://portal.empaquespackvision.com/public_html/back_portal_facturacion/pdf/$nombre"

]);
