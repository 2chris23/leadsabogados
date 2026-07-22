<?php
/**
 * Bot Telegram — Handler del Personal del Despacho
 */
class AdminHandler
{
    public static function menuPrincipal(int $chatId, TelegramBot $bot): void
    {
        $bot->setEstado($chatId, 'menu', []);
        $bot->enviar($chatId,
            "🏛 <b>Panel del Despacho</b>\n¿Qué deseas hacer?",
            TelegramBot::inlineKeyboard([
                [['text' => '🟡 Solicitudes pendientes', 'callback_data' => 'a_solicitudes']],
                [['text' => '📁 Buscar caso',             'callback_data' => 'a_buscar_caso']],
                [['text' => '📊 Resumen del día',         'callback_data' => 'a_resumen']],
                [['text' => '👥 Buscar cliente',          'callback_data' => 'a_buscar_cliente']],
                [['text' => '💶 Pagos de hoy',            'callback_data' => 'a_pagos_hoy']],
            ])
        );
    }

    public static function procesar(int $chatId, string $texto, array $sesion, TelegramBot $bot): void
    {
        $estado = $sesion['estado'] ?? 'menu';
        $data   = $sesion['estado_data'] ?? [];

        if ($estado === 'buscar_caso') {
            self::resultadoBuscarCaso($chatId, $texto, $bot);
        } elseif ($estado === 'buscar_cliente') {
            self::resultadoBuscarCliente($chatId, $texto, $bot);
        } else {
            self::menuPrincipal($chatId, $bot);
        }
    }

    public static function accion(int $chatId, string $cbData, array $sesion, TelegramBot $bot, int $msgId): void
    {
        // Aceptar / rechazar solicitud
        if (strpos($cbData, 'a_aceptar_') === 0) {
            $solId = (int)substr($cbData, strlen('a_aceptar_'));
            self::cambiarEstadoSolicitud($chatId, $solId, 'aceptada', $bot, $msgId);
            return;
        }
        if (strpos($cbData, 'a_rechazar_') === 0) {
            $solId = (int)substr($cbData, strlen('a_rechazar_'));
            self::cambiarEstadoSolicitud($chatId, $solId, 'denegada', $bot, $msgId);
            return;
        }
        // Tipo de solicitud
        if (strpos($cbData, 'sol_tipo_') === 0) {
            $hash = substr($cbData, strlen('sol_tipo_'));
            $tipos = ['Derecho penal','Derecho laboral','Derecho civil','Derecho familiar',
                      'Derecho mercantil','Derecho administrativo','Otro'];
            foreach ($tipos as $t) {
                if (md5($t) === $hash) {
                    ClienteHandler::accionSolicitudTipo($chatId, $t, $bot);
                    return;
                }
            }
        }

        switch ($cbData) {
            case 'a_solicitudes':    self::verSolicitudes($chatId, $bot, $msgId); break;
            case 'a_buscar_caso':    self::pedirBuscarCaso($chatId, $bot, $msgId); break;
            case 'a_resumen':        self::resumenDia($chatId, $bot, $msgId); break;
            case 'a_buscar_cliente': self::pedirBuscarCliente($chatId, $bot, $msgId); break;
            case 'a_pagos_hoy':      self::pagosHoy($chatId, $bot, $msgId); break;
            case 'a_menu':           self::menuPrincipal($chatId, $bot); break;
        }
    }

    // ── Solicitudes pendientes ─────────────────────────────────────────────────
    private static function verSolicitudes(int $chatId, TelegramBot $bot, int $msgId): void
    {
        $db   = Database::getInstance();
        $sols = $db->fetchAll(
            "SELECT id, nombre, apellidos, tipo_problema, telefono, created_at
             FROM solicitudes WHERE estado='pendiente' ORDER BY id DESC LIMIT 8"
        );

        if (!$sols) {
            $bot->editarMensaje($chatId, $msgId, "✅ No hay solicitudes pendientes.",
                TelegramBot::inlineKeyboard([[['text' => '↩ Menú', 'callback_data' => 'a_menu']]]));
            return;
        }

        foreach ($sols as $s) {
            $fecha = date('d/m/Y H:i', strtotime($s['created_at']));
            $txt   = "🟡 <b>{$s['nombre']} {$s['apellidos']}</b>\n" .
                     "📋 {$s['tipo_problema']}\n" .
                     "📞 {$s['telefono']}\n📅 {$fecha}";
            $bot->enviar($chatId, $txt,
                TelegramBot::inlineKeyboard([[
                    ['text' => '✅ Aceptar',  'callback_data' => "a_aceptar_{$s['id']}"],
                    ['text' => '❌ Rechazar', 'callback_data' => "a_rechazar_{$s['id']}"],
                ]]));
        }
        $numSols = count($sols);
        $bot->enviar($chatId, "Mostrando $numSols solicitud(es) pendiente(s).",
            TelegramBot::inlineKeyboard([[['text' => '↩ Menú', 'callback_data' => 'a_menu']]]));
    }

