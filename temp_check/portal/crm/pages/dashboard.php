<?php
/**
 * CRM Abogados - Dashboard Principal (Admin + Abogado)
 * Panel de métricas con KPIs y gráficos
 */
$tituloPagina = 'Dashboard';
include CRM_ROOT . '/templates/layout/header.php';

$db = Database::getInstance();
$esAbogado = ($usuario['rol'] === 'abogado');
$abogadoId = $usuario['id'];

if ($esAbogado) {
    // ========== KPIs ABOGADO (solo sus datos) ==========
    $totalCasosActivos = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM casos WHERE abogado_id = ? AND estado NOT IN ('cerrado','archivado')", [$abogadoId]
    );
    $totalCasosCerrados = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM casos WHERE abogado_id = ? AND estado = 'cerrado'", [$abogadoId]
    );
    $totalCasosTodos = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM casos WHERE abogado_id = ?", [$abogadoId]
    );
    $solicitudesAsignadas = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM solicitudes WHERE procesada_por = ? AND estado = 'aceptada'", [$abogadoId]
    );

    // Últimos casos del abogado
    $ultimosCasos = $db->fetchAll(
        "SELECT c.*, cl.nombre as cliente_nombre, cl.apellidos as cliente_apellidos
         FROM casos c JOIN clientes cl ON c.cliente_id = cl.id
         WHERE c.abogado_id = ? ORDER BY c.updated_at DESC LIMIT 5", [$abogadoId]
    );

    // Solicitudes asignadas para revisar
    $solicitudesParaRevisar = $db->fetchAll(
        "SELECT * FROM solicitudes WHERE procesada_por = ? ORDER BY created_at DESC LIMIT 5", [$abogadoId]
    );

    $totalSolicitudesMes = 0; $totalCobradoMes = 0; $saldoPendiente = 0; $solicitudesPendientes = 0;
    $varSol = null; $varCasos = null; $varPagos = null;
    $spSolArr = json_encode([0]); $spPagosArr = json_encode([0]); $spCasosArr = json_encode([0]); $spPendArr = json_encode([0]);
    $casosPorEstado = $db->fetchAll("SELECT estado, COUNT(*) as total FROM casos WHERE abogado_id = ? GROUP BY estado", [$abogadoId]);
    $ultimasSolicitudes = [];
    $ultimosPagos = [];
} else {
    // ========== KPIs ADMIN (global) ==========
    $totalSolicitudesMes = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM solicitudes WHERE MONTH(created_at)=MONTH(CURRENT_DATE) AND YEAR(created_at)=YEAR(CURRENT_DATE)"
    );
    $totalCasosActivos = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM casos WHERE estado NOT IN ('cerrado','archivado')"
    );
    $totalCobradoMes = (float)$db->fetchColumn(
        "SELECT COALESCE(SUM(cantidad),0) FROM pagos WHERE MONTH(fecha_pago)=MONTH(CURRENT_DATE) AND YEAR(fecha_pago)=YEAR(CURRENT_DATE) AND (tipo_pago IS NULL OR tipo_pago != 'pago_abogado')"
    );
    $saldoPendiente = (float)$db->fetchColumn(
        "SELECT COALESCE(SUM(c.honorarios_totales)-COALESCE((SELECT SUM(p.cantidad) FROM pagos p WHERE p.caso_id=c.id AND (p.tipo_pago IS NULL OR p.tipo_pago != 'pago_abogado')),0),0)
         FROM casos c WHERE c.estado NOT IN ('cerrado','archivado')"
    );
    $solicitudesPendientes = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM solicitudes WHERE estado='pendiente'"
    );

    $solMesAntRaw    = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM solicitudes WHERE MONTH(created_at)=MONTH(DATE_SUB(CURRENT_DATE,INTERVAL 1 MONTH)) AND YEAR(created_at)=YEAR(DATE_SUB(CURRENT_DATE,INTERVAL 1 MONTH))"
    );
    $casosMesAntRaw  = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM casos WHERE created_at < DATE_FORMAT(CURRENT_DATE,'%Y-%m-01')"
    );
    $cobradoMesAntRaw = (float)$db->fetchColumn(
        "SELECT COALESCE(SUM(cantidad),0) FROM pagos WHERE MONTH(fecha_pago)=MONTH(DATE_SUB(CURRENT_DATE,INTERVAL 1 MONTH)) AND YEAR(fecha_pago)=YEAR(DATE_SUB(CURRENT_DATE,INTERVAL 1 MONTH)) AND (tipo_pago IS NULL OR tipo_pago != 'pago_abogado')"
    );

    function varPct($actual, $anteriorRaw) {
        if ($anteriorRaw == 0 && $actual == 0) return null;
        if ($anteriorRaw == 0) return null;
        $p = round((($actual - $anteriorRaw) / abs($anteriorRaw)) * 100, 1);
        return ['val' => abs($p), 'up' => $p >= 0];
    }
    $varSol   = varPct($totalSolicitudesMes, $solMesAntRaw);
    $varCasos = varPct($totalCasosActivos,  $casosMesAntRaw);
    $varPagos = varPct($totalCobradoMes,    $cobradoMesAntRaw);

    $spSol = $db->fetchAll("SELECT COALESCE(COUNT(*),0) as v FROM solicitudes WHERE created_at >= DATE_SUB(CURRENT_DATE,INTERVAL 6 MONTH) GROUP BY YEAR(created_at),MONTH(created_at) ORDER BY YEAR(created_at),MONTH(created_at)");
    $spPagos = $db->fetchAll("SELECT COALESCE(SUM(cantidad),0) as v FROM pagos WHERE fecha_pago >= DATE_SUB(CURRENT_DATE,INTERVAL 6 MONTH) AND (tipo_pago IS NULL OR tipo_pago != 'pago_abogado') GROUP BY YEAR(fecha_pago),MONTH(fecha_pago) ORDER BY YEAR(fecha_pago),MONTH(fecha_pago)");
    $spCasos = $db->fetchAll("SELECT COALESCE(COUNT(*),0) as v FROM casos WHERE created_at >= DATE_SUB(CURRENT_DATE,INTERVAL 6 MONTH) GROUP BY YEAR(created_at),MONTH(created_at) ORDER BY YEAR(created_at),MONTH(created_at)");
    function sparkArr($rows) { $vals = array_column($rows,'v'); return empty($vals) ? [0,0,1,0,1,2,1] : $vals; }
    $spSolArr   = json_encode(sparkArr($spSol));
    $spPagosArr = json_encode(sparkArr($spPagos));
    $spCasosArr = json_encode(sparkArr($spCasos));
    $spPendArr  = json_encode([1,2,1,3,2,4,$solicitudesPendientes]);

    $casosPorEstado = $db->fetchAll("SELECT estado, COUNT(*) as total FROM casos GROUP BY estado");
    $ultimasSolicitudes = $db->fetchAll("SELECT * FROM solicitudes ORDER BY created_at DESC LIMIT 5");
    $ultimosPagos = $db->fetchAll(
        "SELECT p.*, c.titulo as caso_titulo, cl.nombre as cliente_nombre, cl.apellidos as cliente_apellidos
         FROM pagos p JOIN casos c ON p.caso_id = c.id JOIN clientes cl ON c.cliente_id = cl.id
         ORDER BY p.created_at DESC LIMIT 5"
    );
}
?>

