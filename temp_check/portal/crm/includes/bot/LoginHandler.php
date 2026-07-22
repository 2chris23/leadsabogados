<?php
/**
 * Bot Telegram — Handler de Login
 * - Pide email y contraseña
 * - Diferencia personal (tabla usuarios) de clientes (tabla portal_clientes)
 * - Límite de 5 intentos → bloqueo 15 min para clientes, 3 intentos para personal
 */
class LoginHandler
{
    private const MAX_INTENTOS = 5;

    public static function inicio(int $chatId): void
    {
        $db = Database::getInstance();
        $db->query(
            "INSERT INTO telegram_sessions (chat_id, tipo, entidad_id, estado, expires_at)
             VALUES (?, 'usuario', 0, 'esperando_email', DATE_ADD(NOW(), INTERVAL 10 MINUTE))
             ON DUPLICATE KEY UPDATE tipo='usuario', entidad_id=0, estado='esperando_email',
             estado_data=NULL, expires_at=DATE_ADD(NOW(), INTERVAL 10 MINUTE)",
            [$chatId]
        );
        $bot = new TelegramBot();
        $bot->enviar($chatId,
            "👋 <b>Bienvenido al CRM — Despacho de Abogados</b>\n\n" .
            "Introduce tu <b>correo electrónico</b>:"
        );
    }

    public static function procesar(int $chatId, string $texto, TelegramBot $bot): void
    {
        $db = Database::getInstance();
        $s  = $db->fetchOne("SELECT * FROM telegram_sessions WHERE chat_id=?", [$chatId]);
        $estado = $s['estado'] ?? '';
        $data   = json_decode($s['estado_data'] ?? '{}', true) ?: [];

        // ── Esperando email ────────────────────────────────────────────────
        if ($estado === '' || $estado === 'esperando_email') {
            if (!filter_var($texto, FILTER_VALIDATE_EMAIL)) {
                $bot->enviar($chatId, "⚠️ Eso no parece un correo válido. Inténtalo de nuevo:");
                return;
            }
            $db->query(
                "INSERT INTO telegram_sessions (chat_id, tipo, entidad_id, estado, estado_data, expires_at)
                 VALUES (?, 'usuario', 0, 'esperando_password', ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))
                 ON DUPLICATE KEY UPDATE estado='esperando_password',
                 estado_data=VALUES(estado_data), expires_at=VALUES(expires_at)",
                [$chatId, json_encode(['email' => strtolower(trim($texto)), 'intentos' => 0])]
            );
            $bot->enviar($chatId, "🔑 Ahora introduce tu <b>contraseña</b>:");
            return;
        }

