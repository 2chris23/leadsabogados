<?php
/**
 * Portal del Cliente — Dashboard
 * Diseño estilo Domino's Pizza Tracker para solicitudes y casos
 */
$portalId   = $_SESSION['portal_id'];
$nombre     = $_SESSION['portal_nombre'];
$apellidos  = $_SESSION['portal_apellidos'];
$email      = $_SESSION['portal_email'];

$crmUrl = APP_URL . '/portal/crm';

// Obtener datos de la cuenta del portal
$cuenta = $db->fetchOne("SELECT * FROM portal_cuentas WHERE id = ?", [$portalId]);

// Solicitudes de este usuario
$solicitudes = $db->fetchAll(
    "SELECT * FROM solicitudes WHERE portal_cuenta_id = ? ORDER BY created_at DESC", 
    [$portalId]
);

// Si es cliente aprobado, obtener casos
$esCliente = (bool)($cuenta['es_cliente'] ?? false);
$clienteId = $cuenta['cliente_id'] ?? null;
$casos = [];

if ($esCliente && $clienteId) {
    $casos = $db->fetchAll(
        "SELECT c.*, u.nombre as abogado_nombre, u.apellidos as abogado_apellidos 
         FROM casos c LEFT JOIN usuarios_internos u ON c.abogado_id = u.id 
         WHERE c.cliente_id = ? ORDER BY c.created_at DESC", 
        [$clienteId]
    );
}

$flash = getFlash();

// Contadores
$totalSolicitudes   = count($solicitudes);
$solPendientes      = count(array_filter($solicitudes, fn($s) => $s['estado'] === 'pendiente'));
$solAceptadas       = count(array_filter($solicitudes, fn($s) => $s['estado'] === 'aceptada'));
$solRechazadas      = count(array_filter($solicitudes, fn($s) => in_array($s['estado'], ['denegada','cancelada'])));
$totalCasos         = count($casos);

$estadoSol = [
    'pendiente'  => ['label' => 'Pendiente',  'color' => '#f59e0b', 'bg' => '#fffbeb', 'icon' => 'clock'],
    'aceptada'   => ['label' => 'Aceptada',   'color' => '#10b981', 'bg' => '#ecfdf5', 'icon' => 'check'],
    'denegada'   => ['label' => 'Rechazada',  'color' => '#ef4444', 'bg' => '#fef2f2', 'icon' => 'x'],
    'archivada'  => ['label' => 'Archivada',  'color' => '#64748b', 'bg' => '#f8fafc', 'icon' => 'archive'],
    'cancelada'  => ['label' => 'Cancelada',  'color' => '#ef4444', 'bg' => '#fef2f2', 'icon' => 'x'],
];

$estadoCaso = [
    'en_estudio'       => ['label' => 'En Estudio',        'color' => '#f59e0b', 'step' => 1],
    'en_proceso'       => ['label' => 'En Proceso',        'color' => '#3b82f6', 'step' => 2],
    'en_tramitacion'   => ['label' => 'En Tramitación',    'color' => '#8b5cf6', 'step' => 3],
    'pendiente_juicio' => ['label' => 'Pendiente Juicio',  'color' => '#ec4899', 'step' => 4],
    'cerrado'          => ['label' => 'Cerrado',           'color' => '#10b981', 'step' => 5],
    'archivado'        => ['label' => 'Archivado',         'color' => '#64748b', 'step' => 6],
];

