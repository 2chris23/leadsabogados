<?php
require_once 'includes/config.php';
require_once 'includes/Database.php';
$db = Database::getInstance();
$db->query("UPDATE casos SET honorarios_abogado = 0");
echo "<h1>¡Listo! Todos los casos ahora usan la tarifa configurada en el perfil del abogado.</h1>";
echo "<p>Por favor, borra este archivo (fix_honorarios.php) del servidor por seguridad.</p>";
