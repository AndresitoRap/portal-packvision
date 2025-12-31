<?php

function getNextTestFolio(string $prefijo, int $inicio = 1500, bool $reset = false): int
{
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $file = $dir . "/folio_test_{$prefijo}.txt";

    if ($reset || !file_exists($file)) {
        file_put_contents($file, $inicio);
        return $inicio;
    }

    $folio = (int) trim(file_get_contents($file));
    $folio++;

    file_put_contents($file, $folio);
    return $folio;
}



