<?php
/**
 * CRM Abogados - Configuración del Tema (solo Admin)
 */
$db = Database::getInstance();

// ===== Procesar subida de imágenes =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_imagenes'])) {
    CSRF::verificarOAbortar();
    $imgDir  = CRM_ROOT . '/assets/images';
    $authDir = $imgDir . '/auth';
    $errores = [];

    // [campo => [destino, maxAncho, maxAlto, calidad(jpeg 0-100 / png 0-9)]]
    $mapeo = [
        'logo_claro'   => [$imgDir . '/logo.png',           400, 100,  8],
        'logo_oscuro'  => [$imgDir . '/logo-light.png',     400, 100,  8],
        'logo_icono'   => [$imgDir . '/logo-icon.png',       64,  64,  8],
        'favicon'      => [$imgDir . '/favicon.png',          48,  48,  8],
        'imagen_login' => [$authDir . '/auth-img.png',      1200, 1200, 82],
    ];

    /**
     * Comprime y redimensiona una imagen usando GD.
     * SVG e ICO se copian sin procesar (GD no los soporta).
     * PNG → imagepng con nivel de compresión 0-9
     * JPEG/WEBP → imagejpeg con calidad 0-100
     */
    $comprimir = function(string $tmp, string $destino, string $mime, int $maxW, int $maxH, int $calidad): bool {
        // SVG/ICO no los procesa GD — copiar tal cual
        if (in_array($mime, ['image/svg+xml', 'image/x-icon'])) {
            return copy($tmp, $destino);
        }
        if (!extension_loaded('gd')) return copy($tmp, $destino); // fallback sin GD

        // Crear recurso GD según MIME
        $src = match($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/png'  => @imagecreatefrompng($tmp),
            'image/gif'  => @imagecreatefromgif($tmp),
            'image/webp' => @imagecreatefromwebp($tmp),
            default      => false,
        };
        if (!$src) return copy($tmp, $destino); // fallback si GD no puede leer

        [$sw, $sh] = [imagesx($src), imagesy($src)];

        // Calcular nuevas dimensiones manteniendo proporción
        $ratio  = min($maxW / $sw, $maxH / $sh, 1); // nunca ampliar
        $nw     = (int) round($sw * $ratio);
        $nh     = (int) round($sh * $ratio);

        $dst = imagecreatetruecolor($nw, $nh);

        // Preservar transparencia para PNG
        if ($mime === 'image/png') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $sw, $sh);

        // Guardar siempre como PNG si el original era PNG (para mantener transparencia)
        // y como JPEG si era JPG/WEBP (mejor ratio de compresión)
        $esJpeg = in_array($mime, ['image/jpeg', 'image/webp']);
        $ok = $esJpeg
            ? imagejpeg($dst, $destino, $calidad)       // calidad 0-100
            : imagepng($dst, $destino, min(9, (int)round($calidad / 11))); // convierte calidad a 0-9

        unset($src);
        unset($dst);
        return $ok;
    };

    foreach ($mapeo as $campo => [$destino, $maxW, $maxH, $calidad]) {
        if (!isset($_FILES[$campo]) || $_FILES[$campo]['error'] === UPLOAD_ERR_NO_FILE) continue;
        $f = $_FILES[$campo];
        if ($f['error'] !== UPLOAD_ERR_OK) { $errores[] = "Error al subir $campo"; continue; }
        $mime = mime_content_type($f['tmp_name']);
        if (!in_array($mime, ['image/png','image/jpeg','image/gif','image/webp','image/svg+xml','image/x-icon'])) {
            $errores[] = "$campo: formato no permitido. Usa PNG, JPG, SVG o ICO.";
            continue;
        }
        if ($f['size'] > 5 * 1024 * 1024) { $errores[] = "$campo: supera 5 MB."; continue; }

        // Comprimir y guardar
        if (!$comprimir($f['tmp_name'], $destino, $mime, $maxW, $maxH, $calidad)) {
            $errores[] = "$campo: no se pudo procesar la imagen.";
            continue;
        }

        // Sincronizar a XAMPP (dev local)
        $xdest = str_replace(CRM_ROOT, 'c:/xampp/htdocs/portal/crm', $destino);
        if (is_dir(dirname($xdest)) && $xdest !== $destino) {
            @copy($destino, $xdest);
        }
    }

    if ($errores) {
        setFlash('error', implode('<br>', $errores));
    } else {
        AuditLog::registrar('editar', 'configuracion', null, 'Imágenes del sistema actualizadas');
        setFlash('exito', 'Imágenes optimizadas y actualizadas correctamente');
    }
    header('Location: ' . APP_URL . '/index.php?page=configuracion/tema'); exit;
}

