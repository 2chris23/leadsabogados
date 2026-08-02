<?php
/** CRM — Detalle de Solicitud (Rediseñado) */
$tituloPagina = 'Detalle de Solicitud';
$db  = Database::getInstance();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: '.APP_URL.'/index.php?page=solicitudes'); exit; }

$solicitud = $db->fetchOne(
    "SELECT s.*, u.nombre as p_nom, u.apellidos as p_ape, pc.fecha_nacimiento, pc.dni_nif, pc.direccion
     FROM solicitudes s 
     LEFT JOIN usuarios_internos u ON s.procesada_por=u.id 
     LEFT JOIN portal_cuentas pc ON s.portal_cuenta_id = pc.id
     WHERE s.id=?", [$id]
);
if (!$solicitud) { setFlash('error','Solicitud no encontrada'); header('Location: '.APP_URL.'/index.php?page=solicitudes'); exit; }

$archivos = $db->fetchAll("SELECT * FROM solicitud_archivos WHERE solicitud_id=? ORDER BY created_at", [$id]);

include CRM_ROOT.'/templates/layout/header.php';

$badgeCls = match($solicitud['estado']) {
    'pendiente' => 'sv-badge-pending',
    'aceptada'  => 'sv-badge-accepted',
    'denegada'  => 'sv-badge-denied',
    default     => 'sv-badge-other'
};
$estadoLabel = ['pendiente'=>'Pendiente','aceptada'=>'Aceptada','denegada'=>'Denegada','archivada'=>'Archivada'][$solicitud['estado']] ?? ucfirst($solicitud['estado']);

$extColors = ['PDF'=>['#fef2f2','#dc2626'],'DOC'=>['#e8f0fe','#2e6edd'],'DOCX'=>['#e8f0fe','#2e6edd'],'XLS'=>['#ecfdf5','#059669'],'XLSX'=>['#ecfdf5','#059669'],'JPG'=>['#fff7ed','#ea580c'],'PNG'=>['#fff7ed','#ea580c'],'ZIP'=>['#f5f3ff','#7c3aed'],'RAR'=>['#f5f3ff','#7c3aed']];

