<?php
require __DIR__ . "/../vendor/autoload.php";

use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__ . "/..");
$dotenv->load();

class Connection
{
    public static function connect(): PDO
    {
        $host = $_ENV['DB_HOST'] ?? '';
        $db   = $_ENV['DB_NAME'] ?? '';
        $user = $_ENV['DB_USER'] ?? '';
        $pass = $_ENV['DB_PASS'] ?? '';
        $port = $_ENV['DB_PORT'] ?? 3306;

        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

        try {
            error_log("🔌 Intentando conexión BD → host=$host db=$db user=$user port=$port");

            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);

            error_log("✅ Conexión BD EXITOSA");

            return $pdo;

        } catch (PDOException $e) {
            error_log("❌ ERROR conexión BD");
            error_log("📌 Código: " . $e->getCode());
            error_log("📌 Mensaje: " . $e->getMessage());
            error_log("📌 DSN: $dsn");

            // Relanzar para que la app sepa que falló
            throw new Exception("Error conexion con bd" . $e->getCode() . ", " . $e->getMessage() . ", " . $dsn);
        }
    }
}
