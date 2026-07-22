-- =====================================================
-- CRM para Despacho de Abogados - Datos Iniciales
-- =====================================================

USE crm_abogados;

-- Usuario administrador por defecto
-- Email: admin@despacho.com / Password: Admin123!
INSERT INTO usuarios_internos (nombre, apellidos, email, password_hash, rol, activo) VALUES
('Administrador', 'Sistema', 'admin@despacho.com', '$2y$10$VhxQOEcakqU9K6Px/NasguwP4sy7vd.zHL87WBw3Kni/qBGxFEYNC', 'admin', 1);

-- Configuración inicial del sistema
INSERT INTO configuracion (clave, valor, tipo, grupo, descripcion) VALUES
-- General
('nombre_despacho', 'Mi Despacho de Abogados', 'text', 'general', 'Nombre del despacho'),
('email_despacho', 'contacto@despacho.com', 'text', 'general', 'Email principal del despacho'),
('telefono_despacho', '+34 600 000 000', 'text', 'general', 'Teléfono del despacho'),
('direccion_despacho', 'Calle Principal 1, Ciudad', 'text', 'general', 'Dirección del despacho'),

-- Colores del tema
('color_primario', '#487fff', 'color', 'tema', 'Color primario del tema'),
('color_secundario', '#6c757d', 'color', 'tema', 'Color secundario del tema'),
('color_exito', '#28a745', 'color', 'tema', 'Color de éxito/aprobado'),
('color_peligro', '#dc3545', 'color', 'tema', 'Color de peligro/error'),
('color_advertencia', '#ff9f29', 'color', 'tema', 'Color de advertencia'),
('color_info', '#17a2b8', 'color', 'tema', 'Color informativo'),
('color_sidebar', '#1b2431', 'color', 'tema', 'Color de fondo del sidebar'),
('tema_modo', 'light', 'text', 'tema', 'Modo del tema: light o dark'),

-- Seguridad
('sesion_timeout_minutos', '30', 'number', 'seguridad', 'Tiempo de expiración de sesión en minutos'),
('max_intentos_login', '5', 'number', 'seguridad', 'Máximo de intentos de login antes de bloqueo'),
('bloqueo_minutos', '15', 'number', 'seguridad', 'Minutos de bloqueo tras superar intentos'),
('rate_limit_solicitudes', '5', 'number', 'seguridad', 'Máximo de solicitudes por IP por hora'),

-- Email
('smtp_host', '', 'text', 'email', 'Servidor SMTP'),
('smtp_port', '587', 'number', 'email', 'Puerto SMTP'),
('smtp_user', '', 'text', 'email', 'Usuario SMTP'),
('smtp_password', '', 'text', 'email', 'Contraseña SMTP'),
('smtp_from_name', 'CRM Abogados', 'text', 'email', 'Nombre del remitente');
