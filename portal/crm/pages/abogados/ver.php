<?php
// Configuración y Seguridad
if(!defined('CRM_ROOT')) define('CRM_ROOT', dirname(dirname(__DIR__)));
require_once CRM_ROOT . '/includes/config.php';
require_once CRM_ROOT . '/includes/Database.php';

$db = Database::getInstance();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// --- PROCESAR ACTUALIZACIÓN DE FINANZAS POR CASO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_finanzas_caso'])) {
    $casoId = (int)$_POST['caso_id'];
    $sumarCoste = (float)($_POST['sumar_coste'] ?? 0);
    
    if ($sumarCoste > 0) {
        $db->query("UPDATE casos SET honorarios_abogado = honorarios_abogado + ? WHERE id = ?", [$sumarCoste, $casoId]);
        
        // Registrar el egreso en la tabla pagos
        $db->insert('pagos', [
            'caso_id' => $casoId,
            'fecha_pago' => date('Y-m-d'),
            'cantidad' => $sumarCoste,
            'concepto' => 'Pago de honorarios al abogado',
            'metodo_pago' => 'transferencia',
            'tipo_pago' => 'pago_abogado',
            'registrado_por' => $_SESSION['usuario_id'] ?? null
        ]);
        
        // Registrar en el historial del caso
        AuditLog::registrar('pago_abogado', 'casos', $casoId, 'Se ha registrado un pago de honorarios al abogado por €' . number_format($sumarCoste, 2, ',', '.'));
    }
    
    header("Location: index.php?page=abogados/ver&id=" . $id);
    exit;
}

// --- PROCESAR TARIFAS GLOBALES DEL ABOGADO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_tarifas_globales'])) {
    $tipo = $_POST['tipo_pago_predeterminado'];
    $monto = (float)($_POST['monto_principal'] ?? 0);
    $dia = (int)($_POST['dia_pago_mensual'] ?? 0);
    $monto_señal = (float)($_POST['monto_señal'] ?? 0);
    $monto_intermedio = (float)($_POST['monto_intermedio'] ?? 0);
    $monto_final = (float)($_POST['monto_final'] ?? 0);

    $totalHitos = $monto_señal + $monto_intermedio + $monto_final;

    $db->query("UPDATE usuarios_internos SET 
                tipo_pago_predeterminado = ?, 
                tarifa_mensual_default = ?, 
                dia_pago_mensual = ?,
                tarifa_fija_default = ?,
                tarifa_exito_default = ?
                WHERE id = ?", 
                [$tipo, ($tipo == 'mensual' ? $monto : 0), $dia, 
                 ($tipo == 'hitos' ? $totalHitos : 0),
                 ($tipo == 'exito' ? $monto : 0), $id]);
    
    header("Location: index.php?page=abogados/ver&id=" . $id);
    exit;
}

// Obtener datos
$abogado = $db->fetchOne("SELECT * FROM usuarios_internos WHERE id = ? AND rol = 'abogado'", [$id]);
if (!$abogado) { header('Location: index.php?page=abogados'); exit; }

$totalHonorariosClientes = (float)$db->fetchColumn("SELECT SUM(honorarios_totales) FROM casos WHERE abogado_id = ?", [$id]);
$totalHonorariosAbogado  = (float)$db->fetchColumn("SELECT SUM(honorarios_abogado) FROM casos WHERE abogado_id = ?", [$id]);
$beneficioEstimado = $totalHonorariosClientes - $totalHonorariosAbogado;

$casos = $db->fetchAll("SELECT c.*, cl.nombre as cliente_nombre, cl.apellidos as cliente_apellidos, (SELECT SUM(cantidad) FROM pagos WHERE caso_id = c.id AND (tipo_pago IS NULL OR tipo_pago != 'pago_abogado')) as cobrado FROM casos c JOIN clientes cl ON c.cliente_id = cl.id WHERE c.abogado_id = ? ORDER BY c.created_at DESC", [$id]);
$pagos = $db->fetchAll("SELECT p.*, c.titulo as caso_titulo, cl.nombre as cliente_nombre FROM pagos p JOIN casos c ON p.caso_id = c.id JOIN clientes cl ON c.cliente_id = cl.id WHERE c.abogado_id = ? ORDER BY p.fecha_pago DESC LIMIT 10", [$id]);

