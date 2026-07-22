<?php
/**
 * DIAGNÓSTICO — Sube este archivo a la raíz de tu hosting
 * Ábrelo en el navegador: https://app.leadsabogados.com/diagnostico.php
 * Te mostrará el error EXACTO que está causando el 500.
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h2>1. PHP funciona</h2>";
echo "<p>Versión PHP: " . phpversion() . "</p>";

echo "<h2>2. Sesiones</h2>";
$tmpDir = sys_get_temp_dir();
$savePath = ini_get('session.save_path');
echo "<p>session.save_path: <code>" . ($savePath ?: '(vacío — usa default del sistema)') . "</code></p>";
echo "<p>sys_get_temp_dir(): <code>{$tmpDir}</code></p>";
echo "<p>¿Carpeta de sesiones es escribible? ";
$testPath = $savePath ?: $tmpDir;
echo is_writable($testPath) ? "<b style='color:green'>SÍ</b>" : "<b style='color:red'>NO — ESTE ES EL PROBLEMA</b>";
echo "</p>";

// Intentar iniciar sesión
try {
    session_start();
    $_SESSION['test'] = 'ok';
    echo "<p>session_start(): <b style='color:green'>OK</b></p>";
    echo "<p>Session ID: " . session_id() . "</p>";
} catch (Throwable $e) {
    echo "<p>session_start(): <b style='color:red'>FALLO — " . $e->getMessage() . "</b></p>";
}

echo "<h2>3. Base de datos</h2>";
// Leer .env manualmente
$envFile = __DIR__ . '/portal/crm/.env';
if (!file_exists($envFile)) {
    echo "<p style='color:red'>NO se encontró el archivo .env en: {$envFile}</p>";
} else {
    echo "<p>.env encontrado: ✅</p>";
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $env[trim($k)] = trim(trim($v), '"\'');
    }
    
    $host = $env['DB_HOST'] ?? 'localhost';
    $name = $env['DB_NAME'] ?? '';
    $user = $env['DB_USER'] ?? '';
    $pass = $env['DB_PASS'] ?? '';
    
    echo "<p>DB_HOST: <code>{$host}</code></p>";
    echo "<p>DB_NAME: <code>{$name}</code></p>";
    echo "<p>DB_USER: <code>{$user}</code></p>";
    echo "<p>DB_PASS: <code>" . str_repeat('*', strlen($pass)) . "</code> (" . strlen($pass) . " caracteres)</p>";
    
    try {
        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        echo "<p>Conexión BD: <b style='color:green'>EXITOSA ✅</b></p>";
        
        // Verificar tabla usuarios
        $stmt = $pdo->query("SELECT id, nombre, email, rol, activo FROM usuarios_internos");
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<p>Usuarios encontrados: " . count($usuarios) . "</p>";
        echo "<table border='1' cellpadding='5'><tr><th>ID</th><th>Nombre</th><th>Email</th><th>Rol</th><th>Activo</th></tr>";
        foreach ($usuarios as $u) {
            echo "<tr><td>{$u['id']}</td><td>{$u['nombre']}</td><td>{$u['email']}</td><td>{$u['rol']}</td><td>{$u['activo']}</td></tr>";
        }
        echo "</table>";
        
    } catch (PDOException $e) {
        echo "<p>Conexión BD: <b style='color:red'>FALLO ❌</b></p>";
        echo "<p>Error: <code>" . $e->getMessage() . "</code></p>";
    }
}

echo "<h2>4. Intentar cargar el CRM completo</h2>";
echo "<p>Voy a intentar cargar el index.php del CRM para ver dónde falla exactamente...</p>";
try {
    define('CRM_ROOT', __DIR__ . '/portal/crm');
    require_once CRM_ROOT . '/includes/config.php';
    echo "<p>config.php: <b style='color:green'>OK</b></p>";
    
    require_once CRM_ROOT . '/includes/Database.php';
    echo "<p>Database.php: <b style='color:green'>OK</b></p>";
    
    require_once CRM_ROOT . '/includes/AuditLog.php';
    echo "<p>AuditLog.php: <b style='color:green'>OK</b></p>";
    
    require_once CRM_ROOT . '/includes/Auth.php';
    echo "<p>Auth.php: <b style='color:green'>OK</b></p>";
    
    require_once CRM_ROOT . '/includes/CSRF.php';
    echo "<p>CSRF.php: <b style='color:green'>OK</b></p>";
    
    require_once CRM_ROOT . '/includes/RoleGuard.php';
    echo "<p>RoleGuard.php: <b style='color:green'>OK</b></p>";
    
    require_once CRM_ROOT . '/includes/FileUpload.php';
    echo "<p>FileUpload.php: <b style='color:green'>OK</b></p>";
    
    require_once CRM_ROOT . '/includes/Mailer.php';
    echo "<p>Mailer.php: <b style='color:green'>OK</b></p>";
    
    echo "<p><b style='color:green'>🎉 TODOS los archivos cargan correctamente.</b></p>";
    
} catch (Throwable $e) {
    echo "<p style='color:red'><b>❌ ERROR:</b> " . $e->getMessage() . "</p>";
    echo "<p>Archivo: " . $e->getFile() . " línea " . $e->getLine() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
