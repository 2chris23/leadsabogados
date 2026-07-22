<?php
/**
 * CRM Abogados - Listado de Casos
 */
$tituloPagina = 'Casos';
include CRM_ROOT . '/templates/layout/header.php';
$db = Database::getInstance();

// Filtros
$filtroEstado = $_GET['estado'] ?? '';
$filtroAbogado = $_GET['abogado'] ?? '';

$where = '1=1';
$params = [];

// Un abogado solo ve sus propios casos
if ($auth->esAbogado()) {
    $where .= ' AND c.abogado_id = ?';
    $params[] = $usuario['id'];
}

if ($filtroEstado) { $where .= ' AND c.estado = ?'; $params[] = $filtroEstado; }
if ($filtroAbogado) { $where .= ' AND c.abogado_id = ?'; $params[] = (int)$filtroAbogado; }

$casos = $db->fetchAll(
    "SELECT c.*, cl.nombre as cliente_nombre, cl.apellidos as cliente_apellidos,
            u.nombre as abogado_nombre, u.apellidos as abogado_apellidos,
            COALESCE((SELECT SUM(p.cantidad) FROM pagos p WHERE p.caso_id = c.id), 0) as total_pagado
     FROM casos c
     JOIN clientes cl ON c.cliente_id = cl.id
     LEFT JOIN usuarios_internos u ON c.abogado_id = u.id
     WHERE $where ORDER BY c.created_at DESC", $params
);

$abogados = $db->fetchAll("SELECT id, nombre, apellidos FROM usuarios_internos WHERE rol = 'abogado' AND activo = 1 ORDER BY nombre");
$estados = ['en_estudio','en_proceso','en_tramitacion','pendiente_juicio','cerrado','archivado'];
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Casos</h6>
    <ul class="d-flex align-items-center gap-2">
        <li><a href="<?php echo APP_URL; ?>/index.php?page=dashboard" class="hover-text-primary">Dashboard</a></li>
        <li>-</li><li>Casos</li>
    </ul>
</div>

<!-- Filtros -->
<div class="card radius-8 border mb-24">
    <div class="card-body p-20">
        <form class="d-flex flex-wrap align-items-end gap-3">
            <input type="hidden" name="page" value="casos">
            <div>
                <label class="text-sm text-secondary-light mb-4">Estado</label>
                <select name="estado" class="form-select form-select-sm radius-8" style="min-width:160px">
                    <option value="">Todos</option>
                    <?php foreach ($estados as $est): ?>
                    <option value="<?php echo $est; ?>" <?php echo $filtroEstado === $est ? 'selected' : ''; ?>>
                        <?php echo ucfirst(str_replace('_', ' ', $est)); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($auth->esAdmin()): ?>
            <div>
                <label class="text-sm text-secondary-light mb-4">Abogado</label>
                <select name="abogado" class="form-select form-select-sm radius-8" style="min-width:180px">
                    <option value="">Todos</option>
                    <?php foreach ($abogados as $ab): ?>
                    <option value="<?php echo $ab['id']; ?>" <?php echo $filtroAbogado == $ab['id'] ? 'selected' : ''; ?>>
                        <?php echo e($ab['nombre'] . ' ' . $ab['apellidos']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-sm btn-primary radius-8">Filtrar</button>
            <a href="<?php echo APP_URL; ?>/index.php?page=casos" class="btn btn-sm btn-outline-secondary radius-8">Limpiar</a>
        </form>
    </div>
</div>

<div class="card radius-8 border">
    <div class="card-body p-24">
        <div class="table-responsive scroll-sm">
            <table class="table bordered-table sm-table mb-0" id="tablaCasos">
                <thead>
                    <tr>
                        <th>Ref.</th>
                        <th>Cliente</th>
                        <th>Título</th>
                        <th>Abogado</th>
                        <th>Estado</th>
                        <?php if ($auth->esAdmin()): ?><th>Pagado / Total</th><?php endif; ?>
                        <th>Apertura</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($casos as $caso):
                        $estadoBadge = match($caso['estado']) {
                            'en_estudio' => 'bg-primary-100 text-primary-600',
                            'en_proceso' => 'bg-warning-focus text-warning-main',
                            'en_tramitacion' => 'bg-info-focus text-info-main',
                            'pendiente_juicio' => 'bg-danger-focus text-danger-main',
                            'cerrado' => 'bg-success-focus text-success-main',
                            'archivado' => 'bg-neutral-200 text-neutral-600',
                            default => 'bg-neutral-200'
                        };
                    ?>
                    <tr>
                        <td class="text-sm fw-medium"><?php echo e($caso['referencia']); ?></td>
                        <td>
                            <a href="<?php echo APP_URL; ?>/index.php?page=clientes/ver&id=<?php echo $caso['cliente_id']; ?>" class="text-primary-600">
                                <?php echo e($caso['cliente_nombre'] . ' ' . $caso['cliente_apellidos']); ?>
                            </a>
                        </td>
                        <td>
                            <a href="<?php echo APP_URL; ?>/index.php?page=casos/ver&id=<?php echo $caso['id']; ?>" class="fw-medium text-primary-600">
                                <?php echo e($caso['titulo']); ?>
                            </a>
                        </td>
                        <td class="text-sm"><?php echo $caso['abogado_nombre'] ? e($caso['abogado_nombre'] . ' ' . $caso['abogado_apellidos']) : '<em class="text-secondary-light">Sin asignar</em>'; ?></td>
                        <td><span class="badge <?php echo $estadoBadge; ?> radius-4 px-8 py-4"><?php echo ucfirst(str_replace('_',' ',$caso['estado'])); ?></span></td>
                        <?php if ($auth->esAdmin()): ?><td class="text-sm">€<?php echo number_format($caso['total_pagado'],2,',','.'); ?> / €<?php echo number_format($caso['honorarios_totales'],2,',','.'); ?></td><?php endif; ?>
                        <td class="text-sm"><?php echo date('d/m/Y', strtotime($caso['fecha_apertura'])); ?></td>
                        <td class="text-center">
                            <a href="<?php echo APP_URL; ?>/index.php?page=casos/ver&id=<?php echo $caso['id']; ?>"
                                class="bg-info-focus text-info-main w-32-px h-32-px d-flex justify-content-center align-items-center rounded-circle">
                                <iconify-icon icon="iconamoon:eye-light"></iconify-icon>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
include CRM_ROOT . '/templates/layout/footer.php';