<!-- Breadcrumb -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0"><?php echo $esAbogado ? 'Mi Panel' : 'Dashboard'; ?></h6>
    <ul class="d-flex align-items-center gap-2">
        <li class="fw-medium">
            <a href="<?php echo APP_URL; ?>/index.php?page=dashboard" class="d-flex align-items-center gap-1 hover-text-primary">
                <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon> Dashboard
            </a>
        </li>
    </ul>
</div>

<?php if (!$esAbogado && $solicitudesPendientes > 0): ?>
<div class="alert alert-warning alert-dismissible fade show radius-8 mb-24 d-flex align-items-center gap-2" role="alert" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;">
    <iconify-icon icon="solar:bell-bing-bold-duotone" class="text-xl"></iconify-icon>
    <div style="flex:1">
        <strong>¡Nuevas solicitudes!</strong> Tienes <?php echo $solicitudesPendientes; ?> solicitud(es) pendiente(s) de revisión en el portal.
    </div>
    <a href="<?php echo APP_URL; ?>/index.php?page=solicitudes" class="btn btn-sm btn-warning radius-8 text-white fw-semibold">Revisar ahora</a>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="top:50%;transform:translateY(-50%);padding:0.5rem"></button>
</div>
<?php endif; ?>
<?php if ($esAbogado): ?>
<!-- ===== DASHBOARD ABOGADO ===== -->
<div class="row gy-4 mb-24">
    <div class="col-xxl-3 col-sm-6">
        <div class="card p-20 radius-8 border bg-gradient-start-1 h-100">
            <div class="card-body p-0">
                <div class="d-flex align-items-center gap-12 mb-16">
                    <span class="w-44-px h-44-px bg-primary-100 text-primary-600 d-flex justify-content-center align-items-center rounded-circle text-2xl">
                        <iconify-icon icon="solar:case-minimalistic-outline"></iconify-icon>
                    </span>
                    <span class="fw-medium text-secondary-light text-sm">Casos Activos</span>
                </div>
                <h4 class="fw-semibold mb-0"><?php echo $totalCasosActivos; ?></h4>
            </div>
        </div>
    </div>
    <div class="col-xxl-3 col-sm-6">
        <div class="card p-20 radius-8 border bg-gradient-start-2 h-100">
            <div class="card-body p-0">
                <div class="d-flex align-items-center gap-12 mb-16">
                    <span class="w-44-px h-44-px bg-success-100 text-success-600 d-flex justify-content-center align-items-center rounded-circle text-2xl">
                        <iconify-icon icon="solar:check-circle-outline"></iconify-icon>
                    </span>
                    <span class="fw-medium text-secondary-light text-sm">Casos Cerrados</span>
                </div>
                <h4 class="fw-semibold mb-0"><?php echo $totalCasosCerrados; ?></h4>
            </div>
        </div>
    </div>
    <div class="col-xxl-3 col-sm-6">
        <div class="card p-20 radius-8 border bg-gradient-start-3 h-100">
            <div class="card-body p-0">
                <div class="d-flex align-items-center gap-12 mb-16">
                    <span class="w-44-px h-44-px bg-info-100 text-info-600 d-flex justify-content-center align-items-center rounded-circle text-2xl">
                        <iconify-icon icon="solar:folder-with-files-outline"></iconify-icon>
                    </span>
                    <span class="fw-medium text-secondary-light text-sm">Total Casos</span>
                </div>
                <h4 class="fw-semibold mb-0"><?php echo $totalCasosTodos; ?></h4>
            </div>
        </div>
    </div>
    <div class="col-xxl-3 col-sm-6">
        <div class="card p-20 radius-8 border bg-gradient-start-4 h-100">
            <div class="card-body p-0">
                <div class="d-flex align-items-center gap-12 mb-16">
                    <span class="w-44-px h-44-px bg-warning-100 text-warning-600 d-flex justify-content-center align-items-center rounded-circle text-2xl">
                        <iconify-icon icon="solar:inbox-outline"></iconify-icon>
                    </span>
                    <span class="fw-medium text-secondary-light text-sm">Solicitudes Asignadas</span>
                </div>
                <h4 class="fw-semibold mb-0"><?php echo $solicitudesAsignadas; ?></h4>
            </div>
        </div>
    </div>
