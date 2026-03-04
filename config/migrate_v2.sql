-- ============================================================
-- TV Doutor CRM — Migração v2 (melhorias do PRD)
-- MySQL 5.7 compatível — Execute UMA VEZ no banco tvdout68_crm
-- ============================================================

-- 1. Renovação de garantia
-- Adiciona coluna warranty_extended_until em equipment
-- (ignorar erro "Duplicate column name" se já existir)
ALTER TABLE `equipment`
    ADD COLUMN `warranty_extended_until` DATE NULL DEFAULT NULL
    AFTER `purchase_date`;

-- 2. Fotos de equipamentos
CREATE TABLE IF NOT EXISTS `equipment_photos` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `equipment_id`  INT UNSIGNED NOT NULL,
    `filename`      VARCHAR(255) NOT NULL,
    `original_name` VARCHAR(255) NULL,
    `uploaded_by`   INT UNSIGNED NOT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_eq_photo` (`equipment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Após executar o SQL, crie o diretório de uploads via FTP/cPanel:
--   Caminho: /home2/tvdout68/crm.tvdoutor.com.br/uploads/equipment/
--   Permissões: 755
-- ============================================================
