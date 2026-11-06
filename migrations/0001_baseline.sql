-- Replay-sicher: nur CREATE TABLE IF NOT EXISTS / INSERT IGNORE, ein Abbruch mitten drin lässt sich neu starten.
-- Basisschema. Noch auf keiner Datenbank angewendet, daher darf es bis zum ersten Einsatz noch wachsen.
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

CREATE TABLE IF NOT EXISTS accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    plan_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_accounts_plan FOREIGN KEY (plan_id) REFERENCES plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(64) NOT NULL,
    name VARCHAR(190) NOT NULL,
    locale CHAR(2) NOT NULL DEFAULT 'de',
    timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Berlin',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sites_slug (slug),
    CONSTRAINT fk_sites_account FOREIGN KEY (account_id) REFERENCES accounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS posts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    site_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    body_html MEDIUMTEXT NULL,
    status ENUM('draft','in_review','published','declined') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_posts_site_slug (site_id, slug),
    CONSTRAINT fk_posts_site FOREIGN KEY (site_id) REFERENCES sites (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
