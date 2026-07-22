<?php
/**
 * CRM Abogados - Crear Usuario
 */
$db = Database::getInstance();

/**
 * Normaliza un número de teléfono internacional
 * Soporta formatos: +584144016009, +5804144016009, 04144016009, 4144016009
 */
function normalizarTelefono($codigoPais, $numero) {
    // Limpiar: solo dejar dígitos
    $codigoPais = preg_replace('/[^\d]/', '', $codigoPais);
    $numero = preg_replace('/[^\d]/', '', $numero);
    
    if (empty($numero)) return '';
    
    // Si el número empieza con 0, quitarlo (ej: 04144016009 → 4144016009)
    $numero = ltrim($numero, '0');
    
    return '+' . $codigoPais . $numero;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verificarOAbortar();
    $nombre = trim($_POST['nombre']);
    $apellidos = trim($_POST['apellidos']);
    $email = trim(strtolower($_POST['email']));
    $password = $_POST['password'];
    $rol = $_POST['rol'];
    
    // Teléfono simple
    $telefono = trim($_POST['telefono'] ?? '');

    $errores = [];
    if (empty($nombre)) $errores[] = 'El nombre es obligatorio';
    if (empty($email)) $errores[] = 'El email es obligatorio';
    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) $errores[] = 'La contraseña debe tener al menos 8 caracteres, mayúsculas, minúsculas y números';
    if (!in_array($rol, ['admin','abogado','gestor'])) $errores[] = 'Rol inválido';
    if ($db->fetchColumn("SELECT COUNT(*) FROM usuarios_internos WHERE email = ?", [$email])) $errores[] = 'Ya existe un usuario con ese email';

    if (empty($errores)) {
        $id = $db->insert('usuarios_internos', [
            'nombre' => $nombre, 'apellidos' => $apellidos, 'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'rol' => $rol, 'telefono' => $telefono, 'activo' => 1
        ]);
        AuditLog::registrar('crear', 'usuarios_internos', $id, "Nuevo usuario creado: $email ($rol)");
        setFlash('exito', 'Usuario creado correctamente');
        header('Location: ' . APP_URL . '/index.php?page=usuarios'); exit;
    }
}

$tituloPagina = 'Crear Usuario';
include CRM_ROOT . '/templates/layout/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Nuevo Usuario</h6>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card radius-8 border">
            <div class="card-body p-24">
                <?php if (!empty($errores)): ?>
                <div class="alert alert-danger mb-16">
                    <ul class="mb-0"><?php foreach ($errores as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul>
                </div>
                <?php endif; ?>
                <form method="POST">
                    <?php echo CSRF::campo(); ?>
                    <div class="row gy-3">
                        <div class="col-sm-6"><label class="form-label">Nombre <span class="text-danger">*</span></label><input type="text" name="nombre" class="form-control radius-8" value="<?php echo e($_POST['nombre'] ?? ''); ?>" required></div>
                        <div class="col-sm-6"><label class="form-label">Apellidos <span class="text-danger">*</span></label><input type="text" name="apellidos" class="form-control radius-8" value="<?php echo e($_POST['apellidos'] ?? ''); ?>" required></div>
                        <div class="col-sm-6"><label class="form-label">Email <span class="text-danger">*</span></label><input type="email" name="email" class="form-control radius-8" value="<?php echo e($_POST['email'] ?? ''); ?>" required></div>
                        
                        <div class="col-sm-6">
                            <label class="form-label">Teléfono</label>
                            <input type="tel" name="telefono" class="form-control radius-8"
                                placeholder="Ej: +34 600 000 000"
                                value="<?php echo e($_POST['telefono'] ?? ''); ?>">
                        </div>
                        
                        <div class="col-sm-6"><label class="form-label">Contraseña <span class="text-danger">*</span></label><input type="password" name="password" class="form-control radius-8" minlength="8" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Debe contener al menos 8 caracteres, incluyendo una mayúscula, una minúscula y un número" required></div>
                        <div class="col-sm-6"><label class="form-label">Rol <span class="text-danger">*</span></label>
                            <select name="rol" class="form-select radius-8" required>
                                <option value="gestor">Gestor / Recepcionista</option>
                                <option value="abogado">Abogado</option>
                                <option value="admin">Administrador</option>
                            </select>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-24">
                        <button type="submit" class="btn btn-primary radius-8">Crear Usuario</button>
                        <a href="<?php echo APP_URL; ?>/index.php?page=usuarios" class="btn btn-outline-secondary radius-8">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include CRM_ROOT . '/templates/layout/footer.php'; ?>
