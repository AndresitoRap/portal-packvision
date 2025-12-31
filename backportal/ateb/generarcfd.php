<?php
require __DIR__ . "/../functions/create_pdf.php";
require __DIR__ . '/../db/models/cufeModel.php';
//Para el formato en letras
use Luecano\NumeroALetras\NumeroALetras;
//Para generar el qr
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__ . "/..");
$dotenv->load();


// Se define la zona horaria a Bogotá
date_default_timezone_set("America/Bogota");

//HELPER PARA EL FORMATO DE COP
function formatoMoneda(float $valor, string $moneda): string
{
    switch ($moneda) {
        case 'USD':
            return '$ ' . number_format($valor, 2, '.', ',');
        case 'COP':
        default:
            $v = number_format($valor, 2, ',', '.');
            return '$ ' . preg_replace('/(\d)(?=(\d{6})+(?!\d))/', "$1'", $v);
    }
}

function numeroALetrasMoneda(float $numero, string $moneda): string
{
    $formatter = new NumeroALetras();

    $entero = floor($numero);
    $decimal = round(($numero - $entero) * 100);

    switch ($moneda) {
        case 'USD':
            return strtoupper(
                $formatter->toMoney($entero, $decimal, 'DÓLARES AMERICANOS', 'CENTAVOS')
            );

        case 'COP':
        default:
            return strtoupper(
                $formatter->toMoney($entero, 0, 'PESOS COLOMBIANOS', '')
            );
    }
}



class ATEB
{
    static public function generarCFD(
        $empresa,
        $usuario,
        $pwd,
        $archivo,
        $tipo = '02',
        $referencia = "",
        $contexto = []
    ) {
        // $url = "https://login-test.ateb.com.co:8089/cofidi4.ws.co/cofidi4.ws.co.asmx?WSDL";
        $url = $_ENV['URLATEB'];

        // SOAP 1.1 request (lo más compatible con ATEB)
        $soapRequest = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
               xmlns:xsd="http://www.w3.org/2001/XMLSchema"
               xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <GeneraCFD xmlns="http://www.cofidi.com.co/">
      <Empresa>' . $empresa . '</Empresa>
      <Usuario>' . $usuario . '</Usuario>
      <Pwd>' . $pwd . '</Pwd>
      <Archivo>' . htmlspecialchars($archivo) . '</Archivo>
      <Tipo>' . $tipo . '</Tipo>
      <Referencia>' . $referencia . '</Referencia>
    </GeneraCFD>
  </soap:Body>
</soap:Envelope>';

        $headers = [
            "Content-Type: text/xml; charset=utf-8",
            "SOAPAction: \"http://www.cofidi.com.co/GeneraCFD\"",
            "Content-Length: " . strlen($soapRequest)
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $soapRequest);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // test env
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            return [
                "ok" => false,
                "mensaje" => "CURL Error: " . curl_error($ch),
                "codigo" => "CURL",
                "raw" => null
            ];
        }

        curl_close($ch);