// ===== Procesar campos de texto/color =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verificarOAbortar();

    $campos = [
        'nombre_despacho', 'email_despacho', 'telefono_despacho', 'direccion_despacho',
        'color_primario', 'color_secundario', 'color_exito', 'color_peligro',
        'color_advertencia', 'color_info', 'color_sidebar',
        'sesion_timeout_minutos', 'max_intentos_login'
    ];

    foreach ($campos as $campo) {
        if (isset($_POST[$campo])) {
            $db->query(
                "INSERT INTO configuracion (clave, valor, grupo) VALUES (?, ?, 'general')
                 ON DUPLICATE KEY UPDATE valor = VALUES(valor)",
                [$campo, trim($_POST[$campo])]
            );
        }
    }

    AuditLog::registrar('editar', 'configuracion', null, 'Configuración del sistema actualizada');
    setFlash('exito', 'Configuración guardada correctamente');
    header('Location: ' . APP_URL . '/index.php?page=configuracion/tema'); exit;
}

// Cargar configuración actual
$config = [];
$rows = $db->fetchAll("SELECT * FROM configuracion ORDER BY grupo, clave");
foreach ($rows as $r) { $config[$r['clave']] = $r; }

$tituloPagina = 'Configuración';
include CRM_ROOT . '/templates/layout/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Configuración del Sistema</h6>
</div>

<form method="POST">
    <?php echo CSRF::campo(); ?>

    <div class="row gy-4">
        <!-- Datos del despacho -->
        <div class="col-lg-6">
            <div class="card radius-8 border h-100">
                <div class="card-body p-24">
                    <h6 class="fw-semibold mb-16"><iconify-icon icon="solar:buildings-outline" class="me-2"></iconify-icon>Datos del Despacho</h6>
                    <div class="row gy-3">
                        <div class="col-12"><label class="form-label">Nombre del Despacho</label><input type="text" name="nombre_despacho" class="form-control radius-8" value="<?php echo e($config['nombre_despacho']['valor'] ?? ''); ?>"></div>
                        <div class="col-sm-6"><label class="form-label">Email</label><input type="email" name="email_despacho" class="form-control radius-8" value="<?php echo e($config['email_despacho']['valor'] ?? ''); ?>"></div>
                        <div class="col-sm-6"><label class="form-label">Teléfono</label><input type="text" name="telefono_despacho" class="form-control radius-8" value="<?php echo e($config['telefono_despacho']['valor'] ?? ''); ?>"></div>
                        <div class="col-12"><label class="form-label">Dirección</label><input type="text" name="direccion_despacho" class="form-control radius-8" value="<?php echo e($config['direccion_despacho']['valor'] ?? ''); ?>"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Colores del tema -->
        <div class="col-lg-6">
            <div class="card radius-8 border h-100">
                <div class="card-body p-24">
                    <h6 class="fw-semibold mb-16"><iconify-icon icon="solar:palette-outline" class="me-2"></iconify-icon>Colores del Tema</h6>
                    <div class="row gy-3">
                        <div class="col-sm-6">
                            <label class="form-label">Color Primario</label>
                            <div class="d-flex gap-2 align-items-center">
                                <input type="color" name="color_primario" value="<?php echo e($config['color_primario']['valor'] ?? '#487fff'); ?>" class="form-control form-control-color" style="width:48px;height:38px">
                                <input type="text" class="form-control radius-8" value="<?php echo e($config['color_primario']['valor'] ?? '#487fff'); ?>" disabled style="max-width:100px">
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Color Secundario</label>
                            <div class="d-flex gap-2 align-items-center">
                                <input type="color" name="color_secundario" value="<?php echo e($config['color_secundario']['valor'] ?? '#6c757d'); ?>" class="form-control form-control-color" style="width:48px;height:38px">
                                <input type="text" class="form-control radius-8" value="<?php echo e($config['color_secundario']['valor'] ?? '#6c757d'); ?>" disabled style="max-width:100px">
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Color Éxito</label>
                            <div class="d-flex gap-2 align-items-center">
                                <input type="color" name="color_exito" value="<?php echo e($config['color_exito']['valor'] ?? '#28a745'); ?>" class="form-control form-control-color" style="width:48px;height:38px">
                                <input type="text" class="form-control radius-8" value="<?php echo e($config['color_exito']['valor'] ?? '#28a745'); ?>" disabled style="max-width:100px">
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Color Peligro</label>
                            <div class="d-flex gap-2 align-items-center">
                                <input type="color" name="color_peligro" value="<?php echo e($config['color_peligro']['valor'] ?? '#dc3545'); ?>" class="form-control form-control-color" style="width:48px;height:38px">
                                <input type="text" class="form-control radius-8" value="<?php echo e($config['color_peligro']['valor'] ?? '#dc3545'); ?>" disabled style="max-width:100px">
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Color Advertencia</label>
                            <div class="d-flex gap-2 align-items-center">
                                <input type="color" name="color_advertencia" value="<?php echo e($config['color_advertencia']['valor'] ?? '#ff9f29'); ?>" class="form-control form-control-color" style="width:48px;height:38px">
                                <input type="text" class="form-control radius-8" value="<?php echo e($config['color_advertencia']['valor'] ?? '#ff9f29'); ?>" disabled style="max-width:100px">
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Color Info</label>
                            <div class="d-flex gap-2 align-items-center">
                                <input type="color" name="color_info" value="<?php echo e($config['color_info']['valor'] ?? '#17a2b8'); ?>" class="form-control form-control-color" style="width:48px;height:38px">
                                <input type="text" class="form-control radius-8" value="<?php echo e($config['color_info']['valor'] ?? '#17a2b8'); ?>" disabled style="max-width:100px">
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Sidebar</label>
                            <div class="d-flex gap-2 align-items-center">
                                <input type="color" name="color_sidebar" value="<?php echo e($config['color_sidebar']['valor'] ?? '#1b2431'); ?>" class="form-control form-control-color" style="width:48px;height:38px">
                                <input type="text" class="form-control radius-8" value="<?php echo e($config['color_sidebar']['valor'] ?? '#1b2431'); ?>" disabled style="max-width:100px">
                            </div>
                        </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Seguridad -->
        <div class="col-lg-6">
            <div class="card radius-8 border">
                <div class="card-body p-24">
                    <h6 class="fw-semibold mb-16"><iconify-icon icon="solar:shield-keyhole-outline" class="me-2"></iconify-icon>Seguridad</h6>
                    <div class="row gy-3">
                        <div class="col-sm-6"><label class="form-label">Timeout Sesión (min)</label><input type="number" name="sesion_timeout_minutos" class="form-control radius-8" value="<?php echo e($config['sesion_timeout_minutos']['valor'] ?? '30'); ?>"></div>
                        <div class="col-sm-6"><label class="form-label">Max Intentos Login</label><input type="number" name="max_intentos_login" class="form-control radius-8" value="<?php echo e($config['max_intentos_login']['valor'] ?? '5'); ?>"></div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /row -->

    <div class="mt-24">
        <button type="submit" class="btn btn-primary radius-8 px-20">
            <iconify-icon icon="solar:diskette-outline" class="me-1"></iconify-icon> Guardar Configuración
        </button>
    </div>
