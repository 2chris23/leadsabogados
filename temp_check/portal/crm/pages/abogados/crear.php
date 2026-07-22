<?php
/**
 * CRM Abogados - Registrar Nuevo Abogado
 */
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verificarOAbortar();
    $nombre = trim($_POST['nombre']);
    $apellidos = trim($_POST['apellidos']);
    $email = trim(strtolower($_POST['email']));
    $password = $_POST['password'];
    $telefono = trim($_POST['telefono']);
    
    $errores = [];
    if (empty($nombre)) $errores[] = 'El nombre es obligatorio';
    if (empty($email)) $errores[] = 'El email es obligatorio';
    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) $errores[] = 'La contraseña debe tener al menos 8 caracteres, mayúsculas, minúsculas y números';
    if ($db->fetchColumn("SELECT COUNT(*) FROM usuarios_internos WHERE email = ?", [$email])) $errores[] = 'Ya existe un usuario con ese email';
    
    if (empty($errores)) {
        $id = $db->insert('usuarios_internos', [
            'nombre' => $nombre, 
            'apellidos' => $apellidos, 
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'rol' => 'abogado', 
            'telefono' => $telefono, 
            'activo' => 1
        ]);
        AuditLog::registrar('crear', 'usuarios_internos', $id, "Nuevo abogado creado: $email");
        setFlash('exito', 'Abogado registrado correctamente');
        header('Location: ' . APP_URL . '/index.php?page=abogados'); exit;
    }
}

$tituloPagina = 'Registrar Abogado';
include CRM_ROOT . '/templates/layout/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Registrar Nuevo Abogado</h6>
    <a href="<?php echo APP_URL; ?>/index.php?page=abogados" class="btn btn-sm btn-outline-secondary radius-8">
        <iconify-icon icon="solar:arrow-left-outline" class="me-1"></iconify-icon> Volver al Listado
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card radius-12 border shadow-sm">
            <div class="card-body p-24">
                <?php if (!empty($errores)): ?>
                <div class="alert alert-danger mb-16">
                    <ul class="mb-0"><?php foreach ($errores as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul>
                </div>
                <?php endif; ?>

                <form method="POST">
                    <?php echo CSRF::campo(); ?>
                    <div class="row gy-3">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Nombre <span class="text-danger">*</span></label>
                            <input type="text" name="nombre" class="form-control radius-8" value="<?php echo e($_POST['nombre'] ?? ''); ?>" required placeholder="Ej: Juan">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Apellidos <span class="text-danger">*</span></label>
                            <input type="text" name="apellidos" class="form-control radius-8" value="<?php echo e($_POST['apellidos'] ?? ''); ?>" required placeholder="Ej: Pérez García">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Correo Electrónico <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control radius-8" value="<?php echo e($_POST['email'] ?? ''); ?>" required placeholder="abogado@despacho.com">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Teléfono / WhatsApp</label>
                            <input type="text" name="telefono" class="form-control radius-8" value="<?php echo e($_POST['telefono'] ?? ''); ?>" placeholder="+34 600 000 000">
                        </div>
                        <div class="col-sm-12">
                            <label class="form-label fw-semibold">Contraseña de Acceso <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control radius-8" minlength="8" required placeholder="Mínimo 8 caracteres">
                            <small class="text-secondary-light">Esta será la clave inicial del abogado.</small>
                        </div>
                    </div>
                    
                    <div class="d-flex align-items-center justify-content-end gap-3 mt-32">
                        <a href="<?php echo APP_URL; ?>/index.php?page=abogados" class="text-secondary-light hover-text-primary fw-medium text-decoration-none">Cancelar</a>
                        <button type="submit" class="btn btn-primary radius-8 px-24 py-10 d-flex align-items-center gap-2">
                            <iconify-icon icon="solar:diskette-outline" class="text-lg"></iconify-icon> Registrar Abogado
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include CRM_ROOT . '/templates/layout/footer.php'; ?>
