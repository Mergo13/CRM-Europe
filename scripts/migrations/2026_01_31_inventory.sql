-- Inventory migration: lager, lagerbestand, lager_bewegungen
-- Run this after creating your database (see README). Safe to run multiple times.

-- Warehouses
CREATE TABLE IF NOT EXISTS `lager` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL,
  `code` VARCHAR(64) NULL,
  `created_by` BIGINT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional per-product settings (min stock)
CREATE TABLE IF NOT EXISTS `lagerbestand` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `lager_id` INT NOT NULL,
  `produkt_id` INT NOT NULL,
  `min_bestand` DECIMAL(18,3) NULL DEFAULT 0,
  `created_by` BIGINT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lb_lager_prod` (`lager_id`,`produkt_id`),
  CONSTRAINT `fk_lb_lager` FOREIGN KEY (`lager_id`) REFERENCES `lager`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Movement-based stock
CREATE TABLE IF NOT EXISTS `lager_bewegungen` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `lager_id` INT NOT NULL,
  `produkt_id` INT NOT NULL,
  `typ` ENUM('EINGANG','AUSGANG','RESERVIERUNG','KORREKTUR') NOT NULL,
  `menge` DECIMAL(18,3) NOT NULL,
  `bezug_tabelle` VARCHAR(64) NULL,
  `bezug_id` BIGINT NULL,
  `bemerkung` TEXT NULL,
  `created_by` BIGINT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mov_lager_prod` (`lager_id`,`produkt_id`),
  KEY `idx_mov_ref` (`bezug_tabelle`,`bezug_id`),
  CONSTRAINT `fk_mov_lager` FOREIGN KEY (`lager_id`) REFERENCES `lager`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default warehouse if none exists
INSERT INTO `lager` (`name`,`code`) SELECT 'Hauptlager','MAIN' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `lager` LIMIT 1);
