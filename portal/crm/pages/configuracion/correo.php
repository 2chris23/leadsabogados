<?php
/**
 * CRM Abogados — Configuración de Correo Electrónico
 * SMTP + Diseñador visual de plantilla de email
 */
$db = Database::getInstance();

// ── Migración silenciosa: asegurar columnas necesarias ───────────────────────
$emailCampos = [
    'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_from_name',
    'email_color_primario', 'email_color_boton', 'email_color_fondo',
    'email_color_tarjeta', 'email_color_texto', 'email_color_pie',
    'email_logo_url', 'email_pie_texto',
    'email_notif_registro', 'email_notif_solicitud', 'email_notif_nota', 'email_notif_documento',
    'email_subj_registro', 'email_body_registro',
    'email_subj_solicitud', 'email_body_solicitud',
    'email_subj_nota', 'email_body_nota',
    'email_subj_documento', 'email_body_documento'
];

// ── Guardar ajustes SMTP ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_smtp'])) {
    CSRF::verificarOAbortar();
    foreach ($emailCampos as $campo) {
        if (array_key_exists($campo, $_POST)) {
            $db->query(
                "INSERT INTO configuracion (clave, valor, grupo) VALUES (?, ?, 'email')
                 ON DUPLICATE KEY UPDATE valor = VALUES(valor)",
                [$campo, trim($_POST[$campo] ?? '')]
            );
        }
    }
    // Checkboxes de notificaciones (pueden no venir si están desactivados)
    foreach (['email_notif_registro','email_notif_solicitud','email_notif_nota','email_notif_documento'] as $notif) {
        $val = isset($_POST[$notif]) ? '1' : '0';
        $db->query(
            "INSERT INTO configuracion (clave, valor, grupo) VALUES (?, ?, 'email')
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)",
            [$notif, $val]
        );
    }
    AuditLog::registrar('editar', 'configuracion', null, 'Configuración de correo actualizada');
    setFlash('exito', 'Configuración de correo guardada correctamente.');
    header('Location: ' . APP_URL . '/index.php?page=configuracion/correo'); exit;
}

// ── Enviar correo de prueba ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_prueba'])) {
    CSRF::verificarOAbortar();
    $destinatario = trim($_POST['email_prueba'] ?? '');
    if (filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
        $resultado = Mailer::enviar(
            $destinatario,
            'Correo de prueba — ' . (getConfig('nombre_despacho') ?: 'CRM Abogados'),
            "<h2 style='margin:0 0 12px;'>Funciona correctamente</h2>
             <p>Este es un correo de prueba enviado desde el panel de administracion del CRM.</p>
             <p style='color:#64748b;font-size:.875rem;'>Fecha: " . date('d/m/Y H:i:s') . "</p>"
        );
        // Guardar log de diagnóstico en sesión si hubo error
        if (!$resultado['ok'] && !empty($resultado['log'])) {
            $_SESSION['smtp_debug_log'] = $resultado['log'];
        } else {
            unset($_SESSION['smtp_debug_log']);
        }
        setFlash($resultado['ok'] ? 'exito' : 'error', $resultado['msg']);
    } else {
        setFlash('error', 'Ingrese un correo electrónico válido.');
    }
    header('Location: ' . APP_URL . '/index.php?page=configuracion/correo#tab-prueba'); exit;
}

// ── Cargar config actual ─────────────────────────────────────────────────────
$cfg = [];
$rows = $db->fetchAll("SELECT clave, valor FROM configuracion WHERE grupo = 'email' OR clave LIKE 'smtp_%' OR clave LIKE 'email_%'");
foreach ($rows as $r) $cfg[$r['clave']] = $r['valor'];

$v = fn(string $key, string $def = '') => htmlspecialchars($cfg[$key] ?? $def, ENT_QUOTES, 'UTF-8');
$checked = fn(string $key) => (!isset($cfg[$key]) || $cfg[$key] === '1') ? 'checked' : '';

$tituloPagina = 'Configuración de Correo';
include CRM_ROOT . '/templates/layout/header.php';

$flash = getFlash();
?>

