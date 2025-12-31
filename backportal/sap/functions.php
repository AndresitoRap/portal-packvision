<?php

require __DIR__ . "/../vendor/autoload.php";
use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__ . "/..");
$dotenv->load();

class SAP
{
    /**
     * Realiza login en SAP Business One Service Layer.
     * Retorna un SessionId válido para usar como cookie.
     *
     * @return string
     * @throws Exception
     */
    static private function login()
    {
        // Credenciales desde .env
        $user = $_ENV['USERSAP'];
        $password = $_ENV['PASSWORDSAP'];
        $companyDB = $_ENV['COMPANYDBSAP'];
        $url = $_ENV['URLSAP'] . "Login";

        // Datos obligatorios para login
        $loginData = [
            "CompanyDB" => $companyDB,
            "UserName" => $user,
            "Password" => $password
        ];

        // Inicializar CURL
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        // Ejecutar petición
        $response = curl_exec($ch);

        if ($response === false) {
            throw new Exception("Error login SAP: " . curl_error($ch));
        }

        $resJson = json_decode($response, true);

        // Validar que SAP haya retornado un SessionId
        if (!isset($resJson['SessionId'])) {
            throw new Exception("Login SAP fallido: " . $response);
        }

        return $resJson['SessionId'];
    }


    /**
     * Ejecuta una petición al Service Layer.
     * Si la sesión está expirada, hace login nuevamente y reintenta la petición.
     *
     * @param string $url
     * @return string JSON response
     */
    static public function requestWithSession($url, $method = "GET", $body = null)
    {
        // Primer intento con una nueva sesión
        $sessionId = self::login();
        $response = self::curlRequest($url, $sessionId, $method, $body);


        // Verificar si la sesión está expirada o inválida
        if (self::sessionExpired($response)) {

            // Hacer login nuevamente
            $sessionId = self::login();

            // Reintentar la petición con la nueva sesión
            $response = self::curlRequest($url, $sessionId, $method, $body);

        }

        return $response;
    }


