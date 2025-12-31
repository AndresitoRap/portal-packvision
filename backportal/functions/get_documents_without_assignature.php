<?php
// require "../sap/functions.php";
// require "../ateb/generarcfd.php";
// require __DIR__ . "/../vendor/autoload.php";
// require __DIR__."/../functions/process/helpers.php";
// require "../ateb/helpers_test.php";
// use Dotenv\Dotenv;
// $dotenv = Dotenv::createImmutable(__DIR__ . "/..");
// $dotenv->load();


// // Cabeceras para SSE
// header("Content-Type: text/event-stream");
// header("Cache-Control: no-cache");
// header("Access-Control-Allow-Origin: *");

// function sendLog($msg)
// {
//     // Siempre enviamos un objeto con type
//     if (is_string($msg)) {
//         $msg = ["type" => "log", "msg" => $msg];
//     }
//     echo "data: " . json_encode($msg) . "\n\n";
//     ob_flush();
//     flush();
// }


// try {
//     sendLog("Obteniendo facturas sin firmar...");
//     $facturas = SAP::getDocument("Invoices");

//     // Añadir prefijo a cada factura
//     foreach ($facturas as &$f) {
//         $f["prefijo"] = getPrefijo((string) $f["DocNum"]);
//     }
//     unset($f);

//     sendLog("Facturas obtenidas: " . count($facturas));
//     foreach ($facturas as $f) {
//         sendLog(["type" => "factura", "data" => $f]);
//     }

//     sendLog("Obteniendo notas sin firmar...");
//     $notas = SAP::getDocument("CreditNote");

//     // Añadir prefijo a cada nota
//     foreach ($notas as &$n) {
//         $n["prefijo"] = getPrefijo((string) $n["DocNum"]);
//     }
//     unset($n);

//     sendLog("Notas obtenidas: " . count($notas));
//     foreach ($notas as $n) {
//         sendLog(["type" => "nota", "data" => $n]);
//     }


//     // ===============================
// // CARGA ÚNICA DE DATOS MAESTROS
// // ===============================
//     sendLog(["type" => "log", "msg" => "Obteniendo términos de pago SAP"]);
//     $paymentTerms = SAP::getPaymentTermsCached();

//     $paymentGroupCode = $detalle['PaymentGroupCode'] ?? null;

//     // 🔹 Crear mapa: GroupNumber => Nombre
//     $mapaPaymentTerms = [];
//     foreach ($paymentTerms as $pt) {
//         $mapaPaymentTerms[$pt['GroupNumber']] = $pt['PaymentTermsGroupName'];
//     }

//     // 🔹 Nombre real del término
//     $paymentTermName = $mapaPaymentTerms[$paymentGroupCode] ?? 'N/A';


//     sendLog(["type" => "log", "msg" => "Obteniendo vendedores"]);
//     $vendedores = SAP::getSalesPersonsCached();

//     // 🔹 Crear mapa: codigo => nombre
//     $mapaVendedores = [];
//     foreach ($vendedores as $v) {
//         $mapaVendedores[$v['SalesEmployeeCode']] = $v['SalesEmployeeName'];
//     }

//     // 🔹 Código del vendedor desde la factura SAP
//     $salesPersonCode = $detalle['SalesPersonCode'] ?? null;

//     // 🔹 Resolver nombre
//     $vendedorNombre = $mapaVendedores[$salesPersonCode] ?? 'N/A';


//     sendLog(["type" => "log", "msg" => "Obteniendo usuarios SAP"]);
//     $usuarios = SAP::getUsersCached();

//     // 🔹 Crear mapa: InternalKey => UserName
//     $mapaUsuarios = [];
//     foreach ($usuarios as $u) {
//         $mapaUsuarios[$u['InternalKey']] = $u['UserName'];
//     }

//     // 🔹 UserSign viene en la cabecera del documento
//     $userSign = $detalle['UserSign'] ?? null;

//     // 🔹 Resolver nombre del creador
//     $usuarioCreador = $mapaUsuarios[$userSign] ?? 'N/A';




//     // Detalles de facturas
//     $docEntries = array_map(fn($f) => $f["DocEntry"], $facturas);
//     foreach ($docEntries as $doc) {

//         sendLog(["type" => "log", "msg" => "Obteniendo detalle factura $doc..."]);
//         $detalle = SAP::getAllDocument("Invoices", $doc);
//         sendLog([
//             "type" => "detalle",
//             "DocEntry" => $doc,
//             "msg" => "Detalle recibido para $doc"
//         ]);

//         $prefijoDoc = getPrefijo((string) $detalle["DocNum"]);

//         // 🔴 CASO FPRA → no se firma, se marca y se continúa
//         if ($prefijoDoc === "FPRA" || $prefijoDoc === "FSRA" || $prefijoDoc === "SALDOS") {

//             sendLog([
//                 "type" => "log",
//                 "msg" => "✅ Factura {$prefijoDoc}, firmada correctamente"
//             ]);

