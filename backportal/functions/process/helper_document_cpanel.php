<?php

function subirPdfACpanel(string $rutaPdf): ?string
{
    if (!file_exists($rutaPdf)) return null;

    $ch = curl_init("https://portal.empaquespackvision.com/api/subir_pdf.php");

    $post = [
        "pdf" => new CURLFile($rutaPdf, "application/pdf", basename($rutaPdf))
    ];

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_TIMEOUT => 30
    ]);

    $resp = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($resp, true);
    return $json['url'] ?? null;
}

function guardarResultadoEnCpanel(array $data): void
{
    $ch = curl_init("https://portal.empaquespackvision.com/api/guardar_resultado.php");

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => 15
    ]);

    curl_exec($ch);
    curl_close($ch);
}
