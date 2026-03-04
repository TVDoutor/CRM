-- ============================================================
-- TV Doutor CRM — Migração v3: Tabela de Lotes
-- MySQL 5.7 compatível — Execute UMA VEZ no banco tvdout68_crm
-- ============================================================

-- 1. Criar tabela batches (lotes)
CREATE TABLE IF NOT EXISTS `batches` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(100) NOT NULL COMMENT 'Ex: 092025, Lote Março 2026',
    `description`   TEXT NULL,
    `received_at`   DATE NULL COMMENT 'Data de recebimento do lote',
    `created_by`    INT UNSIGNED NOT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_batch_name` (`name`),
    INDEX `idx_batch_received` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Adicionar coluna batch_id em equipment (FK para batches)
ALTER TABLE `equipment`
    ADD COLUMN `batch_id` INT UNSIGNED NULL DEFAULT NULL
    AFTER `batch`;

-- 3. Migrar dados existentes: criar lotes a partir dos valores de batch já cadastrados
--    e preencher batch_id automaticamente
INSERT IGNORE INTO `batches` (`name`, `received_at`, `created_by`)
SELECT DISTINCT
    `batch`,
    MIN(`entry_date`),
    MIN(`created_by`)
FROM `equipment`
WHERE `batch` IS NOT NULL AND `batch` != ''
GROUP BY `batch`;

UPDATE `equipment` e
JOIN `batches` b ON b.`name` = e.`batch`
SET e.`batch_id` = b.`id`
WHERE e.`batch` IS NOT NULL AND e.`batch` != '';

-- 4. Adicionar FK (após migrar os dados)
ALTER TABLE `equipment`
    ADD CONSTRAINT `fk_equipment_batch`
    FOREIGN KEY (`batch_id`) REFERENCES `batches`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE;