<?php if ($flash): ?>
<div class="alert alert-<?php echo $flash['tipo'] === 'exito' ? 'success' : 'danger'; ?> alert-dismissible fade show radius-8 mb-24" role="alert">
    <?php echo htmlspecialchars($flash['mensaje']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0 d-flex align-items-center"><iconify-icon icon="solar:letter-outline" class="me-2"></iconify-icon>Configuración de Correo</h6>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-24" id="emailTabs" role="tablist" style="border-bottom:2px solid #e2e8f0;">
    <li class="nav-item"><button class="nav-link active fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-smtp">SMTP</button></li>
    <li class="nav-item"><button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-diseno">Diseño</button></li>
    <li class="nav-item"><button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-notif">Notificaciones</button></li>
    <li class="nav-item"><button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-prueba">Prueba</button></li>
</ul>

<form method="POST" id="formCorreo">
<?php echo CSRF::campo(); ?>
<input type="hidden" name="guardar_smtp" value="1">

<div class="tab-content">

    <!-- ══ Tab SMTP ══════════════════════════════════════════════════════════ -->
    <div class="tab-pane fade show active" id="tab-smtp">
        <div class="row gy-4">

            <!-- Datos SMTP -->
            <div class="col-lg-6">
                <div class="card radius-8 border h-100">
                    <div class="card-body p-24">
                        <h6 class="fw-semibold mb-20 d-flex align-items-center"><iconify-icon icon="solar:server-outline" class="me-2"></iconify-icon>Servidor SMTP</h6>

                        <div class="alert alert-info radius-8 mb-20 p-12" style="font-size:.8125rem;">
                            <strong>Gmail:</strong> Host: <code>smtp.gmail.com</code> · Puerto: <code>587</code> · Requiere <a href="https://myaccount.google.com/apppasswords" target="_blank">contraseña de aplicación</a><br>
                            <strong>Outlook:</strong> Host: <code>smtp.office365.com</code> · Puerto: <code>587</code><br>
                            <strong>Hostinger:</strong> Host: <code>smtp.hostinger.com</code> · Puerto: <code>465</code> (SSL)
                        </div>

                        <div class="row gy-3">
                            <div class="col-sm-8">
                                <label class="form-label fw-semibold">Servidor SMTP <span class="text-danger">*</span></label>
                                <input type="text" name="smtp_host" class="form-control radius-8" placeholder="smtp.gmail.com" value="<?php echo $v('smtp_host'); ?>" required>
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label fw-semibold">Puerto <span class="text-danger">*</span></label>
                                <select name="smtp_port" class="form-select radius-8">
                                    <option value="587" <?php echo ($cfg['smtp_port'] ?? '587') === '587' ? 'selected' : ''; ?>>587 (TLS)</option>
                                    <option value="465" <?php echo ($cfg['smtp_port'] ?? '') === '465' ? 'selected' : ''; ?>>465 (SSL)</option>
                                    <option value="25"  <?php echo ($cfg['smtp_port'] ?? '') === '25'  ? 'selected' : ''; ?>>25</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Usuario / Email SMTP <span class="text-danger">*</span></label>
                                <input type="email" name="smtp_user" class="form-control radius-8" placeholder="correo@gmail.com" value="<?php echo $v('smtp_user'); ?>" autocomplete="off">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Contraseña SMTP <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" name="smtp_pass" id="smtpPass" class="form-control radius-8" placeholder="••••••••••••" value="<?php echo $v('smtp_pass'); ?>" autocomplete="new-password">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePass()">👁</button>
                                </div>
                                <small class="text-secondary-light">Para Gmail, usa una <strong>contraseña de aplicación</strong> (no tu contraseña habitual).</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Remitente -->
            <div class="col-lg-6">
                <div class="card radius-8 border">
                    <div class="card-body p-24">
                        <h6 class="fw-semibold mb-20 d-flex align-items-center"><iconify-icon icon="solar:user-outline" class="me-2"></iconify-icon>Remitente</h6>
                        <div class="row gy-3">
                            <div class="col-12">
                                <label class="form-label fw-semibold">Email de envío</label>
                                <input type="email" name="smtp_from" class="form-control radius-8" placeholder="noreply@miDespacho.com" value="<?php echo $v('smtp_from'); ?>">
                                <small class="text-secondary-light">Si está vacío, se usa el usuario SMTP.</small>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Nombre del remitente</label>
                                <input type="text" name="smtp_from_name" class="form-control radius-8" placeholder="Despacho García & Asociados" value="<?php echo $v('smtp_from_name', getConfig('nombre_despacho', 'CRM Abogados')); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card radius-8 border mt-20">
                    <div class="card-body p-24">
                        <h6 class="fw-semibold mb-16 d-flex align-items-center"><iconify-icon icon="solar:link-outline" class="me-2"></iconify-icon>Logo en correos</h6>
                        <label class="form-label">URL del logo (opcional)</label>
                        <input type="url" name="email_logo_url" class="form-control radius-8" placeholder="https://midominio.com/logo.png" value="<?php echo $v('email_logo_url'); ?>">
                        <small class="text-secondary-light">Si está vacío, se muestra el nombre del despacho en texto.</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-20 d-flex justify-content-end">
            <button type="submit" class="btn btn-primary radius-8 px-24 d-flex align-items-center">
                <iconify-icon icon="solar:diskette-outline" class="me-2"></iconify-icon> Guardar SMTP
            </button>
        </div>
    </div>

    <!-- ══ Tab Diseño ════════════════════════════════════════════════════════ -->
    <div class="tab-pane fade" id="tab-diseno">
        <div class="row gy-4">
            <div class="col-lg-5">
                <div class="card radius-8 border">
                    <div class="card-body p-24">
                        <h6 class="fw-semibold mb-20 d-flex align-items-center"><iconify-icon icon="solar:palette-outline" class="me-2"></iconify-icon>Colores de la Plantilla</h6>
                        <div class="row gy-3">
                            <?php
                            $colorFields = [
                                'email_color_primario' => ['Color primario (encabezado/links)', '#2e6edd'],
                                'email_color_boton'    => ['Color del botón de acción',          '#2e6edd'],
                                'email_color_fondo'    => ['Fondo del email',                   '#f1f5f9'],
                                'email_color_tarjeta'  => ['Fondo de la tarjeta',               '#ffffff'],
                                'email_color_texto'    => ['Texto principal',                   '#1e293b'],
                                'email_color_pie'      => ['Texto del pie de página',           '#64748b'],
                            ];
                            foreach ($colorFields as $key => [$label, $default]):
                                $val = $cfg[$key] ?? $default;
                            ?>
                            <div class="col-sm-6">
                                <label class="form-label text-sm"><?php echo $label; ?></label>
                                <div class="d-flex gap-2 align-items-center">
                                    <input type="color" name="<?php echo $key; ?>" id="color_<?php echo $key; ?>"
                                           value="<?php echo htmlspecialchars($val); ?>"
                                           class="form-control form-control-color colorPicker" style="width:48px;height:38px"
                                           data-target="text_<?php echo $key; ?>">
                                    <input type="text" id="text_<?php echo $key; ?>"
                                           value="<?php echo htmlspecialchars($val); ?>"
                                           class="form-control radius-8 colorText" style="max-width:90px;font-size:.8125rem;"
                                           data-picker="color_<?php echo $key; ?>"
                                           maxlength="7">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <hr class="my-20">
                        <h6 class="fw-semibold mb-16">Pie de página</h6>
                        <div>
                            <label class="form-label text-sm">Texto del pie</label>
                            <textarea name="email_pie_texto" class="form-control radius-8" rows="3" id="emailPieTexto"
                                      placeholder="© 2025 Mi Despacho. Todos los derechos reservados."><?php echo $v('email_pie_texto', '© ' . date('Y') . ' ' . (getConfig('nombre_despacho') ?: 'CRM Abogados') . '. Todos los derechos reservados.'); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Preview del email -->
            <div class="col-lg-7">
                <div class="card radius-8 border">
                    <div class="card-body p-20">
                        <div class="d-flex align-items-center justify-content-between mb-16">
                            <h6 class="fw-semibold mb-0 d-flex align-items-center"><iconify-icon icon="solar:eye-outline" class="me-2"></iconify-icon>Vista previa en tiempo real</h6>
                            <button type="button" class="btn btn-sm btn-outline-primary radius-8" onclick="actualizarPreview()">Actualizar</button>
                        </div>
                        <div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;height:500px;">
                            <iframe id="emailPreview" style="width:100%;height:100%;border:none;" srcdoc="Cargando..."></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-20 d-flex justify-content-end">
            <button type="submit" class="btn btn-primary radius-8 px-24 d-flex align-items-center">
                <iconify-icon icon="solar:diskette-outline" class="me-2"></iconify-icon> Guardar Diseño
            </button>
        </div>
    </div>

    <!-- ══ Tab Notificaciones ════════════════════════════════════════════════ -->
    <div class="tab-pane fade" id="tab-notif">
        <div class="card radius-8 border">
            <div class="card-body p-24">
                <h6 class="fw-semibold mb-4 d-flex align-items-center"><iconify-icon icon="solar:bell-outline" class="me-2"></iconify-icon>Notificaciones Automáticas por Email</h6>
                <p class="text-secondary-light text-sm mb-24">Seleccione qué eventos generan un correo automático al cliente.</p>

                <?php
                $notifItems = [
                    'registro'   => ['Bienvenida al registrarse', 'El cliente recibe un email de bienvenida al crear su cuenta en el portal.', '¡Bienvenido a nuestro portal!', 'Hola {{cliente_nombre}},\n\nTu cuenta ha sido creada exitosamente.\n\nYa puedes acceder al portal para consultar tus expedientes y enviar solicitudes.'],
                    'solicitud'  => ['Solicitud aceptada', 'El cliente es notificado cuando su solicitud es aceptada y se crea su expediente.', 'Su solicitud ha sido aceptada', 'Estimado/a {{cliente_nombre}},\n\nNos complace informarle que su expediente ha sido creado con la referencia {{caso_referencia}}.\n\nNuestro equipo se pondrá en contacto con usted a la brevedad.'],
                    'nota'       => ['Nueva nota en el expediente', 'El cliente recibe un aviso cuando el abogado añade una nota visible a su caso.', 'Nueva actualización en su expediente', 'Estimado/a {{cliente_nombre}},\n\nSe ha agregado una nueva nota o actualización a su expediente {{caso_referencia}}.\n\nPuede ver los detalles accediendo a su panel de cliente.'],
                    'documento'  => ['Nuevo documento subido', 'El cliente es notificado cuando se sube un documento a su expediente.', 'Nuevo documento en su expediente', 'Estimado/a {{cliente_nombre}},\n\nSe ha subido un nuevo documento ({{documento_nombre}}) a su expediente {{caso_referencia}}.\n\nYa está disponible para su descarga en el portal.']
                ];
                foreach ($notifItems as $key => [$titulo, $desc, $defSubj, $defBody]):
                    $ckey = 'email_notif_' . $key;
                    $skey = 'email_subj_' . $key;
                    $bkey = 'email_body_' . $key;
                ?>
                <div class="border radius-8 mb-16 p-16">
                    <div class="d-flex align-items-start justify-content-between gap-3 mb-12">
                        <div>
                            <p class="fw-semibold mb-4"><?php echo $titulo; ?></p>
                            <p class="text-sm text-secondary-light mb-0"><?php echo $desc; ?></p>
                        </div>
                        <div class="form-check form-switch" style="flex-shrink:0;margin-top:2px;">
                            <input class="form-check-input" type="checkbox" name="<?php echo $ckey; ?>" id="<?php echo $ckey; ?>"
                                   <?php echo $checked($ckey); ?> style="width:44px;height:24px;cursor:pointer;">
                        </div>
                    </div>
                    
                    <div class="bg-light p-16 radius-8">
                        <div class="mb-12">
                            <label class="form-label text-sm fw-semibold">Asunto del correo</label>
                            <input type="text" name="<?php echo $skey; ?>" class="form-control radius-8" value="<?php echo $v($skey, $defSubj); ?>">
                        </div>
                        <div>
                            <label class="form-label text-sm fw-semibold">Contenido del correo</label>
                            <textarea name="<?php echo $bkey; ?>" class="form-control radius-8" rows="4"><?php echo htmlspecialchars($cfg[$bkey] ?? str_replace('\n', "\n", $defBody)); ?></textarea>
                            <small class="text-secondary-light d-block mt-8">
                                Variables disponibles: <code>{{cliente_nombre}}</code>, <code>{{caso_referencia}}</code>, <code>{{documento_nombre}}</code>, <code>{{url_portal}}</code>
                            </small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mt-20 d-flex justify-content-end">
            <button type="submit" class="btn btn-primary radius-8 px-24 d-flex align-items-center">
                <iconify-icon icon="solar:diskette-outline" class="me-2"></iconify-icon> Guardar Notificaciones
            </button>
        </div>
    </div>

    <!-- ══ Tab Prueba ════════════════════════════════════════════════════════ -->
    <div class="tab-pane fade" id="tab-prueba">
        <div class="card radius-8 border" style="max-width:560px;">
            <div class="card-body p-24">
                <h6 class="fw-semibold mb-4 d-flex align-items-center"><iconify-icon icon="solar:send-outline" class="me-2"></iconify-icon>Enviar Correo de Prueba</h6>
                <p class="text-secondary-light text-sm mb-20">Verifique que la configuración SMTP funcione correctamente enviando un correo de prueba.</p>

                <div class="mb-16">
                    <label class="form-label fw-semibold">Destinatario de prueba</label>
                    <input type="email" name="email_prueba" class="form-control radius-8" placeholder="admin@ejemplo.com"
                           value="<?php echo htmlspecialchars($cfg['smtp_user'] ?? ''); ?>">
                </div>

                <button type="submit" name="enviar_prueba" value="1" class="btn btn-success radius-8 w-100 d-flex align-items-center justify-content-center"
                        data-confirm="¿Enviar correo de prueba? Asegúrese de haber guardado la configuración SMTP primero.">
                    <iconify-icon icon="solar:send-outline" class="me-2"></iconify-icon>
                    Enviar Correo de Prueba
                </button>
            </div>
        </div>

        <?php if (!empty($_SESSION['smtp_debug_log'])): ?>
        <div class="card radius-8 border border-warning mt-16" style="max-width:700px;">
            <div class="card-body p-20">
                <h6 class="fw-semibold mb-12 d-flex align-items-center gap-2" style="color:#d97706;">
                    <iconify-icon icon="solar:bug-outline"></iconify-icon> Diagnóstico SMTP — Conversación con el servidor
                </h6>
                <p class="text-sm text-secondary-light mb-12">El correo no se pudo enviar. Esta es la conversación exacta con el servidor para identificar el problema:</p>
                <pre class="p-16 radius-8" style="font-size:.75rem;overflow-x:auto;max-height:300px;background:#0f172a;color:#4ade80;white-space:pre-wrap;"><?php
                    foreach ($_SESSION['smtp_debug_log'] as $linea) {
                        echo htmlspecialchars($linea) . "\n";
                    }
                ?></pre>
                <div class="mt-12 p-12 radius-8" style="background:#eff6ff;border:1px solid #bfdbfe;font-size:.8125rem;">
                    <strong>Causas frecuentes:</strong><br>
                    <strong>&bull; "No se pudo conectar"</strong> &mdash; Su hosting bloquea los puertos SMTP salientes (587/465). Contacte a su proveedor de hosting.<br>
                    <strong>&bull; "Autenticación fallida"</strong> &mdash; Para Gmail use una <strong>Contraseña de Aplicación</strong> en myaccount.google.com/apppasswords.<br>
                    <strong>&bull; "STARTTLS rechazado"</strong> &mdash; Pruebe cambiando el puerto a <strong>465 (SSL)</strong> en vez de 587.
                </div>
            </div>
        </div>
        <?php unset($_SESSION['smtp_debug_log']); endif; ?>
    </div>

</div><!-- /tab-content -->
</form>

<?php
$scriptsExtra = '<script>
// ── Sincronizar color picker ↔ texto ─────────────────────────────────────────
document.querySelectorAll(".colorPicker").forEach(function(picker) {
    picker.addEventListener("input", function() {
        document.getElementById(this.dataset.target).value = this.value;
        debouncePreview();
    });
});
document.querySelectorAll(".colorText").forEach(function(txt) {
    txt.addEventListener("input", function() {
        if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
            document.getElementById(this.dataset.picker).value = this.value;
            debouncePreview();
        }
    });
});
document.getElementById("emailPieTexto")?.addEventListener("input", debouncePreview);

