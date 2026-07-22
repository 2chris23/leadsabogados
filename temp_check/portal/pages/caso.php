<?php
/**
 * Portal del Cliente — Detalle del Caso
 * El cliente puede ver toda la información de su caso
 */
$portalId   = $_SESSION['portal_id'];
global $db;
$nombre     = $_SESSION['portal_nombre'];
$apellidos  = $_SESSION['portal_apellidos'];

$crmUrl = APP_URL . '/portal/crm';
$cuenta = $db->fetchOne("SELECT * FROM portal_cuentas WHERE id = ?", [$portalId]);
$esCliente = (bool)($cuenta['es_cliente'] ?? false);
$clienteId = $cuenta['cliente_id'] ?? null;

if (!$esCliente || !$clienteId) {
    header('Location: ' . portalUrl() . '/index.php?page=dashboard');
    exit;
}

$casoId = (int)($_GET['id'] ?? 0);
if (!$casoId) { header('Location: ' . portalUrl() . '/index.php?page=dashboard'); exit; }

// Obtener caso + verificar que pertenece al cliente
$caso = $db->fetchOne(
    "SELECT c.*, 
            u.nombre as abogado_nombre, u.apellidos as abogado_apellidos
     FROM casos c 
     LEFT JOIN usuarios_internos u ON c.abogado_id = u.id 
     WHERE c.id = ? AND c.cliente_id = ?", [$casoId, $clienteId]
);
if (!$caso) { header('Location: ' . portalUrl() . '/index.php?page=dashboard'); exit; }

// Pagos del caso
$pagos = $db->fetchAll("SELECT * FROM pagos WHERE caso_id = ? AND (tipo_pago IS NULL OR tipo_pago != 'pago_abogado') ORDER BY fecha_pago DESC", [$casoId]);
$totalPagado = array_sum(array_column($pagos, 'cantidad'));
$saldo = $caso['honorarios_totales'] - $totalPagado;

// Documentos del caso
$documentos = $db->fetchAll(
    "SELECT * FROM documentos WHERE caso_id = ? ORDER BY created_at DESC", [$casoId]
);
// Documentos del portal
$docPortal = $db->fetchAll(
    "SELECT sa.* FROM solicitud_archivos sa 
     JOIN solicitudes s ON sa.solicitud_id = s.id 
     WHERE s.portal_cuenta_id = ? AND s.estado = 'aceptada' 
     ORDER BY sa.created_at DESC", [$portalId]
);
$todosDocumentos = array_merge($documentos, $docPortal);

// Historial
$historial = $db->fetchAll(
    "SELECT * FROM audit_log WHERE tabla_afectada = 'casos' AND registro_id = ? ORDER BY created_at DESC LIMIT 15", [$casoId]
);

