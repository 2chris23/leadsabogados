<?php
// Clear opcache and show status
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache limpiado OK";
} else {
    echo "OPcache no activo o no disponible";
}
echo " - " . date('H:i:s');
