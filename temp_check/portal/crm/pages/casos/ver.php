<?php
/**
 * CRM Abogados - Detalle de Caso
 */
$db = Database::getInstance();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/index.php?page=casos'); exit; }

RoleGuard::verificarAccesoCaso($id);


$caso = $db->fetchOne(
    "SELECT c.*, cl.nombre as cliente_nombre, cl.apellidos as cliente_apellidos, cl.email as cliente_email,
            u.nombre as abogado_nombre, u.apellidos as abogado_apellidos
     FROM casos c
     JOIN clientes cl ON c.cliente_id = cl.id
     LEFT JOIN usuarios_internos u ON c.abogado_id = u.id
     WHERE c.id = ?", [$id]
);
if (!$caso) { setFlash('error', 'Caso no encontrado'); header('Location: ' . APP_URL . '/index.php?page=casos'); exit; }

// Procesar cambio de estado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_estado'])) {
    CSRF::verificarOAbortar();
    $nuevoEstado = $_POST['nuevo_estado'];
    $estadosValidos = ['en_estudio','en_proceso','en_tramitacion','pendiente_juicio','cerrado','archivado'];
    if (in_array($nuevoEstado, $estadosValidos)) {
        $datosUpdate = ['estado' => $nuevoEstado];
        if ($nuevoEstado === 'cerrado') $datosUpdate['fecha_cierre'] = date('Y-m-d');
        $db->update('casos', $datosUpdate, 'id = ?', [$id]);
        AuditLog::registrar('cambiar_estado', 'casos', $id, "Estado cambiado a: $nuevoEstado");
        setFlash('exito', 'Estado actualizado');
        header('Location: ' . APP_URL . '/index.php?page=casos/ver&id=' . $id); exit;
    }
}

// Procesar edición financiera (honorarios + plan de pago + calendario)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_financiero'])) {
    CSRF::verificarOAbortar();
    $honorarios     = (float)$_POST['honorarios_totales'];
    $tipoPago       = $_POST['tipo_pago_cliente'] ?? 'pago_unico';
    $frecuencia     = $_POST['frecuencia_pago'] ?? '';
    $numCuotas      = (int)($_POST['num_cuotas'] ?? 3);

    // Guardar en caso
    $db->update('casos', [
        'honorarios_totales'  => $honorarios,
        'tipo_pago_cliente'   => $tipoPago,
        'frecuencia_pago'     => $frecuencia,
        'plan_pago'           => $tipoPago === 'pago_unico' ? 'Pago Único' : "Cuotas: $numCuotas ($frecuencia)",
    ], 'id = ?', [$id]);

    // Eliminar pagos programados anteriores que no estén pagados
    $db->query("DELETE FROM pagos_programados WHERE caso_id = ? AND estado = 'pendiente'", [$id]);

    // Generar pagos programados
    if ($tipoPago === 'pago_unico') {
        $fecha = $_POST['fecha_pago_unico'] ?? date('Y-m-d');
        $db->insert('pagos_programados', [
            'caso_id'           => $id,
            'numero_cuota'      => 1,
            'fecha_vencimiento' => $fecha,
            'monto'             => $honorarios,
            'concepto'          => 'Pago único',
        ]);
    } elseif ($tipoPago === 'cuotas') {
        $fechaInicio = $_POST['fecha_inicio_cuotas'] ?? date('Y-m-d');
        $montoCuota  = round($honorarios / $numCuotas, 2);
        $diasIntervalo = match($frecuencia) {
            'quincenal' => 15,
            'semanal'   => 7,
            default     => 30, // mensual
        };

        for ($i = 0; $i < $numCuotas; $i++) {
            $fecha = date('Y-m-d', strtotime("+".($i * $diasIntervalo)." days", strtotime($fechaInicio)));
            // Último pago ajusta centavos
            $monto = ($i === $numCuotas - 1) ? round($honorarios - ($montoCuota * ($numCuotas - 1)), 2) : $montoCuota;
            $db->insert('pagos_programados', [
                'caso_id'           => $id,
                'numero_cuota'      => $i + 1,
                'fecha_vencimiento' => $fecha,
                'monto'             => $monto,
                'concepto'          => "Cuota " . ($i + 1) . " de $numCuotas",
            ]);
        }
    } elseif ($tipoPago === 'fechas_custom') {
        $fechasCustom  = $_POST['fechas_custom'] ?? [];
        $montosCustom  = $_POST['montos_custom'] ?? [];
        foreach ($fechasCustom as $idx => $fc) {
            if (empty($fc)) continue;
            $db->insert('pagos_programados', [
                'caso_id'           => $id,
                'numero_cuota'      => $idx + 1,
                'fecha_vencimiento' => $fc,
                'monto'             => (float)($montosCustom[$idx] ?? 0),
                'concepto'          => "Pago programado #" . ($idx + 1),
            ]);
        }
    }

    AuditLog::registrar('editar_financiero', 'casos', $id,
        "Honorarios: €" . number_format($honorarios, 2) . ". Plan: $tipoPago");
    setFlash('exito', 'Plan de pago configurado');
    header('Location: ' . APP_URL . '/index.php?page=casos/ver&id=' . $id); exit;
}


