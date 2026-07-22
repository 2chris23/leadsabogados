<?php
/**
 * Migración: Actualizar esquema para sistema financiero completo
 * Ejecutar UNA sola vez: http://localhost/portal/crm/database/migrate.php
 */

define('CRM_ROOT', dirname(__DIR__));
require_once CRM_ROOT . '/includes/config.php';
require_once CRM_ROOT . '/includes/Database.php';

$db = Database::getInstance();
$pdo = $db->getConnection();
$resultados = [];

function columnaExiste($pdo, $tabla, $columna) {
    $stmt = $pdo->query("SHOW COLUMNS FROM `$tabla` LIKE '$columna'");
    return $stmt->rowCount() > 0;
}

function tablaExiste($pdo, $tabla) {
    $stmt = $pdo->query("SHOW TABLES LIKE '$tabla'");
    return $stmt->rowCount() > 0;
}

try {
    // === usuarios_internos ===
    $cols = [
        'tipo_pago_predeterminado' => "VARCHAR(20) DEFAULT 'mensual'",
        'tarifa_mensual_default'   => "DECIMAL(10,2) DEFAULT 0",
        'tarifa_fija_default'      => "DECIMAL(10,2) DEFAULT 0",
        'tarifa_exito_default'     => "DECIMAL(10,2) DEFAULT 0",
        'dia_pago_mensual'         => "INT DEFAULT 1",
    ];
    foreach ($cols as $col => $def) {
        if (!columnaExiste($pdo, 'usuarios_internos', $col)) {
            $pdo->exec("ALTER TABLE usuarios_internos ADD COLUMN `$col` $def");
            $resultados[] = "✅ usuarios_internos.$col añadida";
        } else {
            $resultados[] = "⏭️ usuarios_internos.$col ya existe";
        }
    }

    // === casos ===
    $colsCasos = [
        'honorarios_abogado'  => "DECIMAL(10,2) DEFAULT 0",
        'tipo_pago_abogado'   => "VARCHAR(20) DEFAULT 'fijo'",
        'cuota_abogado'       => "DECIMAL(10,2) DEFAULT 0",
        'tipo_pago_cliente'   => "VARCHAR(30) DEFAULT 'unico'",
    ];
    foreach ($colsCasos as $col => $def) {
        if (!columnaExiste($pdo, 'casos', $col)) {
            $pdo->exec("ALTER TABLE casos ADD COLUMN `$col` $def");
            $resultados[] = "✅ casos.$col añadida";
        } else {
            $resultados[] = "⏭️ casos.$col ya existe";
        }
    }

    // === pagos ===
    if (!columnaExiste($pdo, 'pagos', 'tipo_pago')) {
        $pdo->exec("ALTER TABLE pagos ADD COLUMN tipo_pago VARCHAR(30) DEFAULT 'unico'");
        $resultados[] = "✅ pagos.tipo_pago añadida";
    } else {
        $resultados[] = "⏭️ pagos.tipo_pago ya existe";
    }

    // Añadir metodo domiciliado al ENUM si no existe
    // MySQL permite ampliarlo con ALTER
    try {
        $pdo->exec("ALTER TABLE pagos MODIFY COLUMN metodo_pago ENUM('efectivo','transferencia','tarjeta','cheque','domiciliado','otro') NOT NULL DEFAULT 'transferencia'");
        $resultados[] = "✅ pagos.metodo_pago actualizado (+ domiciliado)";
    } catch (Exception $e) {
        $resultados[] = "⏭️ pagos.metodo_pago ya actualizado";
    }

    // === solicitud_archivos ===
    if (!tablaExiste($pdo, 'solicitud_archivos')) {
        $pdo->exec("CREATE TABLE solicitud_archivos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            solicitud_id INT NOT NULL,
            nombre_original VARCHAR(255) NOT NULL,
            nombre_archivo VARCHAR(255) NOT NULL,
            ruta VARCHAR(500) NOT NULL,
            tipo_mime VARCHAR(100),
            tamano_bytes BIGINT,
            subido_por_cliente TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (solicitud_id) REFERENCES solicitudes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $resultados[] = "✅ Tabla solicitud_archivos creada";
    } else {
        $resultados[] = "⏭️ Tabla solicitud_archivos ya existe";
    }

    // === Super Admin ===
    $admin = $db->fetchOne("SELECT id FROM usuarios_internos WHERE email = 'admin@abogado.com'");
    if (!$admin) {
        $hash = password_hash('4T7pu*17m', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO usuarios_internos (nombre, apellidos, email, password_hash, rol, activo) 
                     VALUES ('Super', 'Admin', 'admin@abogado.com', '$hash', 'admin', 1)");
        $resultados[] = "✅ SuperAdmin creado (admin@abogado.com)";
    } else {
        $resultados[] = "⏭️ SuperAdmin ya existe";
    }

    echo "<h2>Migración Completa</h2>";
    echo "<ul>";
    foreach ($resultados as $r) {
        echo "<li>$r</li>";
    }
    echo "</ul>";
    echo "<p><strong>Puedes borrar este archivo ahora.</strong></p>";

} catch (Exception $e) {
    echo "<h2>Error</h2><pre>" . $e->getMessage() . "</pre>";
}