</form>

<?php
// Rutas absolutas a las imágenes actuales (con cache-buster)
$v = time(); // para forzar recarga en el navegador después de guardar
$imgBase = APP_URL . '/assets/images';
$logoClaro  = file_exists(CRM_ROOT . '/assets/images/logo.png') ? "$imgBase/logo.png?v=$v" : null;
$logoOscuro = file_exists(CRM_ROOT . '/assets/images/logo.png') ? "$imgBase/logo.png?v=$v" : null;
$logoIcono  = file_exists(CRM_ROOT . '/assets/images/logo.png') ? "$imgBase/logo.png?v=$v" : null;
$faviconImg = file_exists(CRM_ROOT . '/assets/images/logo.png') ? "$imgBase/logo.png?v=$v" : null;
$loginImg   = null;
?>

<!-- Tarjeta de imágenes -->
<div class="card radius-8 border mt-24">
    <div class="card-body p-24">
        <h6 class="fw-semibold mb-20">
            <iconify-icon icon="solar:gallery-outline" class="me-2"></iconify-icon>Imágenes del Sistema
        </h6>
        <form method="POST" enctype="multipart/form-data">
            <?php echo CSRF::campo(); ?>
            <input type="hidden" name="guardar_imagenes" value="1">
            <div class="row gy-4">

                <!-- Logo claro (sidebar modo claro) -->
                <div class="col-lg-4 col-sm-6">
                    <div class="upload-card border radius-8 p-16 text-center position-relative">
                        <p class="fw-semibold text-sm mb-2">🎨 Logo (Modo Claro)</p>
                        <p class="text-xs text-secondary-light mb-12">Sidebar claro — 168×40 px recomendado</p>
                        <?php if ($logoClaro): ?>
                        <img src="<?php echo $logoClaro; ?>" alt="Logo" class="mb-12 mx-auto d-block" style="max-height:50px;max-width:100%;object-fit:contain;border:1px solid #e5e7eb;border-radius:6px;padding:6px">
                        <?php else: ?>
                        <div class="mb-12 d-flex align-items-center justify-content-center" style="height:50px;background:#f3f4f6;border-radius:6px;border:1.5px dashed #d1d5db;color:#9ca3af;font-size:12px">Sin logo</div>
                        <?php endif; ?>
                        <label class="btn btn-sm btn-outline-primary radius-6 w-100" style="cursor:pointer">
                            <iconify-icon icon="solar:upload-outline" class="me-1"></iconify-icon>Subir PNG/SVG
                            <input type="file" name="logo_claro" accept="image/png,image/svg+xml,image/jpeg" class="d-none" onchange="preview(this)">
                        </label>
                    </div>
                </div>

                <!-- Logo oscuro (sidebar modo oscuro) -->
                <div class="col-lg-4 col-sm-6">
                    <div class="upload-card border radius-8 p-16 text-center position-relative">
                        <p class="fw-semibold text-sm mb-2">🌙 Logo (Modo Oscuro)</p>
                        <p class="text-xs text-secondary-light mb-12">Sidebar oscuro — 168×40 px recomendado</p>
                        <?php if ($logoOscuro): ?>
                        <img src="<?php echo $logoOscuro; ?>" alt="Logo oscuro" class="mb-12 mx-auto d-block" style="max-height:50px;max-width:100%;object-fit:contain;border:1px solid #e5e7eb;border-radius:6px;padding:6px;background:#1b2431">
                        <?php else: ?>
                        <div class="mb-12 d-flex align-items-center justify-content-center" style="height:50px;background:#1b2431;border-radius:6px;border:1.5px dashed #374151;color:#6b7280;font-size:12px">Sin logo</div>
                        <?php endif; ?>
                        <label class="btn btn-sm btn-outline-primary radius-6 w-100" style="cursor:pointer">
                            <iconify-icon icon="solar:upload-outline" class="me-1"></iconify-icon>Subir PNG/SVG
                            <input type="file" name="logo_oscuro" accept="image/png,image/svg+xml,image/jpeg" class="d-none" onchange="preview(this)">
                        </label>
                    </div>
                </div>

                <!-- Logo icónico (sidebar colapsado) -->
                <div class="col-lg-4 col-sm-6">
                    <div class="upload-card border radius-8 p-16 text-center">
                        <p class="fw-semibold text-sm mb-2">🔳 Ícono del Logo</p>
                        <p class="text-xs text-secondary-light mb-12">Sidebar colapsado — 40×40 px</p>
                        <?php if ($logoIcono): ?>
                        <img src="<?php echo $logoIcono; ?>" alt="Logo icono" class="mb-12 mx-auto d-block" style="height:40px;width:40px;object-fit:contain;border:1px solid #e5e7eb;border-radius:6px;padding:4px">
                        <?php else: ?>
                        <div class="mb-12 d-flex align-items-center justify-content-center" style="height:40px;background:#f3f4f6;border-radius:6px;border:1.5px dashed #d1d5db;color:#9ca3af;font-size:12px">Sin icono</div>
                        <?php endif; ?>
                        <label class="btn btn-sm btn-outline-primary radius-6 w-100" style="cursor:pointer">
                            <iconify-icon icon="solar:upload-outline" class="me-1"></iconify-icon>Subir PNG
                            <input type="file" name="logo_icono" accept="image/png,image/svg+xml" class="d-none" onchange="preview(this)">
                        </label>
                    </div>
                </div>

                <!-- Favicon -->
                <div class="col-lg-4 col-sm-6">
                    <div class="upload-card border radius-8 p-16 text-center">
                        <p class="fw-semibold text-sm mb-2">🌟 Favicon</p>
                        <p class="text-xs text-secondary-light mb-12">Pestaña del navegador — 32×32 px</p>
                        <?php if ($faviconImg): ?>
                        <img src="<?php echo $faviconImg; ?>" alt="Favicon" class="mb-12 mx-auto d-block" style="height:32px;width:32px;border:1px solid #e5e7eb;border-radius:4px">
                        <?php else: ?>
                        <div class="mb-12 d-flex align-items-center justify-content-center" style="height:32px;background:#f3f4f6;border-radius:4px;border:1.5px dashed #d1d5db;color:#9ca3af;font-size:11px">Sin favicon</div>
                        <?php endif; ?>
                        <label class="btn btn-sm btn-outline-primary radius-6 w-100" style="cursor:pointer">
                            <iconify-icon icon="solar:upload-outline" class="me-1"></iconify-icon>Subir PNG/ICO
                            <input type="file" name="favicon" accept="image/png,image/x-icon,image/svg+xml" class="d-none" onchange="preview(this)">
                        </label>
                    </div>
                </div>

                <!-- Imagen de fondo del login -->
                <div class="col-lg-8">
                    <div class="upload-card border radius-8 p-16 text-center">
                        <p class="fw-semibold text-sm mb-2">🖼️ Imagen de Inicio de Sesión</p>
                        <p class="text-xs text-secondary-light mb-12">Panel izquierdo del login — 900×900 px recomendado (PNG, JPG)</p>
                        <?php if ($loginImg): ?>
                        <img src="<?php echo $loginImg; ?>" alt="Login" class="mb-12 mx-auto d-block radius-8" style="max-height:180px;max-width:100%;object-fit:cover;border:1px solid #e5e7eb">
                        <?php else: ?>
                        <div class="mb-12 d-flex align-items-center justify-content-center" style="height:120px;background:#f3f4f6;border-radius:8px;border:1.5px dashed #d1d5db;color:#9ca3af;font-size:12px">Sin imagen</div>
                        <?php endif; ?>
                        <label class="btn btn-sm btn-outline-primary radius-6" style="cursor:pointer">
                            <iconify-icon icon="solar:upload-outline" class="me-1"></iconify-icon>Subir imagen
                            <input type="file" name="imagen_login" accept="image/png,image/jpeg,image/webp" class="d-none" onchange="preview(this)">
                        </label>
                    </div>
                </div>

            </div>
            <div class="mt-20">
                <button type="submit" class="btn btn-primary radius-8 px-20">
                    <iconify-icon icon="solar:diskette-outline" class="me-1"></iconify-icon> Guardar Imágenes
                </button>
                <p class="text-xs text-secondary-light mt-8 mb-0">Máximo 2 MB por archivo. Formatos: PNG, JPG, SVG, ICO.</p>
            </div>
        </form>
    </div>