// Procesar edición del caso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_caso'])) {
    CSRF::verificarOAbortar();
    // Optimistic locking: verificar que nadie editó mientras tanto
    $updatedAtEnviado = $_POST['caso_updated_at'] ?? '';
    $updatedAtActual  = $db->fetchOne("SELECT updated_at FROM casos WHERE id = ?", [$id])['updated_at'] ?? '';
    if ($updatedAtEnviado && $updatedAtEnviado !== $updatedAtActual) {
        setFlash('error', '⚠️ Otro usuario editó este caso mientras trabajabas. Recarga la página para ver los cambios actualizados antes de editarlo.');
        header('Location: ' . APP_URL . '/index.php?page=casos/ver&id=' . $id); exit;
    }
    $db->update('casos', [
        'titulo'         => trim($_POST['titulo']),
        'tipo_caso'      => trim($_POST['tipo_caso']),
        'descripcion'    => trim($_POST['descripcion']),
        'abogado_id'     => $_POST['abogado_id'] ?: null,
        'notas_internas' => trim($_POST['notas_internas'])
    ], 'id = ?', [$id]);
    AuditLog::registrar('editar', 'casos', $id, 'Datos del caso actualizados');
    setFlash('exito', 'Caso actualizado');
    header('Location: ' . APP_URL . '/index.php?page=casos/ver&id=' . $id); exit;
}

// Guardar notas internas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_notas'])) {
    CSRF::verificarOAbortar();
    $usuarioAct = $auth->getUsuario();
    $puedeNotas = $auth->esAdmin() || ($auth->esAbogado() && $caso['abogado_id'] == ($usuarioAct['id'] ?? 0));
    if ($puedeNotas) {
        $db->update('casos', ['notas_internas' => trim($_POST['notas_internas'])], 'id = ?', [$id]);
        AuditLog::registrar('editar_notas', 'casos', $id, 'Notas internas actualizadas');
        
        // Notificar al cliente si está habilitado y la nota no está vacía
        $notaTexto = trim($_POST['notas_internas']);
        if (!empty($notaTexto)) {
            $notifNota = $db->fetchColumn("SELECT valor FROM configuracion WHERE clave = 'email_notif_nota'") ?? '1';
            if ($notifNota === '1') {
                $clienteInfo = $db->fetchOne(
                    "SELECT cl.email, cl.nombre, cl.apellidos, c.referencia 
                     FROM casos c JOIN clientes cl ON c.cliente_id = cl.id 
                     WHERE c.id = ?", [$id]
                );
                if ($clienteInfo && filter_var($clienteInfo['email'], FILTER_VALIDATE_EMAIL)) {
                    require_once dirname(__DIR__, 2) . '/includes/Mailer.php';
                    Mailer::nuevaNota(
                        $clienteInfo['email'],
                        $clienteInfo['nombre'] . ' ' . $clienteInfo['apellidos'],
                        $clienteInfo['referencia'],
                        $notaTexto,
                        APP_URL . '/../portal/index.php?page=dashboard'
                    );
                }
            }
        }
        
        setFlash('exito', 'Notas guardadas');
    }
    header('Location: ' . APP_URL . '/index.php?page=casos/ver&id=' . $id); exit;
}

// Datos complementarios
$pagos = $db->fetchAll("SELECT * FROM pagos WHERE caso_id = ? AND (tipo_pago IS NULL OR tipo_pago != 'pago_abogado') ORDER BY fecha_pago DESC, created_at DESC", [$id]);
$totalPagado = array_sum(array_column($pagos, 'cantidad'));
$saldoPendiente = $caso['honorarios_totales'] - $totalPagado;

// Documentos: tabla propia + archivos del portal vinculados al caso
$documentos = $db->fetchAll("SELECT * FROM documentos WHERE caso_id = ? ORDER BY created_at DESC", [$id]);
// Si no hay documentos propios, buscar en solicitud_archivos via solicitudes del cliente
if (empty($documentos)) {
    $solId = $db->fetchColumn(
        "SELECT id FROM solicitudes WHERE email = (SELECT email FROM clientes WHERE id = ?) ORDER BY id DESC LIMIT 1",
        [$caso['cliente_id']]
    );
    if ($solId) {
        $archivosSol = $db->fetchAll("SELECT *, 'portal' as origen FROM solicitud_archivos WHERE solicitud_id = ? ORDER BY created_at DESC", [$solId]);
        // Normalizar campos para usar la misma vista
        foreach ($archivosSol as &$a) {
            $a['ruta'] = '../portal/' . $a['ruta'];
            $a['descripcion'] = 'Aportado por el cliente';
        }
        $documentos = $archivosSol;
    }
}

$historial = $db->fetchAll("SELECT * FROM audit_log WHERE tabla_afectada = 'casos' AND registro_id = ? ORDER BY created_at DESC LIMIT 20", [$id]);
$abogados = $db->fetchAll("SELECT id, nombre, apellidos FROM usuarios_internos WHERE rol = 'abogado' AND activo = 1");

// Pagos programados (calendario)
$pagosProgramados = $db->fetchAll("SELECT * FROM pagos_programados WHERE caso_id = ? ORDER BY fecha_vencimiento ASC", [$id]);
// Marcar vencidos
foreach ($pagosProgramados as &$pp) {
    if ($pp['estado'] === 'pendiente' && $pp['fecha_vencimiento'] < date('Y-m-d')) {
        $db->update('pagos_programados', ['estado' => 'vencido'], 'id = ?', [$pp['id']]);
        $pp['estado'] = 'vencido';
    }
}
unset($pp);

