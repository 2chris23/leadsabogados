<?php
/**
 * Portal del Cliente — Nueva Solicitud
 * Con selector custom y subida de archivos drag & drop
 */
$portalId   = $_SESSION['portal_id'];
$crmUrl     = str_replace('/portal', '/crm', portalUrl());
$cuenta     = $db->fetchOne("SELECT * FROM portal_cuentas WHERE id = ?", [$portalId]);
$error      = '';

// Crear directorio de uploads si no existe
$uploadsDir = PORTAL_ROOT . '/uploads/solicitudes/';
if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo        = trim($_POST['tipo_problema'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');

    if (empty($tipo) || empty($descripcion)) {
        $error = 'El tipo de consulta y la descripción son obligatorios.';
    } else {
        $solId = $db->insert('solicitudes', [
            'nombre'           => $cuenta['nombre'],
            'apellidos'        => $cuenta['apellidos'],
            'email'            => $cuenta['email'],
            'telefono'         => $cuenta['telefono'],
            'tipo_problema'    => $tipo,
            'descripcion'      => $descripcion,
            'estado'           => 'pendiente',
            'portal_cuenta_id' => $portalId,
            'ip_solicitante'   => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        // Procesar archivos adjuntos
        if (!empty($_FILES['archivos']['name'][0])) {
            $files = $_FILES['archivos'];
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $nombreOriginal = $files['name'][$i];

                    // Sanitizar: quitar caracteres peligrosos, mantener nombre legible
                    $ext       = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
                    $baseName  = pathinfo($nombreOriginal, PATHINFO_FILENAME);
                    $baseName  = preg_replace('/[^\w\-. áéíóúÁÉÍÓÚñÑ]/u', '_', $baseName);
                    $baseName  = trim($baseName, '. _');
                    $safeName  = $baseName . ($ext ? '.' . $ext : '');

                    // Evitar colisiones si ya existe el mismo nombre
                    $destPath = $uploadsDir . $safeName;
                    if (file_exists($destPath)) {
                        $safeName = $baseName . '_' . time() . ($ext ? '.' . $ext : '');
                        $destPath = $uploadsDir . $safeName;
                    }

                    $rutaRelat = 'uploads/solicitudes/' . $safeName;

                    if (move_uploaded_file($files['tmp_name'][$i], $destPath)) {
                        $db->insert('solicitud_archivos', [
                            'solicitud_id'       => $solId,
                            'nombre_original'    => $nombreOriginal,
                            'nombre_archivo'     => $safeName,
                            'ruta'               => $rutaRelat,
                            'tipo_mime'          => $files['type'][$i],
                            'tamano_bytes'       => $files['size'][$i],
                            'subido_por_cliente' => 1,
                        ]);
                    }
                }
            }
        }

        setFlash('success', '✅ Su solicitud ha sido enviada. Nuestro equipo la revisará pronto.');
        header('Location: ' . portalUrl() . '/index.php?page=dashboard');
        exit;
    }
}

$tiposConsulta = ['Civil','Penal','Laboral','Mercantil','Inmobiliario','Familia','Extranjería','Administrativo','Otro'];
$selTipo = $_POST['tipo_problema'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo portalPwaHead(); ?>
    <title>Nueva Solicitud — Portal</title>
    <link rel="icon" type="image/png" href="crm/assets/images/logo.png?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; min-height: 100vh; }

        /* ─── Topbar ─── */
        .topbar {
            background: #fff; border-bottom: 1px solid #e2e8f0;
            padding: 0 32px; height: 64px;
            display: flex; align-items: center; gap: 16px;
            position: sticky; top: 0; z-index: 100;
        }
        .topbar-back {
            text-decoration: none; color: #64748b; font-weight: 600;
            font-size: .875rem; display: flex; align-items: center;
            gap: 6px; transition: color .2s;
        }
        .topbar-back:hover { color: #2e6edd; }
        .topbar-title { font-size: 1rem; font-weight: 700; color: #1a1a2e; }

        /* ─── Main ─── */
        .main { max-width: 680px; margin: 0 auto; padding: 40px 24px 60px; }

        /* ─── Card ─── */
        .card {
            background: #fff; border: 1px solid #e2e8f0;
            border-radius: 20px; padding: 36px;
            box-shadow: 0 2px 12px rgba(0,0,0,.05);
        }
        .card h1 { font-size: 1.5rem; font-weight: 800; letter-spacing: -.02em; margin-bottom: 6px; }
        .card .sub { color: #64748b; font-size: .9375rem; margin-bottom: 32px; line-height: 1.5; }

        /* ─── Field ─── */
        .fld { margin-bottom: 22px; }
        .fld > label { display: block; font-size: .8125rem; font-weight: 600; color: #374151; margin-bottom: 7px; }
        .fld > label .r { color: #dc2626; }

        /* ─── Custom Select ─── */
        .cs-wrap { position: relative; user-select: none; }
        .cs-trigger {
            width: 100%; padding: 13px 44px 13px 16px;
            border: 2px solid #e2e8f0; border-radius: 14px;
            background: #f8fafc; font-size: .9375rem; font-weight: 500;
            color: #94a3b8; cursor: pointer; transition: all .2s;
            display: flex; align-items: center; gap: 10px;
        }
        .cs-trigger.has-value { color: #1a1a2e; }
        .cs-trigger.open { border-color: #2e6edd; box-shadow: 0 0 0 4px rgba(46,110,221,.1); background: #fff; }
        .cs-arrow {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            pointer-events: none; color: #94a3b8; transition: transform .2s;
        }
        .cs-trigger.open + .cs-arrow { transform: translateY(-50%) rotate(180deg); }
        .cs-dropdown {
            position: absolute; top: calc(100% + 6px); left: 0; right: 0;
            background: #fff; border: 1.5px solid #e2e8f0; border-radius: 14px;
            box-shadow: 0 8px 32px rgba(0,0,0,.12); z-index: 200;
            overflow: hidden; display: none;
        }
        .cs-dropdown.open { display: block; }
        .cs-option {
            padding: 12px 16px; font-size: .9375rem; font-weight: 500;
            color: #374151; cursor: pointer; display: flex; align-items: center;
            gap: 10px; transition: all .15s;
        }
        .cs-option:hover { background: #f0f7ff; color: #2e6edd; }
        .cs-option.selected { background: #e8f0fe; color: #2e6edd; font-weight: 700; }
        .cs-option .cs-ico {
            width: 28px; height: 28px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: .8125rem; font-weight: 700; flex-shrink: 0;
        }
        /* Hidden real select */
        .cs-hidden { position: absolute; opacity: 0; pointer-events: none; height: 0; }

        /* ─── Textarea ─── */
        .fld textarea {
            width: 100%; padding: 14px 16px;
            border: 2px solid #e2e8f0; border-radius: 14px;
            font-size: .9375rem; font-weight: 500; color: #1a1a2e;
            background: #f8fafc; transition: all .2s; outline: none;
            font-family: 'Inter', sans-serif; min-height: 140px; resize: vertical; line-height: 1.6;
        }
        .fld textarea:focus { border-color: #2e6edd; box-shadow: 0 0 0 4px rgba(46,110,221,.1); background: #fff; }
        .fld textarea::placeholder { color: #94a3b8; font-weight: 400; }

        /* ─── Drop Zone ─── */
        .drop-zone {
            border: 2px dashed #c7d7f0; border-radius: 16px;
            padding: 32px 24px; text-align: center; cursor: pointer;
            transition: all .25s; background: #f8fafc; position: relative;
        }
        .drop-zone:hover, .drop-zone.drag-over {
            border-color: #2e6edd; background: #eff5ff;
        }
        .drop-zone.drag-over { transform: scale(1.01); }
        .dz-icon {
            width: 56px; height: 56px; background: #e8f0fe;
            border-radius: 16px; display: flex; align-items: center;
            justify-content: center; margin: 0 auto 14px; color: #2e6edd;
        }
        .dz-title { font-size: .9375rem; font-weight: 700; color: #1a1a2e; margin-bottom: 4px; }
        .dz-hint { font-size: .8125rem; color: #94a3b8; }
        .dz-btn {
            display: inline-block; margin-top: 12px;
            padding: 8px 20px; background: #2e6edd; color: #fff;
            border-radius: 10px; font-size: .8125rem; font-weight: 700;
            transition: background .2s;
        }
        .dz-btn:hover { background: #1e52ab; }
        .dz-input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }

        /* ─── File List ─── */
        .file-list { margin-top: 14px; display: flex; flex-direction: column; gap: 8px; }
        .file-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px; background: #fff; border: 1.5px solid #e2e8f0;
            border-radius: 12px; transition: all .2s;
        }
        .file-item:hover { border-color: #2e6edd; }
        .file-icon {
            width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: .75rem; font-weight: 700; flex-shrink: 0;
        }
        .file-info { flex: 1; min-width: 0; }
        .file-name { font-size: .875rem; font-weight: 600; color: #1a1a2e; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .file-size { font-size: .75rem; color: #94a3b8; }
        .file-remove {
            width: 28px; height: 28px; border-radius: 8px;
            border: none; background: #fef2f2; color: #dc2626;
            cursor: pointer; display: flex; align-items: center;
            justify-content: center; flex-shrink: 0; transition: all .2s;
        }
        .file-remove:hover { background: #dc2626; color: #fff; }

        /* ─── Submit ─── */
        .btn-submit {
            width: 100%; padding: 15px; background: #2e6edd;
            color: #fff; border: none; border-radius: 14px;
            font-size: 1rem; font-weight: 700; cursor: pointer;
            transition: all .25s; font-family: 'Inter', sans-serif;
            margin-top: 8px; display: flex; align-items: center;
            justify-content: center; gap: 8px;
        }
        .btn-submit:hover { background: #1e52ab; transform: translateY(-2px); box-shadow: 0 12px 32px rgba(46,110,221,.25); }
        .btn-submit:active { transform: none; }

        .err { padding: 12px 16px; border-radius: 12px; background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; font-size: .875rem; font-weight: 500; margin-bottom: 20px; }
        .info-box { margin-top: 20px; padding: 16px; background: #f0f7ff; border-radius: 12px; border: 1px solid #bfdbfe; font-size: .8125rem; color: #3b82f6; line-height: 1.5; }
        .info-box strong { color: #1e40af; }

        @media (max-width: 640px) { .main { padding: 20px 16px 40px; } .card { padding: 24px; } }
    </style>
</head>
<body>

<div class="topbar">
    <a href="<?php echo portalUrl(); ?>/index.php?page=dashboard" class="topbar-back">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Volver
    </a>
    <span class="topbar-title">Nueva Solicitud</span>
</div>

<div class="main">
    <div class="card">
        <h1>Enviar Consulta</h1>
        <p class="sub">Describa su situación legal. Nuestro equipo le asignará un abogado especializado.</p>

        <?php if ($error): ?>
        <div class="err"><?php echo e($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="<?php echo portalUrl(); ?>/index.php?page=nueva-solicitud"
              enctype="multipart/form-data" id="solForm">

            <!-- Custom Select -->
            <div class="fld">
                <label>Tipo de Consulta <span class="r">*</span></label>
                <div class="cs-wrap">
                    <div class="cs-trigger <?php echo $selTipo ? 'has-value' : ''; ?>" id="csTrigger">
                        <span id="csLabel"><?php echo $selTipo ?: 'Seleccione el área legal'; ?></span>
                    </div>
                    <svg class="cs-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                    <div class="cs-dropdown" id="csDropdown">
                        <?php
                        $svgIcons = [
                            'Civil'          => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>',
                            'Penal'          => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
                            'Laboral'        => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="17"/><line x1="9" y1="14.5" x2="15" y2="14.5"/></svg>',
                            'Mercantil'      => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
                            'Inmobiliario'   => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
                            'Familia'        => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
                            'Extranjería'    => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
                            'Administrativo' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><rect x="9" y="14" width="6" height="7"/></svg>',
                            'Otro'           => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>',
                        ];
                        $colores = [
                            'Civil'=>'#e8f0fe','Penal'=>'#fef2f2','Laboral'=>'#fff7ed',
                            'Mercantil'=>'#ecfdf5','Inmobiliario'=>'#f0fdf4','Familia'=>'#fdf4ff',
                            'Extranjería'=>'#f0f9ff','Administrativo'=>'#f8fafc','Otro'=>'#f8fafc'
                        ];
                        $textColors = [
                            'Civil'=>'#2e6edd','Penal'=>'#dc2626','Laboral'=>'#ea580c',
                            'Mercantil'=>'#059669','Inmobiliario'=>'#16a34a','Familia'=>'#9333ea',
                            'Extranjería'=>'#0284c7','Administrativo'=>'#475569','Otro'=>'#64748b'
                        ];
                        foreach ($tiposConsulta as $t):
                        ?>
                        <div class="cs-option <?php echo $selTipo === $t ? 'selected' : ''; ?>" data-value="<?php echo $t; ?>">
                            <span class="cs-ico" style="background:<?php echo $colores[$t] ?? '#f8fafc'; ?>;color:<?php echo $textColors[$t] ?? '#64748b'; ?>"><?php echo $svgIcons[$t] ?? $svgIcons['Otro']; ?></span>
                            <?php echo $t; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <select name="tipo_problema" class="cs-hidden" id="csHidden" required>
                        <option value="">Seleccione</option>
                        <?php foreach ($tiposConsulta as $t): ?>
                        <option value="<?php echo $t; ?>" <?php echo $selTipo === $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Descripción -->
            <div class="fld">
                <label>Describa su Caso <span class="r">*</span></label>
                <textarea name="descripcion" placeholder="Explique brevemente su situación legal para que podamos evaluar su caso y asignarle el abogado más adecuado..." required><?php echo e($_POST['descripcion'] ?? ''); ?></textarea>
            </div>

            <!-- Drop Zone archivos -->
            <div class="fld">
                <label>Documentos Adjuntos <span style="color:#94a3b8;font-weight:400">(opcional)</span></label>
                <div class="drop-zone" id="dropZone">
                    <input type="file" name="archivos[]" id="fileInput" class="dz-input" multiple accept="*/*">
                    <div class="dz-icon">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    </div>
                    <div class="dz-title">Arrastre archivos aquí</div>
                    <div class="dz-hint">PDF, imágenes, Word, Excel, ZIP… cualquier tipo</div>
                    <div class="dz-btn">Seleccionar archivos</div>
                </div>
                <div class="file-list" id="fileList"></div>
            </div>

            <button type="submit" class="btn-submit">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                Enviar Solicitud
            </button>
        </form>

        <div class="info-box">
            <strong>¿Qué pasa después?</strong> Nuestro equipo revisará su solicitud y, si es aceptada, le asignará un abogado. Podrá seguir el progreso de su caso desde este portal.
        </div>
    </div>
</div>

<script>
/* ── Custom Select ── */
const trigger   = document.getElementById('csTrigger');
const dropdown  = document.getElementById('csDropdown');
const label     = document.getElementById('csLabel');
const hidden    = document.getElementById('csHidden');

trigger.addEventListener('click', () => {
    trigger.classList.toggle('open');
    dropdown.classList.toggle('open');
});

document.querySelectorAll('.cs-option').forEach(opt => {
    opt.addEventListener('click', () => {
        const val = opt.dataset.value;
        label.textContent = val;
        hidden.value = val;
        trigger.classList.add('has-value');
        trigger.classList.remove('open');
        dropdown.classList.remove('open');
        document.querySelectorAll('.cs-option').forEach(o => o.classList.remove('selected'));
        opt.classList.add('selected');
    });
});

document.addEventListener('click', e => {
    if (!e.target.closest('.cs-wrap')) {
        trigger.classList.remove('open');
        dropdown.classList.remove('open');
    }
});

/* ── File Drop Zone ── */
const dropZone  = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const fileList  = document.getElementById('fileList');
let selectedFiles = [];

function formatBytes(b) {
    if (b < 1024) return b + ' B';
    if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
    return (b / 1024 / 1024).toFixed(1) + ' MB';
}

function fileColor(name) {
    const ext = name.split('.').pop().toLowerCase();
    const map = { pdf: ['#fef2f2','#dc2626'], doc: ['#e8f0fe','#2e6edd'], docx: ['#e8f0fe','#2e6edd'], xls: ['#ecfdf5','#059669'], xlsx: ['#ecfdf5','#059669'], jpg: ['#fff7ed','#ea580c'], jpeg: ['#fff7ed','#ea580c'], png: ['#fff7ed','#ea580c'], zip: ['#f5f3ff','#7c3aed'], rar: ['#f5f3ff','#7c3aed'] };
    return map[ext] || ['#f8fafc','#64748b'];
}

function renderFiles() {
    fileList.innerHTML = '';
    selectedFiles.forEach((f, i) => {
        const [bg, color] = fileColor(f.name);
        const ext = f.name.split('.').pop().toUpperCase().slice(0,4);
        const item = document.createElement('div');
        item.className = 'file-item';
        item.innerHTML = `
            <div class="file-icon" style="background:${bg};color:${color}">${ext}</div>
            <div class="file-info">
                <div class="file-name">${f.name}</div>
                <div class="file-size">${formatBytes(f.size)}</div>
            </div>
            <button type="button" class="file-remove" data-idx="${i}">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>`;
        fileList.appendChild(item);
    });

    // Sync with real input using DataTransfer
    const dt = new DataTransfer();
    selectedFiles.forEach(f => dt.items.add(f));
    fileInput.files = dt.files;

    document.querySelectorAll('.file-remove').forEach(btn => {
        btn.addEventListener('click', () => {
            selectedFiles.splice(+btn.dataset.idx, 1);
            renderFiles();
        });
    });
}

fileInput.addEventListener('change', () => {
    Array.from(fileInput.files).forEach(f => selectedFiles.push(f));
    renderFiles();
});

['dragenter','dragover'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.add('drag-over'); }));
['dragleave','drop'].forEach(e => dropZone.addEventListener(e, ev => { ev.preventDefault(); dropZone.classList.remove('drag-over'); }));
dropZone.addEventListener('drop', ev => {
    Array.from(ev.dataTransfer.files).forEach(f => selectedFiles.push(f));
    renderFiles();
});
</script>
<?php echo portalPwaScript(); ?>
</body>
</html>
