<?php
/**
 * Bot Telegram — Handler de Clientes
 */
class ClienteHandler
{
    public static function menuPrincipal(int $chatId, TelegramBot $bot, int $clienteId): void
    {
        $bot->setEstado($chatId, 'menu');
        $bot->enviar($chatId,
            "📋 <b>¿Qué deseas hacer?</b>",
            TelegramBot::inlineKeyboard([
                [['text' => '📁 Mis casos',        'callback_data' => 'c_casos']],
                [['text' => '📨 Nueva solicitud',   'callback_data' => 'c_nueva_sol']],
                [['text' => '💶 Mis pagos',          'callback_data' => 'c_pagos']],
                [['text' => '📊 Estado de solicitud','callback_data' => 'c_sol_estado']],
            ])
        );
    }

    public static function procesar(int $chatId, string $texto, array $sesion, TelegramBot $bot): void
    {
        $estado = $sesion['estado'] ?? 'menu';
        $data   = $sesion['estado_data'] ?? [];

        // Flujo de nueva solicitud
        if (strpos($estado, 'sol_') === 0) {
            self::flujoSolicitud($chatId, $texto, $estado, $data, $sesion, $bot);
            return;
        }

        // Volver al menú con cualquier mensaje inesperado
        self::menuPrincipal($chatId, $bot, $sesion['entidad_id']);
    }

    public static function accion(int $chatId, string $data, array $sesion, TelegramBot $bot, int $msgId): void
    {
        $clienteId = $sesion['entidad_id'];

        switch ($data) {
            case 'c_casos':      self::verCasos($chatId, $clienteId, $bot, $msgId); break;
            case 'c_nueva_sol':  self::iniciarSolicitud($chatId, $bot); break;
            case 'c_pagos':      self::verPagos($chatId, $clienteId, $bot, $msgId); break;
            case 'c_sol_estado': self::verSolicitudes($chatId, $clienteId, $bot, $msgId); break;
            case 'c_menu':       self::menuPrincipal($chatId, $bot, $clienteId); break;
        }
    }

    // ── Mis casos ─────────────────────────────────────────────────────────────
    private static function verCasos(int $chatId, int $clienteId, TelegramBot $bot, int $msgId): void
    {
        $db = Database::getInstance();
        $casos = $db->fetchAll(
            "SELECT referencia, titulo, estado, fecha_apertura FROM casos
             WHERE cliente_id=? ORDER BY fecha_apertura DESC LIMIT 10",
            [$clienteId]
        );

        if (!$casos) {
            $bot->editarMensaje($chatId, $msgId, "📁 No tienes casos registrados aún.",
                TelegramBot::inlineKeyboard([[['text' => '↩ Menú', 'callback_data' => 'c_menu']]]));
            return;
        }

        $txt = "📁 <b>Tus casos:</b>\n\n";
        foreach ($casos as $c) {
            $iconos = ['activo'=>'🟢','cerrado'=>'⚫','ganado'=>'✅','perdido'=>'❌'];
            $icono  = $iconos[$c['estado']] ?? '🔵';
            $txt .= "$icono <b>{$c['referencia']}</b> — {$c['titulo']}\n";
            $txt .= "   Estado: {$c['estado']} | Apertura: " . date('d/m/Y', strtotime($c['fecha_apertura'])) . "\n\n";
        }

        $bot->editarMensaje($chatId, $msgId, $txt,
            TelegramBot::inlineKeyboard([[['text' => '↩ Menú', 'callback_data' => 'c_menu']]]));
    }

    // ── Mis pagos ─────────────────────────────────────────────────────────────
    private static function verPagos(int $chatId, int $clienteId, TelegramBot $bot, int $msgId): void
    {
        $db = Database::getInstance();
        $pagos = $db->fetchAll(
            "SELECT p.fecha_pago, p.cantidad, p.concepto, c.referencia
             FROM pagos p JOIN casos c ON p.caso_id=c.id
             WHERE c.cliente_id=? ORDER BY p.fecha_pago DESC LIMIT 10",
            [$clienteId]
        );

        if (!$pagos) {
            $bot->editarMensaje($chatId, $msgId, "💶 No tienes pagos registrados.",
                TelegramBot::inlineKeyboard([[['text' => '↩ Menú', 'callback_data' => 'c_menu']]]));
            return;
        }

        $txt = "💶 <b>Tus pagos recientes:</b>\n\n";
        foreach ($pagos as $p) {
            $txt .= "• <b>€" . number_format($p['cantidad'], 2, ',', '.') . "</b> — {$p['concepto']}\n";
            $txt .= "  Caso: {$p['referencia']} | " . date('d/m/Y', strtotime($p['fecha_pago'])) . "\n\n";
        }

        $bot->editarMensaje($chatId, $msgId, $txt,
            TelegramBot::inlineKeyboard([[['text' => '↩ Menú', 'callback_data' => 'c_menu']]]));
    }

