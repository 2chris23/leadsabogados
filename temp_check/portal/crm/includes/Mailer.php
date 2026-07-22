<?php
/**
 * CRM Abogados — Mailer (SMTP nativo sin dependencias)
 * Envía correos HTML usando SMTP con AUTH LOGIN (compatible con Gmail, Outlook, etc.)
 * Los ajustes SMTP se leen de la tabla `configuracion` (o del .env como fallback).
 */

if (!defined('CRM_ROOT')) die('Acceso prohibido');

class Mailer {

    // ── Obtener ajuste SMTP desde BD o constante definida en config.php ──────
    private static function cfg(string $key): string {
        try {
            $db  = Database::getInstance();
            $val = $db->fetchColumn("SELECT valor FROM configuracion WHERE clave = ? AND valor != '' LIMIT 1", [$key]);
            if ($val !== false && $val !== '') return (string)$val;
        } catch (Throwable $e) {}
        // Fallback a constantes del config.php / .env
        return defined(strtoupper($key)) ? constant(strtoupper($key)) : '';
    }

    // ── Obtener plantilla HTML del correo ─────────────────────────────────────
    public static function renderTemplate(string $asunto, string $cuerpoHtml): string {
        $despacho   = self::cfg('nombre_despacho') ?: 'Despacho de Abogados';
        $email      = self::cfg('email_despacho')  ?: '';
        $colorPrim  = self::cfg('email_color_primario')  ?: '#2e6edd';
        $colorBtn   = self::cfg('email_color_boton')     ?: '#2e6edd';
        $colorFondo = self::cfg('email_color_fondo')     ?: '#f1f5f9';
        $colorCard  = self::cfg('email_color_tarjeta')   ?: '#ffffff';
        $colorTexto = self::cfg('email_color_texto')     ?: '#1e293b';
        $colorPie   = self::cfg('email_color_pie')       ?: '#64748b';
        $logoUrl    = self::cfg('email_logo_url')        ?: '';
        $pieTexto   = self::cfg('email_pie_texto')       ?: "© " . date('Y') . " $despacho. Todos los derechos reservados.";

        $logoHtml = $logoUrl
            ? "<img src=\"$logoUrl\" alt=\"$despacho\" style=\"max-height:44px;max-width:160px;object-fit:contain;\">"
            : "<span style=\"font-size:1.125rem;font-weight:800;color:$colorPrim;letter-spacing:-0.01em;\">$despacho</span>";

        $emailPie = $email ? "<br><a href=\"mailto:$email\" style=\"color:$colorPrim;\">$email</a>" : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>$asunto</title>
</head>
<body style="margin:0;padding:0;background:$colorFondo;font-family:'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:$colorFondo;padding:40px 16px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

        <!-- Logo / Cabecera -->
        <tr>
          <td align="center" style="padding-bottom:24px;">
            $logoHtml
          </td>
        </tr>

        <!-- Tarjeta principal -->
        <tr>
          <td style="background:$colorCard;border-radius:16px;padding:40px 40px 32px;box-shadow:0 4px 24px rgba(0,0,0,.07);">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="font-size:0.875rem;color:$colorTexto;line-height:1.7;">
                  $cuerpoHtml
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Pie de página -->
        <tr>
          <td align="center" style="padding-top:24px;font-size:0.75rem;color:$colorPie;line-height:1.6;">
            $pieTexto
            $emailPie
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    // ── Enviar correo por SMTP (sin librerías externas) ───────────────────────
    public static function enviar(string $para, string $asunto, string $cuerpoHtml): array {
        $host    = self::cfg('smtp_host');
        $port    = (int)(self::cfg('smtp_port') ?: 587);
        $user    = self::cfg('smtp_user');
        $pass    = self::cfg('smtp_pass');
        $from    = self::cfg('smtp_from')      ?: $user;
        $name    = self::cfg('smtp_from_name') ?: self::cfg('nombre_despacho') ?: 'CRM Abogados';

        if (!$host || !$user || !$pass || !$para) {
            return ['ok' => false, 'msg' => 'SMTP no configurado. Configure los datos en Ajustes -> Correo.'];
        }

        $html = self::renderTemplate($asunto, $cuerpoHtml);
        $log  = [];

        try {
            // ── Conexión inicial ───────────────────────────────────────────────
            $errno = 0; $errstr = '';
            $timeout = 20;

            if ($port === 465) {
                // SSL directo
                $ctx = stream_context_create(['ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ]]);
                $sock = @stream_socket_client("ssl://$host:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
            } else {
                // TCP plano (luego STARTTLS)
                $sock = @stream_socket_client("tcp://$host:$port", $errno, $errstr, $timeout);
            }

            if (!$sock) {
                return [
                    'ok'  => false,
                    'msg' => "No se pudo conectar al servidor SMTP ($host:$port). Error: $errstr (cod.$errno). Su proveedor de hosting puede bloquear puertos SMTP salientes.",
                    'log' => $log,
                ];
            }
            stream_set_timeout($sock, $timeout);

            $read = function() use ($sock, &$log) {
                $line = '';
                while ($buf = fgets($sock, 1024)) {
                    $line .= $buf;
                    $log[] = '<< ' . rtrim($buf);
                    if (strlen($buf) > 3 && $buf[3] === ' ') break;
                }
                return $line;
            };
            $write = function(string $cmd) use ($sock, &$log) {
                // Ocultar credenciales en el log
                $isCredential = preg_match('/^[A-Za-z0-9+\/=]{16,}$/', trim($cmd));
                $log[] = '>> ' . ($isCredential ? '[CREDENCIAL OCULTA]' : $cmd);
                fwrite($sock, $cmd . "\r\n");
            };

            // Banner de bienvenida
            $banner = $read();
            if (substr($banner, 0, 3) !== '220') {
                fclose($sock);
                return ['ok' => false, 'msg' => "Servidor SMTP rechazó la conexión: " . trim($banner), 'log' => $log];
            }

            $ehlo = $_SERVER['HTTP_HOST'] ?? 'crm.local';
            $write("EHLO $ehlo");
            // Leer EHLO multi-línea
            while ($buf = fgets($sock, 1024)) {
                $log[] = '<< ' . rtrim($buf);
                if (strlen($buf) > 3 && $buf[3] === ' ') break;
            }

            // STARTTLS (para puerto 587 y 25)
            if ($port !== 465) {
                $write('STARTTLS');
                $tlsResp = $read();
                if (substr($tlsResp, 0, 3) !== '220') {
                    fclose($sock);
                    return ['ok' => false, 'msg' => "STARTTLS rechazado por el servidor: " . trim($tlsResp), 'log' => $log];
                }
                stream_context_set_option($sock, 'ssl', 'verify_peer', false);
                stream_context_set_option($sock, 'ssl', 'verify_peer_name', false);
                stream_context_set_option($sock, 'ssl', 'allow_self_signed', true);
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    fclose($sock);
                    return ['ok' => false, 'msg' => 'No se pudo establecer cifrado TLS con el servidor SMTP. Verifique que PHP tenga la extensión OpenSSL activa.', 'log' => $log];
                }
                // Re-saludo después de TLS
                $write("EHLO $ehlo");
                while ($buf = fgets($sock, 1024)) {
                    $log[] = '<< ' . rtrim($buf);
                    if (strlen($buf) > 3 && $buf[3] === ' ') break;
                }
            }

            // AUTH LOGIN
            $write('AUTH LOGIN');
            $challenge1 = $read();
            if (substr($challenge1, 0, 3) !== '334') {
                fclose($sock);
                return ['ok' => false, 'msg' => "AUTH LOGIN rechazado: " . trim($challenge1), 'log' => $log];
            }
            $write(base64_encode($user));
            $read(); // challenge para password
            $write(base64_encode($pass));
            $authResp = $read();
            if (substr($authResp, 0, 3) !== '235') {
                fclose($sock);
                $hint = '';
                $authLower = strtolower($authResp);
                if (str_contains($authLower, '534') || str_contains($authLower, '535')) {
                    $hint = ' NOTA: Para Gmail debe usar una "Contrasena de Aplicacion" en myaccount.google.com/apppasswords — NO su contrasena habitual.';
                }
                return ['ok' => false, 'msg' => "Autenticacion SMTP fallida: " . trim($authResp) . $hint, 'log' => $log];
            }

            // Envío
            $write("MAIL FROM:<$from>");
            $read();
            $write("RCPT TO:<$para>");
            $rcptResp = $read();
            if (substr($rcptResp, 0, 3) !== '250') {
                fclose($sock);
                return ['ok' => false, 'msg' => "Destino rechazado: " . trim($rcptResp), 'log' => $log];
            }

            $write('DATA');
            $dataOk = $read();
            if (substr($dataOk, 0, 3) !== '354') {
                fclose($sock);
                return ['ok' => false, 'msg' => "DATA rechazado: " . trim($dataOk), 'log' => $log];
            }

            $boundary = 'b_' . md5(uniqid());
            $headers  = implode("\r\n", [
                "From: =?UTF-8?B?" . base64_encode($name) . "?= <$from>",
                "To: <$para>",
                "Subject: =?UTF-8?B?" . base64_encode($asunto) . "?=",
                "MIME-Version: 1.0",
                "Content-Type: multipart/alternative; boundary=\"$boundary\"",
                "X-Mailer: CRM-Abogados/1.0",
                "Date: " . date('r'),
            ]);

            $plain = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
            $body  =
                "--$boundary\r\n" .
                "Content-Type: text/plain; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: base64\r\n\r\n" .
                chunk_split(base64_encode($plain)) .
                "--$boundary\r\n" .
                "Content-Type: text/html; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: base64\r\n\r\n" .
                chunk_split(base64_encode($html)) .
                "--$boundary--";

            fwrite($sock, $headers . "\r\n\r\n" . $body . "\r\n.\r\n");
            $dataResp = $read();
            $write('QUIT');
            fclose($sock);

            if (substr($dataResp, 0, 3) !== '250') {
                return ['ok' => false, 'msg' => "El servidor rechazó el mensaje: " . trim($dataResp), 'log' => $log];
            }
            return ['ok' => true, 'msg' => 'Correo enviado correctamente.', 'log' => $log];

        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => 'Error inesperado: ' . $e->getMessage(), 'log' => $log ?? []];
        }
    }

    // ── Métodos de conveniencia por evento ────────────────────────────────────

    /** Bienvenida al registrarse en el portal */
    public static function bienvenidaCliente(string $email, string $nombre, string $portalUrl): array {
        $colorBtn  = self::cfg('email_color_boton') ?: '#2e6edd';

        $subj     = self::cfg('email_subj_registro') ?: '¡Bienvenido a nuestro portal!';
        $bodyText = self::cfg('email_body_registro')  ?: "Hola {{cliente_nombre}},\n\nTu cuenta ha sido creada exitosamente.\n\nYa puedes acceder al portal para consultar tus expedientes y enviar solicitudes.";

        $bodyText = str_replace(
            ['{{cliente_nombre}}', '{{url_portal}}'],
            [htmlspecialchars($nombre), $portalUrl],
            $bodyText
        );

        $body = "
            <div style=\"white-space:pre-wrap;font-size:1rem;color:#1e293b;margin-bottom:24px;\">$bodyText</div>
            <div style=\"text-align:center;margin:28px 0;\">
              <a href=\"$portalUrl\" style=\"background:$colorBtn;color:#fff;text-decoration:none;padding:14px 32px;border-radius:12px;font-weight:700;font-size:0.9375rem;display:inline-block;\">Acceder a Mi Portal</a>
            </div>
        ";
        return self::enviar($email, $subj, $body);
    }

    /** Notificación de solicitud aceptada → caso creado */
    public static function solicitudAceptada(string $email, string $nombre, string $refCaso, string $titulo, string $portalUrl): array {
        $colorBtn  = self::cfg('email_color_boton') ?: '#2e6edd';

        $subj     = self::cfg('email_subj_solicitud') ?: 'Su solicitud ha sido aceptada';
        $bodyText = self::cfg('email_body_solicitud')  ?: "Estimado/a {{cliente_nombre}},\n\nNos complace informarle que su expediente ha sido creado con la referencia {{caso_referencia}}.\n\nNuestro equipo se pondrá en contacto con usted a la brevedad.";

        $subj     = str_replace('{{caso_referencia}}', $refCaso, $subj);
        $bodyText = str_replace(
            ['{{cliente_nombre}}', '{{caso_referencia}}', '{{url_portal}}'],
            [htmlspecialchars($nombre), htmlspecialchars($refCaso), $portalUrl],
            $bodyText
        );

        $body = "
            <div style=\"white-space:pre-wrap;font-size:1rem;color:#1e293b;margin-bottom:24px;\">$bodyText</div>
            <div style=\"text-align:center;margin:28px 0;\">
              <a href=\"$portalUrl\" style=\"background:$colorBtn;color:#fff;text-decoration:none;padding:14px 32px;border-radius:12px;font-weight:700;font-size:0.9375rem;display:inline-block;\">Ver Mi Expediente</a>
            </div>
        ";
        return self::enviar($email, $subj, $body);
    }

    /** Nueva nota añadida a un caso */
    public static function nuevaNota(string $email, string $nombre, string $refCaso, string $contenidoNota, string $portalUrl): array {
        $colorBtn = self::cfg('email_color_boton') ?: '#2e6edd';

        $subj     = self::cfg('email_subj_nota') ?: 'Nueva actualización en su expediente';
        $bodyText = self::cfg('email_body_nota')  ?: "Estimado/a {{cliente_nombre}},\n\nSe ha agregado una nueva nota o actualización a su expediente {{caso_referencia}}.\n\nPuede ver los detalles accediendo a su panel de cliente.";

        $subj     = str_replace('{{caso_referencia}}', $refCaso, $subj);
        $bodyText = str_replace(
            ['{{cliente_nombre}}', '{{caso_referencia}}', '{{url_portal}}'],
            [htmlspecialchars($nombre), htmlspecialchars($refCaso), $portalUrl],
            $bodyText
        );

        $extracto = nl2br(htmlspecialchars(mb_substr($contenidoNota, 0, 500))) . (mb_strlen($contenidoNota) > 500 ? '...' : '');

        $body = "
            <div style=\"white-space:pre-wrap;font-size:1rem;color:#1e293b;margin-bottom:24px;\">$bodyText</div>
            <div style=\"background:#f8fafc;border-left:4px solid #2e6edd;border-radius:0 8px 8px 0;padding:16px 20px;margin:20px 0;font-style:italic;color:#374151;\">
              $extracto
            </div>
            <div style=\"text-align:center;margin:28px 0;\">
              <a href=\"$portalUrl\" style=\"background:$colorBtn;color:#fff;text-decoration:none;padding:14px 32px;border-radius:12px;font-weight:700;font-size:0.9375rem;display:inline-block;\">Ver Expediente Completo</a>
            </div>
        ";
        return self::enviar($email, $subj, $body);
    }

    /** Nuevo documento subido por el abogado */
    public static function nuevoDocumento(string $email, string $nombre, string $refCaso, string $nombreDoc, string $portalUrl): array {
        $colorBtn = self::cfg('email_color_boton') ?: '#2e6edd';

        $subj     = self::cfg('email_subj_documento') ?: 'Nuevo documento en su expediente';
        $bodyText = self::cfg('email_body_documento')  ?: "Estimado/a {{cliente_nombre}},\n\nSe ha subido un nuevo documento ({{documento_nombre}}) a su expediente {{caso_referencia}}.\n\nYa está disponible para su descarga en el portal.";

        $subj     = str_replace(['{{caso_referencia}}', '{{documento_nombre}}'], [$refCaso, $nombreDoc], $subj);
        $bodyText = str_replace(
            ['{{cliente_nombre}}', '{{caso_referencia}}', '{{documento_nombre}}', '{{url_portal}}'],
            [htmlspecialchars($nombre), htmlspecialchars($refCaso), htmlspecialchars($nombreDoc), $portalUrl],
            $bodyText
        );

        $body = "
            <div style=\"white-space:pre-wrap;font-size:1rem;color:#1e293b;margin-bottom:24px;\">$bodyText</div>
            <div style=\"text-align:center;margin:28px 0;\">
              <a href=\"$portalUrl\" style=\"background:$colorBtn;color:#fff;text-decoration:none;padding:14px 32px;border-radius:12px;font-weight:700;font-size:0.9375rem;display:inline-block;\">Descargar Documento</a>
            </div>
        ";
        return self::enviar($email, $subj, $body);
    }

    /** Recuperación / acceso al portal para clientes creados desde el CRM */
    public static function recuperarPasswordPortal(string $email, string $nombre, string $resetLink): array {
        $colorBtn = self::cfg('email_color_boton') ?: '#2e6edd';
        $despacho = self::cfg('nombre_despacho') ?: 'Nuestro Despacho';

        $subj = "Acceso a su Portal de Cliente — $despacho";

        $body = "
            <p style=\"font-size:1rem;color:#1e293b;margin-bottom:12px;\">Hola <strong>" . htmlspecialchars($nombre) . "</strong>,</p>
            <p style=\"font-size:0.9375rem;color:#374151;line-height:1.7;margin-bottom:20px;\">
                Le informamos que se ha creado o actualizado su acceso al portal de clientes de <strong>$despacho</strong>.
                Haga clic en el botón de abajo para establecer su contraseña y acceder a sus expedientes.<br>
                <em style=\"font-size:0.8125rem;color:#94a3b8;\">(El enlace es válido por 2 horas.)</em>
            </p>
            <div style=\"text-align:center;margin:28px 0;\">
              <a href=\"$resetLink\" style=\"background:$colorBtn;color:#fff;text-decoration:none;padding:14px 32px;border-radius:12px;font-weight:700;font-size:0.9375rem;display:inline-block;\">Establecer Contraseña y Acceder</a>
            </div>
            <p style=\"font-size:0.8125rem;color:#94a3b8;margin-top:16px;\">Si no reconoce este mensaje, puede ignorarlo con seguridad.</p>
        ";

        return self::enviar($email, $subj, $body);
    }
}
