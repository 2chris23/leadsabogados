<?php
/**
 * CRM Abogados - Sistema de Autenticación
 * Login, logout, sesiones, bloqueo de cuenta
 */

if (!defined('CRM_ROOT')) {
    die('Acceso prohibido');
}

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->iniciarSesion();
    }
    
    /**
     * Iniciar sesión PHP de forma segura
     */
    private function iniciarSesion() {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('CRM_SESSION');
            session_start();
        }
        
        // ─── Auto-login por cookie "Recordarme" (ANTES del timeout check) ───
        if (!$this->estaLogueado() && isset($_COOKIE['crm_remember'])) {
            $cookie = $_COOKIE['crm_remember'];
            $parts  = explode(':', $cookie, 2);
            if (count($parts) === 2) {
                [$userId, $token] = $parts;
                $userId = (int)$userId;
                $usuario = $this->db->fetchOne(
                    "SELECT * FROM usuarios_internos WHERE id = ? AND activo = 1",
                    [$userId]
                );
                if ($usuario) {
                    // Token = HMAC(user_id + password_hash) — se invalida si cambia la contraseña
                    $expectedToken = hash_hmac('sha256', $userId . $usuario['password_hash'], APP_SECRET ?? 'crm_secret_key');
                    if (hash_equals($expectedToken, $token)) {
                        $this->crearSesion($usuario, true); // Restaura sesión y renueva cookie 30 días
                    } else {
                        // Token inválido (contraseña cambiada) — borrar cookie
                        setcookie('crm_remember', '', time() - 3600, '/', '', false, true);
                    }
                } else {
                    // Usuario no existe o desactivado — borrar cookie
                    setcookie('crm_remember', '', time() - 3600, '/', '', false, true);
                }
            }
        }

        // Verificar expiración por inactividad (solo si la sesión no viene de cookie)
        // Las sesiones restauradas por cookie tienen 'ultimo_acceso' recién puesto, nunca expiran por idle
        if ($this->estaLogueado() && empty($_SESSION['via_remember'])) {
            $timeout = $this->getConfigValue('sesion_timeout_minutos', 30) * 60;
            if (isset($_SESSION['ultimo_acceso']) && (time() - $_SESSION['ultimo_acceso']) > $timeout) {
                $this->logout();
                return;
            }
        }

        if ($this->estaLogueado()) {
            $_SESSION['ultimo_acceso'] = time();
        }
    }
    
    /**
     * Intentar login con email y contraseña
     * @return array ['exito' => bool, 'mensaje' => string]
     */
    public function login($email, $password, $remember = false) {
        $email = trim(strtolower($email));
        
        // Buscar usuario
        $usuario = $this->db->fetchOne(
            "SELECT * FROM usuarios_internos WHERE email = ? AND activo = 1",
            [$email]
        );
        
        if (!$usuario) {
            return ['exito' => false, 'mensaje' => 'Credenciales incorrectas'];
        }
        
        // Verificar bloqueo
        if ($usuario['bloqueado_hasta'] && strtotime($usuario['bloqueado_hasta']) > time()) {
            $minutos = ceil((strtotime($usuario['bloqueado_hasta']) - time()) / 60);
            return ['exito' => false, 'mensaje' => "Cuenta bloqueada. Intente en {$minutos} minutos."];
        }
        
        // Verificar contraseña
        if (!password_verify($password, $usuario['password_hash'])) {
            $this->registrarIntentoFallido($usuario);
            return ['exito' => false, 'mensaje' => 'Credenciales incorrectas'];
        }
        
        // Login exitoso
        $this->resetearIntentos($usuario['id']);
        $this->crearSesion($usuario, $remember);
        
        AuditLog::registrar('login', 'usuarios_internos', $usuario['id'], 'Inicio de sesión exitoso');
        
        return ['exito' => true, 'mensaje' => 'Bienvenido'];
    }
    
    /**
     * Cerrar sesión
     */
    public function logout() {
        if (isset($_SESSION['usuario_id'])) {
            AuditLog::registrar('logout', 'usuarios_internos', $_SESSION['usuario_id'], 'Cierre de sesión');
        }
        
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        
        // Borrar cookie de recordarme
        setcookie('crm_remember', '', time() - 3600, '/', '', false, true);
    }
    
    /**
     * Verificar si el usuario está logueado
     */
    public function estaLogueado() {
        return isset($_SESSION['usuario_id']) && !empty($_SESSION['usuario_id']);
    }
    
    /**
     * Requerir login - redirige si no está autenticado
     */
    public function requireLogin() {
        if (!$this->estaLogueado()) {
            header('Location: ' . APP_URL . '/index.php?page=login');
            exit;
        }
    }
    
    /**
     * Obtener datos del usuario actual
     */
    public function getUsuario() {
        if (!$this->estaLogueado()) return null;
        
        return [
            'id'        => $_SESSION['usuario_id'],
            'nombre'    => $_SESSION['usuario_nombre'],
            'apellidos' => $_SESSION['usuario_apellidos'],
            'email'     => $_SESSION['usuario_email'],
            'rol'       => $_SESSION['usuario_rol'],
            'avatar'    => $_SESSION['usuario_avatar'] ?? null
        ];
    }
    
    /**
     * Obtener el rol del usuario actual
     */
    public function getRol() {
        return $_SESSION['usuario_rol'] ?? null;
    }
    
    /**
     * Verificar si el usuario actual es administrador
     */
    public function esAdmin() {
        return $this->getRol() === 'admin';
    }
    
    /**
     * Verificar si el usuario actual es abogado
     */
    public function esAbogado() {
        return $this->getRol() === 'abogado';
    }
    
    /**
     * Verificar si el usuario actual es gestor
     */
    public function esGestor() {
        return $this->getRol() === 'gestor';
    }
    
    // =====================================================
    // Métodos privados
    // =====================================================
    
    private function crearSesion($usuario, $remember = false) {
        session_regenerate_id(true);
        
        $_SESSION['usuario_id']        = $usuario['id'];
        $_SESSION['usuario_nombre']    = $usuario['nombre'];
        $_SESSION['usuario_apellidos'] = $usuario['apellidos'];
        $_SESSION['usuario_email']     = $usuario['email'];
        $_SESSION['usuario_rol']       = $usuario['rol'];
        $_SESSION['usuario_avatar']    = $usuario['avatar'];
        $_SESSION['ultimo_acceso']     = time();
        
        // Actualizar último login
        $this->db->update('usuarios_internos', 
            ['ultimo_login' => date('Y-m-d H:i:s')],
            'id = ?', [$usuario['id']]
        );
        
        // ─── Cookie Recordarme (30 días) ───
        if ($remember) {
            $token = hash_hmac('sha256', $usuario['id'] . $usuario['password_hash'], APP_SECRET ?? 'crm_secret_key');
            $cookieValue = $usuario['id'] . ':' . $token;
            // Marcar sesión como restaurada por cookie → no aplica timeout de inactividad
            $_SESSION['via_remember'] = true;
            $esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
            setcookie('crm_remember', $cookieValue, time() + (30 * 24 * 3600), '/', '', $esHttps, true);
        } else {
            $_SESSION['via_remember'] = false;
        }
    }
    
    private function registrarIntentoFallido($usuario) {
        $intentos = $usuario['intentos_fallidos'] + 1;
        $maxIntentos = $this->getConfigValue('max_intentos_login', 5);
        $bloqueoMinutos = $this->getConfigValue('bloqueo_minutos', 15);
        
        $datos = ['intentos_fallidos' => $intentos];
        
        if ($intentos >= $maxIntentos) {
            $datos['bloqueado_hasta'] = date('Y-m-d H:i:s', time() + ($bloqueoMinutos * 60));
            $datos['intentos_fallidos'] = 0;
        }
        
        $this->db->update('usuarios_internos', $datos, 'id = ?', [$usuario['id']]);
    }
    
    private function resetearIntentos($id) {
        $this->db->update('usuarios_internos', [
            'intentos_fallidos' => 0,
            'bloqueado_hasta' => null
        ], 'id = ?', [$id]);
    }
    
    private function getConfigValue($clave, $default = null) {
        try {
            $valor = $this->db->fetchColumn(
                "SELECT valor FROM configuracion WHERE clave = ?",
                [$clave]
            );
            return $valor !== false ? $valor : $default;
        } catch (Exception $e) {
            return $default;
        }
    }
}
