<?php
/** CRM — Detalle de Solicitud (Rediseñado) */
$tituloPagina = 'Detalle de Solicitud';
$db  = Database::getInstance();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: '.APP_URL.'/index.php?page=solicitudes'); exit; }

$solicitud = $db->fetchOne(
    "SELECT s.*, u.nombre as p_nom, u.apellidos as p_ape, pc.fecha_nacimiento
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
$abogadoSel = $solicitud['abogado_id'] ?? '';
$abogadoNom = '';
foreach($abogados as $ab){ if($ab['id']==$abogadoSel){ $abogadoNom=$ab['nombre'].' '.$ab['apellidos']; break; } }
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
          <div class="sv-field"><label>Correo electrónico</label><p><?php echo e($solicitud['email']); ?></p></div>
          <div class="sv-field"><label>Teléfono</label><p><?php echo e($solicitud['telefono'] ?: 'No proporcionado'); ?></p></div>
          <div class="sv-field"><label>IP de origen</label><p style="font-family:monospace;font-size:.875rem"><?php echo e($solicitud['ip_solicitante'] ?: '—'); ?></p></div>
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

    <!-- Asignación (sólo admin) -->
    <?php if($auth->esAdmin()): ?>
    <div class="sv-card">
      <div class="sv-card-header">
        <div class="sv-hicon" style="background:#fffbeb;color:#d97706">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <h3>Asignación</h3>
      </div>
      <div class="sv-card-body">
        <form method="POST" action="<?php echo APP_URL;?>/index.php?page=solicitudes">
          <?php echo CSRF::campo();?>
          <input type="hidden" name="solicitud_id" value="<?php echo $id;?>">
          <input type="hidden" name="accion" value="asignar">
          <span class="sv-label">Asignar a Abogado</span>

          <!-- Custom select abogados -->
          <div class="cs-w" style="margin-bottom:14px">
            <div class="cs-btn <?php echo $abogadoSel?'hv':'';?>" id="csAbBtn">
              <?php if($abogadoSel):?>
              <div class="cs-av"><?php echo strtoupper(substr($abogadoNom,0,1));?></div>
              <span id="csAbLbl"><?php echo e($abogadoNom);?></span>
              <?php else:?><span id="csAbLbl" style="color:#94a3b8">Seleccione un abogado...</span><?php endif;?>
            </div>
            <svg class="cs-arr" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            <div class="cs-drop" id="csAbDrop">
              <div class="cs-item<?php echo !$abogadoSel?' sel':'';?>" data-val="" data-nom="">
                <span style="color:#94a3b8;font-size:.8125rem">Sin asignar</span>
              </div>
              <?php foreach($abogados as $ab):
                $ini=strtoupper(substr($ab['nombre'],0,1));
                $nom=e($ab['nombre'].' '.$ab['apellidos']);
              ?>
              <div class="cs-item<?php echo $ab['id']==$abogadoSel?' sel':'';?>" data-val="<?php echo $ab['id'];?>" data-nom="<?php echo $nom;?>" data-ini="<?php echo $ini;?>">
                <div class="cs-av"><?php echo $ini;?></div><?php echo $nom;?>
              </div>
              <?php endforeach;?>
            </div>
            <input type="hidden" name="abogado_id" id="csAbHid" value="<?php echo $abogadoSel;?>" required>
          </div>

          <button type="submit" class="sv-btn sv-btn-save">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Guardar Asignación
          </button>
        </form>
      </div>
    </div>
    <?php endif;?>

    <!-- Acciones -->
    <?php if($solicitud['estado']==='pendiente'):?>
    <div class="sv-card">
      <div class="sv-card-header">
        <div class="sv-hicon" style="background:#fef2f2;color:#dc2626">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <h3>Acciones</h3>
      </div>
      <div class="sv-card-body">
        <form method="POST" action="<?php echo APP_URL;?>/index.php?page=solicitudes">
          <?php echo CSRF::campo();?>
          <input type="hidden" name="solicitud_id" value="<?php echo $id;?>">
          <span class="sv-label">Motivo (opcional)</span>
          <textarea name="motivo" class="sv-textarea" placeholder="Escriba el motivo de la decisión..."></textarea>
          <div class="sv-actions-row" style="margin-top:14px">
            <button type="submit" name="accion" value="aceptada" class="sv-btn sv-btn-ok" data-confirm="¿Aceptar esta solicitud? Se creará un caso automáticamente.">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Aceptar
            </button>
            <button type="submit" name="accion" value="denegada" class="sv-btn sv-btn-no" data-confirm="¿Denegar esta solicitud?">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Denegar
            </button>
          </div>
          <button type="submit" name="accion" value="archivada" class="sv-btn sv-btn-arc" style="margin-top:10px">Archivar</button>
        </form>
      </div>
    </div>
    <?php endif;?>
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
    .then(res => {
      if (!res.ok) throw new Error('Error ' + res.status);
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
      if (typeof Swal !== 'undefined') {
        Swal.fire({icon: 'error', title: 'Error', text: 'No se pudo descargar el archivo. Inténtalo de nuevo.', confirmButtonColor: '#2e6edd'});
      } else {
        alert('No se pudo descargar el archivo. Inténtalo de nuevo.');
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