// Estados
$estadoCaso = [
    'en_estudio'       => ['label' => 'En Estudio',       'color' => '#2563eb', 'bg' => '#eff6ff',  'step' => 1],
    'en_proceso'       => ['label' => 'En Proceso',       'color' => '#d97706', 'bg' => '#fffbeb',  'step' => 2],
    'en_tramitacion'   => ['label' => 'En Tramitación',   'color' => '#0284c7', 'bg' => '#f0f9ff',  'step' => 3],
    'pendiente_juicio' => ['label' => 'Pendiente Juicio', 'color' => '#dc2626', 'bg' => '#fef2f2',  'step' => 4],
    'cerrado'          => ['label' => 'Cerrado',          'color' => '#059669', 'bg' => '#f0fdf4',  'step' => 5],
    'archivado'        => ['label' => 'Archivado',        'color' => '#64748b', 'bg' => '#f8fafc',  'step' => 6],
];
$ec = $estadoCaso[$caso['estado']] ?? ['label'=>$caso['estado'],'color'=>'#64748b','bg'=>'#f8fafc','step'=>1];
$stepsOrden = array_keys($estadoCaso);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo portalPwaHead(); ?>
    <title><?php echo e($caso['referencia']); ?> — Mi Portal</title>
    <link rel="icon" type="image/png" href="crm/assets/images/logo.png?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Inter',sans-serif;background:#f1f5f9;color:#1a1a2e;min-height:100vh}

        .topbar{background:#fff;border-bottom:1px solid #e2e8f0;padding:0 32px;height:64px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
        .topbar-logo{display:flex;align-items:center;gap:10px;text-decoration:none}
        .topbar-logo img{height:36px;object-fit:contain}
        .topbar-logo span{font-weight:700;color:#2e6edd;font-size:.9375rem}
        .topbar-right{display:flex;align-items:center;gap:16px}
        .topbar-avatar{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#2e6edd,#6ba3ff);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.875rem}
        .btn-back{padding:8px 16px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.8125rem;font-weight:600;color:#64748b;text-decoration:none;transition:all .2s;display:inline-flex;align-items:center;gap:6px}
        .btn-back:hover{border-color:#2e6edd;color:#2e6edd}

        .main{max-width:1000px;margin:0 auto;padding:28px 20px}

        /* Header caso */
        .caso-header{margin-bottom:24px}
        .caso-header h1{font-size:1.375rem;font-weight:800;letter-spacing:-.02em;margin-bottom:4px}
        .caso-ref{font-size:.8125rem;color:#94a3b8;font-weight:600}
        .caso-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:10px;font-size:.75rem;font-weight:700;margin-top:8px}

        /* Tracker */
        .tracker{display:flex;align-items:center;gap:0;padding:24px;background:#fff;border:1px solid #e2e8f0;border-radius:16px;margin-bottom:24px}
        .tracker-step{flex:1;display:flex;flex-direction:column;align-items:center;position:relative}
        .tracker-dot{width:36px;height:36px;border-radius:50%;border:3px solid #e2e8f0;background:#fff;display:flex;align-items:center;justify-content:center;z-index:2;transition:.3s}
        .tracker-dot.active{border-color:#2e6edd;background:#2e6edd;color:#fff}
        .tracker-dot.completed{border-color:#10b981;background:#10b981;color:#fff}
        .tracker-dot svg{width:14px;height:14px}
        .tracker-label{margin-top:8px;font-size:.625rem;font-weight:600;color:#94a3b8;text-align:center;text-transform:uppercase;letter-spacing:.04em;line-height:1.2}
        .tracker-label.active{color:#2e6edd;font-weight:800}
        .tracker-label.completed{color:#10b981}
        .tracker-line{position:absolute;top:18px;left:50%;width:100%;height:3px;background:#e2e8f0;z-index:1}
        .tracker-line.active{background:#2e6edd}
        .tracker-line.completed{background:#10b981}
        .tracker-step:last-child .tracker-line{display:none}

        /* Cards */
        .card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;margin-bottom:20px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04)}
        .card-hdr{display:flex;align-items:center;gap:12px;padding:18px 24px;border-bottom:1px solid #f1f5f9}
        .card-hdr .ico{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .card-hdr h2{font-size:.9375rem;font-weight:700;flex:1}
        .card-hdr .cnt{padding:3px 10px;border-radius:8px;font-size:.75rem;font-weight:700}
        .card-body{padding:20px 24px}

        /* Grid info */
        .info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px}
        .info-field label{display:block;font-size:.6875rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px}
        .info-field p{font-size:.9375rem;font-weight:600;margin:0}

        /* Finance cards */
        .fin-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
        .fin-card{border-radius:12px;padding:14px 16px;text-align:center}
        .fin-card .lbl{font-size:.6875rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;opacity:.7}
        .fin-card .val{font-size:1.25rem;font-weight:800;margin-top:4px}

        /* Files */
        .file-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #f8fafc}
        .file-row:last-child{border-bottom:none}
        .file-ext{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.625rem;font-weight:800;flex-shrink:0}
        .file-name{font-size:.875rem;font-weight:600;color:#1a1a2e;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .file-meta{font-size:.75rem;color:#94a3b8}
        .file-dl{font-size:.75rem;font-weight:600;color:#2e6edd;text-decoration:none;display:flex;align-items:center;gap:4px;flex-shrink:0}
        .file-dl:hover{color:#1e52ab}

        /* Payments */
        .pay-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f8fafc}
        .pay-row:last-child{border-bottom:none}
        .pay-concept{font-size:.875rem;font-weight:600;color:#1a1a2e}
        .pay-meta{font-size:.75rem;color:#94a3b8}
        .pay-amount{font-weight:800;color:#059669;font-size:.9375rem}

        /* Timeline */
        .tl-item{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid #f8fafc}
        .tl-item:last-child{border-bottom:none}
        .tl-dot{width:8px;height:8px;border-radius:50%;background:#2e6edd;flex-shrink:0;margin-top:6px}
        .tl-txt{font-size:.8125rem;color:#374151;line-height:1.5}
        .tl-date{font-size:.6875rem;color:#94a3b8;margin-top:2px}

        .empty-msg{text-align:center;color:#94a3b8;padding:24px 0;font-size:.875rem}

        @media(max-width:640px){
            .topbar{padding:0 16px}
            .main{padding:16px 12px}
            .fin-grid{grid-template-columns:1fr}
            .info-grid{grid-template-columns:1fr 1fr}
            .tracker{padding:16px 8px}
            .tracker-label{font-size:.5rem}
        }
    </style>
</head>
<body>

<div class="topbar">
    <a href="<?php echo portalUrl(); ?>/index.php?page=dashboard" class="topbar-logo">
        <img src="crm/assets/images/logo.png?v=2" alt="Logo">
        <span>Portal del Cliente</span>
    </a>
    <div class="topbar-right">
        <a href="<?php echo portalUrl(); ?>/index.php?page=dashboard" class="btn-back" style="margin-right: 12px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
            Volver
        </a>
        <a href="index.php?page=perfil" class="btn-back" style="color:#2e6edd; background:#e8f0fe; border-color:#bfdbfe; margin-right: 12px;">Mi Perfil</a>
        <a href="index.php?page=logout" class="btn-back" style="color:#ef4444; border-color:#fecaca; background:#fef2f2; margin-right: 12px;">Salir</a>
        <div class="topbar-avatar"><?php echo strtoupper(substr($nombre,0,1)); ?></div>
    </div>
</div>

<div class="main">

    <!-- Header -->
    <div class="caso-header">
        <span class="caso-ref"><?php echo e($caso['referencia']); ?></span>
        <h1><?php echo e($caso['titulo']); ?></h1>
        <span class="caso-badge" style="background:<?php echo $ec['bg'];?>;color:<?php echo $ec['color'];?>">
            <svg width="8" height="8"><circle cx="4" cy="4" r="4" fill="currentColor"/></svg>
            <?php echo $ec['label']; ?>
        </span>
    </div>

    <!-- Tracker Domino's -->
    <div class="tracker">
        <?php foreach($stepsOrden as $i => $stepKey):
            $stepNum = $i + 1;
            $stepInfo = $estadoCaso[$stepKey];
            $isCompleted = $stepNum < $ec['step'];
            $isActive = $stepNum === $ec['step'];
            $dotClass = $isCompleted ? 'completed' : ($isActive ? 'active' : '');
            $labelClass = $isCompleted ? 'completed' : ($isActive ? 'active' : '');
            $lineClass = $isCompleted ? 'completed' : ($isActive ? 'active' : '');
        ?>
        <div class="tracker-step">
            <div class="tracker-line <?php echo $lineClass; ?>"></div>
            <div class="tracker-dot <?php echo $dotClass; ?>">
                <?php if($isCompleted): ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                <?php elseif($isActive): ?>
                <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="5"/></svg>
                <?php else: ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="2"><circle cx="12" cy="12" r="4"/></svg>
                <?php endif; ?>
            </div>
            <div class="tracker-label <?php echo $labelClass; ?>"><?php echo $stepInfo['label']; ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Info del caso -->
    <div class="card">
        <div class="card-hdr">
            <div class="ico" style="background:#e8f0fe;color:#2e6edd"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            <h2>Información del Caso</h2>
        </div>
        <div class="card-body">
            <?php
            // Próximo pago pendiente
            $proximoPago = $db->fetchOne(
                "SELECT * FROM pagos_programados WHERE caso_id = ? AND estado IN ('pendiente','vencido') ORDER BY fecha_vencimiento ASC LIMIT 1",
                [$casoId]
            );
            $tipoPagoTexto = match($caso['tipo_pago_cliente'] ?? '') {
                'pago_unico'    => 'Pago Único',
                'cuotas'        => 'Cuotas (' . ucfirst($caso['frecuencia_pago'] ?? 'mensual') . ')',
                'fechas_custom' => 'Fechas Personalizadas',
                default         => $caso['plan_pago'] ?? 'Sin definir'
            };
            ?>
            <div class="info-grid">
                <div class="info-field"><label>Tipo de caso</label><p><?php echo e($caso['tipo_caso']); ?></p></div>
                <div class="info-field"><label>Abogado asignado</label><p><?php echo $caso['abogado_nombre'] ? e($caso['abogado_nombre'].' '.$caso['abogado_apellidos']) : '<span style="color:#94a3b8">Pendiente de asignación</span>'; ?></p></div>
                <div class="info-field"><label>Fecha apertura</label><p><?php echo date('d/m/Y', strtotime($caso['fecha_apertura'])); ?></p></div>
                <div class="info-field"><label>Referencia</label><p style="font-family:monospace"><?php echo e($caso['referencia']); ?></p></div>
                <div class="info-field">
                    <label>Tipo de pago</label>
                    <p><?php echo $tipoPagoTexto; ?></p>
                </div>
                <?php if($proximoPago): 
                    $ppVencido = $proximoPago['estado'] === 'vencido';
                    $ppColor = $ppVencido ? '#dc2626' : '#d97706';
                    $ppBg = $ppVencido ? '#fef2f2' : '#fffbeb';
                    $ppLabel = $ppVencido ? 'Vencido' : 'Pendiente';
                    $esHoyPP = $proximoPago['fecha_vencimiento'] === date('Y-m-d');
                ?>
                <div class="info-field">
                    <label>Día de pago</label>
                    <p style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                        <span style="font-weight:800;color:<?php echo $ppColor; ?>"><?php echo date('d/m/Y', strtotime($proximoPago['fecha_vencimiento'])); ?></span>
                        <span style="font-size:.625rem;padding:2px 8px;border-radius:6px;font-weight:700;background:<?php echo $ppBg; ?>;color:<?php echo $ppColor; ?>"><?php echo $ppLabel; ?></span>
                        <?php if($esHoyPP): ?><span style="font-size:.625rem;padding:2px 6px;border-radius:6px;font-weight:700;background:#fef3c7;color:#92400e">HOY</span><?php endif; ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>
            <?php if($caso['descripcion']): ?>
            <div style="margin-top:16px;background:#f8fafc;border-radius:12px;padding:14px 16px;font-size:.875rem;color:#374151;line-height:1.6;border:1px solid #f1f5f9"><?php echo nl2br(e($caso['descripcion'])); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Notas Internas (Novedades del Caso) -->
    <?php if(!empty($caso['notas_internas'])): ?>
    <div class="card" style="border-left:4px solid #2e6edd">
        <div class="card-hdr">
            <div class="ico" style="background:#e8f0fe;color:#2e6edd"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></div>
            <h2>Novedades del Expediente</h2>
        </div>
        <div class="card-body">
            <div style="font-size:.875rem;color:#374151;line-height:1.6;font-style:italic">
                <?php echo nl2br(e($caso['notas_internas'])); ?>
            </div>
            <div style="margin-top:12px;font-size:.6875rem;color:#94a3b8;font-weight:600;text-transform:uppercase">
                Última actualización por su abogado asignado
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Financiero -->
    <?php if($caso['honorarios_totales'] > 0): ?>
    <div class="card">
        <div class="card-hdr">
            <div class="ico" style="background:#f0fdf4;color:#16a34a"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
            <h2>Información Financiera</h2>
        </div>
        <div class="card-body">
            <div class="fin-grid">
                <div class="fin-card" style="background:#eff6ff"><span class="lbl" style="color:#2563eb">Honorarios</span><div class="val" style="color:#2563eb">&euro;<?php echo number_format($caso['honorarios_totales'],2,',','.'); ?></div></div>
                <div class="fin-card" style="background:#f0fdf4"><span class="lbl" style="color:#059669">Pagado</span><div class="val" style="color:#059669">&euro;<?php echo number_format($totalPagado,2,',','.'); ?></div></div>
                <div class="fin-card" style="background:#fef2f2"><span class="lbl" style="color:#dc2626">Pendiente</span><div class="val" style="color:#dc2626">&euro;<?php echo number_format(max(0,$saldo),2,',','.'); ?></div></div>
            </div>
            <?php $pct = $caso['honorarios_totales'] > 0 ? min(100,($totalPagado/$caso['honorarios_totales']*100)) : 0; ?>
            <div style="background:#f1f5f9;border-radius:99px;height:6px;margin-top:16px;overflow:hidden"><div style="width:<?php echo $pct;?>%;height:100%;background:#10b981;border-radius:99px"></div></div>
            <p style="font-size:.75rem;color:#94a3b8;margin-top:4px"><?php echo round($pct,1);?>% pagado</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Próximos Pagos (Calendario) -->
    <?php
    $ppPortal = $db->fetchAll("SELECT * FROM pagos_programados WHERE caso_id = ? ORDER BY fecha_vencimiento ASC", [$casoId]);
    if(!empty($ppPortal)):
    ?>
    <div class="card">
        <div class="card-hdr">
            <div class="ico" style="background:#fff7ed;color:#d97706"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
            <h2>Mis Fechas de Pago</h2>
        </div>
        <div class="card-body">
            <?php foreach($ppPortal as $pp):
                $ppEst = match($pp['estado']) {
                    'pagado'  => ['#059669','#f0fdf4','Pagado'],
                    'vencido' => ['#dc2626','#fef2f2','Vencido'],
                    default   => ['#d97706','#fffbeb','Pendiente'],
                };
                $ppHoy = $pp['fecha_vencimiento'] === date('Y-m-d');
            ?>
            <div style="display:flex;align-items:center;gap:14px;padding:10px 0;border-bottom:1px solid #f8fafc;<?php echo $ppHoy ? 'background:#fffef5;margin:0 -24px;padding:10px 24px;border-radius:8px' : ''; ?>">
                <div style="width:44px;height:44px;border-radius:12px;background:<?php echo $ppEst[1]; ?>;display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0">
                    <span style="font-size:.625rem;font-weight:700;color:<?php echo $ppEst[0]; ?>;text-transform:uppercase;line-height:1"><?php echo date('M', strtotime($pp['fecha_vencimiento'])); ?></span>
                    <span style="font-size:1rem;font-weight:800;color:<?php echo $ppEst[0]; ?>;line-height:1.1"><?php echo date('d', strtotime($pp['fecha_vencimiento'])); ?></span>
                </div>
                <div style="flex:1;min-width:0">
                    <div style="font-size:.875rem;font-weight:600;color:#1a1a2e">
                        <?php echo e($pp['concepto']); ?>
                        <?php if($ppHoy): ?> <span style="font-size:.625rem;background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:6px;font-weight:700">HOY</span><?php endif; ?>
                    </div>
                    <div style="font-size:.75rem;color:#94a3b8">Día de pago: <?php echo date('d/m/Y', strtotime($pp['fecha_vencimiento'])); ?></div>
                </div>
                <span style="font-weight:800;font-size:.9375rem;color:<?php echo $ppEst[0]; ?>">&euro;<?php echo number_format($pp['monto'],2,',','.'); ?></span>
                <span style="padding:3px 10px;border-radius:8px;font-size:.6875rem;font-weight:700;background:<?php echo $ppEst[1]; ?>;color:<?php echo $ppEst[0]; ?>"><?php echo $ppEst[2]; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pagos realizados -->
    <?php if(!empty($pagos)): ?>
    <div class="card">
        <div class="card-hdr">
            <div class="ico" style="background:#ecfdf5;color:#059669"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div>
            <h2>Mis Pagos</h2>
            <span class="cnt" style="background:#ecfdf5;color:#059669"><?php echo count($pagos); ?></span>
        </div>
        <div class="card-body">
            <?php foreach($pagos as $p): ?>
            <div class="pay-row">
                <div>
                    <div class="pay-concept"><?php echo e($p['concepto']); ?></div>
                    <div class="pay-meta"><?php echo date('d/m/Y', strtotime($p['fecha_pago'])); ?> &middot; <?php echo ucfirst($p['metodo_pago']); ?></div>
                </div>
                <span class="pay-amount">&euro;<?php echo number_format($p['cantidad'],2,',','.'); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Documentos -->
    <div class="card">
        <div class="card-hdr">
            <div class="ico" style="background:#f5f3ff;color:#7c3aed"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></div>
            <h2>Documentos</h2>
            <span class="cnt" style="background:#f5f3ff;color:#7c3aed"><?php echo count($todosDocumentos); ?></span>
        </div>
        <div class="card-body">
            <?php if(empty($todosDocumentos)): ?>
            <p class="empty-msg">No hay documentos adjuntos en este caso</p>
            <?php else: ?>
            <?php
            $extColors=['PDF'=>['#fef2f2','#dc2626'],'DOC'=>['#e8f0fe','#2e6edd'],'DOCX'=>['#e8f0fe','#2e6edd'],'XLS'=>['#ecfdf5','#059669'],'XLSX'=>['#ecfdf5','#059669'],'JPG'=>['#fff7ed','#ea580c'],'PNG'=>['#fff7ed','#ea580c'],'ZIP'=>['#f5f3ff','#7c3aed']];
            foreach($todosDocumentos as $doc):
                $fname = $doc['nombre_original'] ?? $doc['nombre_archivo'] ?? 'Archivo';
                $ext = strtoupper(pathinfo($fname, PATHINFO_EXTENSION));
                [$bg,$clr] = $extColors[$ext] ?? ['#f1f5f9','#64748b'];
                $size = isset($doc['tamano_bytes']) ? round($doc['tamano_bytes']/1024,1).' KB' : '';
                $date = date('d/m/Y', strtotime($doc['created_at']));
                // Usar proxy autenticado según el tipo de documento
                if (isset($doc['caso_id'])) {
                    // Documento de caso → proxy CRM (requiere sesión CRM — para el portal usamos el proxy del portal)
                    $url = portalUrl() . '/index.php?page=descargar-doc&doc=' . (int)$doc['id'];
                } elseif (isset($doc['solicitud_id'])) {
                    // Adjunto de solicitud → proxy CRM de solicitudes
                    $url = $crmUrl . '/index.php?page=solicitudes/descargar&id=' . (int)$doc['id'];
                } else {
                    $url = '#';
                }
            ?>
            <div class="file-row">
                <div class="file-ext" style="background:<?php echo $bg;?>;color:<?php echo $clr;?>"><?php echo $ext ?: 'DOC';?></div>
                <div style="flex:1;min-width:0">
                    <div class="file-name"><?php echo e($fname);?></div>
                    <div class="file-meta"><?php echo $size;?> &middot; <?php echo $date;?></div>
                </div>
                <?php if($url !== '#'): ?>
                <a href="<?php echo $url;?>" target="_blank" class="file-dl">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Descargar
                </a>
                <?php endif;?>
            </div>
            <?php endforeach;?>
            <?php endif;?>
        </div>
    </div>

    <!-- Historial -->
    <div class="card">
        <div class="card-hdr">
            <div class="ico" style="background:#fff7ed;color:#d97706"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
            <h2>Historial de Actividad</h2>
        </div>
        <div class="card-body" style="max-height:350px;overflow-y:auto">
            <?php if(empty($historial)): ?>
            <p class="empty-msg">Sin actividad registrada</p>
            <?php else: ?>
            <?php foreach($historial as $h): ?>
            <div class="tl-item">
                <div class="tl-dot"></div>
                <div>
                    <div class="tl-txt"><?php echo e($h['detalles']);?></div>
                    <div class="tl-date"><?php echo e($h['usuario_nombre'] ?? 'Sistema');?> &middot; <?php echo date('d/m/Y H:i', strtotime($h['created_at']));?></div>
                </div>
            </div>
            <?php endforeach;?>
            <?php endif;?>
        </div>
    </div>

</div>

<?php echo portalPwaScript(); ?>
</body>
</html>