$abogados = $auth->esAdmin() ? $db->fetchAll("SELECT id,nombre,apellidos FROM usuarios_internos WHERE rol='abogado' AND activo=1 ORDER BY nombre") : [];
$asignaciones = $db->fetchAll("
    SELECT sa.*, u.nombre, u.apellidos
    FROM solicitud_asignaciones sa
    JOIN usuarios_internos u ON sa.abogado_id = u.id
    WHERE sa.solicitud_id = ?
", [$id]);
$misAsignaciones = array_filter($asignaciones, fn($a) => $a['abogado_id'] == $auth->getUsuario()['id']);
$miAsignacion = reset($misAsignaciones);
?>
<link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/solicitud-ver.css?v=<?php echo time(); ?>">

<!-- Breadcrumb -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px">
  <div>
    <h5 style="font-size:1.25rem;font-weight:800;color:#1a1a2e;margin:0">Solicitud <span style="color:#2e6edd">#<?php echo $id; ?></span></h5>
    <p style="font-size:.8125rem;color:#94a3b8;margin:2px 0 0">Recibida el <?php echo date('d/m/Y \a\l H:i', strtotime($solicitud['created_at'])); ?></p>
  </div>
  <a href="<?php echo APP_URL; ?>/index.php?page=solicitudes" style="display:flex;align-items:center;gap:6px;padding:8px 16px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.8125rem;font-weight:600;color:#64748b;text-decoration:none;transition:.2s" onmouseover="this.style.borderColor='#2e6edd';this.style.color='#2e6edd'" onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#64748b'">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
    Volver
  </a>
</div>

<style>
/* Forzar el grid y evitar que la columna izquierda se estire infinitamente */
.sv-wrap {
    display: grid !important;
    grid-template-columns: minmax(0, 850px) 340px !important;
    gap: 16px !important;
    align-items: start !important;
}
@media(max-width:991px) {
    .sv-wrap {
        grid-template-columns: 1fr !important;
    }
}
</style>
<div class="sv-wrap">
  <!-- ══ COL IZQUIERDA ══ -->
  <div>
    <!-- Solicitante -->
    <div class="sv-card">
      <div class="sv-card-header">
        <div class="sv-hicon" style="background:#e8f0fe;color:#2e6edd">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
        <h3>Datos del Solicitante</h3>
      </div>
      <div class="sv-card-body">
        <div class="sv-grid">
          <div class="sv-field"><label>Nombre completo</label><p><?php echo e($solicitud['nombre'].' '.$solicitud['apellidos']); ?></p></div>
          <div class="sv-field"><label>Fecha de Nacimiento</label><p><?php echo $solicitud['fecha_nacimiento'] ? date('d/m/Y', strtotime($solicitud['fecha_nacimiento'])) : 'No proporcionada'; ?></p></div>
          <div class="sv-field"><label>DNI / NIF</label><p><?php echo e($solicitud['dni_nif'] ?? 'No proporcionado'); ?></p></div>
          <div class="sv-field"><label>Dirección</label><p><?php echo e($solicitud['direccion'] ?? 'No proporcionada'); ?></p></div>
          <div class="sv-field"><label>Correo electrónico</label><p><?php echo e($solicitud['email']); ?></p></div>
          <div class="sv-field"><label>Teléfono</label>
            <p style="display:flex;align-items:center;gap:8px">
              <?php echo e($solicitud['telefono'] ?: 'No proporcionado'); ?>
              <?php if(!empty($solicitud['telefono'])): 
                  $waNum = preg_replace('/[^0-9]/', '', $solicitud['telefono']);
                  if (strlen($waNum) == 9) $waNum = '34' . $waNum;
              ?>
              <a href="https://wa.me/<?php echo $waNum; ?>" target="_blank" style="background:#25D366;color:#fff;padding:4px 10px;border-radius:6px;font-size:0.75rem;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:4px;box-shadow:0 2px 5px rgba(37,211,102,0.3);transition:0.2s" onmouseover="this.style.transform='translateY(-1px)'" onmouseout="this.style.transform='none'">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                WhatsApp
              </a>
              <?php endif; ?>
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Problema Legal -->
    <div class="sv-card">
      <div class="sv-card-header">
        <div class="sv-hicon" style="background:#f0fdf4;color:#16a34a">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
        </div>
        <h3>Problema Legal</h3>
        <span class="sv-badge <?php echo $badgeCls; ?>" style="margin-left:auto"><?php echo $estadoLabel; ?></span>
      </div>
      <div class="sv-card-body">
        <div class="sv-field" style="margin-bottom:16px">
          <label>Área legal</label>
          <p style="display:inline-flex;align-items:center;gap:8px">
            <span style="width:8px;height:8px;border-radius:50%;background:#2e6edd;display:inline-block"></span>
            <?php echo e($solicitud['tipo_problema']); ?>
          </p>
        </div>
        <div class="sv-field">
          <label>Descripción del caso</label>
          <div class="sv-desc"><?php echo nl2br(e($solicitud['descripcion'])); ?></div>
        </div>
        <?php if($solicitud['p_nom']): ?>
        <hr class="sv-divider">
        <div class="sv-info-row">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Procesada por <strong style="color:#1a1a2e"><?php echo e($solicitud['p_nom'].' '.$solicitud['p_ape']); ?></strong>
        </div>
        <?php endif; ?>
        <?php if($solicitud['motivo_estado']): ?>
        <div style="margin-top:12px;padding:12px 14px;background:#f8fafc;border-radius:10px;border:1px solid #f1f5f9">
          <span style="font-size:.75rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em">Motivo</span>
          <p style="font-size:.875rem;color:#374151;margin:4px 0 0"><?php echo e($solicitud['motivo_estado']); ?></p>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Archivos -->
    <?php if(!empty($archivos)): ?>
    <div class="sv-card">
      <div class="sv-card-header">
        <div class="sv-hicon" style="background:#f5f3ff;color:#7c3aed">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
        </div>
        <h3>Documentos Adjuntos</h3>
        <span style="margin-left:auto;background:#f5f3ff;color:#7c3aed;padding:3px 10px;border-radius:8px;font-size:.75rem;font-weight:700"><?php echo count($archivos); ?></span>
      </div>
      <div class="sv-card-body">
        <?php foreach($archivos as $arch):
          $ext=strtoupper(pathinfo($arch['nombre_original'],PATHINFO_EXTENSION));
          [$bg,$clr]=$extColors[$ext]??['#f1f5f9','#64748b'];
          $kb=round($arch['tamano_bytes']/1024,1);
          $baseUrl = rtrim(str_replace('/crm', '', APP_URL), '/');
          $dlUrl = $baseUrl . '/descargar.php?id=' . (int)$arch['id'];
        ?>
        <div class="sv-file">
          <div class="sv-file-ico" style="background:<?php echo $bg;?>;color:<?php echo $clr;?>"><?php echo $ext;?></div>
          <div class="sv-file-info">
            <div class="sv-file-name"><?php echo e($arch['nombre_original']);?></div>
            <div class="sv-file-meta"><?php echo $kb;?> KB &middot; <?php echo date('d/m/Y H:i',strtotime($arch['created_at']));?></div>
          </div>
          <button type="button" class="sv-dl" onclick="downloadArchivo(<?php echo (int)$arch['id'];?>, '<?php echo e(addslashes($arch['nombre_original']));?>')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Descargar
          </button>
        </div>
        <?php endforeach;?>
      </div>
    </div>
    <?php endif;?>
  </div>

  <!-- ══ COL DERECHA ══ -->
  <div>
    <!-- Estado -->
    <div class="sv-card">
      <div class="sv-card-header">
        <div class="sv-hicon" style="background:#f0f9ff;color:#0284c7">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <h3>Estado</h3>
      </div>
      <div class="sv-card-body" style="padding-top:16px">
        <span class="sv-badge <?php echo $badgeCls;?>" style="font-size:.9rem;padding:8px 18px;margin-bottom:10px;display:inline-flex">
          <svg width="8" height="8" style="margin-right:4px"><circle cx="4" cy="4" r="4" fill="currentColor"/></svg>
          <?php echo $estadoLabel;?>
        </span>
        <p class="sv-meta">Recibida: <?php echo date('d/m/Y H:i',strtotime($solicitud['created_at']));?></p>
      </div>
    </div>

    <!-- Asignación / Honorarios -->
    <?php if($auth->esAdmin()): ?>
    <div class="sv-card">
      <div class="sv-card-header">
        <div class="sv-hicon" style="background:#fffbeb;color:#d97706">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <h3>Asignación y Honorarios</h3>
      </div>
      <div class="sv-card-body">
        <?php if(empty($asignaciones)): ?>
        <form method="POST" action="<?php echo APP_URL;?>/index.php?page=solicitudes">
          <?php echo CSRF::campo();?>
          <input type="hidden" name="solicitud_id" value="<?php echo $id;?>">
          <input type="hidden" name="accion" value="asignar_multi">
          
          <span class="sv-label">Valor que pagará el cliente (€)</span>
          <input type="number" step="0.01" name="valor_cliente" id="valCliente" class="sv-input" style="width:100%;margin-bottom:14px;padding:10px;border-radius:8px;border:1px solid #e2e8f0;" required placeholder="Ej: 1000.00" oninput="calcAll()">
          <div style="display:flex;gap:12px;margin-bottom:14px;flex-wrap:wrap">
              <div style="flex:1;min-width:200px;">
                  <span class="sv-label">Honorarios Base del Abogado</span>
                  <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0;">
                      <div style="display:flex;gap:12px;">
                          <div style="flex:1">
                              <label style="font-size:0.7rem;font-weight:700;color:#64748b;margin-bottom:4px;display:block">Porcentaje (%)</label>
                              <input type="number" step="0.1" name="honorarios_pct" id="honPct" class="sv-input" style="width:100%;padding:8px;border-radius:6px;border:1px solid #cbd5e1;background:#fff;" value="0" oninput="calcHon()">
                          </div>
                          <div style="flex:1">
                              <label style="font-size:0.7rem;font-weight:700;color:#64748b;margin-bottom:4px;display:block">Euros (€)</label>
                              <input type="number" step="0.01" name="honorarios_abogado" id="honEur" class="sv-input" style="width:100%;padding:8px;border-radius:6px;border:1px solid #cbd5e1;background:#fff;" value="0" oninput="calcHonEur()" required>
                          </div>
                      </div>
                  </div>
              </div>
              <div style="flex:1;min-width:200px;">
                  <span class="sv-label">Bonificación Extra</span>
                  <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0;">
                      <div style="display:flex;gap:12px;">
                          <div style="flex:1">
                              <label style="font-size:0.7rem;font-weight:700;color:#64748b;margin-bottom:4px;display:block">Porcentaje (%)</label>
                              <input type="number" step="0.1" name="bono_pct" id="bonoPct" class="sv-input" style="width:100%;padding:8px;border-radius:6px;border:1px solid #cbd5e1;background:#fff;" value="0" oninput="calcBono()">
                          </div>
                          <div style="flex:1">
                              <label style="font-size:0.7rem;font-weight:700;color:#64748b;margin-bottom:4px;display:block">Euros (€)</label>
                              <input type="number" step="0.01" name="bonificacion" id="bonoEur" class="sv-input" style="width:100%;padding:8px;border-radius:6px;border:1px solid #cbd5e1;background:#fff;" value="0" oninput="calcBonoEur()">
                          </div>
                      </div>
                  </div>
              </div>
          </div>

          <!-- Barra de Progreso (Vaso de Agua) -->
          <div style="margin-bottom:20px;padding:12px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;">
              <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:0.75rem;font-weight:800;color:#475569;">
                  <span style="color:#2563eb">Despacho: <span id="lblDespacho">100% (0.00€)</span></span>
                  <span style="color:#16a34a">Honorarios: <span id="lblHon">0% (0.00€)</span></span>
                  <span style="color:#ca8a04">Bono: <span id="lblBono">0% (0.00€)</span></span>
              </div>
              <div style="height:14px;border-radius:99px;background:#e2e8f0;display:flex;overflow:hidden;box-shadow:inset 0 1px 3px rgba(0,0,0,0.1)">
                  <div id="barDespacho" style="height:100%;background:#3b82f6;width:100%;transition:width 0.3s"></div>
                  <div id="barHon" style="height:100%;background:#22c55e;width:0%;transition:width 0.3s"></div>
                  <div id="barBono" style="height:100%;background:#eab308;width:0%;transition:width 0.3s"></div>
              </div>
          </div>
          
          <script>
            function updateVaso() {
                let val = parseFloat(document.getElementById('valCliente').value) || 0;
                let honEur = parseFloat(document.getElementById('honEur').value) || 0;
                let bonoEur = parseFloat(document.getElementById('bonoEur').value) || 0;

                let despEur = Math.max(0, val - honEur - bonoEur);
                
                let pctHon = val > 0 ? (honEur / val) * 100 : 0;
                let pctBono = val > 0 ? (bonoEur / val) * 100 : 0;
                let pctDesp = val > 0 ? (despEur / val) * 100 : (val === 0 ? 100 : 0);

                document.getElementById('barHon').style.width = pctHon + '%';
                document.getElementById('barBono').style.width = pctBono + '%';
                document.getElementById('barDespacho').style.width = pctDesp + '%';

                document.getElementById('lblHon').innerText = pctHon.toFixed(1) + '% (' + honEur.toFixed(2) + '€)';
                document.getElementById('lblBono').innerText = pctBono.toFixed(1) + '% (' + bonoEur.toFixed(2) + '€)';
                document.getElementById('lblDespacho').innerText = pctDesp.toFixed(1) + '% (' + despEur.toFixed(2) + '€)';
            }

            function calcHon() {
                let pct = parseFloat(document.getElementById('honPct').value) || 0;
                let val = parseFloat(document.getElementById('valCliente').value) || 0;
                document.getElementById('honEur').value = (val * (pct / 100)).toFixed(2);
                updateVaso();
            }
            function calcHonEur() {
                let eur = parseFloat(document.getElementById('honEur').value) || 0;
                let val = parseFloat(document.getElementById('valCliente').value) || 0;
                document.getElementById('honPct').value = (val > 0 ? (eur / val) * 100 : 0).toFixed(1);
                updateVaso();
            }

            function calcBono() {
                let pct = parseFloat(document.getElementById('bonoPct').value) || 0;
                let val = parseFloat(document.getElementById('valCliente').value) || 0;
                document.getElementById('bonoEur').value = (val * (pct / 100)).toFixed(2);
                updateVaso();
            }
            function calcBonoEur() {
                let eur = parseFloat(document.getElementById('bonoEur').value) || 0;
                let val = parseFloat(document.getElementById('valCliente').value) || 0;
                document.getElementById('bonoPct').value = (val > 0 ? (eur / val) * 100 : 0).toFixed(1);
                updateVaso();
            }

            function calcAll() {
                calcHonEur(); 
                calcBonoEur(); 
                updateVaso();
            }

            document.getElementById('valCliente').addEventListener('input', calcAll);
            setTimeout(updateVaso, 100); // init
          </script>

          <span class="sv-label">Abogados a asignar</span>
          <div style="max-height:200px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;padding:10px;margin-bottom:14px;">
            <?php foreach($abogados as $ab): ?>
            <label class="sv-chk">
              <input type="checkbox" name="abogados[]" value="<?php echo $ab['id']; ?>">
              <span class="sv-chk-box"></span>
              <span class="sv-chk-text"><?php echo e($ab['nombre'] . ' ' . $ab['apellidos']); ?></span>
            </label>
            <?php endforeach; ?>
          </div>

          <button type="submit" class="sv-btn sv-btn-save">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
            Enviar Propuesta
          </button>
        </form>
        <?php else: ?>
          <div style="margin-bottom:16px;">
            <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0;margin-bottom:14px;font-size:0.8125rem;">
                <p style="font-weight:600;margin-bottom:4px;color:#64748b">Valor del Caso: <strong style="color:#1a1a2e"><?php echo e($solicitud['valor_cliente'] ?? '0.00'); ?> €</strong></p>
                <?php
                $vc = (float)($solicitud['valor_cliente'] ?? 0);
                $ha = (float)($solicitud['honorarios_abogado'] ?? 0);
                $bo = (float)($solicitud['bonificacion'] ?? 0);
                $de = max(0, $vc - $ha - $bo);
                ?>
                <p style="font-weight:600;margin-bottom:4px;color:#2563eb">Despacho: <strong><?php echo number_format($de, 2); ?> €</strong></p>
                <p style="font-weight:600;margin-bottom:4px;color:#16a34a">Honorarios Abogado: <strong><?php echo number_format($ha, 2); ?> €</strong></p>
                <?php if($bo > 0): ?>
                <p style="font-weight:600;margin-bottom:0;color:#ca8a04">Bonificación Extra: <strong><?php echo number_format($bo, 2); ?> €</strong></p>
                <?php endif; ?>
            </div>
            <p style="font-weight:600;margin-bottom:8px;">Estado de Asignaciones:</p>
            <ul style="list-style:none;padding:0;margin:0;">
              <?php foreach($asignaciones as $as): 
                $col = $as['estado'] === 'aceptada' ? '#16a34a' : ($as['estado'] === 'rechazada' ? '#dc2626' : '#d97706');
              ?>
              <li style="margin-bottom:4px;display:flex;justify-content:space-between;">
                <span><?php echo e($as['nombre'] . ' ' . $as['apellidos']); ?></span>
                <strong style="color:<?php echo $col; ?>"><?php echo ucfirst($as['estado']); ?></strong>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <form id="formCancelarAsig" method="POST" action="<?php echo APP_URL;?>/index.php?page=solicitudes">
            <?php echo CSRF::campo();?>
            <input type="hidden" name="solicitud_id" value="<?php echo $id;?>">
            <input type="hidden" name="accion" value="cancelar_asignacion">
            <button type="button" class="sv-btn sv-btn-no" style="width:100%;" data-bs-toggle="modal" data-bs-target="#modalCancelarAsig">Cancelar Asignación</button>
          </form>

          <!-- Modal Cancelar Asignación -->
          <div class="modal fade" id="modalCancelarAsig" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-sm">
              <div class="modal-content" style="border-radius:12px; border:none; box-shadow:0 10px 40px rgba(0,0,0,0.1);">
                <div class="modal-header" style="border-bottom:none; padding:24px 24px 0;">
                  <div style="width:56px;height:56px;border-radius:50%;background:#fef2f2;color:#dc2626;display:flex;align-items:center;justify-content:center;margin:0 auto;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                  </div>
                </div>
                <div class="modal-body text-center" style="padding:16px 24px 24px;">
                  <h4 style="font-weight:800;color:#1a1a2e;margin-bottom:8px;font-size:1.15rem;">¿Cancelar Asignación?</h4>
                  <p style="color:#64748b;font-size:0.875rem;margin-bottom:24px;line-height:1.5;">La propuesta se revocará para todos los abogados y la solicitud volverá a estar "Pendiente".</p>
                  <div style="display:flex;gap:12px;justify-content:center;">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal" style="border-radius:8px;font-weight:700;padding:8px 20px;flex:1;background:#f1f5f9;border:none;color:#475569;">Atrás</button>
                    <button type="button" class="btn btn-danger" onclick="document.getElementById('formCancelarAsig').submit();" style="border-radius:8px;font-weight:700;padding:8px 20px;flex:1;background:#ef4444;border:none;">Cancelar</button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif;?>

    <?php if ($auth->esAbogado() && $miAsignacion && $miAsignacion['estado'] === 'pendiente' && $solicitud['estado'] !== 'aceptada'): ?>
    <div class="sv-card">
      <div class="sv-card-header">
        <div class="sv-hicon" style="background:#fef2f2;color:#dc2626">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <h3>Toma de Decisión</h3>
      </div>
      <div class="sv-card-body">
        <?php 
        $tipoPago = $db->fetchColumn("SELECT tipo_pago_predeterminado FROM usuarios_internos WHERE id = ?", [$usuarioAct['id']]);
        $esMensual = ($tipoPago === 'mensual');
        $ha = (float)($solicitud['honorarios_abogado'] ?? 0);
        $bo = (float)($solicitud['bonificacion'] ?? 0);
        ?>
        <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0;margin-bottom:16px;">
            <?php if($esMensual): ?>
                <p style="font-weight:600;margin-bottom:4px;font-size:0.95rem;color:#475569">Retribución Base: <span>Incluida en nómina mensual</span></p>
            <?php else: ?>
                <p style="font-weight:600;margin-bottom:4px;font-size:1.05rem;color:#1a1a2e">Honorarios Base: <span style="color:#16a34a"><?php echo number_format($ha, 2); ?> €</span></p>
            <?php endif; ?>
            
            <?php if($bo > 0): ?>
                <p style="font-weight:600;margin-bottom:0;font-size:1.05rem;color:#1a1a2e">Bonificación Extra: <span style="color:#ca8a04">+<?php echo number_format($bo, 2); ?> €</span></p>
            <?php endif; ?>
            
            <?php if(!$esMensual && $bo > 0): ?>
                <div style="height:1px;background:#e2e8f0;margin:8px 0;"></div>
                <p style="font-weight:800;margin-bottom:0;font-size:1.15rem;color:#1a1a2e">Total a Cobrar: <span style="color:#16a34a"><?php echo number_format($ha + $bo, 2); ?> €</span></p>
            <?php endif; ?>
        </div>
        <form method="POST" action="<?php echo APP_URL;?>/index.php?page=solicitudes">
          <?php echo CSRF::campo();?>
          <input type="hidden" name="solicitud_id" value="<?php echo $id;?>">
          <div class="sv-actions-row">
            <button type="submit" name="accion" value="abogado_aceptar" class="sv-btn sv-btn-ok" data-confirm="¿Aceptar este caso?">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Aceptar Caso
            </button>
            <button type="submit" name="accion" value="abogado_rechazar" class="sv-btn sv-btn-no" data-confirm="¿Rechazar este caso?">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Rechazar Caso
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Acciones (sólo Admin o Gestor, no abogados) -->
    <?php if(!$auth->esAbogado()): ?>
    <div class="sv-card">
      <div class="sv-card-header">
        <div class="sv-hicon" style="background:#fef2f2;color:#dc2626">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <h3>Acciones Manuales</h3>
      </div>
      <div class="sv-card-body">
        <form method="POST" action="<?php echo APP_URL;?>/index.php?page=solicitudes">
          <?php echo CSRF::campo();?>
          <input type="hidden" name="solicitud_id" value="<?php echo $id;?>">
          <span class="sv-label">Motivo (opcional)</span>
          <textarea name="motivo" class="sv-textarea" placeholder="Escriba el motivo de la decisión..."></textarea>
          <div class="sv-actions-row" style="margin-top:14px">
            <?php if($solicitud['estado'] !== 'denegada'): ?>
            <button type="submit" name="accion" value="denegada" class="sv-btn sv-btn-no" data-confirm="¿Denegar esta solicitud?">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Denegar
            </button>
            <?php endif; ?>
          </div>
          <?php if($solicitud['estado'] !== 'archivada'): ?>
          <button type="submit" name="accion" value="archivada" class="sv-btn sv-btn-arc" style="margin-top:10px">Archivar</button>
          <?php endif; ?>
          <?php if($solicitud['estado'] !== 'pendiente'): ?>
          <button type="submit" name="accion" value="pendiente" class="sv-btn sv-btn-arc" style="margin-top:10px;background:#f8fafc;color:#64748b;border:1px solid #e2e8f0" data-confirm="¿Volver a poner esta solicitud como pendiente?">Volver a Pendiente</button>
          <?php endif; ?>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
const btn=document.getElementById('csAbBtn'),drop=document.getElementById('csAbDrop'),lbl=document.getElementById('csAbLbl'),hid=document.getElementById('csAbHid');
if(btn){
  btn.addEventListener('click',()=>{btn.classList.toggle('op');drop.classList.toggle('op')});
  document.querySelectorAll('.cs-item').forEach(i=>{
    i.addEventListener('click',()=>{
      const v=i.dataset.val,n=i.dataset.nom,ini=i.dataset.ini||'';
      hid.value=v;
      if(v){btn.innerHTML=`<div class="cs-av">${ini}</div><span id="csAbLbl">${n}</span>`;btn.classList.add('hv');}
      else{btn.innerHTML=`<span id="csAbLbl" style="color:#94a3b8">Seleccione un abogado...</span>`;btn.classList.remove('hv');}
      document.querySelectorAll('.cs-item').forEach(x=>x.classList.remove('sel'));
      i.classList.add('sel');
      btn.classList.remove('op');drop.classList.remove('op');
    });
  });
  document.addEventListener('click',e=>{if(!e.target.closest('.cs-w')){btn.classList.remove('op');drop.classList.remove('op')}});
}

// Descarga via fetch+blob — evita interceptación del Service Worker
function downloadArchivo(id, nombreOriginal) {
  const url = '<?php echo APP_URL; ?>/index.php?page=solicitudes/descargar&id=' + id;
  const btn = event.currentTarget;
  const originalHtml = btn.innerHTML;

  // Feedback visual
  btn.disabled = true;
  btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Descargando...';

  fetch(url, { credentials: 'same-origin' })
    .then(async res => {
      if (!res.ok) {
        const text = await res.text();
        throw new Error(text || 'Error ' + res.status);
      }
      return res.blob();
    })
    .then(blob => {
      const objUrl = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = objUrl;
      a.download = nombreOriginal;
      document.body.appendChild(a);
      a.click();
      setTimeout(() => { URL.revokeObjectURL(objUrl); a.remove(); }, 1000);
    })
    .catch(err => {
      // Remover etiquetas HTML básicas si el error viene con formato
      const errorText = err.message.replace(/<[^>]*>?/gm, ' ');
      if (typeof Swal !== 'undefined') {
        Swal.fire({icon: 'error', title: 'Error', text: errorText, confirmButtonColor: '#2e6edd'});
      } else {
        alert(errorText);
      }
      console.error(err);
    })
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML = originalHtml;
    });
}
</script>

<?php include CRM_ROOT.'/templates/layout/footer.php';?>
