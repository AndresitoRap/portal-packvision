<?php

function writeLog(string $type, array $payload = [])
{
    // 1️⃣ Log local (backup / auditoría)
    $logFile = __DIR__ . "/../../storage/logs/firma_documentos.log";
    @mkdir(dirname($logFile), 0775, true);

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

    // 2️⃣ Log remoto (cPanel)
    enviarLogACpanel($type, $payload);
}

function enviarLogACpanel(string $type, array $payload): void
{
    $url = "https://portal.empaquespackvision.com/back_portal_facturacion/api/guardar_log.php";

    $data = [
        "type" => $type,
        "msg" => $payload['msg'] ?? '',
        "payload" => $payload
    ];

    error_log(print_r($data));

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "X-TOKEN: PACKVISION_SECURE_2025"
        ],
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    curl_exec($ch);
    curl_close($ch);
}