    private static function cambiarEstadoSolicitud(int $chatId, int $solId, string $nuevoEstado, TelegramBot $bot, int $msgId): void
    {
        $db  = Database::getInstance();
        $sol = $db->fetchOne("SELECT * FROM solicitudes WHERE id=?", [$solId]);
        if (!$sol) { $bot->enviar($chatId, "Solicitud no encontrada."); return; }

        $db->update('solicitudes', [
            'estado' => $nuevoEstado,
        ], 'id=?', [$solId]);

        $icono = $nuevoEstado === 'aceptada' ? '✅' : '❌';
        $bot->editarMensaje($chatId, $msgId,
            "$icono Solicitud de <b>{$sol['nombre']} {$sol['apellidos']}</b> marcada como <b>{$nuevoEstado}</b>.",
            TelegramBot::inlineKeyboard([[['text' => 'Ver más solicitudes', 'callback_data' => 'a_solicitudes']],
                [['text' => '↩ Menú', 'callback_data' => 'a_menu']]])
        );

        // Notificar al cliente si tiene sesión de Telegram
        self::notificarCliente($sol, $nuevoEstado, $bot);
    }

    private static function notificarCliente(array $sol, string $estado, TelegramBot $bot): void
    {
        $db = Database::getInstance();
        // Buscar cliente por email y si tiene sesión Telegram
        $cliente = $db->fetchOne("SELECT id FROM clientes WHERE email=?", [$sol['email']]);
        if (!$cliente) return;

        $sesionCliente = $db->fetchOne(
            "SELECT chat_id FROM telegram_sessions WHERE tipo='cliente' AND entidad_id=?",
            [$cliente['id']]
        );
        if (!$sesionCliente) return;

        $msg = $estado === 'aceptada'
            ? "✅ <b>¡Tu solicitud ha sido aceptada!</b>\nEl equipo se pondrá en contacto contigo pronto.\n\nAsunto: {$sol['tipo_problema']}"
            : "❌ <b>Tu solicitud ha sido denegada.</b>\nAsunto: {$sol['tipo_problema']}\n\n<i>Si tienes dudas, puedes enviar una nueva solicitud.</i>";

        $bot->enviar((int)$sesionCliente['chat_id'], $msg);
    }

    // ── Buscar caso ───────────────────────────────────────────────────────────
    private static function pedirBuscarCaso(int $chatId, TelegramBot $bot, int $msgId): void
    {
        $bot->setEstado($chatId, 'buscar_caso', []);
        $bot->editarMensaje($chatId, $msgId, "🔍 Escribe la referencia o parte del título del caso:");
    }

    private static function resultadoBuscarCaso(int $chatId, string $texto, TelegramBot $bot): void
    {
        $db   = Database::getInstance();
        $like = '%' . $texto . '%';
        $casos = $db->fetchAll(
            "SELECT c.referencia, c.titulo, c.estado, cl.nombre, cl.apellidos
             FROM casos c JOIN clientes cl ON c.cliente_id=cl.id
             WHERE c.referencia LIKE ? OR c.titulo LIKE ?
             LIMIT 5",
            [$like, $like]
        );

        $bot->setEstado($chatId, 'menu', []);
        if (!$casos) {
            $bot->enviar($chatId, "🔍 No se encontraron casos con «{$texto}».",
                TelegramBot::inlineKeyboard([[['text' => '↩ Menú', 'callback_data' => 'a_menu']]]));
            return;
        }

        $txt = "🔍 <b>Resultados para «{$texto}»:</b>\n\n";
        foreach ($casos as $c) {
            $txt .= "• <b>{$c['referencia']}</b> — {$c['titulo']}\n";
            $txt .= "  Cliente: {$c['nombre']} {$c['apellidos']} | Estado: {$c['estado']}\n\n";
        }
        $bot->enviar($chatId, $txt,
            TelegramBot::inlineKeyboard([[['text' => '↩ Menú', 'callback_data' => 'a_menu']]]));
    }

    // ── Buscar cliente ─────────────────────────────────────────────────────────
    private static function pedirBuscarCliente(int $chatId, TelegramBot $bot, int $msgId): void
    {
        $bot->setEstado($chatId, 'buscar_cliente', []);
        $bot->editarMensaje($chatId, $msgId, "👥 Escribe el nombre, apellido o email del cliente:");
    }

