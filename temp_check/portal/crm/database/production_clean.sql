-- =====================================================
-- CRM Abogados - Schema LIMPIO para Producción
-- Generado: 2026-05-08
-- Solo incluye el Super Admin, sin datos de prueba
-- =====================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- LIMPIAR DATOS EXISTENTES (mantener estructura)
-- =====================================================
DROP TABLE IF EXISTS pagos_programados;
DROP TABLE IF EXISTS solicitud_archivos;
DROP TABLE IF EXISTS documentos;
DROP TABLE IF EXISTS pagos;
DROP TABLE IF EXISTS casos;
DROP TABLE IF EXISTS clientes;
DROP TABLE IF EXISTS solicitudes;
DROP TABLE IF EXISTS portal_cuentas;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS configuracion;
DROP TABLE IF EXISTS usuarios_internos;

-- =====================================================
-- Tabla: usuarios_internos
-- =====================================================
CREATE TABLE usuarios_internos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    apellidos VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('admin', 'abogado', 'gestor') NOT NULL DEFAULT 'gestor',
    telefono VARCHAR(20) DEFAULT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    intentos_fallidos INT NOT NULL DEFAULT 0,
    bloqueado_hasta DATETIME DEFAULT NULL,
    ultimo_login DATETIME DEFAULT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    tipo_pago_predeterminado VARCHAR(20) DEFAULT 'mensual',
    tarifa_mensual_default DECIMAL(10,2) DEFAULT 0,
    tarifa_fija_default DECIMAL(10,2) DEFAULT 0,
    tarifa_exito_default DECIMAL(10,2) DEFAULT 0,
    dia_pago_mensual INT DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_rol (rol),
    INDEX idx_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Tabla: solicitudes
-- =====================================================
CREATE TABLE solicitudes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    apellidos VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL,
    telefono VARCHAR(20) DEFAULT NULL,
    tipo_problema VARCHAR(100) NOT NULL,
    descripcion TEXT NOT NULL,
    estado ENUM('pendiente', 'aceptada', 'denegada', 'archivada', 'cancelada') NOT NULL DEFAULT 'pendiente',
    motivo_estado TEXT DEFAULT NULL,
    portal_usuario VARCHAR(100) DEFAULT NULL,
    portal_password_hash VARCHAR(255) DEFAULT NULL,
    email_verificado TINYINT(1) NOT NULL DEFAULT 0,
    token_verificacion VARCHAR(255) DEFAULT NULL,
    ip_solicitante VARCHAR(45) DEFAULT NULL,
    portal_cuenta_id INT DEFAULT NULL,
    procesada_por INT DEFAULT NULL,
    abogado_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_estado (estado),
    INDEX idx_email (email),
    INDEX idx_created (created_at),
    INDEX idx_portal_cuenta (portal_cuenta_id),
    INDEX idx_abogado_solicitud (abogado_id),
    FOREIGN KEY (procesada_por) REFERENCES usuarios_internos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Tabla: clientes
-- =====================================================
CREATE TABLE clientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    solicitud_id INT NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    apellidos VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL,
    telefono VARCHAR(20) DEFAULT NULL,
    direccion TEXT DEFAULT NULL,
    dni_nif VARCHAR(20) DEFAULT NULL,
    notas TEXT DEFAULT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_nombre (nombre, apellidos),
    FOREIGN KEY (solicitud_id) REFERENCES solicitudes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Tabla: casos
-- =====================================================
CREATE TABLE casos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    abogado_id INT DEFAULT NULL,
    titulo VARCHAR(255) NOT NULL,
    tipo_caso VARCHAR(100) NOT NULL,
    descripcion TEXT DEFAULT NULL,
    referencia VARCHAR(50) DEFAULT NULL UNIQUE,
    estado ENUM('en_estudio', 'en_proceso', 'en_tramitacion', 'pendiente_juicio', 'cerrado', 'archivado') NOT NULL DEFAULT 'en_estudio',
    honorarios_totales DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    honorarios_abogado DECIMAL(10,2) DEFAULT 0,
    tipo_pago_abogado VARCHAR(20) DEFAULT 'fijo',
    cuota_abogado DECIMAL(10,2) DEFAULT 0,
    plan_pago TEXT DEFAULT NULL,
    tipo_pago_cliente VARCHAR(50) DEFAULT NULL,
    frecuencia_pago VARCHAR(30) DEFAULT NULL,
    fecha_apertura DATE NOT NULL,
    fecha_cierre DATE DEFAULT NULL,
    notas_internas TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_estado (estado),
    INDEX idx_cliente (cliente_id),
    INDEX idx_abogado (abogado_id),
    INDEX idx_referencia (referencia),
    INDEX idx_fecha_apertura (fecha_apertura),
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE RESTRICT,
    FOREIGN KEY (abogado_id) REFERENCES usuarios_internos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Tabla: pagos
