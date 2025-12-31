<?php

function getPrefijo(string $docNum): string
{
    $num = (int) $docNum;

    if ($num >= 1000000 && $num < 2000000) return "FEON";
    if ($num >= 2000000 && $num < 3000000) return "FEOC";
    if ($num >= 3000000 && $num < 4000000) return "FPRA";
    if ($num >= 4000000 && $num < 5000000) return "FSRA";
    if ($num >= 5000000 && $num < 6000000) return "FEPR";

    return "SALDOS";
}