    private static function resultadoBuscarCliente(int $chatId, string $texto, TelegramBot $bot): void
    {
        $db   = Database::getInstance();
        $like = '%' . $texto . '%';
        $clientes = $db->fetchAll(
            "SELECT nombre, apellidos, email, telefono,
                    (SELECT COUNT(*) FROM casos WHERE cliente_id=clientes.id) as num_casos
             FROM clientes
             WHERE nombre LIKE ? OR apellidos LIKE ? OR email LIKE ?
             LIMIT 5",
            [$like, $like, $like]
        );

        $bot->setEstado($chatId, 'menu', []);
        if (!$clientes) {
            $bot->enviar($chatId, "👥 No se encontraron clientes con «{$texto}».",
                TelegramBot::inlineKeyboard([[['text' => '↩ Menú', 'callback_data' => 'a_menu']]]));
            return;
        }

        $txt = "👥 <b>Resultados:</b>\n\n";
        foreach ($clientes as $c) {
            $txt .= "• <b>{$c['nombre']} {$c['apellidos']}</b>\n";
            $txt .= "  📧 {$c['email']}\n  📞 {$c['telefono']}\n  📁 {$c['num_casos']} caso(s)\n\n";
        }
        $bot->enviar($chatId, $txt,
            TelegramBot::inlineKeyboard([[['text' => '↩ Menú', 'callback_data' => 'a_menu']]]));
    }

    // ── Resumen del día ────────────────────────────────────────────────────────
    private static function resumenDia(int $chatId, TelegramBot $bot, int $msgId): void
    {
        $db = Database::getInstance();
        $hoy = date('Y-m-d');

        $solicitudes = $db->fetchColumn("SELECT COUNT(*) FROM solicitudes WHERE DATE(created_at)=?", [$hoy]);
        $casos       = $db->fetchColumn("SELECT COUNT(*) FROM casos WHERE estado='activo'", []);
        $pagosHoy    = $db->fetchColumn("SELECT COALESCE(SUM(cantidad),0) FROM pagos WHERE DATE(fecha_pago)=? AND (tipo_pago IS NULL OR tipo_pago != 'pago_abogado')", [$hoy]);
        $pendientes  = $db->fetchColumn("SELECT COUNT(*) FROM solicitudes WHERE estado='pendiente'", []);

        $txt = "📊 <b>Resumen de hoy — " . date('d/m/Y') . "</b>\n\n" .
               "🟡 Solicitudes hoy: <b>$solicitudes</b>\n" .
               "⏳ Pendientes de revisión: <b>$pendientes</b>\n" .
               "📁 Casos activos: <b>$casos</b>\n" .
               "💶 Cobrado hoy: <b>€" . number_format($pagosHoy, 2, ',', '.') . "</b>";

        $bot->editarMensaje($chatId, $msgId, $txt,
            TelegramBot::inlineKeyboard([[['text' => '↩ Menú', 'callback_data' => 'a_menu']]]));
    }

    // ── Pagos de hoy ──────────────────────────────────────────────────────────
    private static function pagosHoy(int $chatId, TelegramBot $bot, int $msgId): void
    {
        $db   = Database::getInstance();
        $hoy  = date('Y-m-d');
        $pagos = $db->fetchAll(
            "SELECT p.cantidad, p.concepto, c.referencia, cl.nombre, cl.apellidos
             FROM pagos p JOIN casos c ON p.caso_id=c.id JOIN clientes cl ON c.cliente_id=cl.id
             WHERE DATE(p.fecha_pago)=? ORDER BY p.id DESC",
            [$hoy]
        );

        if (!$pagos) {
            $bot->editarMensaje($chatId, $msgId, "💶 No hay pagos registrados hoy.",
                TelegramBot::inlineKeyboard([[['text' => '↩ Menú', 'callback_data' => 'a_menu']]]));
            return;
        }

        $total = array_sum(array_column($pagos, 'cantidad'));
        $txt   = "💶 <b>Pagos de hoy:</b>\n\n";
        foreach ($pagos as $p) {
            $txt .= "• €" . number_format($p['cantidad'],2,',','.') . " — {$p['concepto']}\n";
            $txt .= "  {$p['nombre']} {$p['apellidos']} | {$p['referencia']}\n\n";
        }
        $txt .= "<b>Total: €" . number_format($total,2,',','.') . "</b>";

        $bot->editarMensaje($chatId, $msgId, $txt,
            TelegramBot::inlineKeyboard([[['text' => '↩ Menú', 'callback_data' => 'a_menu']]]));
    }
}
