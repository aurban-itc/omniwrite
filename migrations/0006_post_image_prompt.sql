-- Phase 3: Bildbeschreibung für KI-Titelbilder. Wiederholbar (Spalte nur ergänzen, wenn sie fehlt).
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE posts ADD COLUMN image_prompt VARCHAR(1000) NOT NULL DEFAULT ''''', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'image_prompt');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