// ── Mostrar/ocultar contraseña SMTP ──────────────────────────────────────────
function togglePass() {
    var el = document.getElementById("smtpPass");
    el.type = el.type === "password" ? "text" : "password";
}

// ── Preview en tiempo real ────────────────────────────────────────────────────
var previewTimer = null;
function debouncePreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(actualizarPreview, 500);
}

function getColor(key) {
    var el = document.querySelector("[name=" + key + "]");
    return el ? el.value : "";
}

function actualizarPreview() {
    var colorPrim  = getColor("email_color_primario") || "#2e6edd";
    var colorBtn   = getColor("email_color_boton")    || "#2e6edd";
    var colorFondo = getColor("email_color_fondo")    || "#f1f5f9";
    var colorCard  = getColor("email_color_tarjeta")  || "#ffffff";
    var colorTxt   = getColor("email_color_texto")    || "#1e293b";
    var colorPie   = getColor("email_color_pie")      || "#64748b";
    var despacho   = "' . addslashes(getConfig('nombre_despacho', 'CRM Abogados')) . '";
    var pieTexto   = document.getElementById("emailPieTexto")?.value || "";

    var html = `<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><style>*{box-sizing:border-box}body{margin:0;padding:0;background:${colorFondo};font-family:"Segoe UI",Arial,sans-serif;}</style></head>
<body>
<table width="100%" cellpadding="0" cellspacing="0" style="background:${colorFondo};padding:32px 16px;">
  <tr><td align="center">
    <table width="540" cellpadding="0" cellspacing="0" style="max-width:540px;width:100%;">
      <tr><td align="center" style="padding-bottom:20px;">
        <span style="font-size:1.125rem;font-weight:800;color:${colorPrim}">${despacho}</span>
      </td></tr>
      <tr><td style="background:${colorCard};border-radius:14px;padding:36px;box-shadow:0 4px 20px rgba(0,0,0,.07);">
        <h2 style="margin:0 0 14px;font-size:1.25rem;font-weight:800;color:${colorTxt};">Su solicitud ha sido aceptada</h2>
        <p style="color:${colorTxt};line-height:1.7;font-size:.9375rem;">Estimado/a <strong>Cliente Ejemplo</strong>,<br>Nos complace informarle que su expediente ha sido creado.</p>
        <table style="width:100%;border-collapse:collapse;margin:18px 0;">
          <tr><td style="padding:10px 14px;background:#f8fafc;border-radius:8px 8px 0 0;font-weight:600;font-size:.8125rem;color:#64748b;">Referencia</td><td style="padding:10px 14px;background:#f8fafc;font-weight:700;color:${colorTxt};">EXP-2025-001</td></tr>
          <tr><td style="padding:10px 14px;font-weight:600;font-size:.8125rem;color:#64748b;">Asunto</td><td style="padding:10px 14px;color:${colorTxt};">Consulta legal general</td></tr>
        </table>
        <div style="text-align:center;margin:24px 0;">
          <a href="#" style="background:${colorBtn};color:#fff;text-decoration:none;padding:13px 28px;border-radius:10px;font-weight:700;font-size:.9375rem;display:inline-block;">Ver Mi Expediente</a>
        </div>
        <p style="color:${colorTxt};font-size:.875rem;line-height:1.6;">Nuestro equipo se pondrá en contacto con usted a la brevedad.</p>
      </td></tr>
      <tr><td align="center" style="padding-top:20px;font-size:.75rem;color:${colorPie};line-height:1.6;">${pieTexto}</td></tr>
    </table>
  </td></tr>
</table>
</body></html>`;

    document.getElementById("emailPreview").srcdoc = html;
}

// Activar preview al cargar la tab de diseño
document.querySelector("[data-bs-target=\'#tab-diseno\']").addEventListener("shown.bs.tab", function() {
    actualizarPreview();
});
</script>';
include CRM_ROOT . '/templates/layout/footer.php';
