<?php
define('CRM_ROOT', __DIR__);
define('DEBUG', true);
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/Database.php';

try {
    $db = Database::getInstance();
    $res = $db->fetchAll("SELECT s.*, u.nombre as procesada_nombre, u.apellidos as procesada_apellidos, (SELECT GROUP_CONCAT(CONCAT(ui.nombre, ' ', ui.apellidos) SEPARATOR ', ') FROM solicitud_asignaciones as2 JOIN usuarios_internos ui ON as2.abogado_id = ui.id WHERE as2.solicitud_id = s.id) as abogados_asignados FROM solicitudes s LEFT JOIN usuarios_internos u ON s.procesada_por = u.id");
    echo "OK: " . count($res);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