$tituloPagina = $caso['referencia'];
include CRM_ROOT . '/templates/layout/header.php';
?>
<link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/caso-ver.css">
<?php
$estadoMap = [
    'en_estudio'       => ['cls'=>'cv-state-study', 'label'=>'En Estudio',       'dot'=>'#2563eb'],
    'en_proceso'       => ['cls'=>'cv-state-proc',  'label'=>'En Proceso',        'dot'=>'#d97706'],
    'en_tramitacion'   => ['cls'=>'cv-state-tram',  'label'=>'En Tramitación',    'dot'=>'#0284c7'],
    'pendiente_juicio' => ['cls'=>'cv-state-juic',  'label'=>'Pendiente Juicio',  'dot'=>'#dc2626'],
    'cerrado'          => ['cls'=>'cv-state-closed','label'=>'Cerrado',           'dot'=>'#059669'],
    'archivado'        => ['cls'=>'cv-state-arch',  'label'=>'Archivado',         'dot'=>'#64748b'],
];
$eActual = $estadoMap[$caso['estado']] ?? ['cls'=>'cv-state-arch','label'=>ucfirst($caso['estado']),'dot'=>'#64748b'];
$estados = array_keys($estadoMap);
$extColors = ['PDF'=>['#fef2f2','#dc2626'],'DOC'=>['#e8f0fe','#2e6edd'],'DOCX'=>['#e8f0fe','#2e6edd'],'XLS'=>['#ecfdf5','#059669'],'XLSX'=>['#ecfdf5','#059669'],'JPG'=>['#fff7ed','#ea580c'],'PNG'=>['#fff7ed','#ea580c'],'ZIP'=>['#f5f3ff','#7c3aed']];
?>

<!-- Header -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px">
  <div>
    <h5 style="font-size:1.125rem;font-weight:800;color:#1a1a2e;margin:0"><?php echo e($caso['referencia']); ?></h5>
    <p style="font-size:.8125rem;color:#94a3b8;margin:2px 0 0"><?php echo e($caso['titulo']); ?></p>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <a href="<?php echo APP_URL; ?>/index.php?page=casos/documentos&id=<?php echo $id; ?>" class="cv-btn cv-btn-ghost" style="width:auto;padding:8px 14px;font-size:.8125rem">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
      Subir Documento
    </a>
    <?php if (RoleGuard::esAdmin()): ?>
    <button class="cv-btn cv-btn-primary" style="width:auto;padding:8px 16px;font-size:.8125rem" data-bs-toggle="modal" data-bs-target="#editarCasoModal">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      Editar
    </button>
    <?php endif; ?>
  </div>
</div>

