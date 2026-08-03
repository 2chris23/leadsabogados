<?php
// The web root on this server is portal/crm/
// So ver.php is at: __DIR__ . '/pages/casos/ver.php'
$path = realpath(__DIR__ . '/pages/casos/ver.php');
echo "Ruta del archivo: " . $path . "<br>";
if ($path && file_exists($path)) {
    echo "Fecha modificación: " . date('Y-m-d H:i:s', filemtime($path)) . "<br>";
    $content = file_get_contents($path);
    echo "Contiene 'Divisor' (codigo nuevo): " . (strpos($content, '<!-- Divisor -->') !== false ? "<b style='color:green'>SI - NUEVO</b>" : "<b style='color:red'>NO - VIEJO</b>") . "<br>";
    echo "Cards separadas ('cv-card mb-24'): " . (strpos($content, 'cv-card mb-24') !== false ? "<b style='color:red'>SI - hay card vieja</b>" : "<b style='color:green'>NO - bien unificado</b>");
} else {
    echo "<b>ARCHIVO NO ENCONTRADO</b><br>";
    echo "Directorio actual: " . __DIR__ . "<br>";
    echo "Archivos en directorio: ";
    print_r(scandir(__DIR__));
}