</div>

<!-- Mis Casos Recientes -->
<div class="row gy-4 mb-24">
    <div class="col-xxl-6">
        <div class="card h-100 radius-8 border">
            <div class="card-body p-24">
                <h6 class="fw-semibold mb-16">Mis Casos por Estado</h6>
                <div id="chartCasosEstado"></div>
            </div>
        </div>
    </div>
    <div class="col-xxl-6">
        <div class="card h-100 radius-8 border">
            <div class="card-body p-24">
                <div class="d-flex align-items-center justify-content-between mb-16">
                    <h6 class="fw-semibold mb-0">Mis Últimos Casos</h6>
                    <a href="<?php echo APP_URL; ?>/index.php?page=casos" class="text-primary-600 text-sm fw-semibold">Ver Todos →</a>
                </div>
                <div class="table-responsive scroll-sm">
                    <table class="table bordered-table sm-table mb-0 dtNoAuto">
                        <thead><tr><th>Cliente</th><th>Caso</th><th>Estado</th></tr></thead>
                        <tbody>
                            <?php if (empty($ultimosCasos)): ?>
                            <tr><td colspan="3" class="text-center text-secondary-light py-3">Sin casos asignados</td></tr>
                            <?php else: ?>
                            <?php foreach ($ultimosCasos as $c): ?>
                            <tr>
                                <td><?php echo e($c['cliente_nombre'] . ' ' . $c['cliente_apellidos']); ?></td>
                                <td><a href="<?php echo APP_URL; ?>/index.php?page=casos/ver&id=<?php echo $c['id']; ?>" class="text-primary-600"><?php echo e($c['titulo']); ?></a></td>
                                <td>
                                    <?php
                                    $bc = match($c['estado']) {
                                        'en_estudio' => 'bg-warning-focus text-warning-main',
                                        'en_proceso' => 'bg-primary-focus text-primary-main',
                                        'en_tramitacion' => 'bg-info-focus text-info-main',
                                        'pendiente_juicio' => 'bg-danger-focus text-danger-main',
                                        'cerrado' => 'bg-success-focus text-success-main',
                                        default => 'bg-neutral-200'
                                    };
                                    ?>
                                    <span class="badge <?php echo $bc; ?> radius-4 px-8 py-4"><?php echo ucfirst(str_replace('_',' ',$c['estado'])); ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php else: ?>