<div class="cv-wrap">
  <!-- ══ COL IZQUIERDA ══ -->
  <div>
    <!-- Info del caso -->
    <div class="cv-card">
      <div class="cv-card-header">
        <div class="cv-icon" style="background:#e8f0fe;color:#2e6edd">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
        </div>
        <h3>Información del Caso</h3>
        <span class="cv-badge <?php echo $eActual['cls']; ?>" style="margin-left:auto">
          <svg width="8" height="8"><circle cx="4" cy="4" r="4" fill="currentColor"/></svg>
          <?php echo $eActual['label']; ?>
        </span>
      </div>
      <div class="cv-card-body">
        <div class="cv-grid">
          <div class="cv-field"><label>Tipo</label><p><?php echo e($caso['tipo_caso']); ?></p></div>
          <div class="cv-field"><label>Abogado</label><p><?php echo $caso['abogado_nombre'] ? e($caso['abogado_nombre'].' '.$caso['abogado_apellidos']) : '<span style="color:#94a3b8;font-weight:400">Sin asignar</span>'; ?></p></div>
          <div class="cv-field"><label>Cliente</label><p><a href="<?php echo APP_URL; ?>/index.php?page=clientes/ver&id=<?php echo $caso['cliente_id']; ?>" style="color:#2e6edd;font-weight:600;text-decoration:none"><?php echo e($caso['cliente_nombre'].' '.$caso['cliente_apellidos']); ?></a></p></div>
          <div class="cv-field"><label>Apertura</label><p><?php echo date('d/m/Y',strtotime($caso['fecha_apertura'])); ?></p></div>
          <div class="cv-field"><label>Cierre</label><p><?php echo $caso['fecha_cierre'] ? date('d/m/Y',strtotime($caso['fecha_cierre'])) : '—'; ?></p></div>
          <div class="cv-field"><label>Referencia</label><p style="font-family:monospace;font-size:.875rem"><?php echo e($caso['referencia']); ?></p></div>
        </div>
        <?php if($caso['descripcion']): ?>
        <div style="margin-top:16px"><div class="cv-field"><label>Descripción</label></div><div class="cv-desc"><?php echo nl2br(e($caso['descripcion'])); ?></div></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (RoleGuard::esAdmin()): ?>
    <!-- Financiero -->
    <div class="cv-card">
      <div class="cv-card-header">
        <div class="cv-icon" style="background:#f0fdf4;color:#16a34a">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <h3>Módulo Financiero</h3>
        <div style="margin-left:auto;display:flex;gap:8px">
          <a href="<?php echo APP_URL; ?>/index.php?page=pagos/registrar&caso_id=<?php echo $id; ?>" class="cv-btn cv-btn-success" style="width:auto;padding:7px 14px;font-size:.8125rem">+ Registrar Pago</a>
          <button class="cv-btn cv-btn-ghost" style="width:auto;padding:7px 14px;font-size:.8125rem" data-bs-toggle="modal" data-bs-target="#editarFinancieroModal">Honorarios</button>
        </div>
      </div>
      <div class="cv-card-body">
        <div class="cv-fin-grid">
          <div class="cv-fin-card" style="background:#eff6ff"><span class="cv-fin-label">Honorarios</span><div class="cv-fin-val" style="color:#2563eb">€<?php echo number_format($caso['honorarios_totales'],2,',','.'); ?></div></div>
          <div class="cv-fin-card" style="background:#f0fdf4"><span class="cv-fin-label">Pagado</span><div class="cv-fin-val" style="color:#059669">€<?php echo number_format($totalPagado,2,',','.'); ?></div></div>
          <div class="cv-fin-card" style="background:#fef2f2"><span class="cv-fin-label">Pendiente</span><div class="cv-fin-val" style="color:#dc2626">€<?php echo number_format($saldoPendiente,2,',','.'); ?></div></div>
        </div>
        <?php if($caso['honorarios_totales']>0): $pct=min(100,($totalPagado/$caso['honorarios_totales']*100)); ?>
        <div style="background:#f1f5f9;border-radius:99px;height:6px;margin-top:14px;overflow:hidden">
          <div style="width:<?php echo $pct; ?>%;height:100%;background:#10b981;border-radius:99px"></div>
        </div>
        <p style="font-size:.75rem;color:#94a3b8;margin:4px 0 0"><?php echo round($pct,1); ?>% pagado</p>
        <?php endif; ?>
        <?php if(!empty($pagos)): ?>
        <div style="margin-top:16px;border-top:1px solid #f1f5f9;padding-top:14px">
          <span class="cv-label">Pagos Registrados</span>
          <?php foreach($pagos as $p): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f8fafc">
            <div>
              <p style="font-size:.875rem;font-weight:600;color:#1a1a2e;margin:0"><?php echo e($p['concepto']); ?></p>
              <?php if(!empty($p['notas'])): ?>
              <p style="font-size:.8125rem;color:#475569;margin:2px 0 0;font-style:italic">"<?php echo e($p['notas']); ?>"</p>
              <?php endif; ?>
              <p style="font-size:.75rem;color:#94a3b8;margin:2px 0 0"><?php echo date('d/m/Y',strtotime($p['fecha_pago'])); ?> <span style="opacity:0.7"><?php echo date('H:i',strtotime($p['created_at'])); ?></span> · <?php echo ucfirst($p['metodo_pago']); ?></p>
            </div>
            <span style="font-weight:800;color:#059669;font-size:.9375rem">€<?php echo number_format($p['cantidad'],2,',','.'); ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Calendario de Pagos -->
    <?php if(!empty($pagosProgramados)): ?>
    <div class="cv-card">
      <div class="cv-card-header">
        <div class="cv-icon" style="background:#fff7ed;color:#d97706">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        </div>
        <h3>Calendario de Pagos</h3>
        <?php
        $tipoPagoLabel = match($caso['tipo_pago_cliente'] ?? '') {
            'pago_unico'     => 'Pago Único',
            'cuotas'         => 'Cuotas (' . ucfirst($caso['frecuencia_pago'] ?? 'mensual') . ')',
            'fechas_custom'  => 'Fechas Personalizadas',
            default          => $caso['plan_pago'] ?? 'Sin definir'
        };
        ?>
        <span style="margin-left:auto;background:#fff7ed;color:#d97706;padding:3px 10px;border-radius:8px;font-size:.6875rem;font-weight:700"><?php echo $tipoPagoLabel; ?></span>
      </div>
      <div class="cv-card-body" style="padding:12px 22px">
        <?php foreach($pagosProgramados as $pp):
            $estColor = match($pp['estado']) {
                'pagado'  => ['#059669','#f0fdf4','Pagado'],
                'vencido' => ['#dc2626','#fef2f2','Vencido'],
                default   => ['#d97706','#fffbeb','Pendiente'],
            };
            $esHoy = $pp['fecha_vencimiento'] === date('Y-m-d');
        ?>
        <div style="display:flex;align-items:center;gap:14px;padding:10px 0;border-bottom:1px solid #f8fafc;<?php echo $esHoy ? 'background:#fffef5;margin:0 -22px;padding:10px 22px;border-radius:8px' : ''; ?>">
          <div style="width:44px;height:44px;border-radius:12px;background:<?php echo $estColor[1]; ?>;display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0">
            <span style="font-size:.625rem;font-weight:700;color:<?php echo $estColor[0]; ?>;text-transform:uppercase;line-height:1"><?php echo strtoupper(strftime('%b', strtotime($pp['fecha_vencimiento']))); ?></span>
            <span style="font-size:1rem;font-weight:800;color:<?php echo $estColor[0]; ?>;line-height:1.1"><?php echo date('d', strtotime($pp['fecha_vencimiento'])); ?></span>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-size:.875rem;font-weight:600;color:#1a1a2e"><?php echo e($pp['concepto']); ?><?php if($esHoy): ?> <span style="font-size:.625rem;background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:6px;font-weight:700">HOY</span><?php endif; ?></div>
            <div style="font-size:.75rem;color:#94a3b8"><?php echo date('d/m/Y', strtotime($pp['fecha_vencimiento'])); ?></div>
          </div>
          <span style="font-weight:800;font-size:.9375rem;color:<?php echo $estColor[0]; ?>">€<?php echo number_format($pp['monto'],2,',','.'); ?></span>
          <span style="padding:3px 10px;border-radius:8px;font-size:.6875rem;font-weight:700;background:<?php echo $estColor[1]; ?>;color:<?php echo $estColor[0]; ?>"><?php echo $estColor[2]; ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; /* esAdmin financiero */ ?>

    <!-- Documentos -->
    <div class="cv-card">
      <div class="cv-card-header">
        <div class="cv-icon" style="background:#f5f3ff;color:#7c3aed">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
        </div>
        <h3>Documentos</h3>
        <span style="margin-left:auto;background:#f5f3ff;color:#7c3aed;padding:3px 10px;border-radius:8px;font-size:.75rem;font-weight:700"><?php echo count($documentos); ?></span>
      </div>
      <div class="cv-card-body">
        <?php if(empty($documentos)): ?>
        <p style="text-align:center;color:#94a3b8;padding:20px 0;font-size:.875rem">No hay documentos adjuntos</p>
        <?php else: ?>
        <?php foreach($documentos as $doc):
          $ext=strtoupper(pathinfo($doc['nombre_original'],PATHINFO_EXTENSION));
          [$bg,$clr]=$extColors[$ext]??['#f1f5f9','#64748b'];
          $kb=round($doc['tamano_bytes']/1024,1);
          $dlUrl=APP_URL.'/'.$doc['ruta'];
        ?>
        <div class="cv-file">
          <div class="cv-file-ico" style="background:<?php echo $bg;?>;color:<?php echo $clr;?>"><?php echo $ext;?></div>
          <div style="flex:1;min-width:0">
            <div class="cv-file-name"><?php echo e($doc['nombre_original']);?></div>
            <div class="cv-file-meta"><?php echo $kb;?> KB · <?php echo date('d/m/Y',strtotime($doc['created_at']));?><?php if(!empty($doc['descripcion'])): ?> · <?php echo e($doc['descripcion']);?><?php endif;?></div>
          </div>
          <a href="<?php echo $dlUrl;?>" target="_blank" class="cv-dl">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Descargar
          </a>
        </div>
        <?php endforeach;?>
        <?php endif;?>
      </div>
    </div>
  </div>


  <div>
    <!-- Estado -->
    <div class="cv-card">
      <div class="cv-card-header">
        <div class="cv-icon" style="background:#f0f9ff;color:#0284c7">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <h3>Estado del Caso</h3>
      </div>
      <div class="cv-card-body">
        <form method="POST">
          <?php echo CSRF::campo(); ?>
          <input type="hidden" name="cambiar_estado" value="1">
          <span class="cv-label">Estado actual</span>
          <!-- Custom select estado -->
          <div class="cs-w" id="csEstW" style="margin-bottom:14px">
            <div class="cs-btn hv" id="csEstBtn">
              <span class="cs-dot" style="background:<?php echo $eActual['dot'];?>"></span>
              <span id="csEstLbl"><?php echo $eActual['label'];?></span>
            </div>
            <svg class="cs-arr" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            <div class="cs-drop" id="csEstDrop">
              <?php foreach($estadoMap as $k=>$v): ?>
              <div class="cs-item <?php echo $caso['estado']===$k?'sel':'';?>" data-val="<?php echo $k;?>" data-nom="<?php echo $v['label'];?>" data-dot="<?php echo $v['dot'];?>">
                <span class="cs-dot" style="background:<?php echo $v['dot'];?>"></span>
                <?php echo $v['label'];?>
              </div>
              <?php endforeach; ?>
            </div>
            <input type="hidden" name="nuevo_estado" id="csEstHid" value="<?php echo $caso['estado'];?>">
          </div>
          <button type="submit" class="cv-btn cv-btn-primary" data-confirm="¿Cambiar el estado del caso?">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            Actualizar Estado
          </button>
        </form>
      </div>
    </div>

    <?php if (RoleGuard::esAdmin()): ?>
    <!-- Historial -->
    <div class="cv-card">
      <div class="cv-card-header">
        <div class="cv-icon" style="background:#fff7ed;color:#d97706">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <h3>Historial</h3>
      </div>
      <div class="cv-card-body" style="max-height:400px;overflow-y:auto">
        <?php if(empty($historial)): ?>
        <p style="color:#94a3b8;font-size:.875rem;text-align:center;padding:16px 0">Sin actividad registrada</p>
        <?php else: ?>
        <?php foreach($historial as $h): ?>
        <div class="cv-log">
          <div class="cv-log-dot"></div>
          <div><div class="cv-log-txt"><?php echo e($h['detalles']);?></div><div class="cv-log-date"><?php echo e($h['usuario_nombre']??'Sistema');?> &middot; <?php echo date('d/m/Y H:i',strtotime($h['created_at']));?></div></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Notas Internas: solo admin + abogado asignado -->
    <?php if($auth->esAdmin() || ($auth->esAbogado() && ($caso['abogado_id'] == ($usuario['id'] ?? 0)))): ?>
    <div class="cv-card">
      <div class="cv-card-header">
        <div class="cv-icon" style="background:#fef2f2;color:#dc2626">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </div>
        <h3>Notas Internas</h3>
        <span style="margin-left:auto;background:#fef2f2;color:#dc2626;padding:3px 10px;border-radius:8px;font-size:.6875rem;font-weight:700">PRIVADO</span>
      </div>
      <div class="cv-card-body">
        <form method="POST">
          <?php echo CSRF::campo(); ?>
          <input type="hidden" name="guardar_notas" value="1">
          <textarea name="notas_internas" class="cv-input" rows="5" placeholder="A&#241;ade notas internas... (solo visible para admins y abogado asignado)" style="min-height:110px;resize:vertical;width:100%"><?php echo e($caso['notas_internas'] ?? ''); ?></textarea>
          <button type="submit" class="cv-btn cv-btn-primary" style="margin-top:10px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
            Guardar Notas
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal editar caso -->
<div class="modal fade" id="editarCasoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content radius-8">
            <?php echo CSRF::campo(); ?>
            <input type="hidden" name="editar_caso" value="1">
            <input type="hidden" name="caso_updated_at" value="<?php echo e($caso['updated_at']); ?>">
            <div class="modal-header"><h6 class="modal-title">Editar Caso</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row gy-3">
                    <div class="col-sm-8"><label class="form-label">Título</label><input type="text" name="titulo" class="form-control" value="<?php echo e($caso['titulo']); ?>" required></div>
                    <div class="col-sm-4"><label class="form-label">Tipo de Caso</label><input type="text" name="tipo_caso" class="form-control" value="<?php echo e($caso['tipo_caso']); ?>" required></div>
                    <div class="col-sm-6"><label class="form-label fw-semibold" style="font-size:.8125rem">Abogado Asignado</label>
                        <?php
                        $abSel = $caso['abogado_id'] ?? '';
                        $abNom = 'Sin asignar';
                        foreach($abogados as $ab){ if($ab['id']==$abSel){ $abNom=e($ab['nombre'].' '.$ab['apellidos']); break; } }
                        ?>
                        <div class="cs-w" id="csModalW">
                          <div class="cs-btn <?php echo $abSel?'hv':''; ?>" id="csModalBtn">
                            <?php if($abSel): ?><div class="cs-av" style="width:22px;height:22px;border-radius:6px;background:linear-gradient(135deg,#2e6edd,#6ba3ff);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.625rem;font-weight:800;margin-right:6px"><?php echo strtoupper(substr($abNom,0,1)); ?></div><?php endif; ?>
                            <span id="csModalLbl"><?php echo $abNom; ?></span>
                          </div>
                          <svg class="cs-arr" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                          <div class="cs-drop" id="csModalDrop">
                            <div class="cs-item <?php echo !$abSel?'sel':''; ?>" data-val="" data-nom="Sin asignar" data-ini=""><span style="color:#94a3b8;font-size:.8125rem">Sin asignar</span></div>
                            <?php foreach($abogados as $ab): $ini=strtoupper(substr($ab['nombre'],0,1)); ?>
                            <div class="cs-item <?php echo $ab['id']==$abSel?'sel':''; ?>" data-val="<?php echo $ab['id']; ?>" data-nom="<?php echo e($ab['nombre'].' '.$ab['apellidos']); ?>" data-ini="<?php echo $ini; ?>">
                              <div style="width:22px;height:22px;border-radius:6px;background:linear-gradient(135deg,#2e6edd,#6ba3ff);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.625rem;font-weight:800;flex-shrink:0"><?php echo $ini; ?></div>
                              <?php echo e($ab['nombre'].' '.$ab['apellidos']); ?>
                            </div>
                            <?php endforeach; ?>
                          </div>
                          <input type="hidden" name="abogado_id" id="csModalHid" value="<?php echo $abSel; ?>">
                        </div>
                    </div>
                    <div class="col-sm-6">&nbsp;</div>
                    <div class="col-12"><label class="form-label">Descripción</label><textarea name="descripcion" class="form-control" rows="3"><?php echo e($caso['descripcion']); ?></textarea></div>
                    <div class="col-12"><label class="form-label">Notas Internas</label><textarea name="notas_internas" class="form-control" rows="2"><?php echo e($caso['notas_internas']); ?></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary radius-8" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary radius-8">Guardar</button></div>
        </form>
    </div>
