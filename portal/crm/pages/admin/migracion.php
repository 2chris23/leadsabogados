<?php
/**
 * Herramienta de migración — Solo accesible para Administradores
 * URL: ?page=admin/migracion
 */
if (!RoleGuard::esAdmin()) {
    http_response_code(403);
    die('<h2>Acceso denegado</h2>');
}

$db  = Database::getInstance();
$pdo = $db->getConnection();
$ok  = [];
$err = [];

function colExiste2($pdo, $tabla, $col) {
    try {
        $s = $pdo->query("SHOW COLUMNS FROM `$tabla` LIKE '$col'");
        return $s->rowCount() > 0;
    } catch (Throwable $e) { return false; }
}
function tablaExiste2($pdo, $tabla) {
    try {
        $s = $pdo->query("SHOW TABLES LIKE '$tabla'");
        return $s->rowCount() > 0;
    } catch (Throwable $e) { return false; }
}

function addCol($pdo, $tabla, $col, $def, &$ok, &$err) {
    if (!colExiste2($pdo, $tabla, $col)) {
        try {
            $pdo->exec("ALTER TABLE `$tabla` ADD COLUMN `$col` $def");
            $ok[] = "✅ $tabla.$col añadida";
        } catch (Throwable $e) {
            $err[] = "❌ $tabla.$col — " . $e->getMessage();
        }
    } else {
        $ok[] = "⏭️ $tabla.$col ya existe";
    }
}

// ── casos ────────────────────────────────────────────────────────────────────
addCol($pdo, 'casos', 'tipo_caso',        "VARCHAR(100) DEFAULT 'General'", $ok, $err);
addCol($pdo, 'casos', 'tipo_pago_cliente',"VARCHAR(30) DEFAULT 'pago_unico'", $ok, $err);
addCol($pdo, 'casos', 'frecuencia_pago',  "VARCHAR(30) DEFAULT 'mensual'", $ok, $err);
addCol($pdo, 'casos', 'honorarios_abogado', "DECIMAL(10,2) DEFAULT 0", $ok, $err);
addCol($pdo, 'casos', 'bono_abogado',       "DECIMAL(10,2) DEFAULT 0", $ok, $err);
addCol($pdo, 'casos', 'tipo_pago_abogado',  "VARCHAR(20) DEFAULT 'fijo'", $ok, $err);
addCol($pdo, 'casos', 'cuota_abogado',       "DECIMAL(10,2) DEFAULT 0", $ok, $err);

// ── documentos ───────────────────────────────────────────────────────────────
addCol($pdo, 'documentos', 'ruta_storage', "VARCHAR(500) DEFAULT NULL AFTER ruta", $ok, $err);
addCol($pdo, 'documentos', 'hash_archivo', "VARCHAR(64) DEFAULT NULL", $ok, $err);
addCol($pdo, 'documentos', 'nombre_original', "VARCHAR(255) DEFAULT NULL", $ok, $err);

// ── pagos_programados ────────────────────────────────────────────────────────
if (!tablaExiste2($pdo, 'pagos_programados')) {
    try {
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
    } catch (Throwable $e) {
        $err[] = "❌ pagos_programados — " . $e->getMessage();
    }
} else {
    $ok[] = "⏭️ Tabla pagos_programados ya existe";
}

// ── pagos_cliente ────────────────────────────────────────────────────────────
if (!tablaExiste2($pdo, 'pagos_cliente')) {
    try {
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
    } catch (Throwable $e) {
        $err[] = "❌ pagos_cliente — " . $e->getMessage();
    }
} else {
    $ok[] = "⏭️ Tabla pagos_cliente ya existe";
}

// ── notas ────────────────────────────────────────────────────────────────────
if (!tablaExiste2($pdo, 'notas')) {
    try {
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
    } catch (Throwable $e) {
        $err[] = "❌ notas — " . $e->getMessage();
    }
} else {
    $ok[] = "⏭️ Tabla notas ya existe";
}

// ── usuarios_internos ────────────────────────────────────────────────────────
addCol($pdo, 'usuarios_internos', 'tipo_pago_predeterminado', "VARCHAR(20) DEFAULT 'mensual'", $ok, $err);
addCol($pdo, 'usuarios_internos', 'tarifa_mensual_default',   "DECIMAL(10,2) DEFAULT 0", $ok, $err);
addCol($pdo, 'usuarios_internos', 'tarifa_fija_default',      "DECIMAL(10,2) DEFAULT 0", $ok, $err);
addCol($pdo, 'usuarios_internos', 'tarifa_exito_default',     "DECIMAL(10,2) DEFAULT 0", $ok, $err);
addCol($pdo, 'usuarios_internos', 'dia_pago_mensual',         "INT DEFAULT 1", $ok, $err);

?>
<div style="max-width:700px;margin:40px auto;font-family:system-ui,sans-serif;padding:24px;background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0">
  <h2 style="color:#1e293b;margin:0 0 20px">🔧 Migración de Base de Datos</h2>

  <?php if (!empty($err)): ?>
  <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:16px;margin-bottom:16px">
    <strong style="color:#dc2626">Errores:</strong>
    <ul style="margin:8px 0 0;padding-left:20px;color:#dc2626">
      <?php foreach ($err as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:16px">
    <strong style="color:#15803d">Resultado:</strong>
    <ul style="margin:8px 0 0;padding-left:20px;color:#166534;font-size:.9rem;line-height:1.8">
      <?php foreach ($ok as $r): ?><li><?= htmlspecialchars($r) ?></li><?php endforeach; ?>
    </ul>
  </div>

  <p style="margin:20px 0 0;color:#64748b;font-size:.875rem">
    ✅ Migración completada. Esta página es segura dejarla — solo los administradores pueden verla.
  </p>
  <a href="<?= APP_URL ?>/index.php" style="display:inline-block;margin-top:12px;padding:8px 20px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none;font-size:.875rem">← Volver al CRM</a>
</div>
