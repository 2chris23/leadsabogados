<?php
/**
 * Migración: Añadir columnas financieras que faltan en casos
 * Ejecutar UNA sola vez desde el servidor
 */
define('CRM_ROOT', dirname(__DIR__));
require_once CRM_ROOT . '/includes/config.php';
require_once CRM_ROOT . '/includes/Database.php';

$db  = Database::getInstance();
$pdo = $db->getConnection();
$ok  = [];

function colExiste($pdo, $tabla, $col) {
    $s = $pdo->query("SHOW COLUMNS FROM `$tabla` LIKE '$col'");
    return $s->rowCount() > 0;
}
function tablaExiste($pdo, $tabla) {
    $s = $pdo->query("SHOW TABLES LIKE '$tabla'");
    return $s->rowCount() > 0;
}

try {
    // ── casos ────────────────────────────────────────────────────────────────
    $colsCasos = [
        'tipo_caso'        => "VARCHAR(100) DEFAULT 'General'",
        'frecuencia_pago'  => "VARCHAR(30) DEFAULT 'mensual'",
    ];
    foreach ($colsCasos as $col => $def) {
        if (!colExiste($pdo, 'casos', $col)) {
            $pdo->exec("ALTER TABLE casos ADD COLUMN `$col` $def");
            $ok[] = "✅ casos.$col añadida";
        } else {
            $ok[] = "⏭️  casos.$col ya existe";
        }
    }

    // Asegurar que tipo_pago_cliente existe y tiene los valores correctos
    if (!colExiste($pdo, 'casos', 'tipo_pago_cliente')) {
        $pdo->exec("ALTER TABLE casos ADD COLUMN `tipo_pago_cliente` VARCHAR(30) DEFAULT 'pago_unico'");
        $ok[] = "✅ casos.tipo_pago_cliente añadida";
    } else {
        $ok[] = "⏭️  casos.tipo_pago_cliente ya existe";
    }

    // ── pagos_programados ─────────────────────────────────────────────────────
    if (!tablaExiste($pdo, 'pagos_programados')) {
        $pdo->exec("CREATE TABLE pagos_programados (
            id INT AUTO_INCREMENT PRIMARY KEY,
            caso_id INT NOT NULL,
            monto DECIMAL(10,2) NOT NULL,
            fecha_vencimiento DATE NOT NULL,
            estado ENUM('pendiente','pagado','vencido') NOT NULL DEFAULT 'pendiente',
            pagado_en DATETIME DEFAULT NULL,
            notas VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (caso_id) REFERENCES casos(id) ON DELETE CASCADE,
            INDEX idx_caso (caso_id),
            INDEX idx_estado (estado),
            INDEX idx_fecha (fecha_vencimiento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ok[] = "✅ Tabla pagos_programados creada";
    } else {
        $ok[] = "⏭️  Tabla pagos_programados ya existe";
    }

    // ── pagos_cliente (alias pagos) ───────────────────────────────────────────
    if (!tablaExiste($pdo, 'pagos_cliente')) {
        // Crear vista o tabla propia
        $pdo->exec("CREATE TABLE pagos_cliente (
            id INT AUTO_INCREMENT PRIMARY KEY,
            caso_id INT NOT NULL,
            monto DECIMAL(10,2) NOT NULL,
            fecha DATE NOT NULL,
            metodo VARCHAR(50) DEFAULT 'transferencia',
            notas TEXT DEFAULT NULL,
            registrado_por INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (caso_id) REFERENCES casos(id) ON DELETE CASCADE,
            INDEX idx_caso (caso_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ok[] = "✅ Tabla pagos_cliente creada";
    } else {
        $ok[] = "⏭️  Tabla pagos_cliente ya existe";
    }

    // ── documentos: añadir ruta_storage si no existe ─────────────────────────
    if (!colExiste($pdo, 'documentos', 'ruta_storage')) {
        $pdo->exec("ALTER TABLE documentos ADD COLUMN `ruta_storage` VARCHAR(500) DEFAULT NULL AFTER ruta");
        $ok[] = "✅ documentos.ruta_storage añadida";
    } else {
        $ok[] = "⏭️  documentos.ruta_storage ya existe";
    }
    if (!colExiste($pdo, 'documentos', 'hash_archivo')) {
        $pdo->exec("ALTER TABLE documentos ADD COLUMN `hash_archivo` VARCHAR(64) DEFAULT NULL");
        $ok[] = "✅ documentos.hash_archivo añadida";
    } else {
        $ok[] = "⏭️  documentos.hash_archivo ya existe";
    }

    // ── notas ─────────────────────────────────────────────────────────────────
    if (!tablaExiste($pdo, 'notas')) {
        $pdo->exec("CREATE TABLE notas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            caso_id INT NOT NULL,
            tipo ENUM('publica','interna') NOT NULL DEFAULT 'publica',
            titulo VARCHAR(255) DEFAULT NULL,
            contenido TEXT NOT NULL,
            created_by INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (caso_id) REFERENCES casos(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES usuarios_internos(id) ON DELETE SET NULL,
            INDEX idx_caso (caso_id),
            INDEX idx_tipo (tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ok[] = "✅ Tabla notas creada";
    } else {
        $ok[] = "⏭️  Tabla notas ya existe";
    }

    echo "<h2 style='font-family:sans-serif;color:#1e293b'>✅ Migración Completada</h2><ul style='font-family:monospace'>";
    foreach ($ok as $r) echo "<li>$r</li>";
    echo "</ul><p style='font-family:sans-serif;color:#dc2626'>Puedes borrar este archivo ahora.</p>";

} catch (Throwable $e) {
    echo "<h2>Error</h2><pre>" . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine() . "</pre>";
}