// Estadísticas por estado
$estadisticaRows = $db->fetchAll("SELECT estado, COUNT(*) as total FROM casos WHERE abogado_id = ? GROUP BY estado", [$id]);
$statsPorEstado = [];
foreach ($estadisticaRows as $r) $statsPorEstado[$r['estado']] = (int)$r['total'];
$totalCasos = array_sum($statsPorEstado);

include CRM_ROOT . '/templates/layout/header.php';
?>

<div class="dashboard-main-body">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <h6 class="fw-semibold mb-0">Perfil del Abogado</h6>
    </div>

    <div class="row gy-24">
        <!-- Sidebar -->
        <div class="col-lg-4 col-xxl-3">
            <div class="card radius-16 border bg-white shadow-sm mb-24" style="overflow: visible;">
                <div class="card-body p-24 text-center">
                    <div class="mb-16">
                        <?php if (!empty($abogado['foto'])): ?>
                            <img src="<?php echo APP_URL . '/../' . $abogado['foto']; ?>" class="w-120-px h-120-px rounded-circle object-fit-cover mx-auto border shadow" alt="Foto">
                        <?php else: ?>
                            <div class="w-120-px h-120-px bg-primary-100 text-primary-600 rounded-circle d-flex align-items-center justify-content-center text-4xl fw-bold mx-auto border shadow">
                                <?php echo strtoupper(substr($abogado['nombre'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <h4 class="mb-4 fw-bold"><?php echo e($abogado['nombre'] . ' ' . $abogado['apellidos']); ?></h4>
                    <span class="badge bg-primary-100 text-primary-600 px-16 py-6 radius-pill fw-bold text-xs uppercase mb-16">Abogado</span>
                    
                    <div class="mb-32 text-center text-secondary-light">
                        <div class="d-flex align-items-center justify-content-center gap-2 mb-8 text-sm">
                            <iconify-icon icon="solar:letter-outline"></iconify-icon> <?php echo e($abogado['email']); ?>
                        </div>
                        <div class="d-flex align-items-center justify-content-center gap-2 text-sm">
                            <iconify-icon icon="solar:phone-outline"></iconify-icon> <?php echo e($abogado['telefono'] ?? '+00 000 000 000'); ?>
                        </div>
                        <?php if (!empty($abogado['sitio_web'])): ?>
                        <div class="d-flex align-items-center justify-content-center gap-2 text-sm mt-8">
                            <iconify-icon icon="solar:global-outline"></iconify-icon> <a href="<?php echo e($abogado['sitio_web']); ?>" target="_blank" class="text-primary-600 hover-text-primary"><?php echo e($abogado['sitio_web']); ?></a>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($abogado['especialidades'])): ?>
                        <div class="mt-16 pt-16 border-top text-center">
                            <div class="text-xs fw-bold text-secondary-light uppercase mb-8">Especialidades</div>
                            <?php foreach(explode(',', $abogado['especialidades']) as $esp): ?>
                                <span class="badge bg-neutral-100 text-neutral-600 radius-4 mb-4"><?php echo e(trim($esp)); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Configuración Financiera -->
                    <div class="pt-24 border-top text-start">
                        <div class="d-flex align-items-center justify-content-between mb-16">
                            <div style="font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px;">Configuración de Cobro</div>
                            <div class="dropend">
                                <button class="btn btn-sm btn-outline-primary radius-8 p-4 d-flex" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-auto-close="outside">
                                    <iconify-icon icon="solar:settings-outline"></iconify-icon>
                                </button>
                                <div class="dropdown-menu p-24 radius-24 shadow-lg border-0" style="width: 320px; position: fixed !important;">
                                    <h6 class="text-sm fw-bold mb-16 border-bottom pb-12 d-flex align-items-center gap-2">
                                        <iconify-icon icon="solar:dollar-minimalistic-outline" class="text-primary-600 text-xl"></iconify-icon>
                                        Acuerdo Financiero
                                    </h6>
                                    <form method="POST">
                                        <input type="hidden" name="actualizar_tarifas_globales" value="1">
                                        <input type="hidden" name="tipo_pago_predeterminado" id="inputTipoAcuerdo" value="<?php echo $abogado['tipo_pago_predeterminado'] ?? 'mensual'; ?>">
                                        
                                        <div class="d-flex flex-column gap-8 mb-20">
                                            <div class="acuerdo-opt p-12 radius-12 border d-flex align-items-center gap-12 cursor-pointer transition-all <?php echo ($abogado['tipo_pago_predeterminado'] == 'mensual') ? 'active' : ''; ?>" onclick="setAcuerdo('mensual', this)">
                                                <iconify-icon icon="solar:calendar-date-outline" class="text-primary-600 text-xl"></iconify-icon>
                                                <div><span class="d-block text-sm fw-bold">Pago Mensual</span><span class="text-xs text-secondary-light">Cuota fija cada mes</span></div>
                                            </div>
                                            <div class="acuerdo-opt p-12 radius-12 border d-flex align-items-center gap-12 cursor-pointer transition-all <?php echo ($abogado['tipo_pago_predeterminado'] == 'hitos') ? 'active' : ''; ?>" onclick="setAcuerdo('hitos', this)">
                                                <iconify-icon icon="solar:layers-outline" class="text-warning-600 text-xl"></iconify-icon>
                                                <div><span class="d-block text-sm fw-bold">Por Hitos</span><span class="text-xs text-secondary-light">Señal + Intermedio + Final</span></div>
                                            </div>
                                            <div class="acuerdo-opt p-12 radius-12 border d-flex align-items-center gap-12 cursor-pointer transition-all <?php echo ($abogado['tipo_pago_predeterminado'] == 'exito') ? 'active' : ''; ?>" onclick="setAcuerdo('exito', this)">
                                                <iconify-icon icon="solar:chart-2-outline" class="text-success-600 text-xl"></iconify-icon>
                                                <div><span class="d-block text-sm fw-bold">Solo si Gana</span><span class="text-xs text-secondary-light">Cobra únicamente al ganar</span></div>
                                            </div>
                                        </div>

                                        <div id="fieldsArea" class="bg-neutral-50 p-16 radius-12 mb-20 border border-dashed"></div>
                                        <button type="submit" class="btn btn-primary w-100 radius-12 fw-bold py-12">Guardar Cambios</button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="bg-neutral-50 p-16 radius-12 border">
                            <?php $t = $abogado['tipo_pago_predeterminado'] ?? 'mensual'; ?>
                            <span class="badge bg-white text-secondary-light border px-12 py-4 radius-pill mb-8" style="font-size: 10px;"><?php echo strtoupper($t); ?></span>
                            <h6 class="text-lg fw-bold mb-0">€<?php echo number_format($abogado['tarifa_mensual_default'] ?: ($abogado['tarifa_fija_default'] ?: $abogado['tarifa_exito_default']), 0, ',', '.'); ?></h6>
                            <p class="text-xs text-secondary-light mt-4 mb-0"><?php echo $t == 'mensual' ? "Cobro el día {$abogado['dia_pago_mensual']}" : "Acuerdo activo"; ?></p>
                        </div>
                    </div>
                    
                    <div class="mt-24 d-grid"><a href="index.php?page=usuarios/editar&id=<?php echo $id; ?>" class="btn btn-primary radius-12 fw-bold py-12">Editar Perfil</a></div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-lg-8 col-xxl-9">
            <div class="row gy-24 mb-24">
                <div class="col-sm-4"><div class="card p-20 radius-12 border bg-white shadow-sm h-100 d-flex align-items-center gap-12"><div class="w-44-px h-44-px bg-primary-50 text-primary-600 rounded-circle d-flex align-items-center justify-content-center text-xl"><iconify-icon icon="solar:folder-with-files-outline"></iconify-icon></div><div><h5 class="mb-0 fw-bold">€<?php echo number_format($totalHonorariosClientes, 0, ',', '.'); ?></h5><span class="text-xs text-secondary-light">Cartera Clientes</span></div></div></div>
                <div class="col-sm-4"><div class="card p-20 radius-12 border bg-white shadow-sm h-100 d-flex align-items-center gap-12"><div class="w-44-px h-44-px bg-success-50 text-success-600 rounded-circle d-flex align-items-center justify-content-center text-xl"><iconify-icon icon="solar:chart-square-outline"></iconify-icon></div><div><h5 class="mb-0 fw-bold text-success-main">€<?php echo number_format($beneficioEstimado, 0, ',', '.'); ?></h5><span class="text-xs text-secondary-light">Beneficio Despacho</span></div></div></div>
                <div class="col-sm-4"><div class="card p-20 radius-12 border bg-white shadow-sm h-100 d-flex align-items-center gap-12"><div class="w-44-px h-44-px bg-info-50 text-info-600 rounded-circle d-flex align-items-center justify-content-center text-xl"><iconify-icon icon="solar:users-group-two-rounded-outline"></iconify-icon></div><div><h5 class="mb-0 fw-bold text-info-main">€<?php echo number_format($totalHonorariosAbogado, 0, ',', '.'); ?></h5><span class="text-xs text-secondary-light">Coste Abogado</span></div></div></div>
            </div>

            <!-- Estadísticas por estado -->
            <div class="card radius-16 border bg-white shadow-sm mb-24 p-20">
                <div class="d-flex align-items-center justify-content-between mb-16">
                    <div style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:1px">Resumen de Casos</div>
                    <span class="badge bg-primary-100 text-primary-600 px-12 py-4 radius-pill fw-bold"><?php echo $totalCasos; ?> total</span>
                </div>
                <?php
                $statsDisplay = [
                    'en_estudio'       => ['En Estudio',       '#eff6ff','#2563eb'],
                    'en_proceso'       => ['En Proceso',       '#fffbeb','#d97706'],
                    'en_tramitacion'   => ['En Tramitación',   '#f0f9ff','#0284c7'],
                    'pendiente_juicio' => ['Pendiente Juicio', '#fef2f2','#dc2626'],
                    'cerrado'          => ['Cerrado',          '#f0fdf4','#059669'],
                    'archivado'        => ['Archivado',        '#f8fafc','#64748b'],
                ];
                ?>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
                <?php foreach($statsDisplay as $est => [$lbl,$bg,$clr]): $cnt=$statsPorEstado[$est]??0; $pct=$totalCasos>0?round($cnt/$totalCasos*100):0; ?>
                    <div style="background:<?php echo $bg;?>;border-radius:12px;padding:12px 14px">
                        <div style="font-size:.6875rem;font-weight:700;color:<?php echo $clr;?>;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px"><?php echo $lbl;?></div>
                        <div style="font-size:1.375rem;font-weight:800;color:#1a1a2e"><?php echo $cnt;?></div>
                        <?php if($totalCasos>0): ?>
                        <div style="background:rgba(0,0,0,.06);border-radius:99px;height:4px;margin-top:6px;overflow:hidden"><div style="width:<?php echo $pct;?>%;height:100%;background:<?php echo $clr;?>;border-radius:99px"></div></div>
                        <div style="font-size:.6875rem;color:<?php echo $clr;?>;font-weight:600;margin-top:3px"><?php echo $pct;?>%</div>
                        <?php endif;?>
                    </div>
                <?php endforeach;?>
                </div>
            </div>

            <div class="mb-24"><div style="font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase;">Cartera de Casos</div></div>
            <div class="row gy-24 mb-24">
                <?php foreach ($casos as $caso): 
                    $t = (float)$caso['honorarios_totales']; $c = (float)$caso['cobrado']; $p = ($t > 0) ? ($c / $t) * 100 : 0;
                    $ha = (float)($caso['honorarios_abogado'] ?? 0);
                ?>
                <div class="col-md-6 col-xxl-4">
                    <?php
                    $estadoBadgeMap = [
                        'en_estudio'       => ['En Estudio',       'bg-primary-50 text-primary-600'],
                        'en_proceso'       => ['En Proceso',       'bg-warning-50 text-warning-main'],
                        'en_tramitacion'   => ['En Tramitación',   'bg-info-50 text-info-main'],
                        'pendiente_juicio' => ['Pendiente Juicio', 'bg-danger-50 text-danger-main'],
                        'cerrado'          => ['Cerrado',          'bg-success-50 text-success-main'],
                        'archivado'        => ['Archivado',        'bg-neutral-200 text-neutral-600'],
                    ];
                    [$estLbl,$estCls] = $estadoBadgeMap[$caso['estado']] ?? ['Activo','bg-warning-50 text-warning-main'];
                    $casoUrl = APP_URL . '/index.php?page=casos/ver&id=' . $caso['id'];
                    ?>
                    <div class="card radius-24 border-0 shadow-sm bg-white p-24" style="cursor:pointer;transition:.2s" onmouseover="this.style.boxShadow='0 8px 30px rgba(0,0,0,.12)'" onmouseout="this.style.boxShadow=''" onclick="window.location='<?php echo $casoUrl; ?>'">
                        <div class="d-flex justify-content-between mb-16">
                            <span class="badge <?php echo $estCls; ?> px-12 py-6 radius-8 fw-bold text-xs"><?php echo $estLbl; ?></span>
                            <span class="text-xs text-secondary-light"><?php echo date('d M', strtotime($caso['created_at'])); ?></span>
                        </div>
                        <a href="<?php echo $casoUrl; ?>" class="text-dark" style="text-decoration:none">
                            <h6 class="mb-12 fw-bold text-dark" style="transition:.15s" onmouseover="this.style.color='#2e6edd'" onmouseout="this.style.color=''"><?php echo e($caso['titulo']); ?></h6>
                        </a>
                        <div class="bg-neutral-50 p-12 radius-16 mb-20 d-flex align-items-center gap-2"><div class="w-24-px h-24-px bg-primary-600 text-white rounded-circle d-flex align-items-center justify-content-center text-xs fw-bold"><?php echo substr($caso['cliente_nombre'],0,1); ?></div><span class="text-sm fw-semibold"><?php echo e($caso['cliente_nombre']); ?></span></div>
                        <div class="mb-16">
                            <div class="d-flex justify-content-between mb-4"><span class="text-xs text-secondary-light fw-bold uppercase">Cobro</span><span class="text-xs fw-bold"><?php echo (int)$p; ?>%</span></div>
                            <div class="progress w-100 radius-pill mb-16" style="height: 6px;"><div class="progress-bar bg-primary-600" style="width: <?php echo $p; ?>%"></div></div>
                            <div class="d-flex justify-content-between pt-12 border-top">
                                <div><span class="text-xs text-secondary-light d-block mb-2 fw-bold uppercase" style="font-size: 9px;">Coste</span><span class="text-sm fw-bold text-info-main">€<?php echo number_format($ha, 0, ',', '.'); ?></span></div>
                                <div class="text-end"><span class="text-xs text-secondary-light d-block mb-2 fw-bold uppercase" style="font-size: 9px;">Margen</span><span class="text-sm fw-bold text-success-main">€<?php echo number_format($t - $ha, 0, ',', '.'); ?></span></div>
                            </div>
                        </div>
                        <div class="d-flex gap-8 pt-12 border-top" onclick="event.stopPropagation()">
                            <a href="<?php echo $casoUrl; ?>" class="btn btn-sm btn-outline-primary flex-grow-1 radius-12 fw-bold">Ver Caso</a>
                            <a href="<?php echo APP_URL; ?>/index.php?page=pagos/registrar&caso_id=<?php echo $caso['id']; ?>" class="btn btn-sm btn-success flex-grow-1 radius-12 fw-bold">Cobro Cliente</a>
                            <button class="btn btn-sm btn-warning radius-12 px-12 fw-bold" data-bs-toggle="modal" data-bs-target="#mC<?php echo $caso['id']; ?>">Pago Abog.</button>
                        </div>
                    </div>
                </div>
                <div class="modal fade" id="mC<?php echo $caso['id']; ?>" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <form method="POST" class="modal-content radius-24 p-24">
                            <input type="hidden" name="actualizar_finanzas_caso" value="1">
                            <input type="hidden" name="caso_id" value="<?php echo $caso['id']; ?>">
                            <h6 class="mb-4 fw-bold">Registrar Pago al Abogado</h6>
                            <p class="text-sm text-secondary-light mb-20">Coste actual en este caso: <strong class="text-info-main">€<?php echo number_format($ha, 2, ',', '.'); ?></strong></p>
                            
                            <div class="mb-20">
                                <label class="form-label text-sm fw-bold text-dark">Monto a sumar (€)</label>
                                <input type="number" name="sumar_coste" class="form-control radius-12" placeholder="Ej: 200" step="0.01" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100 radius-12 fw-bold py-12">Registrar Pago</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<style>
.acuerdo-opt { border-color: #f1f5f9; cursor: pointer; }
.acuerdo-opt:hover { border-color: #4e73df; background: #f8fafc; }
.acuerdo-opt.active { border-color: #4e73df; background: #eff6ff; border-width: 2px; }
</style>

<script>
function setAcuerdo(tipo, el) {
    document.getElementById('inputTipoAcuerdo').value = tipo;
    document.querySelectorAll('.acuerdo-opt').forEach(o => o.classList.remove('active'));
    el.classList.add('active');
    const area = document.getElementById('fieldsArea');
    if(tipo == 'mensual') area.innerHTML = `<div class="row gx-8"><div class="col-6"><label class="text-xs fw-bold mb-4 d-block">DÍA</label><input type="number" name="dia_pago_mensual" class="form-control form-control-sm" value="<?php echo $abogado['dia_pago_mensual'] ?: 20; ?>"></div><div class="col-6"><label class="text-xs fw-bold mb-4 d-block">CUOTA (€)</label><input type="number" name="monto_principal" class="form-control form-control-sm" value="<?php echo $abogado['tarifa_mensual_default'] ?: 200; ?>"></div></div>`;
    else if(tipo == 'hitos') area.innerHTML = `<div class="d-flex flex-column gap-8"><input type="number" name="monto_señal" class="form-control form-control-sm" placeholder="Señal €"><input type="number" name="monto_intermedio" class="form-control form-control-sm" placeholder="Intermedio €"><input type="number" name="monto_final" class="form-control form-control-sm" placeholder="Final €"></div>`;
    else area.innerHTML = `<label class="text-xs fw-bold mb-4 d-block">ÉXITO (€)</label><input type="number" name="monto_principal" class="form-control form-control-sm" value="<?php echo $abogado['tarifa_exito_default'] ?: 500; ?>">`;
}
document.addEventListener('DOMContentLoaded', () => {
    const t = "<?php echo $abogado['tipo_pago_predeterminado'] ?: 'mensual'; ?>";
    setAcuerdo(t, document.querySelector(`.acuerdo-opt[onclick*="${t}"]`));
});
</script>

<?php include CRM_ROOT . '/templates/layout/footer.php'; ?>
