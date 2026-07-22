<?php
/**
 * CRM Abogados - Registro de Auditoría
 * Registra todas las acciones relevantes del sistema
 */

if (!defined('CRM_ROOT')) {
    die('Acceso prohibido');
}

class AuditLog {
    
    /**
     * Registrar una acción en el log de auditoría
     * @param string $accion       Tipo de acción (login, crear, editar, eliminar, etc.)
     * @param string|null $tabla   Tabla afectada
     * @param int|null $registroId ID del registro afectado
     * @param string|null $detalles Descripción detallada del cambio
     */
    public static function registrar($accion, $tabla = null, $registroId = null, $detalles = null) {
        try {
            $db = Database::getInstance();
            
            $usuarioId = $_SESSION['usuario_id'] ?? null;
            $usuarioNombre = null;
            
            if ($usuarioId) {
                $nombre = $_SESSION['usuario_nombre'] ?? '';
                $apellidos = $_SESSION['usuario_apellidos'] ?? '';
                $usuarioNombre = trim($nombre . ' ' . $apellidos);
            }
            
            $db->insert('audit_log', [
                'usuario_id'     => $usuarioId,
                'usuario_nombre' => $usuarioNombre,
                'accion'         => $accion,
                'tabla_afectada' => $tabla,
                'registro_id'    => $registroId,
                'detalles'       => $detalles,
                'ip'             => self::getIP()
            ]);
        } catch (Exception $e) {
            // No queremos que un error de auditoría rompa la aplicación
            if (DEBUG) {
                error_log('Error en AuditLog: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Obtener los últimos registros del log
     * @param int $limite Cantidad de registros
     * @param array $filtros Filtros opcionales
     * @return array
     */
    public static function obtener($limite = 50, $filtros = []) {
        $db = Database::getInstance();
        
        $where = '1=1';
        $params = [];
        
        if (!empty($filtros['usuario_id'])) {
            $where .= ' AND usuario_id = ?';
            $params[] = $filtros['usuario_id'];
        }
        
        if (!empty($filtros['accion'])) {
            $where .= ' AND accion = ?';
            $params[] = $filtros['accion'];
        }
        
        if (!empty($filtros['tabla'])) {
            $where .= ' AND tabla_afectada = ?';
            $params[] = $filtros['tabla'];
        }
        
        if (!empty($filtros['desde'])) {
            $where .= ' AND created_at >= ?';
            $params[] = $filtros['desde'];
        }
        
        if (!empty($filtros['hasta'])) {
            $where .= ' AND created_at <= ?';
            $params[] = $filtros['hasta'];
        }
        
        $params[] = (int) $limite;
        
        return $db->fetchAll(
            "SELECT * FROM audit_log WHERE {$where} ORDER BY created_at DESC LIMIT ?",
            $params
        );
    }
    
    /**
     * Obtener la IP real del usuario
     */
    private static function getIP() {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
