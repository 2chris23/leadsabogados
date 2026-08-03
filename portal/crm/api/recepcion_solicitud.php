<?php
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/Database.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    // Obtener y sanear datos
    $formData = [
        'nombre' => trim($_POST['nombre'] ?? ''),
        'apellidos' => trim($_POST['apellidos'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'telefono' => trim($_POST['telefono'] ?? ''),
        'tipo_problema' => trim($_POST['tipo_problema'] ?? 'General'),
        'descripcion' => trim($_POST['descripcion'] ?? '')
    ];

    if (empty($formData['nombre']) || empty($formData['email']) || empty($formData['descripcion'])) {
        throw new Exception("Por favor, rellene todos los campos obligatorios.");
    }

    if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception("El correo electrónico no es válido.");
    }

    $pdo->beginTransaction();

    // Comprobar si existe cuenta en portal (para asignarlo si existe)
    $portalId = null;
    $cuenta = $db->fetchOne("SELECT id FROM portal_cuentas WHERE email = ?", [$formData['email']]);
    if ($cuenta) {
        $portalId = $cuenta['id'];
    }

    // Insertar solicitud
    $solId = $db->insert('solicitudes', [
        'nombre' => $formData['nombre'],
        'apellidos' => $formData['apellidos'],
        'email' => $formData['email'],
        'telefono' => $formData['telefono'] ?: null,
        'tipo_problema' => $formData['tipo_problema'],
        'descripcion' => $formData['descripcion'],
        'estado' => 'pendiente',
        'portal_cuenta_id' => $portalId,
        'ip_solicitante' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]);

    // Procesar archivos si existen
    if (!empty($_FILES['archivos']['name'][0])) {
        $uploadsDir = dirname(__DIR__) . '/public/uploads/solicitudes/';
        if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);

        $blockedExts = ['php','php3','php4','php5','phtml','js','sh','exe','bat','cmd','msi','vbs','py','rb','cgi','pl'];
        $maxFileSize = 10 * 1024 * 1024; // 10 MB

        $files = $_FILES['archivos'];
        $count = is_array($files['name']) ? count($files['name']) : 1;
        
        for ($i = 0; $i < $count; $i++) {
            $err = is_array($files['error']) ? $files['error'][$i] : $files['error'];
            $size = is_array($files['size']) ? $files['size'][$i] : $files['size'];
            $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
            $tmp = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
            $type = is_array($files['type']) ? $files['type'][$i] : $files['type'];

            if ($err === UPLOAD_ERR_INI_SIZE || $size > $maxFileSize) {
                throw new Exception('El archivo "' . htmlspecialchars($name) . '" pesa demasiado. El límite es de 10MB.');
            }
            if ($err !== UPLOAD_ERR_OK) continue;

            $nombreOriginal = basename($name);
            $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));

            if (in_array($ext, $blockedExts)) {
                throw new Exception('El tipo de archivo ".' . $ext . '" no está permitido por motivos de seguridad.');
            }

            $realMime = $type;
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo !== false) {
                    $detectedMime = finfo_file($finfo, $tmp);
                    if ($detectedMime !== false) $realMime = $detectedMime;
                    finfo_close($finfo);
                }
            }

            $baseName = preg_replace('/[^\w\-. ]/u', '_', pathinfo($nombreOriginal, PATHINFO_FILENAME));
            $baseName = trim($baseName, '. _');
            $safeName = $baseName . '_' . uniqid() . ($ext ? '.' . $ext : '');
            $destPath = $uploadsDir . $safeName;

            if (move_uploaded_file($tmp, $destPath)) {
                $db->insert('solicitud_archivos', [
                    'solicitud_id' => $solId,
                    'nombre_original' => $nombreOriginal,
                    'nombre_archivo' => $safeName,
                    'ruta' => 'uploads/solicitudes/' . $safeName,
                    'tipo_mime' => $realMime,
                    'tamano_bytes' => $size,
                    'subido_por_cliente' => 1
                ]);
            }
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Solicitud enviada correctamente.']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