        // ============================
        // PARSEO RESPUESTA SOAP
        // ============================
        try {

            error_log("este es el response del response".$response);
            $xmlResponse = simplexml_load_string($response);
            $namespaces = $xmlResponse->getNamespaces(true);

            $body = $xmlResponse->children($namespaces['soap'])->Body;
            $result = $body->children($namespaces[''])->GeneraCFDResponse->GeneraCFDResult;


            $resultStr = trim((string) $result);

            // 🔥 LIMPIEZA ATEB (CRÍTICA)
// 1️⃣ quitar wrappers tipo <Mensaje>...</Mensaje>
            $resultStr = preg_replace('/^<Mensaje>|<\/Mensaje>$/i', '', $resultStr);

            // 2️⃣ eliminar cualquier XML declaration interna
            $resultStr = preg_replace('/<\?xml.*?\?>/i', '', $resultStr);

            // 3️⃣ decode entidades HTML si vienen escapadas
            $resultStr = html_entity_decode($resultStr, ENT_QUOTES | ENT_XML1, 'UTF-8');

            // 4️⃣ trim final
            $resultStr = trim($resultStr);

            libxml_use_internal_errors(true);

            $resultXml = simplexml_load_string($resultStr);

            // ===============================
// 1️⃣ BUSCAR ERROR DIAN (XML)
// ===============================
            if ($resultXml !== false) {

                // Caso estándar
                if (isset($resultXml->RegistroDeSucesos->Log)) {
                    foreach ($resultXml->RegistroDeSucesos->Log as $log) {
                        if (strtolower((string) $log->Tipo) === 'error') {
                            self::guardarXMLFallido(
                                $contexto['tipoOperacion'] == "20" ? "NOTA" : "FACTURA",
                                $contexto['detalle']['DocEntry'],
                                $archivo
                            );

                            return [
                                "ok" => false,
                                "mensaje" => trim((string) $log->Mensaje),
                                "codigo" => (string) $log->Codigo ?: 'DIAN',
                                "raw" => null
                            ];
                        }
                    }
                }

                // Caso alterno: Log suelto
                if (isset($resultXml->Log)) {
                    foreach ($resultXml->Log as $log) {
                        if (strtolower((string) $log->Tipo) === 'error') {
                            self::guardarXMLFallido(
                                $contexto['tipoOperacion'] == "20" ? "NOTA" : "FACTURA",
                                $contexto['detalle']['DocEntry'],
                                $archivo
                            );

                            return [
                                "ok" => false,
                                "mensaje" => trim((string) $log->Mensaje),
                                "codigo" => (string) $log->Codigo ?: 'DIAN',
                                "raw" => null
                            ];
                        }
                    }
                }
            }

            // ===============================
// 2️⃣ FALLBACK: REGEX (CRÍTICO)
// ===============================
            if (preg_match('/<Tipo>\s*error\s*<\/Tipo>.*?<Mensaje>(.*?)<\/Mensaje>/si', $resultStr, $m)) {
                return [
                    "ok" => false,
                    "mensaje" => trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8')),
                    "codigo" => "DIAN",
                    "raw" => null
                ];
            }

            $detalle = $contexto["detalle"];
            $bussinesPartner = $contexto["bussinesPartner"];
            $hco = $contexto["hco"];
            $prefijo = $contexto["prefijo"] ?? null;

            $prefijosValidos = ["FEON", "FEOC", "FEPR", "SETT"];
            if (!in_array($prefijo, $prefijosValidos, true)) {
                throw new Exception("❌ Prefijo inválido para resolución DIAN: {$prefijo}");
            }

            $folio = $contexto["folio"];
            $condicionPago = $contexto['payment_term_name'] ?? 'N/A';
            $condicionPago = preg_replace('/\s*-\s*.*/', '', $condicionPago);
            $subtotal = $contexto["subtotal"];
            $iva = $contexto["iva"];
            $totalIVA = $contexto["totalIVA"];
            $vendedor = $contexto["vendedor"];
            $fechaVenc = $contexto["fechaVenc"];

            switch ($prefijo) {
                case "FEON":
                    $resolucion = "18764080835108";
                    $folioinicial = "5001";
                    $fechafolio = "02/10/24";
                    $foliofinal = "10000";
                    $vigencia = "24";
                    break;

                case "FEOC":
                    $resolucion = "18764069064201";
                    $folioinicial = "11001";
                    $fechafolio = "16/04/2024";
                    $foliofinal = "20000";
                    $vigencia = "24";
                    break;

                case "FEPR":
                    $resolucion = "18764094827346";
                    $folioinicial = "10001";
                    $fechafolio = "25/06/2025";
                    $foliofinal = "20000";
                    $vigencia = "24";
                    break;
            }

            //CUFE
            $xmlInternoStr = html_entity_decode($resultStr, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $xmlInterno = simplexml_load_string($xmlInternoStr);
            if ($xmlInterno === false) {
                throw new Exception("No se pudo parsear XML interno ATEB");
            }
            $cufe = null;
            $timbre = $xmlInterno->xpath("//*[local-name()='CFDTimbre']");

            if (!empty($timbre) && isset($timbre[0]['UUID'])) {
                $cufe = (string) $timbre[0]['UUID'];
                if ($contexto['tipoOperacion'] !== "20") {
                    $sapFolio = (int) $contexto['docNum']; // <-- AJUSTA: aquí tu número tipo 5011130
                    [$prefijo, $folio] = self::sapFolioToPrefijoFolio($sapFolio);
                    // ✅ SOLO FACTURAS
                    CufeModel::guardar(
                        $contexto['detalle']['DocEntry'],
                        $contexto['docNum'],
                        'FACTURA',
                        $cufe,
                        $prefijo,
                        $folio
                    );
                }
                $urlQR = "https://catalogo-vpfe.dian.gov.co/User/SearchDocument?DocumentKey=" . $cufe;

                $qrResult = Builder::create()
                    ->writer(new PngWriter())
                    ->data($urlQR)
                    ->size(200)
                    ->margin(5)
                    ->build();

                // Convertir a base64
                $qrBase64 = 'data:image/png;base64,' . base64_encode($qrResult->getString());
            }

            $orderDocNum = SAP::getOrderFromInvoice($detalle['DocEntry']);
            if ($contexto['moneda'] === 'USD') {
                $subtotal = (float) $detalle['BaseAmountFC'];   // USD
                $iva = (float) $detalle['VatSumFc'];             // USD
                $totalIVA = (float) $detalle['VatSumFc'];        // USD

                $retefuente = (float) ($contexto['retefuente_fc'] ?? 0);
                $reteiva = (float) ($contexto['reteiva_fc'] ?? 0);
                $reteica = (float) ($contexto['reteica_fc'] ?? 0);
            } else {
                $subtotal = (float) $detalle['BaseAmount'];      // COP
                $iva = (float) $detalle['VatSum'];                // COP
                $totalIVA = (float) $detalle['VatSum'];

                $retefuente = (float) ($contexto['retefuente'] ?? 0);
                $reteiva = (float) ($contexto['reteiva'] ?? 0);
                $reteica = (float) ($contexto['reteica'] ?? 0);
            }
            $neto = $subtotal + $iva - $retefuente - $reteiva - $reteica;

            $facturaData = [
                "NOMBRE_CLIENTE" => $bussinesPartner["CardName"],
                "IDENTIFICACION" => $bussinesPartner["FederalTaxID"],
                "DIRECCION" => $hco["U_MainAddress"],
                "TELEFONO" => $bussinesPartner["Phone1"],
                "CORREO" => $bussinesPartner["EmailAddress"],
                "FACTURA" => $prefijo . $folio,
                "FECHA_DE_EMISION" => date("Y-m-d"),
                "FECHA_DE_VENCIMIENTO" => $fechaVenc,
                "ORDEN_DE_VENTA" => $orderDocNum,
                "CONDICION_DE_PAGO" => mb_strtoupper(trim($condicionPago), 'UTF-8'),
                "DESPACHADOR" => $detalle['U_P_Despacho'],
                "COMENTARIOS" => $detalle["Comments"] ?? "",
                "SUBTOTAL" => formatoMoneda($subtotal, $contexto['moneda']),
                "IVA" => formatoMoneda($totalIVA, $contexto['moneda']),
                "RETEFUENTE" => formatoMoneda($retefuente, $contexto['moneda']),
                "RETEIVA" => formatoMoneda($reteiva, $contexto['moneda']),
                "RETEICA" => formatoMoneda($reteica, $contexto['moneda']),
                "NETO_A_PAGAR" => formatoMoneda($neto, $contexto['moneda']),
                "VALOR_TOTAL_EN_LETRAS" => numeroALetrasMoneda($subtotal, $contexto['moneda']),
                "VALOR_NETO_EN_LETRAS" => numeroALetrasMoneda($subtotal + $iva - $contexto['retefuente'] - $contexto['reteiva'] - $contexto['reteica'], $contexto['moneda']),
                "VENDEDOR" => $vendedor,
                "USUARIO_CREADOR" => mb_strtoupper($contexto['usuario_creador'] ?? 'N/A', 'UTF-8'),
                "CUFE" => $cufe,
                "QR" => $qrBase64,
                "RESOLUCION" => $resolucion,
                "PREFIJO" => $prefijo,
                "FOLIO_INICIAL" => $folioinicial,
                "FECHA_FOLIO" => $fechafolio,
                "FOLIO_FINAL" => $foliofinal,
                "VIGENCIA" => $vigencia,
                "DOCUMENTO" => $contexto['tipoOperacion'] == "20" ? "NOTA CRÉDITO" : "FACTURA"
            ];

            $itemsHtml = "";
            foreach ($detalle["DocumentLines"] as $line) {
                $itemsHtml .= "
        <tr>
            <td>{$line['ItemCode']}</td>
            <td>{$line['ItemDescription']}</td>
            <td>{$line['Quantity']}</td>
            <td>" . formatoMoneda($line['UnitPrice'], $contexto['moneda']) . "</td>
            <td>" . formatoMoneda(
                    $contexto['moneda'] === 'USD' ? $line['RowTotalFC'] : $line['LineTotal'],
                    $contexto['moneda']
                ) . "</td>
        </tr>";
            }
            $facturaData["ITEMS"] = $itemsHtml;

            $htmlFinal = buildHTMLFactura($facturaData);

            $tipoDoc = $contexto['tipoOperacion'] == "20" ? "NOTA_CREDITO" : "FACTURA";

            // 🔑 Prefijo correcto para el PDF
            if ($contexto['tipoOperacion'] == "20") {
                // NOTA CRÉDITO → usar el prefijo ORIGINAL SAP
                $pdfPrefijo = $contexto['prefijo_original'] ?? $prefijo;
                $pdfFolio = $contexto['folio_original'] ?? $folio;
            } else {
                // FACTURA normal
                $pdfPrefijo = $prefijo;
                $pdfFolio = $folio;
            }

            $rutaPdf = createPDF(
                "{$tipoDoc}_{$pdfPrefijo}{$pdfFolio}",
                $htmlFinal
            );


            $radica = self::RadicaPDF($rutaPdf, $prefijo, $folio, "01");

            if (!$radica["ok"]) {
                return [
                    "ok" => false,
                    "mensaje" => "Factura firmada pero PDF NO radicado: " . $radica["mensaje"],
                    "codigo" => "RADICA_PDF"
                ];
            }

            // ✅ ÉXITO
            return [
                "ok" => true,
                "mensaje" => "Documento firmado correctamente y PDF radicado",
                "codigo" => "OK",
                "cufe" => $cufe
            ];

        } catch (Exception $e) {
            return [
                "ok" => false,
                "mensaje" => "Error técnico al procesar respuesta ATEB : $e",
                "codigo" => "PARSE",
                "raw" => $response
            ];
        }

    }


    static public function buildXMLFromSAP(
        $detalle,
        $bussinesPartner,
        $paymentTermName,
        $tipoOperacion,
        $hco,
        $prefijo,
        $folio,
    ) {
        $prefijo_original = $prefijo;
        $folio_original = $folio;
        $baseEntry = null;
        $cufeFactura = null;
        $warningNC = null;


        if ($tipoOperacion == "20") {

            try {
                // Buscar BaseEntry
                foreach ($detalle['DocumentLines'] as $line) {
                    if (!empty($line['BaseEntry'])) {
                        $baseEntry = (int) $line['BaseEntry'];
                        break;
                    }
                }

                if (!$baseEntry) {
                    throw new Exception("Nota crédito sin documento base");
                }

                // Buscar CUFE de la factura base
                $facturaBase = CufeModel::obtenerFacturaBasePorDocEntry($baseEntry);

                if (!$facturaBase) {
                    throw new Exception("Factura base sin datos ATEB");
                }

                $cufeFactura = $facturaBase['cufe'];
                $prefijoFacturaBase = $facturaBase['prefijo'];
                $folioFacturaBase = (int) $facturaBase['folio'];
                $prefijo = $prefijoFacturaBase;
                $folio = $folioFacturaBase;

                if (!$cufeFactura) {
                    throw new Exception("Factura base sin CUFE registrado");
                }

            } catch (Exception $e) {
                // ⚠️ NO romper el flujo
                $warningNC = $e->getMessage();
            }
        }



        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><FE></FE>');

        // ===============================
        // FECHAS Y DATOS GENERALES
        // ===============================
        $fecha = substr($detalle["DocDate"], 0, 10);
        $fechaVenc = substr($detalle["DocDueDate"], 0, 10);
        $fechaAnticipo = substr($detalle["UpdateDate"], 0, 10);
        $horaActual = date("H:i:s");

        $paymentTermNameUpper = mb_strtoupper($paymentTermName, 'UTF-8');

        $formaPago = (strpos($paymentTermNameUpper, 'DE CONTADO') !== false) ? 1 : 2;

        // Días de vencimiento
        $diasVenc = ($formaPago === 1 ? 0 : 90);
        $subtotal = $detalle['BaseAmount'];
        $totalmontoconiva = 0;

        foreach ($detalle["DocumentLines"] as $line) {
            $taxCode = strtoupper(trim($line['TaxCode'] ?? ''));
            $base = (float) ($line['LineTotal'] ?? 0);

            // ✅ IVA solo si NO es IVA_SAPV
            if ($taxCode !== 'IVA_SAPV') {
                $totalmontoconiva += round($base * 0.19, 2);
            }
        }

        $iva = $totalmontoconiva;
        $sapDocNum = (int) $detalle['DocNum'];
        [$prefijo, $folio] = self::sapFolioToPrefijoFolio($sapDocNum);


        $E01 = $xml->addChild("E01");
        $E01->addAttribute("FolioInterno", $prefijo . $folio);
        $E01->addAttribute("Fecha", "$fecha $horaActual");
        $E01->addAttribute("TipoDeComprobante", $tipoOperacion == "20" ? '02' : '01');
        $E01->addAttribute("Moneda", "COP");

        $E01->addAttribute("Subtotal", "0.00");
        $E01->addAttribute("Monto", "0.00");
        $E01->addAttribute("totalBase", "0.00");
        $E01->addAttribute("subtotalTributos", "0.00");


        // $E01->addAttribute("Subtotal", $subtotal);
        // $E01->addAttribute("Monto", number_format($subtotal + $iva, 2, '.', ''));
        $E01->addAttribute("Descuento", '0.0');
        $E01->addAttribute("formaDePago", $formaPago);
        $E01->addAttribute("Serie", $prefijo);
        $E01->addAttribute("FolioFiscal", $folio);
        $E01->addAttribute("condicionesDePago", "ZZZ");
        $E01->addAttribute("fechaVencimiento", $fechaVenc);
        $E01->addAttribute("anticipo", "0");
        $E01->addAttribute("fechaAnticipo", $fechaAnticipo);
        $E01->addAttribute("redondeoAplicado", "2");
        // $E01->addAttribute("totalBase", $subtotal);
        // $E01->addAttribute("subtotalTributos", $subtotal + $iva);
        $E01->addAttribute("cargos", '0.00');
        $E01->addAttribute("totalItems", count($detalle["DocumentLines"]));
        $E01->addAttribute("tipoOperacion", $tipoOperacion);
        $E01->addAttribute("DiasVencimiento", $diasVenc);

        $cliente = $bussinesPartner['CardCode'];
        $nit = $hco['Code'];
        $dv = $hco['U_AuthDig'];
        $E02 = $xml->addChild("E02");
        $E02->addAttribute("Cliente", $nit);
        $E02->addAttribute("Tipo", $hco["U_TaxCardTypeID"]);
        $E02->addAttribute("NIT", trim($nit));
        $E02->addAttribute("RazonSocial", $bussinesPartner["CardName"]);
        $E02->addAttribute("Regimen", "49");
        $E02->addAttribute("IdentificacionID", $hco["U_DocTypeID"]);
        $E02->addAttribute("DV", trim($dv));
        $E02->addAttribute("nombreComercial", $bussinesPartner["CardName"]);
        $E02->addAttribute("obligacion", "R-99-PN");

        $E03 = $xml->addChild("E03");
        $E03->addAttribute("Calle", $hco["U_MainAddress"]);
        $E03->addAttribute("Departamento", $bussinesPartner["County"]);
        $E03->addAttribute("Municipio", $bussinesPartner["City"]);
        $E03->addAttribute("Ciudad", $bussinesPartner["City"]);
        $E03->addAttribute("Pais", $bussinesPartner["Country"]);
        $E03->addAttribute("codigoMunicipio", $hco["U_CountyCode"]);
        $E03->addAttribute("codigoDepartamento", $hco["U_DeptCode"]);
        $E03->addAttribute("nombrePais", $hco["U_Country"]);
        $E03->addAttribute("codigoLenguaje", "ES");

        $E04 = $xml->addChild("E04");
        $E04->addAttribute("TotalRetencion", number_format($detalle['WTAmount'], 2, '.', ''));
        $E04->addAttribute("TotalImpuesto", number_format($detalle['VatSum'], 2, '.', ''));

        $EBC = $xml->addChild("EBC");
        $EBC->addAttribute("Tipo", "E");
        $EBC->addAttribute("Name", $hco["Name"]);
        $EBC->addAttribute("Telephone", $bussinesPartner["Phone1"]);
        // $EBC->addAttribute("Mail", "sistemas@empaquespackvision.com");
        $EBC->addAttribute("Mail", $bussinesPartner["EmailAddress"]);

        if ($tipoOperacion == "20") {
            $EDR = $xml->addChild("EDR");
            $EDR->addAttribute("DRReferenceID", "1");
            $EDR->addAttribute("DRCode", $detalle['U_anulacion_factura']);

            $EIR = $xml->addChild("EIR");
            $EIR->addAttribute("ReferenceNumber", $folioFacturaBase);
            $EIR->addAttribute("ReferenceUUID", $cufeFactura);
            $EIR->addAttribute("ReferenceDate", "$fecha");
        }


        $counter = 1;
        $counterWithDiscount = 1;
        $totalBase = 0;
        $totalIVA = 0;
        $totalBaseIVA = 0;

        $totalRET = 0;
        $retenciones = [];


        $totalBaseDoc = 0;      // Subtotal real (todas las líneas)
        $totalBaseIVA = 0;      // SOLO líneas con IVA
        $totalIVA = 0;


        foreach ($detalle["DocumentLines"] as $line) {

            $taxCode = strtoupper(trim($line['TaxCode'] ?? ''));
            $tieneIVA = ($taxCode !== 'IVA_SAPV');

            $baseLinea = (float) $line['LineTotal'];
            $ivaLinea = $tieneIVA
                ? round((float) ($line['VatSum'] ?? $line['TaxTotal'] ?? 0), 2)
                : 0.00;

            $totalBaseDoc += $baseLinea;

            if ($tieneIVA) {
                $totalBaseIVA += $baseLinea;
                $totalIVA += $ivaLinea;
            }

            $D01 = $xml->addChild("D01");
            $D01->addAttribute("Consecutivo", $counter);
            $D01->addAttribute("Producto", $line["ItemCode"]);
            $D01->addAttribute("SKU", $line["ItemCode"]);
            $D01->addAttribute("Descripcion", htmlspecialchars($line["ItemDescription"]));
            $D01->addAttribute("Cantidad", $line['Quantity']);
            $D01->addAttribute("Precio", number_format($line['UnitPrice'], 2, '.', ''));
            $D01->addAttribute("Importe", number_format($baseLinea, 2, '.', ''));
            $D01->addAttribute("Descuento", number_format($line['UnitPrice'] - $line['Price'], 2, '.', ''));
            $D01->addAttribute(
                "ImpuestoTras",
                $tieneIVA ? number_format($ivaLinea, 3, '.', '') : "0.00"
            );
            $D01->addAttribute("TipoItemId", "999");
            $D01->addAttribute("indicadorGratuito", "False");
            $D01->addAttribute("precioReferencia", "0.00");
            $D01->addAttribute("codigoTipoPrecio", "0.00");
            $D01->addAttribute("paisOrigen", "CO");
            $D01->addAttribute("unidadMedida", "NIU");
            $D01->addAttribute("DVMandante", "");

            // ======================
// RETENCIONES (ReteFuente + ReteICA + ReteIVA)
// ======================


            // ======================
// RETENCIONES (GENÉRICO)
// ======================

            // 1) Definición de impuestos: WTCode -> DIAN
            $map = [
                // ReteFuente (ej: AFF1, AFF2, AFF...)
                'AFF' => ['tipo' => 'RETEFUENTE', 'id' => '06', 'nombre' => 'ReteFuente'],
                'AFF1' => ['tipo' => 'RETEFUENTE', 'id' => '06', 'nombre' => 'ReteFuente'],
                'AFF2' => ['tipo' => 'RETEFUENTE', 'id' => '06', 'nombre' => 'ReteFuente'],

                // ReteICA
                'AFI6' => ['tipo' => 'RETEICA', 'id' => '07', 'nombre' => 'ReteICA'],
                'AFI3' => ['tipo' => 'RETEICA', 'id' => '07', 'nombre' => 'ReteICA'],

                // ReteIVA
                'RV15' => ['tipo' => 'RETEIVA', 'id' => '05', 'nombre' => 'ReteIVA'],
            ];

            // 2) Arma un índice de retenciones activas desde SAP
            $wts = [];
            if (!empty($detalle['WithholdingTaxDataCollection'])) {
                foreach ($detalle['WithholdingTaxDataCollection'] as $wt) {

                    $wtCode = strtoupper(trim($wt['WTCode'] ?? ''));
                    $wtTotal = (float) ($wt['WTAmount'] ?? 0);
                    $baseDoc = (float) ($wt['U_HCO_BaseAmnt'] ?? 0);
                    $baseType = strtoupper(trim($wt['BaseType'] ?? 'N')); // N = base, V = IVA (según tu caso)

                    if ($wtCode === '' || $wtTotal <= 0 || $baseDoc <= 0)
                        continue;

                    // Normaliza mapeo (si viene AFF1, AFF2 etc.)
                    $mapKey = isset($map[$wtCode]) ? $wtCode : (isset($map[substr($wtCode, 0, 3)]) ? substr($wtCode, 0, 3) : null);
                    if (!$mapKey)
                        continue;

                    $def = $map[$mapKey];

                    // porcentaje REAL del doc (y lo usas para TODAS las líneas)
                    $porcentaje = round(($wtTotal / $baseDoc) * 100, 3);

                    $wts[] = [
                        'code' => $wtCode,
                        'tipo' => $def['tipo'],
                        'id' => $def['id'],
                        'nombre' => $def['nombre'],
                        'baseDoc' => $baseDoc,
                        'wtTotal' => $wtTotal,
                        'baseType' => $baseType,
                        'porcentaje' => $porcentaje,
                    ];
                }
            }

            // 3) Calcula por línea SIN romper DIAN
            if (!empty($wts)) {

                foreach ($wts as $tax) {

                    // base de esta línea según tipo base
                    // BaseType V = IVA línea, BaseType N = base línea
                    if ($tax['baseType'] === 'V') {

                        // ❌ Si no hay IVA, NO aplicar ReteIVA en esta línea
                        if (!$tieneIVA) {
                            continue;
                        }

                        $baseLineaImpuesto = (float) $ivaLinea;

                    } else {
                        // Base normal
                        $baseLineaImpuesto = (float) $baseLinea;
                    }

                    if ($baseLineaImpuesto <= 0)
                        continue;

                    // ✅ monto por fórmula DIAN (base * %)
                    $montoRET = round($baseLineaImpuesto * ($tax['porcentaje'] / 100), 2);
                    if ($montoRET <= 0)
                        continue; {

                        // DA6 línea
                        $DA6 = $D01->addChild("DA6");
                        $DA6->addAttribute("baseImpuesto", number_format($baseLineaImpuesto, 2, '.', ''));
                        $DA6->addAttribute("MontoImpuesto", number_format($montoRET, 2, '.', ''));
                        $DA6->addAttribute("PorcentajeImpuesto", number_format($tax['porcentaje'], 3, '.', ''));
                        $DA6->addAttribute("tipoImpuesto", "RET");
                        $DA6->addAttribute("IdentificacionImpuesto", $tax['id']);
                        $DA6->addAttribute("nombreImpuesto", $tax['nombre']);
                        $DA6->addAttribute("unidadBase", "1.00");
                        $DA6->addAttribute("unidadMedida", "NIU");
                        $DA6->addAttribute("tipoFactor", "T");

                        // acumuladores
                        $retLinea += $montoRET;
                        $totalRET += $montoRET;

                        // acumulado para E05 por impuesto y % (importante para que quede agrupado bien)
                        $k = $tax['tipo'] . '|' . number_format($tax['porcentaje'], 3, '.', '');
                        if (!isset($retenciones[$k])) {
                            $retenciones[$k] = [
                                'base' => 0,
                                'monto' => 0,
                                'id' => $tax['id'],
                                'nombre' => $tax['nombre'],
                                'porcentaje' => $tax['porcentaje'],
                            ];
                        }
                        $retenciones[$k]['base'] += $baseLineaImpuesto;
                        $retenciones[$k]['monto'] += $montoRET;
                    }
                }
            }

            $D01->addAttribute("ImpuestoRet", number_format($retLinea, 2, '.', ''));


            // ======================
            // IVA LÍNEA
            // ======================
            if ($tieneIVA && $ivaLinea > 0) {

                $DA6 = $D01->addChild("DA6");
                $DA6->addAttribute("baseImpuesto", number_format($baseLinea, 2, '.', ''));
                $DA6->addAttribute("MontoImpuesto", number_format($ivaLinea, 2, '.', ''));
                $DA6->addAttribute("PorcentajeImpuesto", "19");
                $DA6->addAttribute("tipoImpuesto", "TRA");
                $DA6->addAttribute("IdentificacionImpuesto", "01");
                $DA6->addAttribute("nombreImpuesto", "IVA");
            }

            // ======================
            // DESCUENTO
            // ======================
            if ($line['DiscountPercent'] != 0) {
                $DA9 = $D01->addChild("DA9");
                $DA9->addAttribute("consecutivo", $counterWithDiscount++);
                $DA9->addAttribute("IndicadorCD", "false");
                $DA9->addAttribute("CodigoCD", "3");
                $DA9->addAttribute("RazonCD", "DESCUENTO");
                $DA9->addAttribute("PorcentajeCD", $line['DiscountPercent']);
                $DA9->addAttribute("ImporteCD", number_format($line['Price'], 2, '.', ''));
                $DA9->addAttribute("tipoFactor", "T");
                $DA9->addAttribute("montoBase", number_format($line['UnitPrice'], 2, '.', ''));
            }

            $counter++;
        }



        foreach ($retenciones as $k => $data) {
            $E05 = $xml->addChild("E05");
            $E05->addAttribute("BaseImpuesto", number_format($data['base'], 2, '.', ''));

            $E05->addAttribute("MontoImpuesto", number_format($data['monto'], 2, '.', ''));
            $E05->addAttribute("PorcentajeImpuesto", number_format($data['porcentaje'], 3, '.', ''));
            $E05->addAttribute("Impuesto", "RET");
            $E05->addAttribute("IdentificacionImpuesto", $data['id']);
            $E05->addAttribute("nombreImpuesto", $data['nombre']);
            $E05->addAttribute("unidadBase", "1.00");
            $E05->addAttribute("unidadMedida", "NIU");
            $E05->addAttribute("tipoFactor", "T");
        }


        // IVA TOTAL
        if ($totalIVA > 0) {
            $E05 = $xml->addChild("E05");
            $E05->addAttribute("BaseImpuesto", number_format($totalBaseIVA, 2, '.', ''));
            $E05->addAttribute("MontoImpuesto", number_format($totalIVA, 2, '.', ''));
            $E05->addAttribute("PorcentajeImpuesto", "19");
            $E05->addAttribute("Impuesto", "TRA");
            $E05->addAttribute("IdentificacionImpuesto", "01");
            $E05->addAttribute("nombreImpuesto", "IVA");
            $E05->addAttribute("unidadBase", "1.00");
            $E05->addAttribute("unidadMedida", "NIU");
            $E05->addAttribute("tipoFactor", "T");
        }

        $E01['Subtotal'] = number_format($totalBaseDoc, 2, '.', '');
        $E01['totalBase'] = number_format($totalBaseIVA, 2, '.', '');
        $E01['subtotalTributos'] = number_format($totalBaseDoc + $totalIVA, 2, '.', '');
        $E01['Monto'] = number_format($totalBaseDoc + $totalIVA, 2, '.', '');


        $retefuente = self::sumRetByTipo($retenciones ?? [], 'RETEFUENTE'); // AFF1, AFF2...
        $reteiva = self::sumRetByTipo($retenciones ?? [], 'RETEIVA');    // RV15
        $reteica = self::sumRetByTipo($retenciones ?? [], 'RETEICA');    // AFI3, AFI6...

        error_log($xml->asXML());

        return [
            "xml" => $xml->asXML(),
            "contexto" => [
                "docNum" => $detalle["DocNum"],
                "detalle" => $detalle,
                "tipoOperacion" => $tipoOperacion,
                "bussinesPartner" => $bussinesPartner,
                "hco" => $hco,
                "prefijo" => $prefijo,
                "folio" => $folio,

                // 🔹 SOLO PARA PDF (NC)
                "prefijo_original" => $prefijo_original,
                "folio_original" => $folio_original,

                "moneda" => $detalle['DocCurrency'],
                "subtotal" => $subtotal,
                "iva" => $iva,
                "totalIVA" => $totalIVA,
                "retefuente" => $retefuente,
                "reteiva" => $reteiva,
                "reteica" => $reteica,
                "fechaVenc" => $fechaVenc,
                "payment_term_name" => $paymentTermName
            ]
        ];
    }

    static public function procesarXMLManual(string $xml, string $tipo, int $docEntry, string $prefijo, string $folio)
    {
        $isInvoice = $tipo === "FACTURA";
        $docTypeSAP = $isInvoice ? "Invoices" : "CreditNote";
        $detalle = SAP::getAllDocument($docTypeSAP, $docEntry);
        if (!$detalle) {
            throw new Exception("No se pudo obtener documento SAP");
        }
        $bussinesPartner = SAP::getBusinessPartners($detalle["CardCode"]);
        $hcoRaw = SAP::getHCO(preg_replace('/\D/', '', $bussinesPartner['CardCode']));
        $hco = $hcoRaw['value'][0];
        $paymentTerms = SAP::getPaymentTermsCached();
        $paymentGroupCode = $detalle['PaymentGroupCode'] ?? null;
        $map = [];
        foreach ($paymentTerms as $pt) {
            $map[$pt['GroupNumber']] = $pt['PaymentTermsGroupName'];
        }
        $paymentTermName = $map[$paymentGroupCode] ?? 'N/A';
        $vendedores = SAP::getSalesPersonsCached();
        $mapV = [];
        foreach ($vendedores as $v) {
            $mapV[$v['SalesEmployeeCode']] = $v['SalesEmployeeName'];
        }
        $vendedor = $mapV[$detalle['SalesPersonCode']] ?? 'N/A';
        $usuarios = SAP::getUsersCached();
        $mapU = [];
        foreach ($usuarios as $u) {
            $mapU[$u['InternalKey']] = $u['UserName'];
        }
        $usuarioCreador = $mapU[$detalle['UserSign']] ?? 'N/A';
        $contexto = ["docNum" => $detalle["DocNum"], "detalle" => $detalle, "tipoOperacion" => $isInvoice ? "10" : "20", "bussinesPartner" => $bussinesPartner, "hco" => $hco, "prefijo" => $prefijo, "folio" => $folio, "payment_term_name" => $paymentTermName, "fechaVenc" => substr($detalle["DocDueDate"], 0, 10), "subtotal" => $detalle["BaseAmount"], "iva" => $detalle["VatSum"], "totalIVA" => $detalle["VatSum"], "vendedor" => $vendedor, "usuario_creador" => $usuarioCreador, "moneda" => $detalle['DocCurrency']];

        return self::generarCFD($_ENV['ENTERPRISE'], $_ENV['USERATEB'], $_ENV['PASSWORDATEB'], $xml, "02", "", $contexto);
    }


    static public function extraerCUFE(string $xmlFirmado): ?string
    {
        $xml = simplexml_load_string($xmlFirmado);
        if ($xml === false) {
            return null;
        }

        // Namespace-safe
        $timbre = $xml->xpath("//*[local-name()='CFDTimbre']");

        if (!empty($timbre) && isset($timbre[0]['UUID'])) {
            return (string) $timbre[0]['UUID'];
        }

        return null;
    }

    static public function RadicaPDF(
        string $rutaPdf,
        string $prefijo,
        string $folio,
        string $tipo = "01"
    ) {
        if (empty($rutaPdf) || !file_exists($rutaPdf)) {
            return [
                "ok" => false,
                "mensaje" => "PDF no encontrado para radicar",
                "codigo" => "PDF_NOT_FOUND"
            ];
        }

        $url = $_ENV['URLATEB'];

        $pdfBase64 = base64_encode(file_get_contents($rutaPdf));
        $anio = (int) date('Y');

        try {
            $soapRadica = new SoapClient($url);

            $paramsPDF = [
                "Empresa" => $_ENV['ENTERPRISE'],
                "Usuario" => $_ENV['USERATEB'],
                "Pwd" => $_ENV['PASSWORDATEB'],
                "NoDocumento" => $prefijo . $folio,
                "TipoDocumento" => $tipo,
                "Periodo" => $anio,
                "ArchivoBase64" => $pdfBase64
            ];

            $respPDF = $soapRadica->__soapCall("RadicaPDF", [$paramsPDF]);

            // Validar respuesta ATEB
            if (isset($respPDF->RadicaPDFResult)) {

                $result = trim((string) $respPDF->RadicaPDFResult);

                // 👉 CASO ÉXITO SIMPLE
                if (strtoupper($result) === 'OK') {
                    return [
                        "ok" => true,
                        "mensaje" => "PDF radicado correctamente en ATEB"
                    ];
                }

                // 👉 CASO XML (cuando hay errores)
                if (str_starts_with($result, '<')) {
                    $xmlResp = simplexml_load_string($result);

                    if (isset($xmlResp->RegistroDeSucesos->Log)) {
                        foreach ($xmlResp->RegistroDeSucesos->Log as $log) {
                            if (strtolower((string) $log->Tipo) === 'error') {
                                return [
                                    "ok" => false,
                                    "mensaje" => (string) $log->Mensaje,
                                    "codigo" => (string) $log->Codigo
                                ];
                            }
                        }
                    }
                }

                // 👉 CUALQUIER OTRA RESPUESTA
                return [
                    "ok" => false,
                    "mensaje" => "Respuesta inesperada ATEB: " . $result,
                    "codigo" => "ATEB_UNKNOWN"
                ];
            }


            return [
                "ok" => true,
                "mensaje" => "PDF radicado correctamente en ATEB"
            ];

        } catch (SoapFault $e) {
            return [
                "ok" => false,
                "mensaje" => $e->getMessage(),
                "codigo" => "SOAP_RADICA_PDF"
            ];
        }
    }

    function obtenerBaseEntryDesdeSAP(array $detalle): int
    {
        if (empty($detalle['DocumentLines'])) {
            throw new Exception("Documento sin líneas, no se puede determinar BaseEntry");
        }

        foreach ($detalle['DocumentLines'] as $line) {
            if (!empty($line['BaseEntry'])) {
                return (int) $line['BaseEntry'];
            }
        }

        throw new Exception("No se encontró BaseEntry en las líneas del documento");
    }


    static public function sumRetByTipo(array $retenciones, string $tipo): float
    {
        $sum = 0.0;
        foreach ($retenciones as $k => $data) {
            // $k = "RETEFUENTE|2.500" etc
            if (strpos($k, $tipo . '|') === 0) {
                $sum += (float) ($data['monto'] ?? 0);
            }
        }
        return round($sum, 2);
    }


    static public function sapFolioToPrefijoFolio(int $sapFolio): array
    {
        // FEON 100000–1999999
        if ($sapFolio >= 100000 && $sapFolio <= 1999999) {
            $prefijo = "FEON";
            // si es 1,000,000+ entonces le restas 1,000,000; si no, 100,000
            $folio = ($sapFolio >= 1000000) ? ($sapFolio - 1000000) : ($sapFolio - 100000);
            return [$prefijo, $folio];
        }

        // FEOC 2,000,000–2,999,999
        if ($sapFolio >= 2000000 && $sapFolio <= 2999999) {
            $prefijo = "FEOC";
            $folio = ($sapFolio >= 2000000) ? ($sapFolio - 2000000) : ($sapFolio - 200000); // por seguridad
            return [$prefijo, $folio];
        }

        // FEPR 5,000,000–5,999,999
        if ($sapFolio >= 5000000 && $sapFolio <= 5999999) {
            return ["FEPR", $sapFolio - 5000000];
        }

        // Fallback
        return ["", $sapFolio];
    }



    static public function guardarXMLFallido(
        string $tipo,      // FACTURA | NOTA
        int $docEntry,
        string $xml
    ): string {

        $baseDir = __DIR__ . "/../storage/xml_errors";

        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0775, true);
        }

        $file = "{$baseDir}/{$tipo}_{$docEntry}.xml";

        file_put_contents(
            $file,
            $xml,
            LOCK_EX
        );

        return $file;
    }



}