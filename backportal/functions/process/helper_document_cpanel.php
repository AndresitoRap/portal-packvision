<?php
function logCpanel(string $msg, array $context = []): void
{
    $file = __DIR__ . "/../../storage/logs/cpanel_sync.log";
    @mkdir(dirname($file), 0775, true);

    $entry = [
        "time" => date("Y-m-d H:i:s"),
        "msg"  => $msg,
        "ctx"  => $context
    ];

    file_put_contents(
        $file,
        json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Guarda resultado en DB de cPanel
 */
function guardarResultadoEnCpanel(array $data): void
{
    logCpanel("➡️ Enviando resultado a cPanel", $data);

    $url = "https://portal.empaquespackvision.com/back_portal_facturacion/api/guardar_resultado.php";
    logCpanel("URL guardar_resultado", ["url" => $url]);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "X-TOKEN: PACKVISION_SECURE_2025"
        ],
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    logCpanel("⬅️ Respuesta guardar_resultado", [
        "http_code" => $http,
        "errno"     => $errno,
        "error"     => $error,
        "response"  => $resp
    ]);

    if ($resp === false) {
        throw new Exception("cURL error guardar_resultado: $error");
    }

    $json = json_decode($resp, true);

    if (!$json) {
        throw new Exception("Respuesta NO JSON desde guardar_resultado");
    }

    if (empty($json['ok'])) {
        throw new Exception(
            "guardar_resultado respondió ok=false: " . json_encode($json)
        );
    }

    logCpanel("✅ Resultado guardado correctamente en cPanel");
}

/**
 * Sube PDF a cPanel
 */
function subirPdfACpanel(string $rutaPdf): ?string
{
    logCpanel("➡️ Subiendo PDF a cPanel", ["archivo" => $rutaPdf]);

    if (!file_exists($rutaPdf)) {
        logCpanel("❌ PDF no existe", ["ruta" => $rutaPdf]);
        return null;
    }

    $url = "https://portal.empaquespackvision.com/back_portal_facturacion/api/subir_pdf.php";
    logCpanel("URL subir_pdf", ["url" => $url]);

    $ch = curl_init($url);

    $post = [
        'pdf' => new CURLFile($rutaPdf)
    ];

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "X-TOKEN: PACKVISION_SECURE_2025"
        ],
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    logCpanel("⬅️ Respuesta subir_pdf", [
        "http_code" => $http,
        "errno"     => $errno,
        "error"     => $error,
        "response"  => $resp
    ]);

    if ($resp === false) {
        throw new Exception("cURL error subir_pdf: $error");
    }

    $json = json_decode($resp, true);

    if (!$json) {
        throw new Exception("Respuesta NO JSON desde subir_pdf");
    }

    if (empty($json['ok'])) {
        throw new Exception(
            "subir_pdf respondió ok=false: " . json_encode($json)
        );
    }

    logCpanel("✅ PDF subido correctamente", ["url" => $json['url'] ?? null]);

    return $json['url'] ?? null;
}
