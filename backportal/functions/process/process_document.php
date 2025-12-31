<?php
require __DIR__ . "/../../sap/functions.php";
require __DIR__ . "/../../ateb/generarcfd.php";
require __DIR__ . "/../../vendor/autoload.php";
require __DIR__ . "/../../ateb/helpers_test.php";
require __DIR__ . "/helpers.php";

use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__ . "/../..");
$dotenv->load();


function writeLog(string $type, array $payload = [])
{
    $logFile = __DIR__ . "/../../storage/logs/firma_documentos.log";

    // asegura carpeta
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
}


function docNumToPrefijoFolio(int $docNum): array
{
    // FEON → 1,000,000 – 1,999,999
    if ($docNum >= 1000000 && $docNum < 2000000) {
        return ["FEON", $docNum - 1000000];
    }

    // FEOC → 2,000,000 – 2,999,999
    if ($docNum >= 2000000 && $docNum < 3000000) {
        return ["FEOC", $docNum - 2000000];
    }

    // FEPR → 5,000,000 – 5,999,999
    if ($docNum >= 5000000 && $docNum < 6000000) {
        return ["FEPR", $docNum - 5000000];
    }

    // Otros (FPRA, FSRA, SALDOS, etc.)
    return ["SALDOS", $docNum];
}



