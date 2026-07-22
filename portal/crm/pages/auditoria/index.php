<?php
/**
 * CRM Abogados - Registro de Auditoría (solo Admin)
 */
$tituloPagina = 'Auditoría';
include CRM_ROOT . '/templates/layout/header.php';
$db = Database::getInstance();

$filtros = [];
if (!empty($_GET['accion'])) $filtros['accion'] = $_GET['accion'];
if (!empty($_GET['desde']))  $filtros['desde']  = $_GET['desde'];
if (!empty($_GET['hasta']))  $filtros['hasta']  = $_GET['hasta'] . ' 23:59:59';

$logs = AuditLog::obtener(200, $filtros);
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Registro de Auditoría</h6>
</div>

<div class="card radius-8 border mb-24">
    <div class="card-body p-20">
        <form class="d-flex flex-wrap align-items-end gap-3">
            <input type="hidden" name="page" value="auditoria">
            <div><label class="text-sm mb-4">Acción</label><input type="text" name="accion" class="form-control form-control-sm radius-8" value="<?php echo e($_GET['accion'] ?? ''); ?>" placeholder="Ej: login, crear, editar..."></div>
            <div><label class="text-sm mb-4">Desde</label><input type="date" name="desde" class="form-control form-control-sm radius-8" value="<?php echo e($_GET['desde'] ?? ''); ?>"></div>
            <div><label class="text-sm mb-4">Hasta</label><input type="date" name="hasta" class="form-control form-control-sm radius-8" value="<?php echo e($_GET['hasta'] ?? ''); ?>"></div>
            <button type="submit" class="btn btn-sm btn-primary radius-8">Filtrar</button>
            <a href="<?php echo APP_URL; ?>/index.php?page=auditoria" class="btn btn-sm btn-outline-secondary radius-8">Limpiar</a>
        </form>
    </div>
</div>

<div class="card radius-8 border">
    <div class="card-body p-24">
        <div class="table-responsive scroll-sm">
            <table class="table bordered-table sm-table mb-0" id="tablaAuditoria">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Usuario</th>
                        <th>Acción</th>
                        <th>Detalles</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="text-sm"><?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?></td>
                        <td class="text-sm"><?php echo e($log['usuario_nombre'] ?: 'Sistema'); ?></td>
                        <td><span class="badge bg-neutral-200 radius-4 px-8 py-4"><?php echo e($log['accion']); ?></span></td>
                        <td class="text-sm" style="max-width:360px;overflow:hidden;text-overflow:ellipsis"><?php echo e($log['detalles'] ?: '-'); ?></td>
                        <td class="text-sm"><?php echo e($log['ip']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
include CRM_ROOT . '/templates/layout/footer.php';
