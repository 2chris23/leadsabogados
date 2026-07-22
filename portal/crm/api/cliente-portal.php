<?php
/**
 * CRM Abogados - API Endpoint para Portal del Cliente (WordPress)
 * Devuelve datos del caso en JSON para que WordPress los muestre
 */
define('CRM_ROOT', dirname(__DIR__));
require_once CRM_ROOT . '/includes/config.php';
require_once CRM_ROOT . '/includes/Database.php';
require_once CRM_ROOT . '/includes/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

// Leer datos de la petición
$input = json_decode(file_get_contents('php://input'), true);
$portalUsuario = trim($input['usuario'] ?? '');
$portalPassword = $input['password'] ?? '';
$apiKey = $input['api_key'] ?? '';

// Verificar API key
$expectedApiKey = APP_SECRET;
if (empty($apiKey) || !hash_equals($expectedApiKey, $apiKey)) {
    http_response_code(403);
    echo json_encode(['error' => 'Clave API inválida']);
    exit;
}

// Validar credenciales del cliente
if (empty($portalUsuario) || empty($portalPassword)) {
    http_response_code(400);
    echo json_encode(['error' => 'Credenciales requeridas']);
    exit;
}

$db = Database::getInstance();

// Buscar solicitud con esas credenciales
$solicitud = $db->fetchOne(
    "SELECT * FROM solicitudes WHERE portal_usuario = ? AND estado = 'aceptada'",
    [$portalUsuario]
);

if (!$solicitud || !password_verify($portalPassword, $solicitud['portal_password_hash'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Credenciales incorrectas']);
    exit;
}

// Obtener cliente y casos
$cliente = $db->fetchOne(
    "SELECT * FROM clientes WHERE solicitud_id = ?",
    [$solicitud['id']]
);

if (!$cliente) {
    echo json_encode(['error' => 'No se encontró cliente asociado']);
    exit;
}

$casos = $db->fetchAll(
    "SELECT c.id, c.referencia, c.titulo, c.tipo_caso, c.estado, c.fecha_apertura, c.fecha_cierre,
            u.nombre as abogado_nombre, u.apellidos as abogado_apellidos,
            c.honorarios_totales,
            COALESCE((SELECT SUM(p.cantidad) FROM pagos p WHERE p.caso_id = c.id), 0) as total_pagado
     FROM casos c
     LEFT JOIN usuarios_internos u ON c.abogado_id = u.id
     WHERE c.cliente_id = ?
     ORDER BY c.created_at DESC",
    [$cliente['id']]
);

// Formatear respuesta
$respuesta = [
    'exito' => true,
    'cliente' => [
        'nombre'    => $cliente['nombre'] . ' ' . $cliente['apellidos'],
        'email'     => $cliente['email']
    ],
    'casos' => array_map(function($c) {
        return [
            'referencia'  => $c['referencia'],
            'titulo'      => $c['titulo'],
            'tipo'        => $c['tipo_caso'],
            'estado'      => ucfirst(str_replace('_', ' ', $c['estado'])),
            'abogado'     => $c['abogado_nombre'] ? $c['abogado_nombre'] . ' ' . $c['abogado_apellidos'] : 'Por asignar',
            'apertura'    => date('d/m/Y', strtotime($c['fecha_apertura'])),
            'cierre'      => $c['fecha_cierre'] ? date('d/m/Y', strtotime($c['fecha_cierre'])) : null,
            'honorarios'  => number_format($c['honorarios_totales'], 2),
            'pagado'      => number_format($c['total_pagado'], 2),
            'pendiente'   => number_format($c['honorarios_totales'] - $c['total_pagado'], 2)
        ];
    }, $casos)
];

AuditLog::registrar('consulta_portal', 'clientes', $cliente['id'], 'Consulta del portal del cliente');

echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