</div>

<!-- Modal editar datos financieros -->
<div class="modal fade" id="editarFinancieroModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content radius-8">
            <?php echo CSRF::campo(); ?>
            <input type="hidden" name="editar_financiero" value="1">
            <div class="modal-header">
                <h6 class="modal-title" style="display:flex;align-items:center;gap:8px">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    Configurar Plan de Pago
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php $tpc = $caso['tipo_pago_cliente'] ?? 'pago_unico'; $freq = $caso['frecuencia_pago'] ?? 'mensual'; ?>
                <div class="row gy-3">
                    <div class="col-12">
                        <label class="cv-label">Honorarios Totales (&euro;)</label>
                        <input type="number" name="honorarios_totales" class="cv-input" id="finHonorarios"
                               step="0.01" min="0" value="<?php echo $caso['honorarios_totales']; ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="cv-label">Tipo de Pago del Cliente</label>
                        <div class="cs-w" id="csTipoPagoW">
                          <div class="cs-btn hv" id="csTipoPagoBtn">
                            <span class="cs-dot" id="csTipoPagoDot" style="background:<?php echo match($tpc){'cuotas'=>'#d97706','fechas_custom'=>'#7c3aed',default=>'#2563eb'}; ?>"></span>
                            <span id="csTipoPagoLbl"><?php echo match($tpc){'cuotas'=>'Pago por Cuotas','fechas_custom'=>'Fechas Personalizadas',default=>'Pago Único'}; ?></span>
                          </div>
                          <svg class="cs-arr" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                          <div class="cs-drop" id="csTipoPagoDrop">
                            <div class="cs-item <?php echo $tpc==='pago_unico'?'sel':''; ?>" data-val="pago_unico" data-nom="Pago Único" data-dot="#2563eb">
                              <span class="cs-dot" style="background:#2563eb"></span> Pago Único
                            </div>
                            <div class="cs-item <?php echo $tpc==='cuotas'?'sel':''; ?>" data-val="cuotas" data-nom="Pago por Cuotas" data-dot="#d97706">
                              <span class="cs-dot" style="background:#d97706"></span> Pago por Cuotas
                            </div>
                            <div class="cs-item <?php echo $tpc==='fechas_custom'?'sel':''; ?>" data-val="fechas_custom" data-nom="Fechas Personalizadas" data-dot="#7c3aed">
                              <span class="cs-dot" style="background:#7c3aed"></span> Fechas Personalizadas
                            </div>
                          </div>
                          <input type="hidden" name="tipo_pago_cliente" id="csTipoPagoHid" value="<?php echo $tpc; ?>">
                        </div>
                    </div>

                    <!-- PAGO ÚNICO -->
                    <div class="col-12" id="wrapPagoUnico" style="<?php echo $tpc !== 'pago_unico' ? 'display:none' : ''; ?>">
                        <label class="cv-label">Fecha de Pago</label>
                        <input type="date" name="fecha_pago_unico" class="cv-input" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                    </div>

                    <!-- CUOTAS -->
                    <div id="wrapCuotas" style="<?php echo $tpc !== 'cuotas' ? 'display:none' : ''; ?>">
                        <div class="row gy-3">
                            <div class="col-sm-4">
                                <label class="cv-label">Número de Cuotas</label>
                                <div class="cs-w" id="csCuotasW">
                                  <div class="cs-btn hv" id="csCuotasBtn"><span id="csCuotasLbl">3 cuotas</span></div>
                                  <svg class="cs-arr" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                                  <div class="cs-drop" id="csCuotasDrop">
                                    <?php for($n=2;$n<=12;$n++): ?>
                                    <div class="cs-item" data-val="<?php echo $n;?>" data-nom="<?php echo $n;?> cuotas"><?php echo $n;?> cuotas</div>
                                    <?php endfor;?>
                                  </div>
                                  <input type="hidden" name="num_cuotas" id="csCuotasHid" value="3">
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <label class="cv-label">Frecuencia</label>
                                <div class="cs-w" id="csFreqW">
                                  <div class="cs-btn hv" id="csFreqBtn"><span id="csFreqLbl"><?php echo ucfirst($freq); ?></span></div>
                                  <svg class="cs-arr" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                                  <div class="cs-drop" id="csFreqDrop">
                                    <div class="cs-item <?php echo $freq==='mensual'?'sel':'';?>" data-val="mensual" data-nom="Mensual">Mensual (30 días)</div>
                                    <div class="cs-item <?php echo $freq==='quincenal'?'sel':'';?>" data-val="quincenal" data-nom="Quincenal">Quincenal (15 días)</div>
                                    <div class="cs-item <?php echo $freq==='semanal'?'sel':'';?>" data-val="semanal" data-nom="Semanal">Semanal (7 días)</div>
                                  </div>
                                  <input type="hidden" name="frecuencia_pago" id="csFreqHid" value="<?php echo $freq; ?>">
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <label class="cv-label">Fecha 1ª Cuota</label>
                                <input type="date" name="fecha_inicio_cuotas" class="cv-input" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        <div id="cuotaHelper" style="font-size:.8125rem;color:#059669;font-weight:600;margin-top:8px"></div>
                    </div>

                    <!-- FECHAS CUSTOM -->
                    <div class="col-12" id="wrapCustom" style="<?php echo $tpc !== 'fechas_custom' ? 'display:none' : ''; ?>">
                        <label class="cv-label">Fechas y Montos Personalizados</label>
                        <div id="customRows">
                            <div style="display:flex;gap:8px;margin-bottom:8px">
                                <input type="date" name="fechas_custom[]" class="cv-input" style="flex:1">
                                <input type="number" name="montos_custom[]" class="cv-input" style="flex:1" step="0.01" placeholder="Monto €">
                                <button type="button" onclick="this.parentElement.remove()" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:1.2rem;padding:0 8px">&times;</button>
                            </div>
                        </div>
                        <button type="button" onclick="addCustomRow()" class="cv-btn cv-btn-ghost" style="width:auto;padding:6px 14px;font-size:.8125rem;margin-top:4px">+ Añadir Fecha</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary radius-8" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary radius-8">Guardar Plan de Pago</button>
            </div>
        </form>
    </div>
