<?php
require_once __DIR__ . '/../connection.php';

class CufeModel
{
    public static function guardar(
        int $docEntry,
        int $docNum,
        string $tipo,
        string $cufe,
        string $prefijo,
        int $folio
    ): void {
        $db = Connection::connect();

        $sql = "
        INSERT INTO ateb_cufe (
            doc_entry,
            doc_num,
            tipo,
            cufe,
            prefijo,
            folio
        ) VALUES (
            :docEntry,
            :docNum,
            :tipo,
            :cufe,
            :prefijo,
            :folio
        )
        ON DUPLICATE KEY UPDATE
            cufe = VALUES(cufe),
            prefijo = VALUES(prefijo),
            folio = VALUES(folio),
            fecha_creacion = NOW()
    ";


        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':docEntry' => $docEntry,
            ':docNum' => $docNum,
            ':tipo' => $tipo,
            ':cufe' => $cufe,
            ':prefijo' => $prefijo,
            ':folio' => $folio
        ]);
    }


    public static function obtenerPorDocEntry(int $docEntry): ?string
    {
        $db = Connection::connect();

        $sql = "
            SELECT cufe
            FROM ateb_cufe
            WHERE doc_entry = :docEntry
              AND tipo = 'FACTURA'
            ORDER BY fecha_creacion DESC
            LIMIT 1
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([':docEntry' => $docEntry]);

        $row = $stmt->fetch();
        return $row['cufe'] ?? null;
    }

    public static function obtenerFacturaBasePorDocEntry(int $docEntry): ?array
    {
        $db = Connection::connect();

        $sql = "
        SELECT cufe, prefijo, folio
        FROM ateb_cufe
        WHERE doc_entry = :docEntry
          AND tipo = 'FACTURA'
        ORDER BY fecha_creacion DESC
        LIMIT 1
    ";

        $stmt = $db->prepare($sql);
        $stmt->execute([':docEntry' => $docEntry]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);


        return $row ?: null;
    }

}