        // ── Esperando contraseña ───────────────────────────────────────────
        if ($estado === 'esperando_password') {
            $email    = $data['email'] ?? '';
            $intentos = (int)($data['intentos'] ?? 0);

            // 1. Buscar en PERSONAL DEL DESPACHO
            $usuario = $db->fetchOne("SELECT * FROM usuarios_internos WHERE email=? AND activo=1", [$email]);
            if ($usuario) {
                // Verificar bloqueo del personal
                if (!empty($usuario['bloqueado_hasta']) && strtotime($usuario['bloqueado_hasta']) > time()) {
                    $min = ceil((strtotime($usuario['bloqueado_hasta']) - time()) / 60);
                    $bot->enviar($chatId, "🔒 Cuenta bloqueada. Intenta en {$min} minuto(s).");
                    $db->query("UPDATE telegram_sessions SET estado='esperando_email', estado_data=NULL WHERE chat_id=?", [$chatId]);
                    return;
                }
                if (password_verify($texto, $usuario['password_hash'])) {
                    // Reset intentos
                    $db->query("UPDATE usuarios_internos SET intentos_fallidos=0, bloqueado_hasta=NULL, ultimo_login=NOW() WHERE id=?", [$usuario['id']]);
                $bot->crearSesion($chatId, 'usuario', $usuario['id']);
                    $bot->enviar($chatId,
                        "✅ <b>Bienvenido, {$usuario['nombre']}!</b>\n" .
                        "Sesión como <b>personal del despacho</b>. Sesión activa 7 días.\n\n" .
                        "Escribe /salir para cerrar sesión."
                    );
                    AdminHandler::menuPrincipal($chatId, $bot);
                    return;
                } else {
                    // Contraseña incorrecta para personal
                    $ni      = $usuario['intentos_fallidos'] + 1;
                    $bloqueo = $ni >= self::MAX_INTENTOS ? date('Y-m-d H:i:s', strtotime('+15 minutes')) : null;
                    $db->query("UPDATE usuarios_internos SET intentos_fallidos=?, bloqueado_hasta=? WHERE id=?", [$ni, $bloqueo, $usuario['id']]);
                }
            }

            // 2. Buscar en CLIENTES DEL PORTAL
            $cuenta = $db->fetchOne("SELECT * FROM portal_clientes WHERE email=?", [$email]);
            if ($cuenta) {
                // Verificar bloqueo
                if (!empty($cuenta['bloqueado_hasta']) && strtotime($cuenta['bloqueado_hasta']) > time()) {
                    $min = ceil((strtotime($cuenta['bloqueado_hasta']) - time()) / 60);
                    $bot->enviar($chatId, "🔒 Cuenta bloqueada. Intenta en {$min} minuto(s).");
                    // Resetear sesión de login
                    $db->query("UPDATE telegram_sessions SET estado='esperando_email', estado_data=NULL WHERE chat_id=?", [$chatId]);
                    return;
                }

                if (password_verify($texto, $cuenta['password_hash'])) {
                    if (!$cuenta['verificado']) {
                        $bot->enviar($chatId,
                            "⚠️ Tu cuenta aún no está verificada por email.\n" .
                            "Verifica en: " . APP_URL . "/public/portal.php?v=verificar"
                        );
                        $db->query("UPDATE telegram_sessions SET estado='esperando_email', estado_data=NULL WHERE chat_id=?", [$chatId]);
                        return;
                    }
                    $db->update('portal_clientes', [
                        'intentos_login' => 0, 'bloqueado_hasta' => null, 'ultimo_login' => date('Y-m-d H:i:s')
                    ], 'id=?', [$cuenta['id']]);
                    $cliente   = $db->fetchOne("SELECT * FROM clientes WHERE email=?", [$email]);
                    $entidadId = $cliente['id'] ?? $cuenta['id'];
                    $bot->crearSesion($chatId, 'cliente', $entidadId);
                    $bot->enviar($chatId,
                        "✅ <b>Bienvenido, {$cuenta['nombre']}!</b>\n" .
                        "Sesión como <b>cliente</b>. Activa 7 días.\n\n" .
                        "Escribe /salir para cerrar sesión."
                    );
                    ClienteHandler::menuPrincipal($chatId, $bot, $entidadId);
                    return;
                } else {
                    // Contraseña incorrecta → incrementar intentos en portal_clientes
                    $ni      = $cuenta['intentos_login'] + 1;
                    $bloqueo = $ni >= self::MAX_INTENTOS ? date('Y-m-d H:i:s', strtotime('+15 minutes')) : null;
                    $db->update('portal_clientes', ['intentos_login' => $ni, 'bloqueado_hasta' => $bloqueo], 'id=?', [$cuenta['id']]);
                }
            }

            // Credenciales inválidas — contar intentos globales del bot
            $intentos++;
            $restantes = self::MAX_INTENTOS - $intentos;

            if ($intentos >= self::MAX_INTENTOS) {
                // Borrar sesión temporal → bloqueo de 15 min
                $db->query("DELETE FROM telegram_sessions WHERE chat_id=?", [$chatId]);
                $db->query(
                    "INSERT INTO telegram_sessions (chat_id, tipo, entidad_id, estado, expires_at)
                     VALUES (?, 'usuario', 0, 'bloqueado', DATE_ADD(NOW(), INTERVAL 15 MINUTE))
                     ON DUPLICATE KEY UPDATE estado='bloqueado', expires_at=DATE_ADD(NOW(), INTERVAL 15 MINUTE)",
                    [$chatId]
                );
                $bot->enviar($chatId,
                    "🚫 <b>Demasiados intentos fallidos.</b>\n\n" .
                    "Acceso bloqueado 15 minutos. Vuelve a intentarlo después."
                );
                return;
            }

            $bot->enviar($chatId, "❌ Correo o contraseña incorrectos. Te quedan <b>{$restantes}</b> intento(s).\n\nIntroduce tu contraseña de nuevo:");
            // Guardar intentos actualizados
            $db->query(
                "UPDATE telegram_sessions SET estado_data=? WHERE chat_id=?",
                [json_encode(['email' => $email, 'intentos' => $intentos]), $chatId]
            );
        }

        // Estado bloqueado
        if ($estado === 'bloqueado') {
            $bot->enviar($chatId, "🚫 Acceso bloqueado temporalmente. Espera unos minutos y escribe /start.");
        }
    }
}
