-- Create table for global customs profiles
-- Mirrors the data model in docs/instructions.md §1.2

CREATE TABLE IF NOT EXISTS `wp_wrd_customs_profiles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `description_raw` TEXT NOT NULL,
  `description_normalized` VARCHAR(512) NOT NULL,
  `country_code` CHAR(2) NOT NULL,
  `hs_code` VARCHAR(20) NOT NULL,
  `source` VARCHAR(50) NOT NULL DEFAULT 'legacy',
  `last_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `us_duty_json` JSON NOT NULL,
  `fta_flags` JSON NOT NULL,
  `effective_from` DATE NOT NULL DEFAULT (CURRENT_DATE),
  `effective_to` DATE NULL DEFAULT NULL,
  `notes` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_desc_norm_country_active` (`description_normalized`, `country_code`, `effective_from`, `effective_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uniqueness by version window can be enforced at import time; optional composite unique index:
-- ALTER TABLE `wp_wrd_customs_profiles`
--   ADD UNIQUE KEY `uniq_desc_country_from` (`description_normalized`, `country_code`, `effective_from`);

