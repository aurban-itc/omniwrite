-- Phase 7b: Push-Anbindungen (Webhook, GitHub). Wiederholbar (CREATE TABLE IF NOT EXISTS).

-- Ziele pro Site. Zugangsdaten stehen nur verschlüsselt (SecretBox) in config_enc.
CREATE TABLE IF NOT EXISTS push_targets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    site_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(16) NOT NULL,
    name VARCHAR(120) NOT NULL DEFAULT '',
    config_enc TEXT NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    locked_until TIMESTAMP NULL,
    lock_token CHAR(32) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_push_targets_site (site_id),
    CONSTRAINT fk_push_targets_site FOREIGN KEY (site_id) REFERENCES sites (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Zustand je Ziel und Beitrag: was zuletzt zugestellt wurde (Hash des Inhalts, NULL = nicht vorhanden) und der Stand der Wiederholungen.
CREATE TABLE IF NOT EXISTS push_state (
    target_id BIGINT UNSIGNED NOT NULL,
    post_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NOT NULL,
    delivered_hash CHAR(64) NULL,
    attempt_hash CHAR(64) NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    next_attempt_at TIMESTAMP NULL,
    last_error VARCHAR(600) NOT NULL DEFAULT '',
    remote_ref VARCHAR(255) NOT NULL DEFAULT '',
    post_slug VARCHAR(255) NOT NULL DEFAULT '',
    post_title VARCHAR(255) NOT NULL DEFAULT '',
    delivered_at TIMESTAMP NULL,
    PRIMARY KEY (target_id, post_id),
    KEY idx_push_state_site (site_id),
    CONSTRAINT fk_push_state_target FOREIGN KEY (target_id) REFERENCES push_targets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
