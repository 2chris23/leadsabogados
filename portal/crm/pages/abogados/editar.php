<?php
/**
 * CRM Abogados - Editar Perfil de Abogado (con foto de perfil)
 */
RoleGuard::requireRole('admin');

$db  = Database::getInstance();
$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$abogado = $db->fetchOne(
    "SELECT * FROM usuarios_internos WHERE id = ? AND rol = 'abogado'",
    [$id]
);
if (!$abogado) {
    setFlash('error', 'Abogado no encontrado.');
    header('Location: ' . APP_URL . '/index.php?page=abogados'); exit;
}

$errores = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verificarOAbortar();

    $nombre       = trim($_POST['nombre'] ?? '');
    $apellidos    = trim($_POST['apellidos'] ?? '');
    $email        = trim(strtolower($_POST['email'] ?? ''));
    $telefono     = trim($_POST['telefono'] ?? '');
    $especialidades = trim($_POST['especialidades'] ?? '');
    $sitio_web    = trim($_POST['sitio_web'] ?? '');
    $newPassword  = $_POST['new_password'] ?? '';

    if (empty($nombre))  $errores[] = 'El nombre es obligatorio.';
    if (empty($email))   $errores[] = 'El email es obligatorio.';

    // Check duplicate email (excluding self)
    $dupEmail = $db->fetchColumn(
        "SELECT COUNT(*) FROM usuarios_internos WHERE email = ? AND id != ?",
        [$email, $id]
    );
    if ($dupEmail) $errores[] = 'Ya existe otro usuario con ese email.';

    if (!empty($newPassword)) {
        if (strlen($newPassword) < 8 ||
            !preg_match('/[A-Z]/', $newPassword) ||
            !preg_match('/[a-z]/', $newPassword) ||
            !preg_match('/[0-9]/', $newPassword)) {
            $errores[] = 'La nueva contraseña debe tener al menos 8 caracteres, mayúsculas, minúsculas y números.';
        }
    }

    if (empty($errores)) {
        $datos = [
            'nombre'        => $nombre,
            'apellidos'     => $apellidos,
            'email'         => $email,
            'telefono'      => $telefono,
            'especialidades'=> $especialidades,
            'sitio_web'     => $sitio_web,
        ];

        if (!empty($newPassword)) {
            $datos['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        // Handle photo upload
        if (!empty($_FILES['foto']['name']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                // Delete old photo
                if (!empty($abogado['foto'])) {
                    $oldPath = CRM_ROOT . '/public/' . $abogado['foto'];
                    if (file_exists($oldPath)) @unlink($oldPath);
                }
                $fotoName = 'abogado_' . $id . '_' . time() . '.' . $ext;
                $fotoDir  = CRM_ROOT . '/public/uploads/perfiles/';
                if (!is_dir($fotoDir)) @mkdir($fotoDir, 0755, true);
                if (move_uploaded_file($_FILES['foto']['tmp_name'], $fotoDir . $fotoName)) {
                    $datos['foto'] = 'uploads/perfiles/' . $fotoName;
                }
            } else {
                $errores[] = 'Solo se permiten imágenes JPG, PNG o WebP.';
            }
        }

        // Handle photo removal
        if (isset($_POST['eliminar_foto']) && $_POST['eliminar_foto'] === '1') {
            if (!empty($abogado['foto'])) {
                $oldPath = CRM_ROOT . '/public/' . $abogado['foto'];
                if (file_exists($oldPath)) @unlink($oldPath);
            }
            $datos['foto'] = null;
        }

        if (empty($errores)) {
            $db->update('usuarios_internos', $datos, 'id = ?', [$id]);
            AuditLog::registrar('editar', 'usuarios_internos', $id, "Perfil de abogado actualizado: $email");

            // Refresh abogado data
            $abogado = $db->fetchOne("SELECT * FROM usuarios_internos WHERE id = ?", [$id]);
            setFlash('exito', 'Perfil actualizado correctamente.');
            header('Location: ' . APP_URL . '/index.php?page=abogados/editar&id=' . $id); exit;
        }
    }
}

$tituloPagina = 'Editar Abogado';
include CRM_ROOT . '/templates/layout/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
        <h6 class="fw-semibold mb-4">Editar Abogado</h6>
        <p class="text-secondary-light mb-0 text-sm">Modifica los datos y foto de perfil del abogado.</p>
    </div>
    <a href="<?php echo APP_URL; ?>/index.php?page=abogados" class="btn btn-sm btn-outline-secondary radius-8">
        <iconify-icon icon="solar:arrow-left-outline" class="me-1"></iconify-icon> Volver al Directorio
    </a>
</div>

<?php $flash = getFlash(); if ($flash): ?>
<div class="alert alert-<?php echo $flash['tipo'] === 'exito' ? 'success' : 'danger'; ?> alert-dismissible fade show radius-8 mb-24" role="alert">
    <iconify-icon icon="<?php echo $flash['tipo'] === 'exito' ? 'solar:check-circle-outline' : 'solar:danger-triangle-outline'; ?>" class="me-2"></iconify-icon>
    <?php echo e($flash['mensaje']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <?php if (!empty($errores)): ?>
        <div class="alert alert-danger radius-8 mb-24">
            <ul class="mb-0">
                <?php foreach ($errores as $err): ?>
                <li><?php echo e($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <?php echo CSRF::campo(); ?>
            <div class="row g-24">

                <!-- === FOTO DE PERFIL === -->
                <div class="col-12">
                    <div class="card radius-12 border shadow-sm">
                        <div class="card-body p-24">
                            <h6 class="fw-semibold mb-20 d-flex align-items-center gap-2">
                                <iconify-icon icon="solar:camera-add-outline" class="text-primary-600 text-xl"></iconify-icon>
                                Foto de Perfil
                            </h6>
                            <div class="d-flex align-items-center gap-24 flex-wrap">
                                <!-- Current Photo Preview -->
                                <div class="position-relative" id="avatarWrapper" style="flex-shrink:0;">
                                    <?php if (!empty($abogado['foto'])): ?>
                                        <img id="avatarPreview"
                                             src="<?php echo APP_URL . '/public/' . e($abogado['foto']); ?>"
                                             alt="Foto"
                                             class="w-120-px h-120-px rounded-circle object-fit-cover border shadow"
                                             style="transition: opacity 0.2s;">
                                    <?php else: ?>
                                        <div id="avatarInitial" class="w-120-px h-120-px bg-primary-100 text-primary-600 rounded-circle d-flex align-items-center justify-content-center fw-bold mx-auto border shadow"
                                             style="font-size:2.5rem;">
                                            <?php echo strtoupper(substr($abogado['nombre'], 0, 1)); ?>
                                        </div>
                                        <img id="avatarPreview" src="" alt="" class="w-120-px h-120-px rounded-circle object-fit-cover border shadow d-none">
                                    <?php endif; ?>
                                </div>

                                <!-- Upload Controls -->
                                <div class="flex-grow-1">
                                    <label class="form-label fw-semibold mb-8">Cambiar Foto</label>
                                    <div class="d-flex align-items-center gap-3 flex-wrap">
                                        <label for="fotoInput" class="btn btn-outline-primary radius-8 btn-sm d-flex align-items-center gap-2 mb-0" style="cursor:pointer;">
                                            <iconify-icon icon="solar:upload-outline"></iconify-icon>
                                            Subir imagen
                                        </label>
                                        <input type="file" name="foto" id="fotoInput" accept="image/jpeg,image/png,image/webp" class="d-none">
                                        <span class="text-secondary-light text-sm" id="fotoFileName">Ningún archivo seleccionado</span>
                                    </div>
                                    <p class="text-secondary-light text-xs mt-8 mb-0">JPG, PNG o WebP. Tamaño máximo 5 MB. La imagen se recortará en círculo.</p>
                                    <?php if (!empty($abogado['foto'])): ?>
                                    <div class="mt-12">
                                        <label class="d-flex align-items-center gap-2 text-danger text-sm" style="cursor:pointer;">
                                            <input type="checkbox" name="eliminar_foto" value="1" id="chkEliminarFoto" class="form-check-input mt-0">
                                            <iconify-icon icon="solar:trash-bin-trash-outline"></iconify-icon>
                                            Eliminar foto actual
                                        </label>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- === DATOS PERSONALES === -->
                <div class="col-12">
                    <div class="card radius-12 border shadow-sm">
                        <div class="card-body p-24">
                            <h6 class="fw-semibold mb-20 d-flex align-items-center gap-2">
                                <iconify-icon icon="solar:user-id-outline" class="text-primary-600 text-xl"></iconify-icon>
                                Datos Personales
                            </h6>
                            <div class="row gy-3">
                                <div class="col-sm-6">
                                    <label class="form-label fw-semibold">Nombre <span class="text-danger">*</span></label>
                                    <input type="text" name="nombre" class="form-control radius-8"
                                           value="<?php echo e($_POST['nombre'] ?? $abogado['nombre']); ?>" required>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label fw-semibold">Apellidos</label>
                                    <input type="text" name="apellidos" class="form-control radius-8"
                                           value="<?php echo e($_POST['apellidos'] ?? $abogado['apellidos']); ?>">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control radius-8"
                                           value="<?php echo e($_POST['email'] ?? $abogado['email']); ?>" required>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label fw-semibold">Teléfono / WhatsApp</label>
                                    <input type="text" name="telefono" class="form-control radius-8"
                                           value="<?php echo e($_POST['telefono'] ?? $abogado['telefono']); ?>"
                                           placeholder="+34 600 000 000">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label fw-semibold">Sitio Web</label>
                                    <input type="url" name="sitio_web" class="form-control radius-8"
                                           value="<?php echo e($_POST['sitio_web'] ?? $abogado['sitio_web']); ?>"
                                           placeholder="https://miweb.com">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label fw-semibold">Especialidades</label>
                                    <input type="text" name="especialidades" class="form-control radius-8"
                                           value="<?php echo e($_POST['especialidades'] ?? $abogado['especialidades']); ?>"
                                           placeholder="Derecho Penal, Civil, Familia...">
                                    <small class="text-secondary-light">Separa por comas.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- === CAMBIO DE CONTRASEÑA === -->
                <div class="col-12">
                    <div class="card radius-12 border shadow-sm">
                        <div class="card-body p-24">
                            <h6 class="fw-semibold mb-4 d-flex align-items-center gap-2">
                                <iconify-icon icon="solar:lock-password-outline" class="text-primary-600 text-xl"></iconify-icon>
                                Cambiar Contraseña
                            </h6>
                            <p class="text-secondary-light text-sm mb-20">Deja el campo vacío si no deseas cambiar la contraseña.</p>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Nueva Contraseña</label>
                                <input type="password" name="new_password" class="form-control radius-8"
                                       minlength="8" placeholder="Mínimo 8 caracteres">
                                <small class="text-secondary-light">Debe tener mayúsculas, minúsculas y números.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- === SUBMIT === -->
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <a href="<?php echo APP_URL; ?>/index.php?page=abogados/ver&id=<?php echo $id; ?>"
                           class="btn btn-outline-secondary radius-8 px-24">
                            Ver Perfil Completo
                        </a>
                        <button type="submit" class="btn btn-primary radius-8 px-32 py-10 d-flex align-items-center gap-2">
                            <iconify-icon icon="solar:diskette-outline" class="text-lg"></iconify-icon>
                            Guardar Cambios
                        </button>
                    </div>
                </div>

            </div>
        </form>
    </div>
</div>

<script>
// Preview photo before upload
document.getElementById('fotoInput').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    document.getElementById('fotoFileName').textContent = file.name;
    const reader = new FileReader();
    reader.onload = function(e) {
        const preview = document.getElementById('avatarPreview');
        const initial = document.getElementById('avatarInitial');
        if (preview) {
            preview.src = e.target.result;
            preview.classList.remove('d-none');
        }
        if (initial) initial.classList.add('d-none');
    };
    reader.readAsDataURL(file);
});

// If "eliminar_foto" checked, show placeholder
const chkEliminar = document.getElementById('chkEliminarFoto');
if (chkEliminar) {
    chkEliminar.addEventListener('change', function() {
        const preview = document.getElementById('avatarPreview');
        if (this.checked && preview) {
            preview.style.opacity = '0.3';
        } else if (preview) {
            preview.style.opacity = '1';
        }
    });
}
</script>

<?php include CRM_ROOT . '/templates/layout/footer.php'; ?>
