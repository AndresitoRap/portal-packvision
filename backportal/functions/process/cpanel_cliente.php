<?php

function guardarResultadoEnCpanel(array $data): void
{
    $ch = curl_init(
        "https://portal.empaquespackvision.com/back_portal_facturacion/api/guardar_resultado.php"
    );

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "X-TOKEN: PACKVISION_SECURE_2025"
        ],
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);

    $resp = curl_exec($ch);

    if ($resp === false) {
        throw new Exception("Curl error: " . curl_error($ch));
    }

    curl_close($ch);

    $json = json_decode($resp, true);
    if (!$json || empty($json['ok'])) {
        throw new Exception("Error guardando resultado en cPanel: " . $resp);
    }
}
