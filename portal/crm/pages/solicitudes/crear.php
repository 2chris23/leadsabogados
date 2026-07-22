<?php
/**
 * CRM Abogados - Crear Solicitud
 */
$tituloPagina = 'Crear Solicitud';
if (!$auth->esAdmin()) {
    header('Location: index.php?page=solicitudes');
    exit;
}

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verificarOAbortar();
    
    $nombre = trim($_POST['nombre'] ?? '');
    $apellidos = trim($_POST['apellidos'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $tipo_problema = trim($_POST['tipo_problema'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    
    $errores = [];
    if (!$nombre) $errores[] = 'El nombre es obligatorio';
    if (!$apellidos) $errores[] = 'Los apellidos son obligatorios';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'Email inválido';
    if (!$tipo_problema) $errores[] = 'El tipo de problema es obligatorio';
    
    if (empty($errores)) {
        try {
            $solId = $db->insert('solicitudes', [
                'nombre' => $nombre,
                'apellidos' => $apellidos,
                'email' => $email,
                'telefono' => $telefono,
                'tipo_problema' => $tipo_problema,
                'descripcion' => $descripcion,
                'estado' => 'pendiente',
                'procesada_por' => null
            ]);
            
            AuditLog::registrar('crear', 'solicitudes', $solId, "Nueva solicitud creada manualmente: $email");
            setFlash('exito', 'Solicitud creada correctamente');
            header('Location: ' . APP_URL . '/index.php?page=solicitudes/ver&id=' . $solId);
            exit;
        } catch (Exception $e) {
            $errores[] = 'Error al crear la solicitud: ' . $e->getMessage();
        }
    }
    
    if (!empty($errores)) {
        setFlash('error', implode('<br>', $errores));
    }
}

include CRM_ROOT . '/templates/layout/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Crear Solicitud</h6>
    <ul class="d-flex align-items-center gap-2">
        <li class="fw-medium"><a href="<?php echo APP_URL; ?>/index.php?page=dashboard" class="hover-text-primary">Dashboard</a></li>
        <li>-</li>
        <li class="fw-medium"><a href="<?php echo APP_URL; ?>/index.php?page=solicitudes" class="hover-text-primary">Solicitudes</a></li>
        <li>-</li>
        <li class="fw-medium">Crear</li>
    </ul>
</div>

<div class="row">
    <div class="col-lg-8 mx-auto">
        <div class="card radius-8 border">
            <div class="card-body p-24">
                <form method="POST">
                    <?php echo CSRF::campo(); ?>
                    <div class="row gy-3">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Nombre <span class="text-danger">*</span></label>
                            <input type="text" name="nombre" class="form-control radius-8" value="<?php echo e($_POST['nombre'] ?? ''); ?>" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Apellidos <span class="text-danger">*</span></label>
                            <input type="text" name="apellidos" class="form-control radius-8" value="<?php echo e($_POST['apellidos'] ?? ''); ?>" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Correo Electrónico <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control radius-8" value="<?php echo e($_POST['email'] ?? ''); ?>" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Teléfono</label>
                            <input type="text" name="telefono" class="form-control radius-8" value="<?php echo e($_POST['telefono'] ?? ''); ?>">
                        </div>
                        <div class="col-sm-12">
                            <label class="form-label fw-semibold">Tipo de Consulta <span class="text-danger">*</span></label>
                            <select name="tipo_problema" class="form-control radius-8" required>
                                <option value="">Seleccione...</option>
                                <?php
                                $tipos = ['Civil','Penal','Laboral','Mercantil','Inmobiliario','Familia','Extranjería','Administrativo','Otro'];
                                foreach ($tipos as $t):
                                    $sel = (($_POST['tipo_problema'] ?? '') === $t) ? 'selected' : '';
                                    echo "<option value=\"$t\" $sel>$t</option>";
                                endforeach;
                                ?>
                            </select>
                        </div>
                        <div class="col-sm-12">
                            <label class="form-label fw-semibold">Descripción del Caso</label>
                            <textarea name="descripcion" class="form-control radius-8" rows="5"><?php echo e($_POST['descripcion'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-sm-12 mt-20">
                            <button type="submit" class="btn btn-primary radius-8 px-24 py-12">Crear Solicitud</button>
                            <a href="<?php echo APP_URL; ?>/index.php?page=solicitudes" class="btn btn-neutral radius-8 px-24 py-12 ms-8">Cancelar</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include CRM_ROOT . '/templates/layout/footer.php'; ?>