$tiposConsulta = ['Civil','Penal','Laboral','Mercantil','Inmobiliario','Familia','Extranjería','Otro'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo portalPwaHead(); ?>
    <title>Mi Portal — <?php echo e($nombre); ?></title>
    <link rel="icon" type="image/png" href="crm/assets/images/logo.png?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; color: #1a1a2e; min-height: 100vh; }

        /* ─── Topbar ─── */
        .topbar { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; }
        .topbar-logo { display: flex; align-items: center; gap: 10px; text-decoration: none; }
        .topbar-logo img { height: 36px; object-fit: contain; }
        .topbar-logo span { font-weight: 700; color: #2e6edd; font-size: .9375rem; }
        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .topbar-avatar { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, #2e6edd, #6ba3ff); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: .875rem; }
        .topbar-info { line-height: 1.3; }
        .topbar-name { font-weight: 600; font-size: .875rem; color: #1a1a2e; }
        .topbar-role { font-size: .7rem; color: #64748b; text-transform: uppercase; letter-spacing: .04em; font-weight: 600; }
        .btn-logout { padding: 6px 14px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-size: .8125rem; font-weight: 600; color: #64748b; text-decoration: none; transition: all .2s; }
        .btn-logout:hover { border-color: #dc2626; color: #dc2626; }

        /* ─── Main ─── */
        .main { max-width: 1100px; margin: 0 auto; padding: 32px 24px; }

        /* ─── Welcome ─── */
        .welcome { margin-bottom: 32px; }
        .welcome h1 { font-size: 1.75rem; font-weight: 800; letter-spacing: -.02em; margin-bottom: 4px; }
        .welcome p { color: #64748b; font-size: .9375rem; }
        .welcome-banner { background: linear-gradient(135deg, #2e6edd 0%, #6ba3ff 100%); color: #fff; padding: 20px 28px; border-radius: 16px; margin-top: 16px; display: none; }
        .welcome-banner.show { display: block; }
        .welcome-banner h3 { font-size: 1.125rem; font-weight: 700; margin-bottom: 6px; }
        .welcome-banner p { color: rgba(255,255,255,.8); font-size: .875rem; }

        /* ─── Quick Actions ─── */
        .actions { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 32px; }
        .action-card { background: #fff; border: 2px solid #e2e8f0; border-radius: 16px; padding: 24px; cursor: pointer; transition: all .25s; text-decoration: none; color: inherit; display: flex; align-items: flex-start; gap: 16px; }
        .action-card:hover { border-color: #2e6edd; transform: translateY(-2px); box-shadow: 0 8px 24px rgba(46,110,221,.12); }
        .action-icon { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .action-card h3 { font-size: .9375rem; font-weight: 700; margin-bottom: 4px; }
        .action-card p { font-size: .8125rem; color: #64748b; line-height: 1.4; }
        .action-count { font-size: 1.5rem; font-weight: 800; margin-top: 4px; }

        /* ─── Section ─── */
        .section { margin-bottom: 32px; }
        .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .section-title { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #94a3b8; }
        .badge-count { background: #e2e8f0; color: #64748b; padding: 3px 10px; border-radius: 99px; font-size: .75rem; font-weight: 700; }

        /* ─── Card ─── */
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
        .card + .card { margin-top: 12px; }
        .card-body { padding: 20px 24px; }

        /* ─── Status Badge ─── */
        .status { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 8px; font-size: .75rem; font-weight: 700; }

        /* ─── Tracker (Domino's Style) ─── */
        .tracker { display: flex; align-items: center; gap: 0; padding: 20px 24px; background: #f8fafc; border-top: 1px solid #f1f5f9; }
        .tracker-step { flex: 1; display: flex; flex-direction: column; align-items: center; position: relative; }
        .tracker-dot { width: 32px; height: 32px; border-radius: 50%; border: 3px solid #e2e8f0; background: #fff; display: flex; align-items: center; justify-content: center; z-index: 2; transition: all .3s; }
        .tracker-dot.active { border-color: #2e6edd; background: #2e6edd; color: #fff; }
        .tracker-dot.completed { border-color: #10b981; background: #10b981; color: #fff; }
        .tracker-dot svg { width: 14px; height: 14px; }
        .tracker-label { margin-top: 8px; font-size: .625rem; font-weight: 600; color: #94a3b8; text-align: center; text-transform: uppercase; letter-spacing: .04em; line-height: 1.2; }
        .tracker-label.active { color: #2e6edd; }
        .tracker-label.completed { color: #10b981; }
        .tracker-line { position: absolute; top: 16px; left: 50%; width: 100%; height: 3px; background: #e2e8f0; z-index: 1; }
        .tracker-line.active { background: #2e6edd; }
        .tracker-line.completed { background: #10b981; }
        .tracker-step:last-child .tracker-line { display: none; }

        /* ─── Info Grid ─── */
        .info-row { display: flex; flex-wrap: wrap; gap: 20px; }
        .info-item { }
        .info-item label { display: block; font-size: .6875rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 3px; }
        .info-item span { font-size: .9375rem; font-weight: 600; }

        /* ─── Empty ─── */
        .empty { text-align: center; padding: 48px 24px; }
        .empty-icon { width: 64px; height: 64px; background: #f1f5f9; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 16px; color: #94a3b8; }
        .empty h3 { font-size: 1.0625rem; font-weight: 700; margin-bottom: 6px; }
        .empty p { color: #64748b; font-size: .875rem; max-width: 360px; margin: 0 auto 20px; }
        
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; border: none; border-radius: 12px; font-size: .875rem; font-weight: 600; cursor: pointer; transition: all .2s; text-decoration: none; font-family: 'Inter', sans-serif; }
        .btn-primary { background: #2e6edd; color: #fff; }
        .btn-primary:hover { background: #1e52ab; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(46,110,221,.2); }

        .flash { padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: .875rem; font-weight: 500; }
        .flash-success { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .flash-error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

        @media (max-width: 640px) {
            .topbar { padding: 0 16px; }
            .main { padding: 20px 16px; }
            .actions { grid-template-columns: 1fr; }
            .tracker { padding: 16px 12px; }
            .tracker-label { font-size: .5625rem; }
        }
    </style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
    <a href="index.php?page=dashboard" class="topbar-logo">
        <img src="crm/assets/images/logo.png?v=2" alt="Logo">
        <span>Portal del Cliente</span>
    </a>
    <div class="topbar-right">
        <div class="topbar-avatar"><?php echo strtoupper(substr($nombre, 0, 1)); ?></div>
        <div class="topbar-info">
            <div class="topbar-name"><?php echo e($nombre . ' ' . $apellidos); ?></div>
            <div class="topbar-role"><?php echo $esCliente ? 'Cliente' : 'Visitante'; ?></div>
        </div>
        <a href="index.php?page=perfil" class="btn-logout" style="color:#2e6edd; background:#e8f0fe;">Mi Perfil</a>
        <a href="index.php?page=logout" class="btn-logout">Salir</a>
    </div>
</div>

<!-- Main -->
<div class="main">
    <!-- Welcome -->
    <div class="welcome">
        <h1>Hola, <?php echo e($nombre); ?></h1>
        <p>Gestione sus consultas legales y siga el estado de sus casos en tiempo real.</p>
        <?php if (isset($_GET['welcome'])): ?>
        <div class="welcome-banner show">
            <h3>Bienvenido al Portal</h3>
            <p>Su cuenta ha sido creada. Puede enviar su primera consulta haciendo clic en "Nueva Solicitud".</p>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?>
    <div class="flash flash-<?php echo $flash['tipo'] === 'success' ? 'success' : 'error'; ?>"><?php echo e($flash['mensaje']); ?></div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="actions">
        <?php if (empty($cuenta['dni_nif']) || empty($cuenta['direccion'])): ?>
        <a href="index.php?page=perfil" class="action-card" style="border: 2px solid #f59e0b; background: #fffbeb;">
            <div class="action-icon" style="background: #fef3c7; color: #d97706;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <div>
                <h3 style="color: #b45309;">Termina tu perfil</h3>
                <p>Faltan datos de facturación</p>
            </div>
        </a>
        <?php else: ?>
        <a href="index.php?page=nueva-solicitud" class="action-card">
            <div class="action-icon" style="background: #e8f0fe; color: #2e6edd;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </div>
            <div>
                <h3>Nueva Solicitud</h3>
                <p>Envíe una nueva consulta</p>
            </div>
        </a>
        <?php endif; ?>
        <div class="action-card" style="cursor: default;">
            <div class="action-icon" style="background: #fffbeb; color: #f59e0b;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div>
                <h3>Solicitudes</h3>
                <p><?php echo $solPendientes; ?> pendiente<?php echo $solPendientes !== 1 ? 's' : ''; ?></p>
                <div class="action-count" style="color: #f59e0b;"><?php echo $totalSolicitudes; ?></div>
            </div>
        </div>
        <div class="action-card" style="cursor: default;">
            <div class="action-icon" style="background: <?php echo $esCliente ? '#ecfdf5' : '#f1f5f9'; ?>; color: <?php echo $esCliente ? '#10b981' : '#94a3b8'; ?>;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div>
                <h3>Mis Casos</h3>
                <p><?php echo $esCliente ? 'Casos activos' : 'Sin casos asignados'; ?></p>
                <div class="action-count" style="color: <?php echo $esCliente ? '#10b981' : '#94a3b8'; ?>;"><?php echo $totalCasos; ?></div>
            </div>
        </div>
    </div>

    <!-- ═══ SOLICITUDES ═══ -->
    <div class="section">
        <div class="section-header">
            <span class="section-title">Mis Solicitudes</span>
            <span class="badge-count"><?php echo $totalSolicitudes; ?></span>
        </div>

        <?php if (empty($solicitudes)): ?>
        <div class="card">
            <div class="empty">
                <div class="empty-icon">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                </div>
                <h3>Aún no tiene solicitudes</h3>
                <p>Envíe su primera consulta legal y nuestro equipo le asignará un abogado especializado.</p>
                <a href="index.php?page=nueva-solicitud" class="btn btn-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Crear Primera Solicitud
                </a>
            </div>
        </div>
        <?php else: ?>
            <?php foreach ($solicitudes as $sol): ?>
            <?php $est = $estadoSol[$sol['estado']] ?? ['label'=>$sol['estado'],'color'=>'#64748b','bg'=>'#f8fafc']; ?>
            <div class="card">
                <div class="card-body">
                    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                        <div>
                            <div style="font-size:.8125rem;font-weight:700;color:#94a3b8;margin-bottom:4px;">Solicitud #<?php echo $sol['id']; ?> · <?php echo date('d/m/Y', strtotime($sol['created_at'])); ?></div>
                            <div style="font-size:1.0625rem;font-weight:700;"><?php echo e($sol['tipo_problema']); ?></div>
                        </div>
                        <span class="status" style="background:<?php echo $est['bg']; ?>;color:<?php echo $est['color']; ?>;">
                            <svg width="8" height="8"><circle cx="4" cy="4" r="4" fill="currentColor"/></svg>
                            <?php echo $est['label']; ?>
                        </span>
                    </div>
                    <?php if (!empty($sol['descripcion'])): ?>
                    <p style="margin-top:12px;font-size:.875rem;color:#64748b;line-height:1.5;"><?php echo nl2br(e(mb_substr($sol['descripcion'], 0, 200))); ?><?php echo mb_strlen($sol['descripcion']) > 200 ? '...' : ''; ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ═══ CASOS (Tracker Domino's) ═══ -->
    <?php if ($esCliente && !empty($casos)): ?>
    <div class="section">
        <div class="section-header">
            <span class="section-title">Mis Casos</span>
            <span class="badge-count"><?php echo $totalCasos; ?></span>
        </div>

        <?php 
        $stepsOrden = ['en_estudio','en_proceso','en_tramitacion','pendiente_juicio','cerrado','archivado'];
        foreach ($casos as $caso): 
            $ec = $estadoCaso[$caso['estado']] ?? ['label'=>$caso['estado'],'color'=>'#64748b','step'=>1];
            $currentStep = $ec['step'];
        ?>
            <div class="card" style="cursor:pointer;transition:all .2s" onmouseover="this.style.boxShadow='0 8px 24px rgba(46,110,221,.12)';this.style.borderColor='#2e6edd'" onmouseout="this.style.boxShadow='';this.style.borderColor='#e2e8f0'" onclick="window.location='index.php?page=caso&id=<?php echo $caso['id']; ?>'">
            <div class="card-body">
                <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
                    <div>
                        <div style="font-size:.8125rem;font-weight:700;color:#94a3b8;margin-bottom:4px;">Caso #<?php echo $caso['id']; ?> · Ref: <?php echo e($caso['referencia'] ?? 'N/A'); ?></div>
                        <div style="font-size:1.0625rem;font-weight:700;"><?php echo e($caso['titulo']); ?></div>
                    </div>
                    <span class="status" style="background:<?php echo $ec['color']; ?>15;color:<?php echo $ec['color']; ?>;">
                        <svg width="8" height="8"><circle cx="4" cy="4" r="4" fill="currentColor"/></svg>
                        <?php echo $ec['label']; ?>
                    </span>
                </div>
                <div class="info-row">
                    <div class="info-item"><label>Tipo</label><span><?php echo e($caso['tipo_caso']); ?></span></div>
                    <div class="info-item"><label>Apertura</label><span><?php echo date('d/m/Y', strtotime($caso['fecha_apertura'])); ?></span></div>
                    <?php if(!empty($caso['abogado_nombre'])): ?>
                    <div class="info-item"><label>Abogado</label><span><?php echo e($caso['abogado_nombre'].' '.$caso['abogado_apellidos']); ?></span></div>
                    <?php endif; ?>
                </div>
                <div style="margin-top:12px;display:flex;align-items:center;gap:8px">
                    <span style="font-size:.75rem;font-weight:600;color:#2e6edd;display:flex;align-items:center;gap:4px">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
                        Ver detalle del caso
                    </span>
                </div>
            </div>

            <!-- Tracker Domino's -->
            <div class="tracker">
                <?php foreach ($stepsOrden as $i => $stepKey): 
                    $stepNum = $i + 1;
                    $stepInfo = $estadoCaso[$stepKey];
                    $isCompleted = $stepNum < $currentStep;
                    $isActive = $stepNum === $currentStep;
                    $dotClass = $isCompleted ? 'completed' : ($isActive ? 'active' : '');
                    $labelClass = $isCompleted ? 'completed' : ($isActive ? 'active' : '');
                    $lineClass = $isCompleted ? 'completed' : ($isActive ? 'active' : '');
                ?>
                <div class="tracker-step">
                    <div class="tracker-line <?php echo $lineClass; ?>"></div>
                    <div class="tracker-dot <?php echo $dotClass; ?>">
                        <?php if ($isCompleted): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                        <?php elseif ($isActive): ?>
                        <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="5"/></svg>
                        <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="2"><circle cx="12" cy="12" r="4"/></svg>
                        <?php endif; ?>
                    </div>
                    <div class="tracker-label <?php echo $labelClass; ?>"><?php echo $stepInfo['label']; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php elseif (!$esCliente): ?>
    <!-- Mensaje para usuarios no-cliente -->
    <div class="section">
        <div class="section-header">
            <span class="section-title">Mis Casos</span>
        </div>
        <div class="card">
            <div class="empty">
                <div class="empty-icon">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                <h3>Área de Casos</h3>
                <p>Cuando una de sus solicitudes sea aceptada, podrá ver aquí el progreso de su caso con seguimiento en tiempo real.</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php echo portalPwaScript(); ?>
</body>
</html>
