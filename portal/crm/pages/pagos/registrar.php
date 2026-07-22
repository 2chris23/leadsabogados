<?php
/**
 * CRM Abogados - Registrar Pago
 */
RoleGuard::requireRole('admin');
$db = Database::getInstance();
global $usuario;
$casoId = (int)($_GET['caso_id'] ?? $_POST['caso_id'] ?? 0);

if (!$casoId) { header('Location: ' . APP_URL . '/index.php?page=casos'); exit; }
RoleGuard::verificarAccesoCaso($casoId);

$caso = $db->fetchOne(
    "SELECT c.*, cl.nombre as cliente_nombre, cl.apellidos as cliente_apellidos
     FROM casos c JOIN clientes cl ON c.cliente_id = cl.id WHERE c.id = ?", [$casoId]
);
if (!$caso) { setFlash('error', 'Caso no encontrado'); header('Location: ' . APP_URL . '/index.php?page=casos'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_pago'])) {
    CSRF::verificarOAbortar();
    $cantidad   = (float)$_POST['cantidad'];
    $concepto   = trim($_POST['concepto']);
    $metodoPago = $_POST['metodo_pago'];
    $tipoPago   = $_POST['tipo_pago'];
    $fechaPago  = $_POST['fecha_pago'];

    $db->insert('pagos', [
        'caso_id'        => $casoId,
        'fecha_pago'     => $fechaPago,
        'cantidad'       => $cantidad,
        'concepto'       => $concepto,
        'metodo_pago'    => $metodoPago,
        'tipo_pago'      => $tipoPago,
        'notas'          => trim($_POST['notas']),
        'registrado_por' => $usuario['id']
    ]);
    AuditLog::registrar('registrar_pago', 'casos', $casoId,
        'Pago de cliente por €' . number_format($cantidad, 2) . ' registrado');

    setFlash('exito', 'Pago registrado correctamente');
    header('Location: ' . APP_URL . '/index.php?page=casos/ver&id=' . $casoId); exit;
}

$tituloPagina = 'Registrar Pago';
include CRM_ROOT . '/templates/layout/header.php';

$tiposPago = [
    'cuota_mensual'          => ['Cuota Mensual',              '#2563eb'],
    'senal_intermedio_final' => ['Señal + Intermedio + Final', '#d97706'],
    'solo_si_gana'           => ['Solo si Gana',               '#059669'],
    'provision_fondos'       => ['Provisión de Fondos',        '#7c3aed'],
    'pago_unico'             => ['Pago Único',                 '#0284c7'],
];
$metodosPago = [
    'transferencia' => ['Transferencia Bancaria', '#2563eb'],
    'efectivo'      => ['Efectivo',               '#059669'],
    'tarjeta'       => ['Tarjeta Crédito/Débito', '#d97706'],
    'domiciliado'   => ['Pago Domiciliado',       '#7c3aed'],
    'cheque'        => ['Cheque',                  '#64748b'],
    'otro'          => ['Otro',                    '#94a3b8'],
];
?>
<link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/caso-ver.css">

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Registrar Pago — <?php echo e($caso['referencia']); ?></h6>
    <ul class="d-flex align-items-center gap-2">
        <li><a href="<?php echo APP_URL; ?>/index.php?page=casos/ver&id=<?php echo $casoId; ?>" class="hover-text-primary">Caso</a></li>
        <li>-</li><li>Registrar Pago</li>
    </ul>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card radius-8 border">
            <div class="card-body p-24">
                <div class="bg-primary-50 radius-8 p-16 mb-24">
                    <div class="row">
                        <div class="col-sm-6">
                            <small class="text-secondary-light">Cliente</small>
                            <p class="fw-medium mb-0"><?php echo e($caso['cliente_nombre'].' '.$caso['cliente_apellidos']); ?></p>
                        </div>
                        <div class="col-sm-6">
                            <small class="text-secondary-light">Caso</small>
                            <p class="fw-medium mb-0"><?php echo e($caso['titulo']); ?></p>
                        </div>
                    </div>
                </div>

                <form method="POST">
                    <?php echo CSRF::campo(); ?>
                    <input type="hidden" name="registrar_pago" value="1">
                    <input type="hidden" name="caso_id" value="<?php echo $casoId; ?>">
                    <div class="row gy-3">
                        <div class="col-sm-6">
                            <label class="cv-label">Fecha del Pago <span style="color:#dc2626">*</span></label>
                            <input type="date" name="fecha_pago" class="cv-input" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="cv-label">Cantidad (€) <span style="color:#dc2626">*</span></label>
                            <input type="number" name="cantidad" class="cv-input" step="0.01" min="0.01" placeholder="0.00" required>
                        </div>
                        <div class="col-12">
                            <label class="cv-label">Concepto <span style="color:#dc2626">*</span></label>
                            <input type="text" name="concepto" class="cv-input" placeholder="Ej: Pago parcial de honorarios" required>
                        </div>
                        <div class="col-12">
                            <label class="cv-label">Método de Pago <span style="color:#dc2626">*</span></label>
                            <div class="cs-w" id="csMetodoW">
                              <div class="cs-btn" id="csMetodoBtn"><span id="csMetodoLbl" style="color:#94a3b8">Seleccionar método...</span></div>
                              <svg class="cs-arr" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                              <div class="cs-drop" id="csMetodoDrop">
                                <?php foreach($metodosPago as $k=>[$lbl,$clr]): ?>
                                <div class="cs-item" data-val="<?php echo $k;?>" data-nom="<?php echo $lbl;?>">
                                  <span class="cs-dot" style="background:<?php echo $clr;?>"></span> <?php echo $lbl;?>
                                </div>
                                <?php endforeach;?>
                              </div>
                              <input type="hidden" name="metodo_pago" id="csMetodoHid" value="transferencia" required>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="cv-label">Notas (opcional)</label>
                            <textarea name="notas" class="cv-input" rows="2" style="resize:vertical;min-height:60px"></textarea>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-24">
                        <button type="submit" class="cv-btn cv-btn-success" style="width:auto;padding:11px 24px">Registrar Pago</button>
                        <a href="<?php echo APP_URL; ?>/index.php?page=casos/ver&id=<?php echo $casoId; ?>" class="cv-btn cv-btn-ghost" style="width:auto;padding:11px 20px">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function initCS(wId,btnId,dropId,lblId,hidId){
  const btn=document.getElementById(btnId),drop=document.getElementById(dropId),lbl=document.getElementById(lblId),hid=document.getElementById(hidId);
  if(!btn)return;
  function pos(){const r=btn.getBoundingClientRect();drop.style.top=(r.bottom+window.scrollY+4)+'px';drop.style.left=(r.left+window.scrollX)+'px';drop.style.width=r.width+'px';document.body.appendChild(drop);}
  btn.addEventListener('click',()=>{pos();btn.classList.toggle('op');drop.classList.toggle('op');});
  drop.querySelectorAll('.cs-item').forEach(i=>{
    i.addEventListener('click',()=>{
      hid.value=i.dataset.val;lbl.textContent=i.dataset.nom;lbl.style.color='#1a1a2e';btn.classList.add('hv');
      drop.querySelectorAll('.cs-item').forEach(x=>x.classList.remove('sel'));i.classList.add('sel');
      btn.classList.remove('op');drop.classList.remove('op');
    });
  });
  document.addEventListener('click',e=>{if(!e.target.closest('#'+wId)&&!e.target.closest('#'+dropId)){btn.classList.remove('op');drop.classList.remove('op');}});
}
initCS('csMetodoW','csMetodoBtn','csMetodoDrop','csMetodoLbl','csMetodoHid');
</script>

<?php include CRM_ROOT . '/templates/layout/footer.php'; ?>