    // ── Estado de solicitudes ──────────────────────────────────────────────────
    private static function verSolicitudes(int $chatId, int $clienteId, TelegramBot $bot, int $msgId): void
    {
        $db = Database::getInstance();
        $cliente = $db->fetchOne("SELECT email FROM clientes WHERE id=?", [$clienteId]);
        $sols = $db->fetchAll(
            "SELECT estado, tipo_problema, created_at FROM solicitudes WHERE email=?
             ORDER BY id DESC LIMIT 5",
            [$cliente['email'] ?? '']
        );

        if (!$sols) {
            $bot->editarMensaje($chatId, $msgId, "📊 No tienes solicitudes registradas.",
                TelegramBot::inlineKeyboard([[['text' => '↩ Menú', 'callback_data' => 'c_menu']]]));
            return;
        }

        $iconos = ['pendiente'=>'🟡','aceptada'=>'✅','denegada'=>'❌','archivada'=>'📦','cancelada'=>'🚫'];
        $txt = "📊 <b>Tus solicitudes:</b>\n\n";
        foreach ($sols as $s) {
            $ic = $iconos[$s['estado']] ?? '🔵';
            $txt .= "$ic {$s['tipo_problema']} — <b>{$s['estado']}</b>\n";
            $txt .= "  " . date('d/m/Y', strtotime($s['created_at'])) . "\n\n";
        }

        $bot->editarMensaje($chatId, $msgId, $txt,
            TelegramBot::inlineKeyboard([[['text' => '↩ Menú', 'callback_data' => 'c_menu']]]));
    }

    // ── Nueva solicitud (flujo por pasos) ─────────────────────────────────────
    private static function iniciarSolicitud(int $chatId, TelegramBot $bot): void
    {
        $bot->setEstado($chatId, 'sol_tipo', []);
        $tipos = ['Derecho penal','Derecho laboral','Derecho civil','Derecho familiar',
                  'Derecho mercantil','Derecho administrativo','Otro'];
        $filas = [];
        foreach (array_chunk($tipos, 2) as $fila) {
            $row = [];
            foreach ($fila as $t) {
                $row[] = ['text' => $t, 'callback_data' => 'sol_tipo_' . md5($t)];
            }
            $filas[] = $row;
        }
        $bot->enviar($chatId, "📨 <b>Nueva Solicitud</b>\n\nElige el tipo de asunto:", TelegramBot::inlineKeyboard($filas));
    }

    /** Continuar flujo según estado */
    private static function flujoSolicitud(int $chatId, string $texto, string $estado, array $data, array $sesion, TelegramBot $bot): void
    {
        if ($estado === 'sol_desc') {
            $data['descripcion'] = $texto;
            // Guardar solicitud
            self::guardarSolicitud($chatId, $data, $sesion, $bot);
        }
    }

    public static function accionSolicitudTipo(int $chatId, string $tipo, TelegramBot $bot): void
    {
        $bot->setEstado($chatId, 'sol_desc', ['tipo' => $tipo]);
        $bot->enviar($chatId, "📝 Describe brevemente tu situación:\n<i>(Escribe en un solo mensaje)</i>");
    }

    private static function guardarSolicitud(int $chatId, array $data, array $sesion, TelegramBot $bot): void
    {
        $db = Database::getInstance();
        $cliente = $db->fetchOne("SELECT * FROM clientes WHERE id=?", [$sesion['entidad_id']]);

        if (!$cliente) { $bot->enviar($chatId, "Error al obtener tus datos."); return; }

        // Generar credenciales de portal
        $portalUser = strtolower(substr($cliente['nombre'],0,3).substr($cliente['apellidos']??'cl',0,3)).rand(100,999);
        $portalPass = bin2hex(random_bytes(4));

        $db->insert('solicitudes', [
            'nombre'               => $cliente['nombre'],
            'apellidos'            => $cliente['apellidos'] ?? '',
            'email'                => $cliente['email'],
            'telefono'             => $cliente['telefono'] ?? '',
            'tipo_problema'        => $data['tipo'] ?? 'Otro',
            'descripcion'          => $data['descripcion'] ?? '',
            'portal_usuario'       => $portalUser,
            'portal_password_hash' => password_hash($portalPass, PASSWORD_DEFAULT),
            'ip_solicitante'       => 'telegram',
        ]);

        $bot->setEstado($chatId, 'menu', []);
        $bot->enviar($chatId,
            "✅ <b>¡Solicitud enviada!</b>\n\n" .
            "El equipo revisará tu caso y te notificaremos aquí mismo cuando haya novedades.\n\n"
        );
        self::menuPrincipal($chatId, $bot, $sesion['entidad_id']);

        // Notificar al admin
        Telegram::enviar(
            "🟡 <b>Nueva solicitud vía Telegram</b>\n" .
            "👤 <b>{$cliente['nombre']} {$cliente['apellidos']}</b>\n" .
            "📋 {$data['tipo']}"
        );
    }
}
