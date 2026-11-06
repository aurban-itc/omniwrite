-- Pläne und accounts.plan_id. Vorwärts-Migration, vollständig wiederholbar: ein Abbruch zwischen den
-- Statements oder eine Datenbank mit dem alten Baseline-Stand führt beim nächsten Lauf zum selben Ergebnis.
CREATE TABLE IF NOT EXISTS plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL,
    name VARCHAR(100) NOT NULL,
    posts_per_month INT UNSIGNED NULL,
    trial_days INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_plans_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Platzhalter bis die Limits entschieden sind (NULL = unbegrenzt).
INSERT IGNORE INTO plans (code, name) VALUES ('default', 'Standard');

-- ALTER TABLE ist nicht wiederholbar, daher nur ausführen, wenn Spalte bzw. Constraint fehlen.
SET @sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE accounts ADD COLUMN plan_id BIGINT UNSIGNED NULL', 'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND COLUMN_NAME = 'plan_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE accounts ADD CONSTRAINT fk_accounts_plan FOREIGN KEY (plan_id) REFERENCES plans (id)', 'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND CONSTRAINT_NAME = 'fk_accounts_plan');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