<div class="row gy-4 mb-24">

    <!-- Solicitudes del mes -->
    <div class="col-xxl-3 col-sm-6">
        <div class="card p-20 radius-8 border bg-gradient-start-1 h-100">
            <div class="card-body p-0">
                <div class="d-flex align-items-center justify-content-between mb-16">
                    <div class="d-flex align-items-center gap-12">
                        <span class="w-44-px h-44-px bg-primary-100 text-primary-600 d-flex justify-content-center align-items-center rounded-circle text-2xl">
                            <iconify-icon icon="mage:email"></iconify-icon>
                        </span>
                        <span class="fw-medium text-secondary-light text-sm">Solicitudes del Mes</span>
                    </div>
                </div>
                <div class="d-flex align-items-end justify-content-between">
                    <div>
                        <h4 class="fw-semibold mb-4"><?php echo $totalSolicitudesMes; ?></h4>
                        <p class="text-sm mb-0">
                            <?php if ($varSol): ?>
                            <span class="<?php echo $varSol['up'] ? 'text-success-main' : 'text-danger-main'; ?> fw-semibold">
                                <?php echo $varSol['up'] ? '+' : '-'; ?><?php echo $varSol['val']; ?>%
                            </span>
                            <span class="text-secondary-light"> vs mes anterior</span>
                            <?php else: ?>
                            <span class="text-secondary-light fst-italic">Sin mes anterior</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Casos activos -->
    <div class="col-xxl-3 col-sm-6">
        <div class="card p-20 radius-8 border bg-gradient-start-2 h-100">
            <div class="card-body p-0">
                <div class="d-flex align-items-center justify-content-between mb-16">
                    <div class="d-flex align-items-center gap-12">
                        <span class="w-44-px h-44-px bg-success-100 text-success-600 d-flex justify-content-center align-items-center rounded-circle text-2xl">
                            <iconify-icon icon="hugeicons:invoice-03"></iconify-icon>
                        </span>
                        <span class="fw-medium text-secondary-light text-sm">Casos Activos</span>
                    </div>
                </div>
                <div class="d-flex align-items-end justify-content-between">
                    <div>
                        <h4 class="fw-semibold mb-4"><?php echo $totalCasosActivos; ?></h4>
                        <p class="text-sm mb-0">
                            <?php if ($varCasos): ?>
                            <span class="<?php echo $varCasos['up'] ? 'text-success-main' : 'text-danger-main'; ?> fw-semibold">
                                <?php echo $varCasos['up'] ? '+' : '-'; ?><?php echo $varCasos['val']; ?>%
                            </span>
                            <span class="text-secondary-light"> vs mes anterior</span>
                            <?php else: ?>
                            <span class="text-secondary-light fst-italic">Sin mes anterior</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Cobrado este mes -->
    <div class="col-xxl-3 col-sm-6">
        <div class="card p-20 radius-8 border bg-gradient-start-3 h-100">
            <div class="card-body p-0">
                <div class="d-flex align-items-center justify-content-between mb-16">
                    <div class="d-flex align-items-center gap-12">
                        <span class="w-44-px h-44-px bg-info-100 text-info-600 d-flex justify-content-center align-items-center rounded-circle text-2xl">
                            <iconify-icon icon="hugeicons:money-send-square"></iconify-icon>
                        </span>
                        <span class="fw-medium text-secondary-light text-sm">Cobrado este Mes</span>
                    </div>
                </div>
                <div class="d-flex align-items-end justify-content-between">
                    <div>
                        <h4 class="fw-semibold mb-4">€<?php echo number_format($totalCobradoMes, 2, ',', '.'); ?></h4>
                        <p class="text-sm mb-0">
                            <?php if ($varPagos): ?>
                            <span class="<?php echo $varPagos['up'] ? 'text-success-main' : 'text-danger-main'; ?> fw-semibold">
                                <?php echo $varPagos['up'] ? '+' : '-'; ?><?php echo $varPagos['val']; ?>%
                            </span>
                            <span class="text-secondary-light"> vs mes anterior</span>
                            <?php else: ?>
                            <span class="text-secondary-light fst-italic">Sin mes anterior</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Solicitudes pendientes -->
    <div class="col-xxl-3 col-sm-6">
        <div class="card p-20 radius-8 border bg-gradient-start-4 h-100">
            <div class="card-body p-0">
                <div class="d-flex align-items-center justify-content-between mb-16">
                    <div class="d-flex align-items-center gap-12">
                        <span class="w-44-px h-44-px bg-warning-100 text-warning-600 d-flex justify-content-center align-items-center rounded-circle text-2xl">
                            <iconify-icon icon="solar:clock-circle-outline"></iconify-icon>
                        </span>
                        <span class="fw-medium text-secondary-light text-sm">Pendientes de Revisión</span>
                    </div>
                </div>
                <div class="d-flex align-items-end justify-content-between">
                    <div>
                        <h4 class="fw-semibold mb-4"><?php echo $solicitudesPendientes; ?></h4>
                        <p class="text-sm mb-0">
                            <?php if ($solicitudesPendientes > 0): ?>
                            <a href="<?php echo APP_URL; ?>/index.php?page=solicitudes" class="text-primary-600 fw-semibold text-sm">Ver solicitudes →</a>
                            <?php else: ?>
                            <span class="text-success-main fw-semibold">✓ Al día</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<div class="row gy-4 mb-24">
    <!-- Gráfico de casos por estado -->
    <div class="col-xxl-6">
        <div class="card h-100 radius-8 border">
            <div class="card-body p-24">
                <h6 class="fw-semibold mb-16">Casos por Estado</h6>
                <div id="chartCasosEstado"></div>
            </div>
        </div>
    </div>

    <!-- Últimas solicitudes -->
    <div class="col-xxl-6">
        <div class="card h-100 radius-8 border">
            <div class="card-body p-24">
                <div class="d-flex align-items-center flex-wrap gap-2 justify-content-between mb-16">
                    <h6 class="fw-semibold mb-0">Últimas Solicitudes</h6>
                    <a href="<?php echo APP_URL; ?>/index.php?page=solicitudes" class="text-primary-600 hover-text-primary d-flex align-items-center gap-1">
                        Ver Todas <iconify-icon icon="solar:alt-arrow-right-linear" class="icon"></iconify-icon>
                    </a>
                </div>
                <div class="table-responsive scroll-sm">
                    <table class="table bordered-table sm-table mb-0 dtNoAuto">
                        <thead>
                            <tr>
                                <th>Solicitante</th>
                                <th>Tipo</th>
                                <th>Estado</th>
                                <th>Fecha</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ultimasSolicitudes)): ?>
                            <tr><td colspan="4" class="text-center text-secondary-light py-3">No hay solicitudes</td></tr>
                            <?php else: ?>
                            <?php foreach ($ultimasSolicitudes as $sol): ?>
                            <tr>
                                <td>
                                    <a href="<?php echo APP_URL; ?>/index.php?page=solicitudes/ver&id=<?php echo $sol['id']; ?>" class="text-primary-600">
                                        <?php echo e($sol['nombre'] . ' ' . $sol['apellidos']); ?>
                                    </a>
                                </td>
                                <td><?php echo e($sol['tipo_problema']); ?></td>
                                <td>
                                    <?php
                                    $badgeClass = match($sol['estado']) {
                                        'pendiente' => 'bg-warning-focus text-warning-main',
                                        'aceptada'  => 'bg-success-focus text-success-main',
                                        'denegada'  => 'bg-danger-focus text-danger-main',
                                        'archivada' => 'bg-neutral-focus text-neutral-main',
                                        'cancelada' => 'bg-danger-focus text-danger-main',
                                        default     => 'bg-neutral-200'
                                    };
                                    ?>
                                    <span class="badge <?php echo $badgeClass; ?> radius-4 px-8 py-4">
                                        <?php echo e(ucfirst($sol['estado'])); ?>
                                    </span>
                                </td>
                                <td class="text-sm"><?php echo date('d/m/Y', strtotime($sol['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Últimos pagos -->
<div class="row gy-4">
    <div class="col-12">
        <div class="card radius-8 border">
            <div class="card-body p-24">
                <div class="d-flex align-items-center flex-wrap gap-2 justify-content-between mb-16">
                    <h6 class="fw-semibold mb-0">Últimos Pagos Registrados</h6>
                </div>
                <div class="table-responsive scroll-sm">
                    <table class="table bordered-table sm-table mb-0 dtNoAuto">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Caso</th>
                                <th>Concepto</th>
                                <th>Cantidad</th>
                                <th>Método</th>
                                <th>Fecha</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ultimosPagos)): ?>
                            <tr><td colspan="6" class="text-center text-secondary-light py-3">No hay pagos registrados</td></tr>
                            <?php else: ?>
                            <?php foreach ($ultimosPagos as $pago): ?>
                            <tr>
                                <td><?php echo e($pago['cliente_nombre'] . ' ' . $pago['cliente_apellidos']); ?></td>
                                <td><?php echo e($pago['caso_titulo']); ?></td>
                                <td><?php echo e($pago['concepto']); ?></td>
                                <td class="fw-semibold text-success-main">€<?php echo number_format($pago['cantidad'], 2, ',', '.'); ?></td>
                                <td><?php echo e(ucfirst($pago['metodo_pago'])); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($pago['fecha_pago'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; /* end admin/abogado dashboard toggle */ ?>

<?php
// Preparar datos del gráfico
$estadosLabels = [];
$estadosData = [];
$estadosColores = [
    'en_estudio'        => '#487fff',
    'en_proceso'        => '#ff9f29',
    'en_tramitacion'    => '#17a2b8',
    'pendiente_juicio'  => '#dc3545',
    'cerrado'           => '#28a745',
    'archivado'         => '#6c757d'
];
$coloresChart = [];

foreach ($casosPorEstado as $ce) {
    $label = ucfirst(str_replace('_', ' ', $ce['estado']));
    $estadosLabels[] = $label;
    $estadosData[] = (int)$ce['total'];
    $coloresChart[] = $estadosColores[$ce['estado']] ?? '#999';
}

$scriptsExtra = '<style>.apex-kpi-tip{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:4px 10px;font-size:13px;font-weight:600;color:#1e293b;box-shadow:0 2px 8px rgba(0,0,0,.1)}</style><script>
// ── Donut Casos por Estado ──────────────────────────
if (document.querySelector("#chartCasosEstado")) {
    new ApexCharts(document.querySelector("#chartCasosEstado"), {
        series: ' . json_encode($estadosData) . ',
        chart: { type: "donut", height: 300 },
        labels: ' . json_encode($estadosLabels) . ',
        colors: ' . json_encode($coloresChart) . ',
        legend: { position: "bottom" },
        responsive: [{ breakpoint: 480, options: { chart: { height: 250 }, legend: { position: "bottom" } } }]
    }).render();
}

// ── Sparklines KPI eliminados ──
</script>';

include CRM_ROOT . '/templates/layout/footer.php';
