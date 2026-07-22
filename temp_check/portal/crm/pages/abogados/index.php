<?php
/**
 * CRM Abogados - Listado de Abogados (Vista Grid)
 */
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_abogado'])) {
    CSRF::verificarOAbortar();
    $idAbog = (int)$_POST['abogado_id'];
    $db->update('usuarios_internos', ['activo' => 0], 'id = ?', [$idAbog]);
    AuditLog::registrar('eliminar', 'usuarios_internos', $idAbog, 'Abogado desactivado/eliminado desde el directorio');
    setFlash('exito', 'Abogado eliminado correctamente');
    header('Location: ' . APP_URL . '/index.php?page=abogados'); exit;
}

$tituloPagina = 'Directorio de Abogados';

// Obtener todos los usuarios con rol abogado
$abogados = $db->fetchAll(
    "SELECT a.*, 
    (SELECT COUNT(*) FROM casos c WHERE c.abogado_id = a.id AND c.estado NOT IN ('cerrado', 'archivado')) as casos_activos,
    (SELECT COUNT(*) FROM casos c WHERE c.abogado_id = a.id) as total_casos
    FROM usuarios_internos a 
    WHERE a.rol = 'abogado' AND a.activo = 1 
    ORDER BY a.nombre ASC"
);

include CRM_ROOT . '/templates/layout/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Directorio de Abogados</h6>
    <div class="d-flex align-items-center gap-2">
        <a href="<?php echo APP_URL; ?>/index.php?page=abogados/crear" class="btn btn-primary radius-8 d-flex align-items-center gap-2">
            <iconify-icon icon="solar:user-plus-outline" class="text-xl"></iconify-icon>
            Agregar Nuevo Abogado
        </a>
    </div>
</div>

<div class="row gy-4">
    <?php if (empty($abogados)): ?>
        <div class="col-12">
            <div class="card p-24 text-center">
                <div class="card-body">
                    <iconify-icon icon="solar:users-group-two-rounded-outline" class="text-6xl text-secondary-light mb-16"></iconify-icon>
                    <h5 class="mb-8">No hay abogados registrados</h5>
                    <p class="text-secondary-light">Comienza agregando profesionales a tu equipo jurídico.</p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($abogados as $abogado): ?>
            <div class="col-xxl-3 col-md-4 col-sm-6">
                <div class="card contact-card radius-12 border h-100 shadow-none hover-shadow position-relative">
                    <div class="card-body p-24">
                        <!-- Card Header Actions (Top layer) -->
                        <div class="d-flex justify-content-end align-items-start mb-16 position-relative" style="z-index: 9;">
                            <div class="dropdown">
                                <button class="btn btn-sm text-secondary-light p-0 border-0" type="button" data-bs-toggle="dropdown">
                                    <iconify-icon icon="entypo:dots-three-vertical" class="text-xl"></iconify-icon>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                    <li><a class="dropdown-item py-8 px-16 d-flex align-items-center gap-2" href="<?php echo APP_URL; ?>/index.php?page=usuarios/editar&id=<?php echo $abogado['id']; ?>">
                                        <iconify-icon icon="solar:pen-new-square-outline"></iconify-icon> Editar
                                    </a></li>
                                    <li>
                                        <form method="POST" data-confirm="¿Eliminar a este abogado? Esta acción no se puede deshacer." style="margin:0;">
                                            <?php echo CSRF::campo(); ?>
                                            <input type="hidden" name="eliminar_abogado" value="1">
                                            <input type="hidden" name="abogado_id" value="<?php echo $abogado['id']; ?>">
                                            <button type="submit" class="dropdown-item py-8 px-16 d-flex align-items-center gap-2 text-danger w-100 border-0 bg-transparent">
                                                <iconify-icon icon="solar:trash-bin-trash-outline"></iconify-icon> Eliminar
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- Card Content (Clickable via stretched-link) -->
                        <div class="text-center">
                            <a href="<?php echo APP_URL; ?>/index.php?page=abogados/ver&id=<?php echo $abogado['id']; ?>" class="stretched-link"></a>
                            <div class="mb-16 d-inline-block position-relative">
                                <?php if ($abogado['avatar']): ?>
                                    <img src="<?php echo APP_URL . '/' . e($abogado['avatar']); ?>" alt="" class="w-80-px h-80-px rounded-circle object-fit-cover border-2 border-white shadow-sm">
                                <?php else: ?>
                                    <div class="w-80-px h-80-px rounded-circle bg-neutral-100 text-secondary-light d-flex justify-content-center align-items-center text-3xl fw-bold border-2 border-white shadow-sm">
                                        <?php echo strtoupper(substr($abogado['nombre'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <h6 class="mb-4 fw-bold text-dark h5"><?php echo e($abogado['nombre'] . ' ' . $abogado['apellidos']); ?></h6>
                            <p class="text-sm text-secondary-light mb-4"><?php echo e($abogado['email']); ?></p>
                            <p class="text-sm text-secondary-light mb-12"><?php echo e($abogado['telefono'] ?? '+00 000 000 000'); ?></p>
                            
                            <div class="d-flex align-items-center justify-content-center gap-2 mb-20">
                                <span class="w-8-px h-8-px bg-success-main rounded-circle"></span>
                                <span class="text-xs fw-semibold text-secondary-light">Abogado</span>
                            </div>
                        </div>

                        <!-- Card Footer Actions (Top layer) -->
                        <div class="row g-2 border-top pt-20 position-relative z-1">
                            <div class="col-6">
                                <a href="mailto:<?php echo e($abogado['email']); ?>" class="btn btn-outline-light-custom w-100 btn-sm radius-8 d-flex align-items-center justify-content-center gap-1 py-8">
                                    <iconify-icon icon="solar:letter-outline" class="text-lg"></iconify-icon>
                                    <span class="text-xs fw-bold">Mensaje</span>
                                </a>
                            </div>
                            <div class="col-6">
                                <a href="<?php echo APP_URL; ?>/index.php?page=abogados/ver&id=<?php echo $abogado['id']; ?>" class="btn btn-outline-light-custom w-100 btn-sm radius-8 d-flex align-items-center justify-content-center gap-1 py-8">
                                    <iconify-icon icon="solar:user-outline" class="text-lg"></iconify-icon>
                                    <span class="text-xs fw-bold">Perfil</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
.contact-card {
    transition: all 0.2s ease-in-out;
    cursor: pointer;
}
.contact-card.hover-shadow:hover {
    box-shadow: 0 10px 25px rgba(0,0,0,0.1) !important;
    border-color: var(--primary-color) !important;
}
.z-1 { z-index: 1; }
.btn-outline-light-custom {
    border: 1px solid #e2e8f0;
    color: #64748b;
    background: #fff;
    transition: all 0.2s;
}
.btn-outline-light-custom:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: var(--primary-color);
}
</style>

<?php include CRM_ROOT . '/templates/layout/footer.php'; ?>
