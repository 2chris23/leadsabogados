<?php
/**
 * CRM Abogados - Control de Acceso por Roles
 * Verifica permisos según el rol del usuario
 */

if (!defined('CRM_ROOT')) {
    die('Acceso prohibido');
}

class RoleGuard {
    
    /**
     * Verificar que el usuario tiene uno de los roles permitidos
     * @param array|string $rolesPermitidos Rol o array de roles permitidos
     */
    public static function requireRole($rolesPermitidos) {
        if (is_string($rolesPermitidos)) {
            $rolesPermitidos = [$rolesPermitidos];
        }
        
        $rolActual = $_SESSION['usuario_rol'] ?? null;
        
        if (!$rolActual || !in_array($rolActual, $rolesPermitidos)) {
            // Registrar intento de acceso no autorizado
            AuditLog::registrar(
                'acceso_denegado',
                null,
                null,
                'Intento de acceso con rol: ' . ($rolActual ?? 'sin sesión') . 
                ' a ruta: ' . ($_SERVER['REQUEST_URI'] ?? 'desconocida')
            );
            
            http_response_code(403);
            include CRM_ROOT . '/pages/acceso-denegado.php';
            exit;
        }
    }
    
    /**
     * Verificar si el usuario actual es administrador
     */
    public static function esAdmin() {
        return ($_SESSION['usuario_rol'] ?? '') === 'admin';
    }
    
    /**
     * Verificar si el usuario actual es abogado
     */
    public static function esAbogado() {
        return ($_SESSION['usuario_rol'] ?? '') === 'abogado';
    }
    
    /**
     * Verificar si el usuario actual es gestor
     */
    public static function esGestor() {
        return ($_SESSION['usuario_rol'] ?? '') === 'gestor';
    }
    
    /**
     * Verificar que el abogado tiene acceso al caso especificado
     * Los admins siempre tienen acceso
     */
    public static function verificarAccesoCaso($casoId) {
        if (self::esAdmin()) return true;
        
        $db = Database::getInstance();
        $caso = $db->fetchOne(
            "SELECT abogado_id FROM casos WHERE id = ?",
            [$casoId]
        );
        
        if (!$caso) {
            http_response_code(404);
            die('Caso no encontrado');
        }
        
        $usuarioId = $_SESSION['usuario_id'] ?? 0;
        
        if (self::esAbogado() && $caso['abogado_id'] != $usuarioId) {
            http_response_code(403);
            include CRM_ROOT . '/pages/acceso-denegado.php';
            exit;
        }
        
        return true;
    }
    
    /**
     * Obtener menú filtrado por rol
     * @return array Elementos del menú visibles para el rol actual
     */
    public static function getMenuItems() {
        $rol = $_SESSION['usuario_rol'] ?? '';
        
        $menu = [
            [
                'titulo'  => 'Dashboard',
                'icono'   => 'solar:home-smile-outline',
                'url'     => 'dashboard',
                'roles'   => ['admin', 'abogado'],
            ],
            [
                'titulo'  => 'Solicitudes',
                'icono'   => 'solar:inbox-outline',
                'url'     => 'solicitudes',
                'roles'   => ['admin', 'gestor'],
            ],
            [
                'titulo'  => 'Clientes',
                'icono'   => 'solar:users-group-rounded-outline',
                'url'     => 'clientes',
                'roles'   => ['admin', 'abogado', 'gestor'],
            ],
            [
                'titulo'  => 'Casos',
                'icono'   => 'solar:case-minimalistic-outline',
                'url'     => 'casos',
                'roles'   => ['admin', 'abogado'],
            ],
            [
                'titulo'  => 'Pagos',
                'icono'   => 'solar:wallet-money-outline',
                'url'     => 'pagos',
                'roles'   => ['admin'],
            ],
            [
                'titulo'  => 'Abogados',
                'icono'   => 'solar:user-id-outline',
                'url'     => 'abogados',
                'roles'   => ['admin'],
            ],
            [
                'titulo'    => 'Administración',
                'icono'     => 'solar:settings-outline',
                'roles'     => ['admin'],
                'submenu'   => [
                    ['titulo' => 'Usuarios',      'url' => 'usuarios',              'icono_color' => 'text-primary-600'],
                    ['titulo' => 'Configuración', 'url' => 'configuracion/tema',   'icono_color' => 'text-warning-main'],
                    ['titulo' => 'Correo',        'url' => 'configuracion/correo', 'icono_color' => 'text-success-main'],
                    ['titulo' => 'Auditoría',     'url' => 'auditoria',             'icono_color' => 'text-info-main'],
                ],
            ],
        ];
        
        // Filtrar por rol
        return array_filter($menu, function($item) use ($rol) {
            return in_array($rol, $item['roles']);
        });
    }
}
