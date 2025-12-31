<?php
require "process_documents.php";

header("Content-Type: text/event-stream");
header("Cache-Control: no-cache");
header("Access-Control-Allow-Origin: *");

function send($type, $data)
{
    echo "data: " . json_encode([
        "type" => $type,
        "data" => $data
    ]) . "\n\n";
    ob_flush();
    flush();
}

procesarDocumentos(function ($type, $data) {
    send($type, $data);
});

send("finished", "Proceso terminado");
