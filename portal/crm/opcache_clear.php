<?php
// Show where ver.php actually is on the server
$path = realpath(__DIR__ . '/portal/crm/pages/casos/ver.php');
echo "Ruta del archivo: " . $path . "<br>";
echo "Fecha modificación: " . date('Y-m-d H:i:s', filemtime($path)) . "<br>";
// Read last 200 chars to see if it has the new code
$content = file_get_contents($path);
echo "Contiene 'Divisor': " . (strpos($content, 'Divisor') !== false ? "SI (codigo nuevo)" : "NO (codigo viejo)") . "<br>";
echo "Contiene 'Estado de Pagos' como card separada: " . (substr_count($content, 'cv-card mb-24') > 0 ? "SI (hay card vieja)" : "NO (bien unificado)");
