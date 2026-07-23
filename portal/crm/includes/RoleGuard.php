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
     * Verificar si el usuario actual tiene un permiso especifico.
     * El rol admin SIEMPRE retorna true sin consultar la BD.
     * Los permisos del rol actual se leen una vez y se cachean en sesion.
     *
     * @param string $permiso  Ej: 'solicitudes.eliminar', 'clientes.crear'
     * @return bool
     */
    public static function can($permiso) {
        $rol = $_SESSION['usuario_rol'] ?? '';
        // Admin puede todo
        if ($rol === 'admin') return true;
        // Cargar cache si no existe
        if (!isset($_SESSION['_permisos_cache'])) {
            self::_cargarPermisos();
        }
        return !empty($_SESSION['_permisos_cache'][$permiso]);
    }

    /**
     * Como can() pero aborta con 403 si no tiene permiso.
     */
    public static function requirePermission($permiso) {
        if (!self::can($permiso)) {
            AuditLog::registrar('acceso_denegado', null, null,
                'Sin permiso "' . $permiso . '" - rol: ' . ($_SESSION['usuario_rol'] ?? ''));
            http_response_code(403);
            include CRM_ROOT . '/pages/acceso-denegado.php';
            exit;
        }
    }

    /**
     * Borrar cache de permisos (llamar tras guardar cambios en role_permisos).
     */
    public static function clearPermissionCache() {
        unset($_SESSION['_permisos_cache']);
    }

    /**
     * Carga todos los permisos del rol actual desde la BD y los guarda en sesion.
     */
    private static function _cargarPermisos() {
        $rol = $_SESSION['usuario_rol'] ?? '';
        $_SESSION['_permisos_cache'] = [];
        if (!$rol) return;
        try {
            $db = Database::getInstance();
            $rows = $db->fetchAll(
                "SELECT permiso, activo FROM role_permisos WHERE rol = ?",
                [$rol]
            );
            foreach ($rows as $row) {
                $_SESSION['_permisos_cache'][$row['permiso']] = (bool)$row['activo'];
            }
        } catch (\Throwable $e) {
            // Tabla aun no creada: sin restricciones
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
                    ['titulo' => 'Usuarios',       'url' => 'usuarios',              'icono_color' => 'text-primary-600'],
                    ['titulo' => 'Permisos',        'url' => 'permisos',              'icono_color' => 'text-purple-600'],
                    ['titulo' => 'Configuración',  'url' => 'configuracion/tema',   'icono_color' => 'text-warning-main'],
                    ['titulo' => 'Correo',          'url' => 'configuracion/correo', 'icono_color' => 'text-success-main'],
                    ['titulo' => 'Auditoría',       'url' => 'auditoria',            'icono_color' => 'text-info-main'],
                ],
            ],
        ];
        
        // Filtrar por rol
        return array_filter($menu, function($item) use ($rol) {
            return in_array($rol, $item['roles']);
        });
    }
}
