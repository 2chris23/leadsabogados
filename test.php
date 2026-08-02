<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=crm;charset=utf8mb4', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $stmt = $pdo->query("SELECT s.*, u.nombre as procesada_nombre, u.apellidos as procesada_apellidos,
     (SELECT GROUP_CONCAT(CONCAT(ui.nombre, ' ', ui.apellidos) SEPARATOR ', ') FROM solicitud_asignaciones as2 JOIN usuarios_internos ui ON as2.abogado_id = ui.id WHERE as2.solicitud_id = s.id) as abogados_asignados
     FROM solicitudes s
     LEFT JOIN usuarios_internos u ON s.procesada_por = u.id
     ORDER BY s.created_at DESC");
    echo "OK: " . $stmt->rowCount();
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