//             SAP::change_U_Filtro_SAP($doc, "3", true); // o el estado que definas

//             sendLog([
//                 "type" => "success",
//                 "DocEntry" => $doc,
//                 "msg" => "Factura {$prefijoDoc} marcada automáticamente (sin firma)"
//             ]);

//             continue; // ⛔ salta todo el proceso de XML + ATEB
//         }
//         if ($prefijoDoc === "SALDOS") {

//             sendLog([
//                 "type" => "log",
//                 "msg" => "✅ Factura {$prefijoDoc}, firmada correctamente"
//             ]);

//             SAP::change_U_Filtro_SAP($doc, "4", true); // o el estado que definas

//             sendLog([
//                 "type" => "success",
//                 "DocEntry" => $doc,
//                 "msg" => "Factura {$prefijoDoc} marcada automáticamente (sin firma)"
//             ]);

//             continue; // ⛔ salta todo el proceso de XML + ATEB
//         }

//         sendLog(["type" => "log", "msg" => "Obteniendo información del socio de negocio"]);
//         $bussinesPartner = SAP::getBusinessPartners($detalle["CardCode"]);

//         $cardCodeNumeric = preg_replace('/\D/', '', $bussinesPartner['CardCode']);
//         $hcoRaw = SAP::getHCO(preg_replace('/\D/', '', $bussinesPartner['CardCode']));
//         $hco = $hcoRaw['value'][0];

//         $prefijo = "SETT";
//         $folio = getNextTestFolio($prefijo, 1500);

//         sendLog([
//             "type" => "log",
//             "msg" => "Usando folio de prueba {$prefijo}{$folio}"
//         ]);


//         sendLog(["type" => "log", "msg" => "Generando XML desde SAP..."]);
//         $resultado = ATEB::buildXMLFromSAP(
//             $detalle,
//             $bussinesPartner,
//             $paymentTermName,
//             "10",
//             $hco,
//             $prefijo,
//             $folio
//         );

//         $xml = $resultado["xml"];
//         $contexto = $resultado["contexto"];
//         //Se inyecta el vendedor al contexto
//         $contexto['vendedor'] = $vendedorNombre;
//         $contexto['usuario_creador'] = $usuarioCreador;

//         sendLog(["type" => "log", "msg" => "Enviando XML a ATEB..."]);
//         $res = ATEB::generarCFD(
//             $_ENV['ENTERPRISE'],
//             $_ENV['USERATEB'],
//             $_ENV['PASSWORDATEB'],
//             $xml,
//             "02",
//             "",
//             $contexto
//         );
//         if ($res["ok"]) {
//             SAP::change_U_Filtro_SAP($doc, "1", true);
//             sendLog(["type" => "log", "msg" => "✅ Factura firmada correctamente."]);
//             sendLog([
//                 "type" => "success",
//                 "docType" => "FACTURA",
//                 "DocEntry" => $doc,
//                 "msg" => "Factura firmada correctamente"
//             ]);

//         } else {
//             SAP::change_U_Filtro_SAP($doc, "2", true);
//             sendLog([
//                 "type" => "error",
//                 "docType" => "FACTURA",
//                 "DocEntry" => $doc,
//                 "msg" => "Error al firmar factura: " . $res["mensaje"]
//             ]);

//         }

//     }

//     // Detalles de notas
//     $noteEntries = array_map(fn($n) => $n["DocEntry"], $notas);
//     foreach ($noteEntries as $not) {
//         sendLog(["type" => "log", "msg" => "Obteniendo detalle nota $not..."]);
//         $detalle = SAP::getAllDocument("CreditNote", $not);
//         sendLog([
//             "type" => "detalle",
//             "DocEntry" => $not,
//             "msg" => "Detalle recibido para $not"
//         ]);


//         $prefijoDoc = getPrefijo((string) $detalle["DocNum"]);

//         // 🔴 CASO FPRA → no se firma, se marca y se continúa
//         if ($prefijoDoc === "FPRA" || $prefijoDoc === "FSRA") {

//             sendLog([
//                 "type" => "log",
//                 "msg" => "✅ Nota crédito {$prefijoDoc}, firmada correctamente"
//             ]);

//             SAP::change_U_Filtro_SAP($not, "3", false);

//             sendLog([
//                 "type" => "success",
//                 "docType" => "NOTA",
//                 "DocEntry" => $not,
//                 "msg" => "Nota crédito {$prefijoDoc} marcada automáticamente (sin firma)"
//             ]);

//             continue;
//         }

//         if ($prefijoDoc === "SALDOS") {

//             sendLog([
//                 "type" => "log",
//                 "msg" => "✅ Nota crédito {$prefijoDoc}, firmada correctamente"
//             ]);

//             SAP::change_U_Filtro_SAP($not, "4", false);

