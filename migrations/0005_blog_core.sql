-- Phase 2: Blog-Kern (Posts erweitert, Themenliste, Site-Einstellungen). Vollständig wiederholbar:
-- Spalten werden nur ergänzt, wenn sie fehlen; Tabellen nur, wenn sie fehlen.

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE posts ADD COLUMN excerpt VARCHAR(600) NOT NULL DEFAULT ''''', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'excerpt');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE posts ADD COLUMN category VARCHAR(80) NOT NULL DEFAULT ''''', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'category');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE posts ADD COLUMN keywords VARCHAR(400) NOT NULL DEFAULT ''''', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'keywords');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE posts ADD COLUMN meta_description VARCHAR(320) NOT NULL DEFAULT ''''', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'meta_description');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE posts ADD COLUMN cover_image VARCHAR(500) NOT NULL DEFAULT ''''', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'cover_image');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE posts ADD COLUMN cover_alt VARCHAR(255) NOT NULL DEFAULT ''''', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'cover_alt');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE posts ADD COLUMN read_minutes INT UNSIGNED NOT NULL DEFAULT 1', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'read_minutes');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE posts ADD COLUMN topic_id BIGINT UNSIGNED NULL', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'topic_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE posts ADD COLUMN published_at TIMESTAMP NULL', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'published_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE posts ADD COLUMN updated_at TIMESTAMP NULL', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'updated_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS topics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    site_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    keywords VARCHAR(400) NOT NULL DEFAULT '',
    category VARCHAR(80) NOT NULL DEFAULT '',
    notes MEDIUMTEXT NULL,
    status ENUM('active','paused','suggested') NOT NULL DEFAULT 'active',
    sort_order INT NOT NULL DEFAULT 0,
    times_used INT UNSIGNED NOT NULL DEFAULT 0,
    last_used_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_topics_site_order (site_id, sort_order),
    CONSTRAINT fk_topics_site FOREIGN KEY (site_id) REFERENCES sites (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Einstellungen pro Site (Style-Guide, Firmenfakten, Bildstil, Themen-Cursor).
CREATE TABLE IF NOT EXISTS site_settings (
    site_id BIGINT UNSIGNED NOT NULL,
    k VARCHAR(64) NOT NULL,
    v MEDIUMTEXT NOT NULL,
    PRIMARY KEY (site_id, k),
    CONSTRAINT fk_site_settings_site FOREIGN KEY (site_id) REFERENCES sites (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
