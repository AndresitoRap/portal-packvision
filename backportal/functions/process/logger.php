<?php

function writeLog(string $type, array $payload = [])
{
    $logFile = __DIR__ . "/../../storage/logs/firma_documentos.log";

    $entry = [
        "time" => date("Y-m-d H:i:s"),
        "type" => $type,
        "payload" => $payload
    ];

    file_put_contents(
        $logFile,
        json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}
