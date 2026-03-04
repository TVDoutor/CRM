-- Tabela de projetos sincronizados do Pipedrive
-- Execute no MySQL do HostGator via phpMyAdmin

CREATE TABLE IF NOT EXISTS `pipedrive_projects` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pipedrive_id`      INT UNSIGNED NOT NULL UNIQUE,
    `title`             VARCHAR(255) NOT NULL,
    `client_code`       VARCHAR(50) DEFAULT NULL,
    `asset_tag`         VARCHAR(20) DEFAULT NULL,
    `client_id`         INT UNSIGNED DEFAULT NULL,
    `pipedrive_org_id`  INT UNSIGNED DEFAULT NULL,
    `board_id`          INT UNSIGNED DEFAULT NULL,
    `phase_id`          INT UNSIGNED DEFAULT NULL,
    `phase_name`        VARCHAR(100) DEFAULT NULL,
    `status`            ENUM('open','completed','canceled','deleted') NOT NULL DEFAULT 'open',
    `start_date`        DATE DEFAULT NULL,
    `end_date`          DATE DEFAULT NULL,
    `description`       TEXT DEFAULT NULL,
    `labels`            VARCHAR(255) DEFAULT NULL,
    `owner_id`          INT UNSIGNED DEFAULT NULL,
    `pipedrive_synced_at` DATETIME DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_client_id`      (`client_id`),
    KEY `idx_client_code`    (`client_code`),
    KEY `idx_asset_tag`      (`asset_tag`),
    KEY `idx_pipedrive_id`   (`pipedrive_id`),
    KEY `idx_status`         (`status`),
    KEY `idx_phase_id`       (`phase_id`),
    KEY `idx_board_id`       (`board_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log de sincronização de projetos
CREATE TABLE IF NOT EXISTS `pipedrive_projects_sync_log` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `status`       ENUM('success','partial','error') NOT NULL,
    `total_found`  INT NOT NULL DEFAULT 0,
    `created`      INT NOT NULL DEFAULT 0,
    `updated`      INT NOT NULL DEFAULT 0,
    `errors`       TEXT DEFAULT NULL,
    `duration_ms`  INT NOT NULL DEFAULT 0,
    `performed_by` INT UNSIGNED DEFAULT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Se a tabela pipedrive_projects já existir, adicione as colunas novas:
-- ALTER TABLE `pipedrive_projects` ADD COLUMN IF NOT EXISTS `asset_tag` VARCHAR(20) DEFAULT NULL AFTER `client_code`;
-- ALTER TABLE `pipedrive_projects` ADD INDEX `idx_asset_tag` (`asset_tag`);
-- ALTER TABLE `pipedrive_projects` ADD INDEX `idx_phase_id` (`phase_id`);
-- ALTER TABLE `pipedrive_projects` ADD INDEX `idx_board_id` (`board_id`);