function procesarDocumentos(callable $onLog = null)
{
    $log = function ($type, $data = null) use ($onLog) {

        // Normalizar evento a un array estándar
        if (is_array($type)) {
            $evtType = $type['type'] ?? 'log';
            $payload = $type;
        } else {
            $evtType = $type;
            $payload = is_array($data) ? $data : ["msg" => (string) $data];
        }

        // 1) SIEMPRE guardar historial en archivo
        writeLog($evtType, $payload);

        // 2) Si te pasaron callback (SSE o CLI), también lo emites
        if ($onLog) {
            $onLog($evtType, $payload);
        }
    };



    $log("log", "Iniciando proceso");

    $facturas = SAP::getDocument("Invoices");
    $notas = SAP::getDocument("CreditNote");

    $log(["type" => "log", "msg" => "Obteniendo vendedores"]);
    $vendedores = SAP::getSalesPersonsCached();

    // 🔹 Crear mapa: codigo => nombre
    $mapaVendedores = [];
    foreach ($vendedores as $v) {
        $mapaVendedores[$v['SalesEmployeeCode']] = $v['SalesEmployeeName'];
    }

    $log(["type" => "log", "msg" => "Obteniendo usuarios SAP"]);
    $usuarios = SAP::getUsersCached();

    // 🔹 Crear mapa: InternalKey => UserName
    $mapaUsuarios = [];
    foreach ($usuarios as $u) {
        $mapaUsuarios[$u['InternalKey']] = $u['UserName'];
    }

    $log(["type" => "log", "msg" => "Obteniendo términos de pago SAP"]);
    $paymentTerms = SAP::getPaymentTermsCached();




    try {
        $log("log", "Obteniendo facturas sin firmar...");
        $facturas = SAP::getDocument("Invoices");

        // Añadir prefijo a cada factura
        foreach ($facturas as &$f) {
            $f["prefijo"] = getPrefijo((string) $f["DocNum"]);
        }
        unset($f);

        $log("log", "Facturas obtenidas: " . count($facturas));
        foreach ($facturas as $f) {
            $log(["type" => "factura", "data" => $f]);
        }

        $log("log", "Obteniendo notas sin firmar...");
        $notas = SAP::getDocument("CreditNote");

        // Añadir prefijo a cada nota
        foreach ($notas as &$n) {
            $n["prefijo"] = getPrefijo((string) $n["DocNum"]);
        }
        unset($n);

        $log("log", "Notas obtenidas: " . count($notas));
        foreach ($notas as $n) {
            $log(["type" => "nota", "data" => $n]);
        }
        // Detalles de facturas
        $docEntries = array_map(fn($f) => $f["DocEntry"], $facturas);
        foreach ($docEntries as $doc) {

            $log(["type" => "log", "msg" => "Obteniendo detalle factura $doc..."]);
            $detalle = SAP::getAllDocument("Invoices", $doc);
            $log([
                "type" => "detalle",
                "DocEntry" => $doc,
                "msg" => "Detalle recibido para $doc"
            ]);

            // 🔹 Código del vendedor desde la factura SAP
            $salesPersonCode = $detalle['SalesPersonCode'] ?? null;

            // 🔹 Resolver nombre
            $vendedorNombre = $mapaVendedores[$salesPersonCode] ?? 'N/A';



            // 🔹 UserSign viene en la cabecera del documento
            $userSign = $detalle['UserSign'] ?? null;

            // 🔹 Resolver nombre del creador
            $usuarioCreador = $mapaUsuarios[$userSign] ?? 'N/A';



            $paymentGroupCode = $detalle['PaymentGroupCode'] ?? null;

            // 🔹 Crear mapa: GroupNumber => Nombre
            $mapaPaymentTerms = [];
            foreach ($paymentTerms as $pt) {
                $mapaPaymentTerms[$pt['GroupNumber']] = $pt['PaymentTermsGroupName'];
            }

            // 🔹 Nombre real del término
            $paymentTermName = $mapaPaymentTerms[$paymentGroupCode] ?? 'N/A';

            [$prefijoDoc, $folio] = docNumToPrefijoFolio((int) $detalle["DocNum"]);


            // 🔴 CASO FPRA → no se firma, se marca y se continúa
            if ($prefijoDoc === "FPRA" || $prefijoDoc === "FSRA" || $prefijoDoc === "SALDOS") {

                $log([
                    "type" => "log",
                    "msg" => "✅ Factura {$prefijoDoc}, firmada correctamente"
                ]);

                SAP::change_U_Filtro_SAP($doc, "3", true); // o el estado que definas

                $log([
                    "type" => "success",
                    "DocEntry" => $doc,
                    "msg" => "Factura {$prefijoDoc} marcada automáticamente (sin firma)"
                ]);

                continue; // ⛔ salta todo el proceso de XML + ATEB
            }
            if ($prefijoDoc === "SALDOS") {

                $log([
                    "type" => "log",
                    "msg" => "✅ Factura {$prefijoDoc}, firmada correctamente"
                ]);

                SAP::change_U_Filtro_SAP($doc, "4", true); // o el estado que definas

                $log([
                    "type" => "success",
                    "DocEntry" => $doc,
                    "msg" => "Factura {$prefijoDoc} marcada automáticamente (sin firma)"
                ]);

                continue; // ⛔ salta todo el proceso de XML + ATEB
            }

            $log(["type" => "log", "msg" => "Obteniendo información del socio de negocio"]);
            $bussinesPartner = SAP::getBusinessPartners($detalle["CardCode"]);

            $hcoRaw = SAP::getHCO(
                preg_replace('/[^0-9\-]/', '', $bussinesPartner['CardCode'])
            );
            $hco = $hcoRaw['value'][0];


            $log(["type" => "log", "msg" => "Generando XML desde SAP..."]);
            $resultado = ATEB::buildXMLFromSAP(
                $detalle,
                $bussinesPartner,
                $paymentTermName,
                "10",
                $hco,
                $prefijoDoc,
                $folio
            );

            $xml = $resultado["xml"];
            $contexto = $resultado["contexto"];
            //Se inyecta el vendedor al contexto
            $contexto['vendedor'] = $vendedorNombre;
            $contexto['usuario_creador'] = $usuarioCreador;

            $log(["type" => "log", "msg" => "Enviando XML a ATEB..."]);
            $res = ATEB::generarCFD(
                $_ENV['ENTERPRISE'],
                $_ENV['USERATEB'],
                $_ENV['PASSWORDATEB'],
                $xml,
                "02",
                "",
                $contexto
            );
            if ($res["ok"]) {
                SAP::change_U_Filtro_SAP($doc, "1", true);
                $log(["type" => "log", "msg" => "✅ Factura firmada correctamente."]);
                $log([
                    "type" => "success",
                    "docType" => "FACTURA",
                    "DocEntry" => $doc,
                    "msg" => "Factura firmada correctamente"
                ]);

            } else {
                ATEB::guardarXMLFallido("FACTURA", $doc, $xml);
                SAP::change_U_Filtro_SAP($doc, "2", true);
                $log([
                    "type" => "error",
                    "docType" => "FACTURA",
                    "DocEntry" => $doc,
                    "msg" => "❌ Error al firmar factura: " . $res["mensaje"]
                ]);

            }

        }

        // Detalles de notas
        $noteEntries = array_map(fn($n) => $n["DocEntry"], $notas);
        foreach ($noteEntries as $not) {
            $log(["type" => "log", "msg" => "Obteniendo detalle nota $not..."]);
            $detalle = SAP::getAllDocument("CreditNote", $not);
            $log([
                "type" => "detalle",
                "DocEntry" => $not,
                "msg" => "Detalle recibido para $not"
            ]);

            $paymentGroupCode = $detalle['PaymentGroupCode'] ?? null;

            // 🔹 Crear mapa: GroupNumber => Nombre
            $mapaPaymentTerms = [];
            foreach ($paymentTerms as $pt) {
                $mapaPaymentTerms[$pt['GroupNumber']] = $pt['PaymentTermsGroupName'];
            }

            // 🔹 Nombre real del término
            $paymentTermName = $mapaPaymentTerms[$paymentGroupCode] ?? 'N/A';

            [$prefijoDoc, $folio] = docNumToPrefijoFolio((int) $detalle["DocNum"]);


            // 🔴 CASO FPRA → no se firma, se marca y se continúa
            if ($prefijoDoc === "FPRA" || $prefijoDoc === "FSRA") {

                $log([
                    "type" => "log",
                    "msg" => "✅ Nota crédito {$prefijoDoc}, firmada correctamente"
                ]);

                SAP::change_U_Filtro_SAP($not, "3", false);

                $log([
                    "type" => "success",
                    "docType" => "NOTA",
                    "DocEntry" => $not,
                    "msg" => "Nota crédito {$prefijoDoc} marcada automáticamente (sin firma)"
                ]);

                continue;
            }

            if ($prefijoDoc === "SALDOS") {

                $log([
                    "type" => "log",
                    "msg" => "✅ Nota crédito {$prefijoDoc}, firmada correctamente"
                ]);

                SAP::change_U_Filtro_SAP($not, "4", false);

                $log([
                    "type" => "success",
                    "docType" => "NOTA",
                    "DocEntry" => $not,
                    "msg" => "Nota crédito {$prefijoDoc} marcada automáticamente (sin firma)"
                ]);

                continue;
            }

            $log(["type" => "log", "msg" => "Obteniendo información del socio de negocio"]);
            $bussinesPartner = SAP::getBusinessPartners($detalle["CardCode"]);
            // Asignación directa del PaymentGroupCode según el nombre del término de pago
            $log(["type" => "log", "msg" => "Obteniendo términos de pago SAP"]);
            $paymentTerms = SAP::getPaymentTermsCached();

            $paymentGroupCode = $detalle['PaymentGroupCode'] ?? null;

            // 🔹 Crear mapa: GroupNumber => Nombre
            $mapaPaymentTerms = [];
            foreach ($paymentTerms as $pt) {
                $mapaPaymentTerms[$pt['GroupNumber']] = $pt['PaymentTermsGroupName'];
            }

            // 🔹 Nombre real del término
            $paymentTermName = $mapaPaymentTerms[$paymentGroupCode] ?? 'N/A';


            $cardCodeNumeric = preg_replace('/\D/', '', $bussinesPartner['CardCode']);
            $hcoRaw = SAP::getHCO(preg_replace('/\D/', '', $bussinesPartner['CardCode']));
            $hco = $hcoRaw['value'][0];

            $log(["type" => "log", "msg" => "Obteniendo vendedores"]);
            $vendedores = SAP::getSalesPersonsCached();

            // 🔹 Crear mapa: codigo => nombre
            $mapaVendedores = [];
            foreach ($vendedores as $v) {
                $mapaVendedores[$v['SalesEmployeeCode']] = $v['SalesEmployeeName'];
            }

            // 🔹 Código del vendedor desde la factura SAP
            $salesPersonCode = $detalle['SalesPersonCode'] ?? null;

            // 🔹 Resolver nombre
            $vendedorNombre = $mapaVendedores[$salesPersonCode] ?? 'N/A';

            $log(["type" => "log", "msg" => "Obteniendo usuarios SAP"]);
            $usuarios = SAP::getUsersCached();

            // 🔹 Crear mapa: InternalKey => UserName
            $mapaUsuarios = [];
            foreach ($usuarios as $u) {
                $mapaUsuarios[$u['InternalKey']] = $u['UserName'];
            }

            // 🔹 UserSign viene en la cabecera del documento
            $userSign = $detalle['UserSign'] ?? null;

            // 🔹 Resolver nombre del creador
            $usuarioCreador = $mapaUsuarios[$userSign] ?? 'N/A';



            $log(["type" => "log", "msg" => "Generando XML desde SAP..."]);
            $resultado = ATEB::buildXMLFromSAP(
                $detalle,
                $bussinesPartner,
                $paymentTermName,
                "20",
                $hco,
                $prefijoDoc,
                $folio
            );

            $xml = $resultado["xml"];
            $contexto = $resultado["contexto"];
            //Se inyecta el vendedor al contexto
            $contexto['vendedor'] = $vendedorNombre;
            $contexto['usuario_creador'] = $usuarioCreador;

            $log(["type" => "log", "msg" => "Enviando XML a ATEB..."]);
            $res = ATEB::generarCFD(
                $_ENV['ENTERPRISE'],
                $_ENV['USERATEB'],
                $_ENV['PASSWORDATEB'],
                $xml,
                "02",
                "",
                $contexto
            );
            if ($res["ok"]) {
                SAP::change_U_Filtro_SAP($not, "1", false);
                $log(["type" => "log", "msg" => "✅ Nota crédito firmada correctamente."]);
                $log([
                    "type" => "success",
                    "docType" => "NOTA",
                    "DocEntry" => $not,
                    "msg" => "Nota crédito firmada correctamente"
                ]);
            } else {

                ATEB::guardarXMLFallido("NOTA", $not, $xml);
                SAP::change_U_Filtro_SAP($not, "2", false);
                $log([
                    "type" => "error",
                    "docType" => "NOTA",
                    "DocEntry" => $not,
                    "msg" => "❌ Error al firmar nota crédito: " . $res["mensaje"]
                ]);

            }
        }

        $log([
            "type" => "finished",
            "msg" => "Proceso de firmado completado"
        ]);


    } catch (Exception $e) {
        $log(["type" => "log", "msg" => "❌ Error: " . $e->getMessage()]);
    }

    return [
        "facturas" => count($facturas),
        "notas" => count($notas),
        "status" => "ok"
    ];
}
