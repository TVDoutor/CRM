-- ============================================================
-- TV Doutor CRM — Migração: Etiquetas (labels) para equipamentos
-- MySQL 5.7 compatível — Execute UMA VEZ no banco
-- ============================================================

-- Execute apenas uma vez. Se der erro "Duplicate column", a coluna já existe.
ALTER TABLE `equipment`
    ADD COLUMN `custom_labels` TEXT NULL
    COMMENT 'JSON array de etiquetas manuais, ex: ["2º ENVIO","CANCELADO"]'
    AFTER `contract_type`;