//             sendLog([
//                 "type" => "success",
//                 "docType" => "NOTA",
//                 "DocEntry" => $not,
//                 "msg" => "Nota crédito {$prefijoDoc} marcada automáticamente (sin firma)"
//             ]);

//             continue;
//         }

//         sendLog(["type" => "log", "msg" => "Obteniendo información del socio de negocio"]);
//         $bussinesPartner = SAP::getBusinessPartners($detalle["CardCode"]);
//         // Asignación directa del PaymentGroupCode según el nombre del término de pago
//         sendLog(["type" => "log", "msg" => "Obteniendo términos de pago SAP"]);
//         $paymentTerms = SAP::getPaymentTermsCached();

//         $paymentGroupCode = $detalle['PaymentGroupCode'] ?? null;

//         // 🔹 Crear mapa: GroupNumber => Nombre
//         $mapaPaymentTerms = [];
//         foreach ($paymentTerms as $pt) {
//             $mapaPaymentTerms[$pt['GroupNumber']] = $pt['PaymentTermsGroupName'];
//         }

//         // 🔹 Nombre real del término
//         $paymentTermName = $mapaPaymentTerms[$paymentGroupCode] ?? 'N/A';


//         $cardCodeNumeric = preg_replace('/\D/', '', $bussinesPartner['CardCode']);
//         $hcoRaw = SAP::getHCO(preg_replace('/\D/', '', $bussinesPartner['CardCode']));
//         $hco = $hcoRaw['value'][0];

//         sendLog(["type" => "log", "msg" => "Obteniendo vendedores"]);
//         $vendedores = SAP::getSalesPersonsCached();

//         // 🔹 Crear mapa: codigo => nombre
//         $mapaVendedores = [];
//         foreach ($vendedores as $v) {
//             $mapaVendedores[$v['SalesEmployeeCode']] = $v['SalesEmployeeName'];
//         }

//         // 🔹 Código del vendedor desde la factura SAP
//         $salesPersonCode = $detalle['SalesPersonCode'] ?? null;

//         // 🔹 Resolver nombre
//         $vendedorNombre = $mapaVendedores[$salesPersonCode] ?? 'N/A';

//         sendLog(["type" => "log", "msg" => "Obteniendo usuarios SAP"]);
//         $usuarios = SAP::getUsersCached();

//         // 🔹 Crear mapa: InternalKey => UserName
//         $mapaUsuarios = [];
//         foreach ($usuarios as $u) {
//             $mapaUsuarios[$u['InternalKey']] = $u['UserName'];
//         }

//         // 🔹 UserSign viene en la cabecera del documento
//         $userSign = $detalle['UserSign'] ?? null;

//         // 🔹 Resolver nombre del creador
//         $usuarioCreador = $mapaUsuarios[$userSign] ?? 'N/A';


//         $prefijo = "SETT";
//         $folio = getNextTestFolio($prefijo, 1500);

//         sendLog([
//             "type" => "log",
//             "msg" => "Usando folio de prueba {$prefijo}{$folio}"
//         ]);


//         sendLog(["type" => "log", "msg" => "Generando XML desde SAP..."]);
//         $resultado = ATEB::buildXMLFromSAP(
//             $detalle,
//             $bussinesPartner,
//             $paymentTermName,
//             "20",
//             $hco,
//             $prefijo,
//             $folio
//         );

//         $xml = $resultado["xml"];
//         $contexto = $resultado["contexto"];
//         //Se inyecta el vendedor al contexto
//         $contexto['vendedor'] = $vendedorNombre;
//         $contexto['usuario_creador'] = $usuarioCreador;

//         sendLog(["type" => "log", "msg" => "Enviando XML a ATEB..."]);
//         $res = ATEB::generarCFD(
//             $_ENV['ENTERPRISE'],
//             $_ENV['USERATEB'],
//             $_ENV['PASSWORDATEB'],
//             $xml,
//             "02",
//             "",
//             $contexto
//         );
//         if ($res["ok"]) {
//             SAP::change_U_Filtro_SAP($not, "1", false);
//             sendLog(["type" => "log", "msg" => "✅ Nota crédito firmada correctamente."]);
//             sendLog([
//                 "type" => "success",
//                 "docType" => "NOTA",
//                 "DocEntry" => $not,
//                 "msg" => "Nota crédito firmada correctamente"
//             ]);
//         } else {
//             SAP::change_U_Filtro_SAP($not, "2", false);
//             sendLog([
//                 "type" => "error",
//                 "docType" => "NOTA",
//                 "DocEntry" => $not,
//                 "msg" => "Error al firmar nota crédito: " . $res["mensaje"]
//             ]);

//         }
//     }

//     sendLog([
//         "type" => "finished",
//         "msg" => "Proceso de firmado completado"
//     ]);


//     exit;

// } catch (Exception $e) {
//     sendLog(["type" => "log", "msg" => "Error: " . $e->getMessage()]);
// }

