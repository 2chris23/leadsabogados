<?php
/**
 * CRM Abogados - Editar Usuario
 */
$db = Database::getInstance();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/index.php?page=usuarios'); exit; }
$u = $db->fetchOne("SELECT * FROM usuarios_internos WHERE id = ?", [$id]);
if (!$u) { setFlash('error', 'Usuario no encontrado'); header('Location: ' . APP_URL . '/index.php?page=usuarios'); exit; }

// Extraer código de país y número del teléfono actual
$codigoPaisActual = '';
$numActual = '';
if (!empty($u['telefono'])) {
    // Intentar parsear +XXNNNNNNN
    if (preg_match('/^\+(\d{1,3})(\d{7,})$/', $u['telefono'], $m)) {
        $codigoPaisActual = $m[1];
        $numActual = $m[2];
    } else {
        $numActual = ltrim($u['telefono'], '+');
    }
}

function normalizarTelefono($codigoPais, $numero) {
    $codigoPais = preg_replace('/[^\d]/', '', $codigoPais);
    $numero = preg_replace('/[^\d]/', '', $numero);
    if (empty($numero)) return '';
    $numero = ltrim($numero, '0');
    return '+' . $codigoPais . $numero;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verificarOAbortar();
    
    $telefono = trim($_POST['telefono'] ?? '');
    
    $datos = [
        'nombre' => trim($_POST['nombre']),
        'apellidos' => trim($_POST['apellidos']),
        'email' => trim(strtolower($_POST['email'])),
        'telefono' => $telefono,
        'rol' => $_POST['rol']
    ];
    if (!empty($_POST['password'])) {
        $password = $_POST['password'];
        if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            setFlash('error', 'La contraseña debe tener al menos 8 caracteres, mayúsculas, minúsculas y números');
            header('Location: ' . APP_URL . '/index.php?page=usuarios/editar&id=' . $id); exit;
        }
        $datos['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }
    $db->update('usuarios_internos', $datos, 'id = ?', [$id]);
    AuditLog::registrar('editar', 'usuarios_internos', $id, 'Datos del usuario actualizados');
    setFlash('exito', 'Usuario actualizado');
    header('Location: ' . APP_URL . '/index.php?page=usuarios'); exit;
}

$tituloPagina = 'Editar Usuario';
include CRM_ROOT . '/templates/layout/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Editar Usuario — <?php echo e($u['nombre'].' '.$u['apellidos']); ?></h6>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card radius-24 border shadow-sm" style="overflow: visible;">
            <div class="card-body p-40" style="overflow: visible;">
                <form method="POST">
                    <?php echo CSRF::campo(); ?>
                    <div class="row gy-32">
                        <div class="col-sm-6">
                            <label style="font-size: 0.75rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.08em; display: block; margin-bottom: 12px;">Nombre</label>
                            <input type="text" name="nombre" class="form-control radius-16 border-neutral-200 px-16" style="height: 52px;" value="<?php echo e($u['nombre']); ?>" required>
                        </div>
                        <div class="col-sm-6">
                            <label style="font-size: 0.75rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.08em; display: block; margin-bottom: 12px;">Apellidos</label>
                            <input type="text" name="apellidos" class="form-control radius-16 border-neutral-200 px-16" style="height: 52px;" value="<?php echo e($u['apellidos']); ?>" required>
                        </div>
                        <div class="col-sm-6">
                            <label style="font-size: 0.75rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.08em; display: block; margin-bottom: 12px;">Email</label>
                            <input type="email" name="email" class="form-control radius-16 border-neutral-200 px-16" style="height: 52px;" value="<?php echo e($u['email']); ?>" required>
                        </div>
                            
                            <div class="col-sm-6">
                                <label style="font-size: 0.75rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.08em; display: block; margin-bottom: 12px;">Teléfono</label>
                                <input type="tel" name="telefono" class="form-control radius-16 border-neutral-200 px-16"
                                    style="height: 52px;"
                                    placeholder="Ej: +34 600 000 000"
                                    value="<?php echo e($u['telefono'] ?? ''); ?>">
                            </div>
                            
                            <div class="col-sm-6">
                                <label style="font-size: 0.75rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.08em; display: block; margin-bottom: 12px;">Nueva Contraseña</label>
                                <input type="password" name="password" class="form-control radius-16 border-neutral-200 px-16" style="height: 52px;" placeholder="Dejar vacío para no cambiar" minlength="8" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Debe contener al menos 8 caracteres, incluyendo una mayúscula, una minúscula y un número">
                            </div>

                            <div class="col-sm-6">
                                <label style="font-size: 0.75rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.08em; display: block; margin-bottom: 12px;">Rol de Usuario</label>
                                <div class="custom-rol-select position-relative">
                                    <div id="rol-dropdown-btn" class="form-control radius-16 border-neutral-200 d-flex align-items-center justify-content-between fw-bold text-dark px-16" style="background: #f8fafc; cursor: pointer; height: 52px;">
                                        <span id="selected-rol-label">
                                            <?php 
                                                $roles = ['gestor'=>'Gestor / Recepcionista', 'abogado'=>'Abogado', 'admin'=>'Administrador'];
                                                echo $roles[$u['rol']] ?? 'Seleccionar Rol';
                                            ?>
                                        </span>
                                        <iconify-icon icon="solar:alt-arrow-down-outline"></iconify-icon>
                                    </div>
                                    <input type="hidden" name="rol" id="rol_input" value="<?php echo $u['rol']; ?>">
                                    
                                    <ul id="rol-dropdown-list" class="position-absolute w-100 bg-white border radius-16 shadow-lg mt-8 p-8 d-none" style="z-index: 9999; list-style: none;">
                                        <?php foreach ($roles as $val => $txt): ?>
                                        <li class="dropdown-item-rol p-12 radius-12 cursor-pointer fw-bold text-sm" data-value="<?php echo $val; ?>" data-label="<?php echo $txt; ?>">
                                            <?php echo $txt; ?>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-3 mt-48">
                            <button type="submit" class="btn btn-primary radius-16 px-40 py-16 fw-bold shadow-sm">Guardar Cambios</button>
                            <a href="<?php echo APP_URL; ?>/index.php?page=abogados/ver&id=<?php echo $id; ?>" class="btn btn-outline-neutral radius-16 px-40 py-16 text-secondary-main border-neutral-200 fw-bold">Cancelar</a>
                        </div>
                    </form>
            </div>
        </div>
    </div>
</div>

<style>
    .dropdown-item-phone:hover,
    .dropdown-item-rol:hover {
        background-color: #f1f5f9;
        color: #487fff !important;
    }
    #phone-dropdown-list::-webkit-scrollbar { width: 4px; }
    #phone-dropdown-list::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btn = document.getElementById('phone-dropdown-btn');
    const list = document.getElementById('phone-dropdown-list');
    const input = document.getElementById('codigo_pais_input');
    const label = document.getElementById('selected-code-label');

    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        list.classList.toggle('d-none');
    });

    document.querySelectorAll('.dropdown-item-phone').forEach(item => {
        item.addEventListener('click', function() {
            const code = this.getAttribute('data-code');
            const flag = this.getAttribute('data-flag');
            input.value = code;
            label.innerHTML = `<img src="${flag}" width="20"> +${code}`;
            list.classList.add('d-none');
        });
    });

    document.addEventListener('click', function() {
        list.classList.add('d-none');
    });

    // ---- Rol dropdown ----
    const rolBtn = document.getElementById('rol-dropdown-btn');
    const rolList = document.getElementById('rol-dropdown-list');
    const rolInput = document.getElementById('rol_input');
    const rolLabel = document.getElementById('selected-rol-label');

    rolBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        rolList.classList.toggle('d-none');
    });

    document.querySelectorAll('.dropdown-item-rol').forEach(item => {
        item.addEventListener('click', function(e) {
            e.stopPropagation();
            rolInput.value = this.getAttribute('data-value');
            rolLabel.textContent = this.getAttribute('data-label');
            rolList.classList.add('d-none');
        });
    });

    document.addEventListener('click', function() {
        rolList.classList.add('d-none');
    });
});
</script>

<?php include CRM_ROOT . '/templates/layout/footer.php'; ?>
