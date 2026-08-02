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

<!-- TOP BAR: Title + Actions -->
<div class="sv-topbar">
  <div class="sv-topbar-left">
    <div style="font-size:1.25rem;font-weight:800;color:#1a1a2e;margin:0;">Solicitud <span style="color:#2e6edd">#<?php echo $id; ?></span></div>
    <p style="font-size:0.8rem;color:#64748b;margin:2px 0 0;">Recibida el <?php echo date('d/m/Y \a\l H:i', strtotime($solicitud['created_at'])); ?></p>
  </div>
  <div class="sv-topbar-right" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
    <span class="sv-badge <?php echo $badgeCls;?>" style="font-size:0.8rem;padding:5px 12px;box-shadow:0 2px 4px rgba(0,0,0,0.05);">
        <svg width="8" height="8" style="margin-right:6px"><circle cx="4" cy="4" r="4" fill="currentColor"/></svg>
        <?php echo $estadoLabel;?>
    </span>
    
    <!-- Admin Actions -->
    <?php if(!$auth->esAbogado()): ?>
        <form method="POST" action="<?php echo APP_URL;?>/index.php?page=solicitudes" style="display:flex;gap:6px;align-items:center;margin:0;">
          <?php echo CSRF::campo();?>
          <input type="hidden" name="solicitud_id" value="<?php echo $id;?>">
          <?php if($solicitud['estado'] !== 'denegada'): ?>
          <button type="submit" name="accion" value="denegada" class="sv-btn sv-btn-no" style="width:auto;padding:5px 10px;font-size:0.8rem;" data-confirm="¿Denegar esta solicitud?">Denegar</button>
          <?php endif; ?>
          <?php if($solicitud['estado'] !== 'archivada'): ?>
          <button type="submit" name="accion" value="archivada" class="sv-btn sv-btn-arc" style="width:auto;padding:5px 10px;font-size:0.8rem;">Archivar</button>
          <?php endif; ?>
          <?php if($solicitud['estado'] !== 'pendiente'): ?>
          <button type="submit" name="accion" value="pendiente" class="sv-btn sv-btn-arc" style="width:auto;padding:5px 10px;font-size:0.8rem;background:#f8fafc;border:1px solid #cbd5e1" data-confirm="¿Volver a Pendiente?">A Pendiente</button>
          <?php endif; ?>
        </form>
    <?php endif; ?>
    
    <a href="<?php echo APP_URL; ?>/index.php?page=solicitudes" class="sv-btn sv-btn-arc" style="text-decoration:none;width:auto;padding:5px 14px;font-size:0.8rem;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> Volver
    </a>
  </div>
</div>