</div>


<script>
// Generic CS init
function initCS(wId,btnId,dropId,lblId,hidId,onSelect){
  const btn=document.getElementById(btnId),drop=document.getElementById(dropId),lbl=document.getElementById(lblId),hid=document.getElementById(hidId);
  if(!btn)return;
  function pos(){const r=btn.getBoundingClientRect();drop.style.top=(r.bottom+window.scrollY+4)+'px';drop.style.left=(r.left+window.scrollX)+'px';drop.style.width=r.width+'px';document.body.appendChild(drop);}
  btn.addEventListener('click',()=>{pos();btn.classList.toggle('op');drop.classList.toggle('op');});
  drop.querySelectorAll('.cs-item').forEach(i=>{
    i.addEventListener('click',()=>{
      hid.value=i.dataset.val;if(lbl)lbl.textContent=i.dataset.nom;
      const dot=btn.querySelector('.cs-dot')||document.getElementById(btnId.replace('Btn','Dot'));
      if(dot&&i.dataset.dot)dot.style.background=i.dataset.dot;
      drop.querySelectorAll('.cs-item').forEach(x=>x.classList.remove('sel'));i.classList.add('sel');
      btn.classList.remove('op');drop.classList.remove('op');
      if(onSelect)onSelect(i.dataset.val,i);
    });
  });
  document.addEventListener('click',e=>{if(!e.target.closest('#'+wId)&&!e.target.closest('#'+dropId)){btn.classList.remove('op');drop.classList.remove('op');}});
}
// Abogado select (modal editar caso)
(function(){
  const btn=document.getElementById('csModalBtn'),drop=document.getElementById('csModalDrop'),lbl=document.getElementById('csModalLbl'),hid=document.getElementById('csModalHid');
  if(!btn)return;
  function pos(){const r=btn.getBoundingClientRect();drop.style.top=(r.bottom+window.scrollY+4)+'px';drop.style.left=(r.left+window.scrollX)+'px';drop.style.width=r.width+'px';document.body.appendChild(drop);}
  btn.addEventListener('click',()=>{pos();btn.classList.toggle('op');drop.classList.toggle('op');});
  drop.querySelectorAll('.cs-item').forEach(i=>{
    i.addEventListener('click',()=>{
      hid.value=i.dataset.val;lbl.textContent=i.dataset.nom;
      const av=btn.querySelector('.cs-av');
      if(i.dataset.ini){if(av)av.textContent=i.dataset.ini;else{const d=document.createElement('div');d.className='cs-av';d.style.cssText='width:22px;height:22px;border-radius:6px;background:linear-gradient(135deg,#2e6edd,#6ba3ff);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.625rem;font-weight:800;margin-right:6px';d.textContent=i.dataset.ini;btn.insertBefore(d,btn.firstChild);}}
      else{if(av)av.remove();}
      drop.querySelectorAll('.cs-item').forEach(x=>x.classList.remove('sel'));i.classList.add('sel');
      btn.classList.remove('op');drop.classList.remove('op');
    });
  });
  document.addEventListener('click',e=>{if(!e.target.closest('#csModalW')&&!e.target.closest('#csModalDrop')){btn.classList.remove('op');drop.classList.remove('op');}});
})();
// Estado
initCS('csEstW','csEstBtn','csEstDrop','csEstLbl','csEstHid');
// Tipo de Pago
initCS('csTipoPagoW','csTipoPagoBtn','csTipoPagoDrop','csTipoPagoLbl','csTipoPagoHid',function(v){
  document.getElementById('wrapPagoUnico').style.display=v==='pago_unico'?'block':'none';
  document.getElementById('wrapCuotas').style.display=v==='cuotas'?'block':'none';
  document.getElementById('wrapCustom').style.display=v==='fechas_custom'?'block':'none';
  calcCuota();
});
// Cuotas
initCS('csCuotasW','csCuotasBtn','csCuotasDrop','csCuotasLbl','csCuotasHid',calcCuota);
// Frecuencia
initCS('csFreqW','csFreqBtn','csFreqDrop','csFreqLbl','csFreqHid');
// Calc cuota helper
function calcCuota(){
  const h=parseFloat(document.getElementById('finHonorarios')?.value)||0;
  const n=parseInt(document.getElementById('csCuotasHid')?.value)||1;
  const el=document.getElementById('cuotaHelper');
  if(el&&h>0)el.textContent='\u2248 \u20ac'+(h/n).toLocaleString('es-ES',{minimumFractionDigits:2,maximumFractionDigits:2})+' / cuota';
  else if(el)el.textContent='';
}
function addCustomRow(){
  const c=document.getElementById('customRows');
  const d=document.createElement('div');d.style.cssText='display:flex;gap:8px;margin-bottom:8px';
  d.innerHTML='<input type="date" name="fechas_custom[]" class="cv-input" style="flex:1"><input type="number" name="montos_custom[]" class="cv-input" style="flex:1" step="0.01" placeholder="Monto \u20ac"><button type="button" onclick="this.parentElement.remove()" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:1.2rem;padding:0 8px">&times;</button>';
  c.appendChild(d);
}
document.addEventListener('DOMContentLoaded',()=>{
  document.getElementById('finHonorarios')?.addEventListener('input',calcCuota);
  calcCuota();
});
</script>


<?php include CRM_ROOT . '/templates/layout/footer.php'; ?>
