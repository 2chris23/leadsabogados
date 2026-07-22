-- =====================================================
-- MIGRATION: Agregar columna abogado_id a solicitudes
-- Ejecutar en phpMyAdmin de InfinityFree
-- =====================================================

-- 1. Agregar la columna (si no existe)
ALTER TABLE solicitudes
    ADD COLUMN abogado_id INT DEFAULT NULL AFTER procesada_por;

-- 2. Agregar índice para mejor rendimiento
ALTER TABLE solicitudes
    ADD INDEX idx_abogado_solicitud (abogado_id);

-- Verificar que se agregó correctamente:
-- DESCRIBE solicitudes;