    /**
     * Ejecuta una petición GET usando un SessionId.
     *
     * @param string $url
     * @param string $sessionId
     * @return string JSON response
     */
    static private function curlRequest($url, $sessionId, $method = "GET", $body = null)
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "If-Match: *",
            "Cookie: B1SESSION=$sessionId"
        ]);

        // ✅ Método (GET, PATCH, POST, etc.)
        if ($method !== "GET") {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        // ✅ Body (PATCH / POST)
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        return curl_exec($ch);
    }



    /**
     * Determina si SAP respondió con un error indicando que la sesión está expirada.
     *
     * @param string $response JSON response
     * @return bool
     */
    static private function sessionExpired($response)
    {
        if ($response === false)
            return true;

        $json = json_decode($response, true);

        // Si no hay estructura de error → no está expirada
        if (!isset($json['error']))
            return false;

        // Extraer mensaje de error
        $msg = strtolower($json['error']['message']['value'] ?? "");

        // Palabras usadas por SAP cuando la sesión es inválida
        return (
            str_contains($msg, "session") ||
            str_contains($msg, "expired") ||
            str_contains($msg, "invalid")
        );
    }


    /**
     * Obtiene facturas o notas crédito desde SAP,
     * con filtro OData `U_filtrofacturacion eq '0'` o `U_NotaCredito eq '0'`.
     * Reintenta automáticamente si la sesión expira.
     *
     * @param string $isInvoice 'Invoices' | 'CreditNote'
     * @return array
     * @throws Exception
     */
    static public function getDocument($isInvoice)
    {
        // Seleccionar campo según tipo de documento
        $param = $isInvoice === 'Invoices'
            ? "U_filtrofacturacion"
            : "U_NotaCredito";

        $invoiceorcreditnote = $isInvoice === 'Invoices'
            ? "Invoices"
            : "CreditNotes";

        // Construcción del endpoint con filtro OData
        $url = $_ENV['URLSAP'] . "$invoiceorcreditnote?\$filter=$param%20eq%20'0'";


        // Realizar la petición con re-login automático
        $response = self::requestWithSession($url);

        $json = json_decode($response, true);

        if (!isset($json["value"])) {
            throw new Exception("Respuesta inesperada: " . $response);
        }

        // Filtrar documentos que NO tienen firma
        $sinFirmar = array_filter(
            $json["value"],
            fn($f) =>
            empty($f["SignatureInputMessage"])
        );

        // Mapear solo los campos que necesita el frontend
        return array_values(array_map(fn($f) => [
            "DocEntry" => $f["DocEntry"],
            "DocNum" => $f["DocNum"]
        ], $sinFirmar));
    }

    static public function getAllDocument($isInvoice, $DocEntry, )
    {

        $invoiceorcreditnote = $isInvoice === 'Invoices'
            ? "Invoices"
            : "CreditNotes";

        $url = $_ENV['URLSAP'] . "$invoiceorcreditnote($DocEntry)";

        $response = self::requestWithSession($url);

        $json = json_decode($response, true);
        return $json;

    }

    // OBTIENE LOS CODIGOS DE LAS SALESPERSON DE SAP CON NOMBRE
    static public function getSalesPersons()
    {
        $allResults = [];
        $skip = 0;

        do {
            $url = $_ENV['URLSAP'] .
                "SalesPersons?\$select=SalesEmployeeCode,SalesEmployeeName,Active&\$filter=Active%20eq%20'tYES'&\$skip=$skip";

            $response = self::requestWithSession($url);
            $json = json_decode($response, true);

            if (!isset($json["value"])) {
                throw new Exception("Respuesta inesperada al obtener vendedores: " . $response);
            }

            $batch = $json["value"];
            $allResults = array_merge($allResults, $batch);

            $count = count($batch);
            $skip += $count;

        } while ($count > 0);

        return $allResults;
    }

    // CACHEA LOS VENDEDORES PARA NO TENER QUE HACER LA FUNCION SIEMPRE
    static public function getSalesPersonsCached()
    {
        $cacheFile = __DIR__ . "/salespersons_cache.json";
        $cacheTime = 86400; // 1 hora

        // Si hay cache y no está vencido → usarlo
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);

            if (isset($data["timestamp"]) && (time() - $data["timestamp"]) < $cacheTime) {
                return $data["data"]; // vendedores ya guardados
            }
        }

        // Si NO hay cache → llamar a SAP
        $vendedores = self::getSalesPersons();

        // Guardar cache
        file_put_contents($cacheFile, json_encode([
            "timestamp" => time(),
            "data" => $vendedores
        ]));

        return $vendedores;
    }

    // OBTIENE TODOS LOS USUARIOS SAP (OUSR)
    static public function getUsers()
    {
        $allResults = [];
        $skip = 0;

        do {
            $url = $_ENV['URLSAP'] .
                "Users?\$select=InternalKey,UserName&\$skip=$skip";

            $response = self::requestWithSession($url);
            $json = json_decode($response, true);

            if (!isset($json["value"])) {
                throw new Exception("Respuesta inesperada al obtener usuarios SAP: " . $response);
            }

            $batch = $json["value"];
            $allResults = array_merge($allResults, $batch);

            $count = count($batch);
            $skip += $count;

        } while ($count > 0);

        return $allResults;
    }

    // CACHEA LOS USUARIOS SAP PARA NO CONSULTAR SIEMPRE
    static public function getUsersCached()
    {
        $cacheFile = __DIR__ . "/users_cache.json";
        $cacheTime = 86400; // 24 horas

        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);

            if (isset($data["timestamp"]) && (time() - $data["timestamp"]) < $cacheTime) {
                return $data["data"];
            }
        }

        $users = self::getUsers();

        file_put_contents($cacheFile, json_encode([
            "timestamp" => time(),
            "data" => $users
        ]));

        return $users;
    }




    static public function getBusinessPartners($CardCode)
    {
        $url = $_ENV["URLSAP"] . "BusinessPartners('$CardCode')";

        $response = self::requestWithSession($url);

        $json = json_decode($response, true);
        return $json;

    }

    // Función que obtiene todos los términos de pago desde SAP
    static public function getPaymentTerms()
    {
        $allResults = [];
        $skip = 0;

        do {
            $url = $_ENV['URLSAP'] .
                "PaymentTermsTypes?\$select=GroupNumber,PaymentTermsGroupName&\$skip=$skip";

            $response = self::requestWithSession($url);
            $json = json_decode($response, true);

            if (!isset($json["value"])) {
                throw new Exception("Respuesta inesperada al obtener términos de pago: " . $response);
            }

            $batch = $json["value"];
            $allResults = array_merge($allResults, $batch);

            $count = count($batch);
            $skip += $count;

        } while ($count > 0);

        return $allResults;
    }

    // Función que cachea los términos de pago para no llamar siempre a SAP
    static public function getPaymentTermsCached()
    {
        $cacheFile = __DIR__ . "/paymentterms_cache.json";
        $cacheTime = 86400; // 1 hora

        // Si hay cache y no está vencido → usarlo
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);

            if (isset($data["timestamp"]) && (time() - $data["timestamp"]) < $cacheTime) {
                return $data["data"]; // términos de pago ya guardados
            }
        }

        // Si NO hay cache → llamar a SAP
        $terms = self::getPaymentTerms();

        // Guardar cache
        file_put_contents($cacheFile, json_encode([
            "timestamp" => time(),
            "data" => $terms
        ]));

        return $terms;
    }


    static public function getHCO($code)
    {
        $url = $_ENV["URLSAP"] . "HCO_FRP1100?\$filter=contains(Code,'$code')";

        $response = self::requestWithSession($url);

        $json = json_decode($response, true);
        return $json;
    }
    static public function getRetenciones($code)
    {
        $url = $_ENV["URLSAP"] . "WithholdingTaxCodes('$code')?\$select=WTName,BaseAmount";

        $response = self::requestWithSession($url);

        $json = json_decode($response, true);
        return $json;
    }

    static public function change_U_Filtro_SAP($docentry, $number, $isInvoice)
    {
        $url = $_ENV['URLSAP'] . ($isInvoice ? "Invoices($docentry)" : "CreditNotes($docentry)");

        $body = [
            $isInvoice ? "U_filtrofacturacion" : "U_NotaCredito" => (string) $number
        ];

        $response = self::requestWithSession(
            $url,
            "PATCH",
            json_encode($body)
        );
        // ✅ CASO OK → 204 No Content → string vacío
        if ($response === "" || $response === null) {
            return [
                "ok" => true
            ];
        }

        // ❌ Si hay contenido, intentamos leer error SAP
        $json = json_decode($response, true);


        if (isset($json['error'])) {
            $msg = $json['error']['message']['value'] ?? 'Error desconocido SAP';

            return [
                "ok" => false,
                "mensaje" => $msg
            ];
        }

        return [
            "ok" => false,
            "mensaje" => "Respuesta inesperada de SAP"
        ];
    }




    static public function getDocEntryByDocNum(int $docNum, bool $isInvoice): int
    {
        $entity = $isInvoice ? "Invoices" : "CreditNotes";

        $url = $_ENV['URLSAP'] . "$entity?\$filter=DocNum%20eq%20$docNum";

        $response = self::requestWithSession($url);


        $json = json_decode($response, true);

        if (empty($json["value"])) {
            throw new Exception("Documento no encontrado: $docNum");
        }

        return (int) $json["value"][0]["DocEntry"];
    }

    static public function patchFiltroByDocEntry(
        int $docEntry,
        bool $isInvoice,
        string $valor
    ) {
        $entity = $isInvoice ? "Invoices" : "CreditNotes";
        $itemtopatch = $isInvoice ? "U_filtrofacturacion" : "U_NotaCredito";

        $url = $_ENV['URLSAP'] . "$entity($docEntry)";

        $body = [
            $itemtopatch => $valor
        ];

        $response = self::requestWithSession(
            $url,
            "PATCH",
            json_encode($body)
        );


        if ($response === "" || $response === null) {
            return ["ok" => true];
        }

        $json = json_decode($response, true);

        return [
            "ok" => false,
            "mensaje" => $json['error']['message']['value'] ?? 'Error SAP'
        ];
    }




    static public function getOrderFromInvoice(int $invoiceDocEntry): ?int
    {

        try {

            // ===============================
            // 1️⃣ FACTURA
            // ===============================
            $urlInvoice = $_ENV['URLSAP'] . "Invoices($invoiceDocEntry)";

            $invoiceRes = self::requestWithSession($urlInvoice);
            $invoice = json_decode($invoiceRes, true);

            if (empty($invoice["DocumentLines"][0]["BaseEntry"])) {
                return null;
            }

            $deliveryEntry = (int) $invoice["DocumentLines"][0]["BaseEntry"];

            // ===============================
            // 2️⃣ ENTREGA
            // ===============================
            $urlDelivery = $_ENV['URLSAP'] . "DeliveryNotes($deliveryEntry)";

            $deliveryRes = self::requestWithSession($urlDelivery);
            $delivery = json_decode($deliveryRes, true);

            if (empty($delivery["DocumentLines"][0]["BaseEntry"])) {
                return null;
            }

            $orderEntry = (int) $delivery["DocumentLines"][0]["BaseEntry"];

            // ===============================
            // 3️⃣ ORDEN DE VENTA
            // ===============================
            $urlOrder = $_ENV['URLSAP'] . "Orders($orderEntry)";

            $orderRes = self::requestWithSession($urlOrder);
            $order = json_decode($orderRes, true);

            if (empty($order["DocNum"])) {
                return null;
            }

            return (int) $order["DocNum"];

        } catch (\Throwable $e) {

            return null;
        }
    }




}

