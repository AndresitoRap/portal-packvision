<?php
require __DIR__ . "/../vendor/autoload.php";
use Dompdf\Dompdf;

function buildHTMLFactura(array $data)
{
    $html = file_get_contents(__DIR__ . "/../templates/factura.html");
    $css  = file_get_contents(__DIR__ . "/../templates/factura.css");

    $html = str_replace("</head>", "<style>$css</style></head>", $html);

    foreach ($data as $key => $value) {
        $html = str_replace("{{{$key}}}", $value, $html);
    }

    return $html;
}

function createPDF($title, $html)
{
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('chroot', realpath(__DIR__ . "/../"));

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $dir = __DIR__ . "/../pdf/";
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }

    $path = $dir . "$title.pdf";
    file_put_contents($path, $dompdf->output());

    return $path;
}