</div>

<?php
$scriptsExtra = '<script>
// Sincronizar color picker con texto
document.querySelectorAll("input[type=color]").forEach(function(picker) {
    picker.addEventListener("input", function() {
        var textInput = this.parentElement.querySelector("input[type=text]");
        if (textInput) textInput.value = this.value;
    });
});

// Previsualizar imagen antes de subir
function preview(input) {
    if (!input.files || !input.files[0]) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        var card = input.closest(".upload-card");
        // Buscar img existente o placeholder div
        var img = card.querySelector("img");
        var placeholder = card.querySelector("div[style*=dashed]");
        if (!img) {
            img = document.createElement("img");
            img.className = "mb-12 mx-auto d-block";
            img.style.cssText = "max-height:100px;max-width:100%;object-fit:contain;border:1px solid #e5e7eb;border-radius:6px;padding:6px";
            card.insertBefore(img, card.querySelector("label"));
            if (placeholder) placeholder.remove();
        }
        img.src = e.target.result;
        // Badge de "Listo para guardar"
        var badge = card.querySelector(".preview-badge");
        if (!badge) {
            badge = document.createElement("span");
            badge.className = "preview-badge d-block text-xs text-success-main fw-semibold mb-8";
            badge.innerHTML = "✅ " + input.files[0].name;
            card.insertBefore(badge, card.querySelector("label"));
        } else {
            badge.innerHTML = "✅ " + input.files[0].name;
        }
    };
    reader.readAsDataURL(input.files[0]);
}

// Botón: enviar correo de prueba (SMTP)
document.addEventListener("DOMContentLoaded", function() {
    var btn = document.getElementById("btnTestEmail");
    var result = document.getElementById("testEmailResult");
    if (!btn) return;
    btn.addEventListener("click", function() {
        btn.disabled = true;
        btn.innerHTML = "<span class=\"spinner-border spinner-border-sm me-1\"></span> Enviando...";
        result.style.display = "none";

        fetch("<?php echo APP_URL; ?>/api/test-smtp.php", {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: "csrf_token=" + encodeURIComponent(document.querySelector("[name=csrf_token]").value)
        })
        .then(r => r.json())
        .then(data => {
            result.className = "alert " + (data.ok ? "alert-success" : "alert-danger");
            result.innerHTML = data.msg;
            result.style.display = "block";
        })
        .catch(() => {
            result.className = "alert alert-danger";
            result.innerHTML = "❌ Error de conexión. Inténtalo de nuevo.";
            result.style.display = "block";
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = "<iconify-icon icon=\"solar:send-outline\" class=\"me-1\"></iconify-icon> Enviar correo de prueba";
        });
    });
});
</script>';
include CRM_ROOT . '/templates/layout/footer.php';
