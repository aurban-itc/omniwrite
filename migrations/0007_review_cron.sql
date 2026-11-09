-- Phase 4: Review-Mail und Wochenlauf. Wiederholbar (CREATE TABLE IF NOT EXISTS, Spalten nur ergänzen, wenn sie fehlen).

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE posts ADD COLUMN reviewed_by VARCHAR(190) NULL', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'reviewed_by');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE posts ADD COLUMN reviewed_at TIMESTAMP NULL', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'reviewed_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE posts ADD COLUMN review_note VARCHAR(600) NULL', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'review_note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Empfänger der Review-Mail pro Site (kein Account nötig).
CREATE TABLE IF NOT EXISTS recipients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    site_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    name VARCHAR(120) NOT NULL DEFAULT '',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_recipients_site_email (site_id, email),
    CONSTRAINT fk_recipients_site FOREIGN KEY (site_id) REFERENCES sites (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Einmal-Links aus der Review-Mail. Gespeichert wird nur der SHA-256 des Tokens.
CREATE TABLE IF NOT EXISTS review_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    site_id BIGINT UNSIGNED NOT NULL,
    post_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_review_tokens_hash (token_hash),
    KEY idx_review_tokens_post (post_id),
    CONSTRAINT fk_review_tokens_site FOREIGN KEY (site_id) REFERENCES sites (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Protokoll der Wochenläufe pro Site.
CREATE TABLE IF NOT EXISTS runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    site_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL,
    post_id BIGINT UNSIGNED NULL,
    message VARCHAR(600) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_runs_site (site_id, id),
    CONSTRAINT fk_runs_site FOREIGN KEY (site_id) REFERENCES sites (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
