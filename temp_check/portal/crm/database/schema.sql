-- =====================================================
-- CRM para Despacho de Abogados - Esquema de Base de Datos
-- Version 1.0
-- =====================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

CREATE DATABASE IF NOT EXISTS crm_abogados
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE crm_abogados;

-- =====================================================
-- Tabla: usuarios_internos
-- Gestiona cuentas de abogados, gestores y administradores
-- =====================================================
CREATE TABLE IF NOT EXISTS usuarios_internos (
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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_rol (rol),
    INDEX idx_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Tabla: solicitudes
-- Almacena solicitudes entrantes antes de ser procesadas
-- =====================================================
CREATE TABLE IF NOT EXISTS solicitudes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    -- Datos del solicitante
    nombre VARCHAR(100) NOT NULL,
    apellidos VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL,
    telefono VARCHAR(20) DEFAULT NULL,
    -- Datos del problema
    tipo_problema VARCHAR(100) NOT NULL,
    descripcion TEXT NOT NULL,
    -- Estado de la solicitud
    estado ENUM('pendiente', 'aceptada', 'denegada', 'archivada', 'cancelada') NOT NULL DEFAULT 'pendiente',
    motivo_estado TEXT DEFAULT NULL,
    -- Credenciales generadas para el portal del cliente
    portal_usuario VARCHAR(100) DEFAULT NULL,
    portal_password_hash VARCHAR(255) DEFAULT NULL,
    email_verificado TINYINT(1) NOT NULL DEFAULT 0,
    token_verificacion VARCHAR(255) DEFAULT NULL,
    -- Metadatos
    ip_solicitante VARCHAR(45) DEFAULT NULL,
    procesada_por INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_estado (estado),
    INDEX idx_email (email),
    INDEX idx_created (created_at),
    FOREIGN KEY (procesada_por) REFERENCES usuarios_internos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Tabla: clientes
-- Ficha completa del cliente, creada desde solicitud aceptada
-- =====================================================
CREATE TABLE IF NOT EXISTS clientes (
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
-- Vinculado a un cliente, con ciclo de vida propio
-- =====================================================
CREATE TABLE IF NOT EXISTS casos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    abogado_id INT DEFAULT NULL,
    -- Datos del caso
    titulo VARCHAR(255) NOT NULL,
    tipo_caso VARCHAR(100) NOT NULL,
    descripcion TEXT DEFAULT NULL,
    referencia VARCHAR(50) DEFAULT NULL UNIQUE,
    -- Estado del caso
    estado ENUM('en_estudio', 'en_proceso', 'en_tramitacion', 'pendiente_juicio', 'cerrado', 'archivado') NOT NULL DEFAULT 'en_estudio',
    -- Financiero
    honorarios_totales DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    plan_pago TEXT DEFAULT NULL,
    -- Fechas
    fecha_apertura DATE NOT NULL,
    fecha_cierre DATE DEFAULT NULL,
    -- Metadatos
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
-- Registro individual de pagos por caso
-- =====================================================
CREATE TABLE IF NOT EXISTS pagos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    caso_id INT NOT NULL,
    fecha_pago DATE NOT NULL,
    cantidad DECIMAL(10, 2) NOT NULL,
    concepto VARCHAR(255) NOT NULL,
    metodo_pago ENUM('efectivo', 'transferencia', 'tarjeta', 'cheque', 'otro') NOT NULL DEFAULT 'transferencia',
    notas TEXT DEFAULT NULL,
    registrado_por INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_caso (caso_id),
    INDEX idx_fecha (fecha_pago),
    FOREIGN KEY (caso_id) REFERENCES casos(id) ON DELETE RESTRICT,
    FOREIGN KEY (registrado_por) REFERENCES usuarios_internos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Tabla: documentos
-- Archivos adjuntos por caso
-- =====================================================
CREATE TABLE IF NOT EXISTS documentos (
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
-- Tabla: audit_log
-- Registro de auditoría de todas las acciones
-- =====================================================
CREATE TABLE IF NOT EXISTS audit_log (
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
-- Ajustes del sistema (colores, nombre del despacho, etc.)
-- =====================================================
CREATE TABLE IF NOT EXISTS configuracion (
    clave VARCHAR(100) PRIMARY KEY,
    valor TEXT NOT NULL,
    tipo ENUM('text', 'color', 'number', 'boolean', 'json') NOT NULL DEFAULT 'text',
    grupo VARCHAR(50) NOT NULL DEFAULT 'general',
    descripcion VARCHAR(255) DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
