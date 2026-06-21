-- migracion_v22.sql
-- Agrega EMF (Empresa de Monitoreo de Flota) a empresa y manifiesto.
ALTER TABLE maestro_empresa  ADD COLUMN IF NOT EXISTS emf VARCHAR(20) NULL COMMENT 'NIT empresa monitoreo flota [NITMONITOREOFLOTA]';
ALTER TABLE manifiesto      ADD COLUMN IF NOT EXISTS emf VARCHAR(20) NULL COMMENT '[NITMONITOREOFLOTA]';