-- =====================================================
CREATE TABLE pagos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    caso_id INT NOT NULL,
    fecha_pago DATE NOT NULL,
    cantidad DECIMAL(10, 2) NOT NULL,
    concepto VARCHAR(255) NOT NULL,
    metodo_pago ENUM('efectivo', 'transferencia', 'tarjeta', 'cheque', 'domiciliado', 'otro') NOT NULL DEFAULT 'transferencia',
    tipo_pago VARCHAR(30) DEFAULT 'unico',
    notas TEXT DEFAULT NULL,
    registrado_por INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_caso (caso_id),
    INDEX idx_fecha (fecha_pago),
    FOREIGN KEY (caso_id) REFERENCES casos(id) ON DELETE RESTRICT,
    FOREIGN KEY (registrado_por) REFERENCES usuarios_internos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Tabla: pagos_programados
-- =====================================================
CREATE TABLE pagos_programados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    caso_id INT NOT NULL,
    numero_cuota INT DEFAULT 1,
    fecha_vencimiento DATE NOT NULL,
    monto DECIMAL(10,2) NOT NULL DEFAULT 0,
    concepto VARCHAR(255) DEFAULT '',
    estado ENUM('pendiente','pagado','vencido') DEFAULT 'pendiente',
    pago_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_caso (caso_id),
    INDEX idx_fecha (fecha_vencimiento),
    INDEX idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Tabla: documentos
-- =====================================================
CREATE TABLE documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    caso_id INT NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    nombre_archivo VARCHAR(255) NOT NULL,
    ruta VARCHAR(500) NOT NULL,
    tipo_mime VARCHAR(100) DEFAULT NULL,
    tamano_bytes BIGINT DEFAULT NULL,
    descripcion VARCHAR(255) DEFAULT NULL,
    subido_por INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_caso (caso_id),
    FOREIGN KEY (caso_id) REFERENCES casos(id) ON DELETE RESTRICT,
    FOREIGN KEY (subido_por) REFERENCES usuarios_internos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Tabla: solicitud_archivos
-- =====================================================
CREATE TABLE solicitud_archivos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    solicitud_id INT NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    nombre_archivo VARCHAR(255) NOT NULL,
    ruta VARCHAR(500) NOT NULL,
    tipo_mime VARCHAR(100),
    tamano_bytes BIGINT,
    subido_por_cliente TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (solicitud_id) REFERENCES solicitudes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Tabla: portal_cuentas
-- =====================================================
CREATE TABLE portal_cuentas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    apellidos VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    telefono VARCHAR(20) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    es_cliente TINYINT(1) NOT NULL DEFAULT 0,
    cliente_id INT DEFAULT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    ip_registro VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Tabla: audit_log
-- =====================================================
CREATE TABLE audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT DEFAULT NULL,
    usuario_nombre VARCHAR(255) DEFAULT NULL,
    accion VARCHAR(100) NOT NULL,
    tabla_afectada VARCHAR(100) DEFAULT NULL,
    registro_id INT DEFAULT NULL,
    detalles TEXT DEFAULT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_usuario (usuario_id),
    INDEX idx_accion (accion),
    INDEX idx_tabla (tabla_afectada),
    INDEX idx_created (created_at),
    FOREIGN KEY (usuario_id) REFERENCES usuarios_internos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Tabla: configuracion
-- =====================================================
CREATE TABLE configuracion (
    clave VARCHAR(100) PRIMARY KEY,
    valor TEXT NOT NULL,
    tipo ENUM('text', 'color', 'number', 'boolean', 'json') NOT NULL DEFAULT 'text',
    grupo VARCHAR(50) NOT NULL DEFAULT 'general',
    descripcion VARCHAR(255) DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- DATOS INICIALES
-- =====================================================

-- Super Admin (password: 4T7pu*17m)
-- Hash generado con password_hash('4T7pu*17m', PASSWORD_DEFAULT)
INSERT INTO usuarios_internos (nombre, apellidos, email, password_hash, rol, activo)
VALUES ('Super', 'Admin', 'staff-abogado@superadmin.com', '$2y$10$NbKkyeVj6J4STf1yDGYxGeP4WPfbSTQa/mqBM.j67ElwutAE5jin6', 'admin', 1);

-- Configuración por defecto del despacho
INSERT INTO configuracion (clave, valor, tipo, grupo) VALUES
('nombre_despacho', 'Despacho de Abogados', 'text', 'general'),
('email_despacho', 'staff-abogado@superadmin.com', 'text', 'general'),
('telefono_despacho', '', 'text', 'general'),
('direccion_despacho', '', 'text', 'general'),
('color_primario', '#487fff', 'color', 'general'),
('color_secundario', '#6c757d', 'color', 'general'),
('color_exito', '#28a745', 'color', 'general'),
('color_peligro', '#dc3545', 'color', 'general'),
('color_advertencia', '#ff9f29', 'color', 'general'),
('color_info', '#17a2b8', 'color', 'general'),
('color_sidebar', '#1b2431', 'color', 'general'),
('sesion_timeout_minutos', '30', 'number', 'general'),
('max_intentos_login', '5', 'number', 'general');
