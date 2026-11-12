-- Push-Connectors: Sperre je Ziel und zuletzt zugestellter Slug/Titel. Vorwärts-Migration zu 0008, vollständig wiederholbar
-- (ALTER TABLE nur, wenn die Spalte fehlt).

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE push_targets ADD COLUMN locked_until TIMESTAMP NULL', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_targets' AND COLUMN_NAME = 'locked_until');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE push_targets ADD COLUMN lock_token CHAR(32) NULL', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_targets' AND COLUMN_NAME = 'lock_token');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE push_state ADD COLUMN post_slug VARCHAR(255) NOT NULL DEFAULT ''''', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_state' AND COLUMN_NAME = 'post_slug');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE push_state ADD COLUMN post_title VARCHAR(255) NOT NULL DEFAULT ''''', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_state' AND COLUMN_NAME = 'post_title');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
