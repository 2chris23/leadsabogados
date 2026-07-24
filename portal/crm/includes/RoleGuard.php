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
     * Obtener menú filtrado por rol y permisos configurados
     * Admin siempre ve todo. Otros roles ven según role_permisos.
     */
    public static function getMenuItems() {
        $rol = $_SESSION['usuario_rol'] ?? '';

        // Menú completo del admin (siempre todo)
        $menuAdmin = [
            ['titulo'=>'Dashboard',    'icono'=>'solar:home-smile-outline',             'url'=>'dashboard'],
            ['titulo'=>'Solicitudes',  'icono'=>'solar:inbox-outline',                  'url'=>'solicitudes'],
            ['titulo'=>'Clientes',     'icono'=>'solar:users-group-rounded-outline',    'url'=>'clientes'],
            ['titulo'=>'Casos',        'icono'=>'solar:case-minimalistic-outline',      'url'=>'casos'],
            ['titulo'=>'Pagos',        'icono'=>'solar:wallet-money-outline',           'url'=>'pagos'],
            ['titulo'=>'Abogados',     'icono'=>'solar:user-id-outline',                'url'=>'abogados'],
            [
                'titulo'    => 'Administración',
                'icono'     => 'solar:settings-outline',
                'submenu'   => [
                    ['titulo'=>'Usuarios',      'url'=>'usuarios',              'icono_color'=>'text-primary-600'],
                    ['titulo'=>'Permisos',       'url'=>'permisos',              'icono_color'=>'text-purple-600'],
                    ['titulo'=>'Configuración', 'url'=>'configuracion/tema',   'icono_color'=>'text-warning-main'],
                    ['titulo'=>'Correo',         'url'=>'configuracion/correo', 'icono_color'=>'text-success-main'],
                    ['titulo'=>'Auditoría',      'url'=>'auditoria',            'icono_color'=>'text-info-main'],
                ],
            ],
        ];

        if ($rol === 'admin') {
            return $menuAdmin;
        }

        // Para otros roles: cargar permisos configurados
        $permisos = [];
        try {
            $db = Database::getInstance();
            $rows = $db->fetchAll(
                "SELECT permiso, activo FROM role_permisos WHERE rol = ?",
                [$rol]
            );
            foreach ($rows as $r) {
                $permisos[$r['permiso']] = (bool)$r['activo'];
            }
        } catch (\Throwable $e) {
            // Si la tabla no existe aún, usar permisos por rol como fallback
        }

        // Helper: ¿tiene al menos uno de los permisos?
        $tiene = function(array $claves) use ($permisos, $rol) {
            // Si no hay configuración en BD, usar roles por defecto
            if (empty($permisos)) {
                return in_array($rol, ['abogado', 'gestor']);
            }
            foreach ($claves as $c) {
                if (!empty($permisos[$c])) return true;
            }
            return false;
        };

        $items = [];

        // Dashboard — siempre visible para abogados
        if ($rol === 'abogado') {
            $items[] = ['titulo'=>'Dashboard', 'icono'=>'solar:home-smile-outline', 'url'=>'dashboard'];
        }

        // Solicitudes
        if ($tiene(['solicitudes.ver', 'solicitudes.crear', 'solicitudes.editar', 'solicitudes.cambiar_estado'])) {
            $items[] = ['titulo'=>'Solicitudes', 'icono'=>'solar:inbox-outline', 'url'=>'solicitudes'];
        }

        // Clientes
        if ($tiene(['clientes.ver', 'clientes.crear', 'clientes.editar'])) {
            $items[] = ['titulo'=>'Clientes', 'icono'=>'solar:users-group-rounded-outline', 'url'=>'clientes'];
        }

        // Casos
        if ($tiene(['casos.ver', 'casos.crear', 'casos.editar']) || $rol === 'abogado') {
            $items[] = ['titulo'=>'Casos', 'icono'=>'solar:case-minimalistic-outline', 'url'=>'casos'];
        }

        // Pagos — solo si tiene permiso explícito
        if ($tiene(['pagos.ver', 'pagos.registrar'])) {
            $items[] = ['titulo'=>'Pagos', 'icono'=>'solar:wallet-money-outline', 'url'=>'pagos'];
        }

        // Abogados — visible para abogado en su propio perfil
        if ($rol === 'abogado') {
            $items[] = ['titulo'=>'Abogados', 'icono'=>'solar:user-id-outline', 'url'=>'abogados'];
        }

        return $items;
    }
}