<style>
.sv-wrap-container { width: 100%; }
.sv-topbar { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px; background:#fff; padding:12px 20px; border-radius:10px; border:1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
.sv-grid-main { display:grid; grid-template-columns: 1fr 1.1fr; gap:16px; align-items:start; }
@media(max-width:991px) { .sv-grid-main { grid-template-columns: 1fr; } }
.sv-card-premium { background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow: 0 2px 4px -1px rgba(0,0,0,0.02); overflow:hidden; margin-bottom:16px; }
.sv-header-premium { padding:10px 16px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; gap:8px; background:linear-gradient(to right, #ffffff, #f8fafc); }
.sv-header-premium-title { font-size:0.95rem; font-weight:800; color:#1e293b; margin:0; }
.sv-body-premium { padding:16px; }
.sv-info-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; }
.sv-data-group label { display:block; font-size:0.65rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:2px; }
.sv-data-group p { font-size:0.85rem; font-weight:600; color:#0f172a; margin:0; display:flex; align-items:center; gap:6px; }
.sv-input-compact { width:100%; padding:6px 10px; border-radius:6px; border:1px solid #cbd5e1; background:#f8fafc; font-size:0.85rem; font-weight:600; color:#0f172a; transition:all 0.2s; outline:none; }
.sv-input-compact:focus { background:#fff; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,0.1); }
.sv-slider-compact { width:100%; height:4px; -webkit-appearance: none; background:#e2e8f0; border-radius:99px; outline:none; margin-top:8px; }
.sv-slider-compact::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width:14px; height:14px; border-radius:50%; background:#3b82f6; cursor:pointer; border:2px solid #fff; box-shadow:0 2px 4px rgba(0,0,0,0.2); }
</style>

<div class="sv-wrap-container">

<!-- MAIN GRID: Left (Datos Solicitante, Problema Legal) / Right (Asignacion / Finanzas) -->
<div class="sv-grid-main">
    
    <!-- LEFT COLUMN -->
    <div>
        <!-- Datos del Solicitante -->
        <div class="sv-card-premium">
            <div class="sv-header-premium">
                <div class="sv-hicon" style="background:#e0e7ff;color:#4f46e5;width:24px;height:24px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
                <div class="sv-header-premium-title">Datos del Solicitante</div>
            </div>
            <div class="sv-body-premium">
                <div class="sv-info-grid">
                    <div class="sv-data-group"><label>Nombre Completo</label><p><?php echo e($solicitud['nombre'].' '.$solicitud['apellidos']); ?></p></div>
                    <div class="sv-data-group"><label>DNI / NIF</label><p><?php echo e($solicitud['dni_nif'] ?? 'N/A'); ?></p></div>
                    <div class="sv-data-group"><label>Correo Electrónico</label><p><?php echo e($solicitud['email']); ?></p></div>
                    <div class="sv-data-group"><label>Teléfono</label>
                        <p style="display:flex; align-items:center; gap:8px;">
                          <?php echo e($solicitud['telefono'] ?: 'N/A'); ?>
                          <?php if(!empty($solicitud['telefono'])): 
                              $waNum = preg_replace('/[^0-9]/', '', $solicitud['telefono']);
                              if (strlen($waNum) == 9) $waNum = '34' . $waNum;
                          ?>
                          <a href="https://wa.me/<?php echo $waNum; ?>" target="_blank" style="display:inline-flex;align-items:center;gap:4px;background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;padding:4px 10px;border-radius:99px;font-size:0.7rem;font-weight:700;text-decoration:none;transition:0.2s;line-height:1;" onmouseover="this.style.background='#dcfce7';this.style.borderColor='#86efac'" onmouseout="this.style.background='#f0fdf4';this.style.borderColor='#bbf7d0'">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg> 
                            WhatsApp
                          </a>
                          <?php endif; ?>
                        </p>
                    </div>
                    <div class="sv-data-group"><label>Fecha Nac.</label><p><?php echo $solicitud['fecha_nacimiento'] ? date('d/m/Y', strtotime($solicitud['fecha_nacimiento'])) : 'N/A'; ?></p></div>
                </div>
            </div>
        </div>

        <div class="sv-card-premium">
            <div class="sv-header-premium">
                <div class="sv-hicon" style="background:#dcfce7;color:#16a34a;width:24px;height:24px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg></div>
                <div class="sv-header-premium-title">Problema Legal</div>
            </div>
            <div class="sv-body-premium">
                <div class="sv-data-group" style="margin-bottom:12px;">
                    <label>Área Legal</label>
                    <p><span style="width:8px;height:8px;border-radius:50%;background:#3b82f6;display:inline-block;margin-right:4px;"></span> <?php echo e($solicitud['tipo_problema']); ?></p>
                </div>
                <div class="sv-data-group">
                    <label>Descripción del caso</label>
                    <div style="background:#f8fafc;border-radius:6px;padding:10px;font-size:0.85rem;color:#334155;line-height:1.4;border:1px solid #f1f5f9;margin-top:4px;">
                        <?php echo nl2br(e($solicitud['descripcion'])); ?>
                    </div>
                </div>
                
                <?php if($solicitud['p_nom']): ?>
                <div style="margin-top:12px;padding-top:12px;border-top:1px solid #f1f5f9;display:flex;align-items:center;gap:6px;font-size:0.75rem;color:#64748b;">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                  Procesada por <strong style="color:#0f172a;"><?php echo e($solicitud['p_nom'].' '.$solicitud['p_ape']); ?></strong>
                </div>
                <?php endif; ?>
                
                <?php if($solicitud['motivo_estado']): ?>
                <div style="margin-top:10px;padding:10px;background:#fff1f2;border-radius:6px;border:1px solid #fecdd3;">
                  <span style="font-size:0.65rem;font-weight:700;color:#e11d48;text-transform:uppercase;">Motivo (<?php echo $estadoLabel;?>)</span>
                  <p style="font-size:0.8rem;color:#881337;margin:2px 0 0;"><?php echo e($solicitud['motivo_estado']); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if(!empty($archivos)): ?>
        <div class="sv-card-premium">
          <div class="sv-header-premium">
            <div class="sv-hicon" style="background:#f3e8ff;color:#9333ea;width:24px;height:24px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></div>
            <div class="sv-header-premium-title">Documentos Adjuntos</div>
            <span style="margin-left:auto;background:#f3e8ff;color:#9333ea;padding:2px 8px;border-radius:99px;font-size:0.7rem;font-weight:700"><?php echo count($archivos); ?></span>
          </div>
          <div class="sv-body-premium">
            <?php foreach($archivos as $arch):
              $ext=strtoupper(pathinfo($arch['nombre_original'],PATHINFO_EXTENSION));
              [$bg,$clr]=$extColors[$ext]??['#f1f5f9','#64748b'];
              $kb=round($arch['tamano_bytes']/1024,1);
            ?>
            <div style="display:flex;align-items:center;gap:10px;padding:8px;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:6px;transition:0.2s;background:#fff;" onmouseover="this.style.borderColor='#3b82f6'" onmouseout="this.style.borderColor='#e2e8f0'">
              <div style="width:28px;height:28px;border-radius:4px;background:<?php echo $bg;?>;color:<?php echo $clr;?>;display:flex;align-items:center;justify-content:center;font-size:0.55rem;font-weight:800;"><?php echo $ext;?></div>
              <div style="flex:1;min-width:0;">
                <div style="font-size:0.8rem;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo e($arch['nombre_original']);?></div>
                <div style="font-size:0.7rem;color:#64748b;margin-top:0;"><?php echo $kb;?> KB &middot; <?php echo date('d/m/Y H:i',strtotime($arch['created_at']));?></div>
              </div>
              <button type="button" onclick="downloadArchivo(<?php echo (int)$arch['id'];?>, '<?php echo e(addslashes($arch['nombre_original']));?>')" style="background:none;border:none;color:#3b82f6;cursor:pointer;padding:4px;display:flex;align-items:center;justify-content:center;border-radius:4px;transition:0.2s;" onmouseover="this.style.background='#eff6ff'">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
              </button>
            </div>
            <?php endforeach;?>
          </div>
        </div>
        <?php endif;?>
    </div>

    <!-- RIGHT COLUMN -->
    <div>
        <?php if($auth->esAdmin()): ?>
        <div class="sv-card-premium" style="border-top:3px solid #f59e0b;">
            <div class="sv-header-premium" style="background:#fff;">
                <div class="sv-hicon" style="background:#fef3c7;color:#d97706;width:24px;height:24px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                <div class="sv-header-premium-title">Asignación y Finanzas</div>
            </div>
            <div class="sv-body-premium">
                <?php if(empty($asignaciones)): ?>
                <form method="POST" action="<?php echo APP_URL;?>/index.php?page=solicitudes">
                  <?php echo CSRF::campo();?>
                  <input type="hidden" name="solicitud_id" value="<?php echo $id;?>">
                  <input type="hidden" name="accion" value="asignar_multi">
                  
                  <div class="sv-data-group" style="margin-bottom:12px;">
                      <label style="color:#0f172a;">Valor Total del Caso (€)</label>
                      <input type="number" step="0.01" name="valor_cliente" id="valCliente" class="sv-input-compact" style="font-size:1rem;padding:8px 10px;text-align:right;" required placeholder="Ej: 1000.00" oninput="calcAll()">
                  </div>

                  <div style="display:flex;gap:12px;margin-bottom:16px;">
                      <div style="flex:1;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px;">
                          <div style="font-size:0.7rem;font-weight:800;color:#16a34a;margin-bottom:6px;text-transform:uppercase;">Honorarios Base</div>
                          <div style="display:flex;gap:6px;margin-bottom:6px;">
                              <div style="flex:1;">
                                  <label style="font-size:0.6rem;color:#64748b;">%</label>
                                  <input type="number" step="0.1" name="honorarios_pct" id="honPct" class="sv-input-compact" style="padding:4px;text-align:center;font-size:0.8rem;" value="0" oninput="calcHon()">
                              </div>
                              <div style="flex:1.5;">
                                  <label style="font-size:0.6rem;color:#64748b;">€</label>
                                  <input type="number" step="0.01" name="honorarios_abogado" id="honEur" class="sv-input-compact" style="padding:4px;text-align:right;font-size:0.8rem;" value="0" oninput="calcHonEur()" required>
                              </div>
                          </div>
                          <input type="range" class="sv-slider-compact" id="honSlider" min="0" max="100" step="1" value="0" oninput="syncHonSlider()">
                      </div>
                      
                      <div style="flex:1;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px;">
                          <div style="font-size:0.7rem;font-weight:800;color:#ca8a04;margin-bottom:6px;text-transform:uppercase;">Bono Extra</div>
                          <div style="display:flex;gap:6px;margin-bottom:6px;">
                              <div style="flex:1;">
                                  <label style="font-size:0.6rem;color:#64748b;">%</label>
                                  <input type="number" step="0.1" name="bono_pct" id="bonoPct" class="sv-input-compact" style="padding:4px;text-align:center;font-size:0.8rem;" value="0" oninput="calcBono()">
                              </div>
                              <div style="flex:1.5;">
                                  <label style="font-size:0.6rem;color:#64748b;">€</label>
                                  <input type="number" step="0.01" name="bonificacion" id="bonoEur" class="sv-input-compact" style="padding:4px;text-align:right;font-size:0.8rem;" value="0" oninput="calcBonoEur()">
                              </div>
                          </div>
                          <input type="range" class="sv-slider-compact" id="bonoSlider" min="0" max="100" step="1" value="0" oninput="syncBonoSlider()">
                      </div>
                  </div>

                  <!-- Stacked Bar (Vaso de Agua) -->
                  <div style="margin-bottom:20px;padding:12px;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,0.02);">
                      <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:0.7rem;font-weight:800;">
                          <span style="color:#2563eb">Despacho: <span id="lblDespacho">100% (0.00€)</span></span>
                          <span style="color:#16a34a">Hon: <span id="lblHon">0% (0.00€)</span></span>
                          <span style="color:#ca8a04">Bono: <span id="lblBono">0% (0.00€)</span></span>
                      </div>
                      <div style="height:10px;border-radius:99px;background:#e2e8f0;display:flex;overflow:hidden;">
                          <div id="barDespacho" style="height:100%;background:#3b82f6;width:100%;transition:width 0.3s"></div>
                          <div id="barHon" style="height:100%;background:#22c55e;width:0%;transition:width 0.3s"></div>
                          <div id="barBono" style="height:100%;background:#eab308;width:0%;transition:width 0.3s"></div>
                      </div>
                  </div>
                  
                  <div class="sv-data-group" style="margin-bottom:12px;">
                      <label>Abogados a Asignar</label>
                      <div style="max-height:140px;overflow-y:auto;border:1px solid #cbd5e1;border-radius:6px;padding:6px;background:#f8fafc;">
                        <?php foreach($abogados as $ab): ?>
                        <label class="sv-chk" style="padding:4px 8px;margin-bottom:2px;display:flex;align-items:center;gap:8px;cursor:pointer;">
                          <input type="checkbox" name="abogados[]" value="<?php echo $ab['id']; ?>" style="margin:0;">
                          <span class="sv-chk-text" style="font-size:0.8rem;color:#334155;font-weight:600;"><?php echo e($ab['nombre'] . ' ' . $ab['apellidos']); ?></span>
                        </label>
                        <?php endforeach; ?>
                      </div>
                  </div>

                  <button type="submit" class="sv-btn-save" style="width:100%;padding:10px;border-radius:6px;border:none;font-size:0.85rem;font-weight:700;display:flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;background:#3b82f6;color:#fff;transition:0.2s;" onmouseover="this.style.background='#2563eb'" onmouseout="this.style.background='#3b82f6'">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg> Enviar Propuesta
                  </button>
                  
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
                        
                        document.getElementById('honSlider').value = Math.min(100, Math.max(0, pctHon));
                        document.getElementById('bonoSlider').value = Math.min(100, Math.max(0, pctBono));
                    }
                    function syncHonSlider() { document.getElementById('honPct').value = document.getElementById('honSlider').value; calcHon(); }
                    function syncBonoSlider() { document.getElementById('bonoPct').value = document.getElementById('bonoSlider').value; calcBono(); }
                    function calcHon() { let pct = parseFloat(document.getElementById('honPct').value) || 0; let val = parseFloat(document.getElementById('valCliente').value) || 0; document.getElementById('honEur').value = (val * (pct / 100)).toFixed(2); updateVaso(); }
                    function calcHonEur() { let eur = parseFloat(document.getElementById('honEur').value) || 0; let val = parseFloat(document.getElementById('valCliente').value) || 0; document.getElementById('honPct').value = (val > 0 ? (eur / val) * 100 : 0).toFixed(1); updateVaso(); }
                    function calcBono() { let pct = parseFloat(document.getElementById('bonoPct').value) || 0; let val = parseFloat(document.getElementById('valCliente').value) || 0; document.getElementById('bonoEur').value = (val * (pct / 100)).toFixed(2); updateVaso(); }
                    function calcBonoEur() { let eur = parseFloat(document.getElementById('bonoEur').value) || 0; let val = parseFloat(document.getElementById('valCliente').value) || 0; document.getElementById('bonoPct').value = (val > 0 ? (eur / val) * 100 : 0).toFixed(1); updateVaso(); }
                    function calcAll() { calcHonEur(); calcBonoEur(); updateVaso(); }
                    document.getElementById('valCliente').addEventListener('input', calcAll);
                    setTimeout(updateVaso, 100);
                  </script>
                </form>
                <?php else: ?>
                  <!-- Ya Asignada (Vista Admin) -->
                  <div style="background:#f8fafc;padding:16px;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:16px;">
                      <div class="sv-data-group" style="margin-bottom:12px;">
                          <label>Valor Total del Caso</label>
                          <p style="font-size:1.2rem;"><strong style="color:#0f172a"><?php echo number_format($solicitud['valor_cliente'] ?? 0, 2); ?> €</strong></p>
                      </div>
                      <?php
                      $vc = (float)($solicitud['valor_cliente'] ?? 0);
                      $ha = (float)($solicitud['honorarios_abogado'] ?? 0);
                      $bo = (float)($solicitud['bonificacion'] ?? 0);
                      $de = max(0, $vc - $ha - $bo);
                      ?>
                      <div style="display:flex;justify-content:space-between;border-top:1px solid #cbd5e1;padding-top:12px;margin-bottom:4px;">
                          <span style="font-weight:600;color:#64748b;font-size:0.85rem;">Despacho</span>
                          <span style="font-weight:800;color:#2563eb;"><?php echo number_format($de, 2); ?> €</span>
                      </div>
                      <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                          <span style="font-weight:600;color:#64748b;font-size:0.85rem;">Honorarios Base</span>
                          <span style="font-weight:800;color:#16a34a;"><?php echo number_format($ha, 2); ?> €</span>
                      </div>
                      <?php if($bo > 0): ?>
                      <div style="display:flex;justify-content:space-between;">
                          <span style="font-weight:600;color:#64748b;font-size:0.85rem;">Bonificación</span>
                          <span style="font-weight:800;color:#ca8a04;"><?php echo number_format($bo, 2); ?> €</span>
                      </div>
                      <?php endif; ?>
                  </div>
                  
                  <div class="sv-data-group" style="margin-bottom:16px;">
                      <label>Estado de Asignaciones</label>
                      <ul style="list-style:none;padding:0;margin:0;border:1px solid #e2e8f0;border-radius:8px;background:#fff;overflow:hidden;">
                        <?php foreach($asignaciones as $as): 
                          $col = $as['estado'] === 'aceptada' ? '#16a34a' : ($as['estado'] === 'rechazada' ? '#dc2626' : '#d97706');
                          $bg = $as['estado'] === 'aceptada' ? '#f0fdf4' : ($as['estado'] === 'rechazada' ? '#fef2f2' : '#fffbeb');
                        ?>
                        <li style="padding:10px 12px;display:flex;justify-content:space-between;border-bottom:1px solid #f1f5f9;background:<?php echo $bg;?>;">
                          <span style="font-size:0.85rem;font-weight:600;color:#334155;"><?php echo e($as['nombre'] . ' ' . $as['apellidos']); ?></span>
                          <strong style="color:<?php echo $col; ?>;font-size:0.8rem;text-transform:uppercase;"><?php echo ucfirst($as['estado']); ?></strong>
                        </li>
                        <?php endforeach; ?>
                      </ul>
                  </div>
                  
                  <form id="formCancelarAsig" method="POST" action="<?php echo APP_URL;?>/index.php?page=solicitudes">
                    <?php echo CSRF::campo();?>
                    <input type="hidden" name="solicitud_id" value="<?php echo $id;?>">
                    <input type="hidden" name="accion" value="cancelar_asignacion">
                    <button type="button" class="sv-btn-no" style="width:100%;padding:10px;border-radius:8px;border:none;background:#ef4444;color:#fff;font-size:0.9rem;font-weight:700;cursor:pointer;" onclick="if(confirm('¿Cancelar todas las asignaciones y volver a Pendiente?')) document.getElementById('formCancelarAsig').submit();">
                        Cancelar Asignaciones
                    </button>
                  </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($auth->esAbogado() && $miAsignacion && $miAsignacion['estado'] === 'pendiente' && $solicitud['estado'] !== 'aceptada'): ?>
        <div class="sv-card-premium" style="border-top:4px solid #10b981;">
            <div class="sv-header-premium" style="background:#fff;">
                <div class="sv-hicon" style="background:#dcfce7;color:#059669;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
                <h3>Oferta de Caso</h3>
            </div>
            <div class="sv-body-premium">
                <?php 
                $tipoPago = $db->fetchColumn("SELECT tipo_pago_predeterminado FROM usuarios_internos WHERE id = ?", [$usuarioAct['id']]);
                $esMensual = ($tipoPago === 'mensual');
                $ha = (float)($solicitud['honorarios_abogado'] ?? 0);
                $bo = (float)($solicitud['bonificacion'] ?? 0);
                ?>
                <div style="background:#f8fafc;padding:20px;border-radius:12px;border:1px solid #e2e8f0;margin-bottom:20px;text-align:center;">
                    <?php if($esMensual): ?>
                        <div style="font-size:0.85rem;font-weight:700;color:#64748b;text-transform:uppercase;">Retribución Base</div>
                        <div style="font-size:1.1rem;font-weight:800;color:#0f172a;margin-bottom:12px;">Incluida en nómina</div>
                    <?php else: ?>
                        <div style="font-size:0.85rem;font-weight:700;color:#64748b;text-transform:uppercase;">Honorarios Base</div>
                        <div style="font-size:1.6rem;font-weight:800;color:#16a34a;margin-bottom:12px;"><?php echo number_format($ha, 2); ?> €</div>
                    <?php endif; ?>
                    
                    <?php if($bo > 0): ?>
                        <div style="display:inline-block;background:#fef3c7;border:1px solid #fde68a;padding:6px 16px;border-radius:99px;">
                            <span style="font-size:0.8rem;font-weight:800;color:#d97706;text-transform:uppercase;">Bono Extra: +<?php echo number_format($bo, 2); ?> €</span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if(!$esMensual && $bo > 0): ?>
                        <div style="height:1px;background:#cbd5e1;margin:16px 0;"></div>
                        <div style="font-size:0.9rem;font-weight:700;color:#64748b;text-transform:uppercase;">Total a Cobrar</div>
                        <div style="font-size:1.8rem;font-weight:800;color:#059669;"><?php echo number_format($ha + $bo, 2); ?> €</div>
                    <?php endif; ?>
                </div>

                <form method="POST" action="<?php echo APP_URL;?>/index.php?page=solicitudes">
                  <?php echo CSRF::campo();?>
                  <input type="hidden" name="solicitud_id" value="<?php echo $id;?>">
                  <div style="display:flex;gap:12px;">
                    <button type="submit" name="accion" value="abogado_aceptar" class="sv-btn-ok" style="flex:1;background:#10b981;color:#fff;padding:12px;border-radius:8px;border:none;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;" data-confirm="¿Aceptar este caso?">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Aceptar
                    </button>
                    <button type="submit" name="accion" value="abogado_rechazar" class="sv-btn-no" style="flex:1;background:#ef4444;color:#fff;padding:12px;border-radius:8px;border:none;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;" data-confirm="¿Rechazar este caso?">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Rechazar
                    </button>
                  </div>
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
