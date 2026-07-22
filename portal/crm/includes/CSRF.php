<?php
/**
 * CRM Abogados - Protección CSRF
 * Generación y validación de tokens anti-CSRF
 */

if (!defined('CRM_ROOT')) {
    die('Acceso prohibido');
}

class CSRF {
    
    /**
     * Generar un token CSRF y guardarlo en la sesión
     * Si ya existe un token válido, lo reutiliza
     * @return string Token generado o existente
     */
    public static function generarToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Reutilizar token existente si hay uno válido (evita sobrescribir entre formularios)
        if (!empty($_SESSION['csrf_token']) && !empty($_SESSION['csrf_token_time'])) {
            if (time() - $_SESSION['csrf_token_time'] < 3600) {
                return $_SESSION['csrf_token'];
            }
        }
        
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();
        
        return $token;
    }
    
    /**
     * Generar el campo hidden HTML con el token
     * @return string HTML del input hidden
     */
    public static function campo() {
        $token = self::generarToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
    
    /**
     * Validar el token CSRF recibido
     * @param string $token Token recibido del formulario
     * @param int $maxEdad Tiempo máximo de validez en segundos (default: 1 hora)
     * @return bool
     */
    public static function validar($token = null, $maxEdad = 3600) {
        if ($token === null) {
            $token = $_POST['csrf_token'] ?? '';
        }
        
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        
        // Verificar que no haya expirado
        if (isset($_SESSION['csrf_token_time'])) {
            if (time() - $_SESSION['csrf_token_time'] > $maxEdad) {
                unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
                return false;
            }
        }
        
        // Comparación segura contra timing attacks
        $valido = hash_equals($_SESSION['csrf_token'], $token);
        
        // Regenerar token para siguiente petición
        if ($valido) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }
        
        return $valido;
    }
    
    /**
     * Verificar CSRF y abortar si no es válido
     */
    public static function verificarOAbortar() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!self::validar()) {
                http_response_code(403);
                die('Error de seguridad: token CSRF inválido. Recargue la página e intente de nuevo.');
            }
        }
    }
}
