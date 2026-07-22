<?php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache reseteado.<br>";
}
if (function_exists('apcu_clear_cache')) {
    apcu_clear_cache();
    echo "APCu reseteado.<br>";
}
echo "Caché de PHP limpio exitosamente. Por favor, vuelve a cargar la página de login.";
?>
