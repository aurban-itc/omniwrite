<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Support;

use PDO;

/** SQLite-Spiegel der MySQL-Migrationen für schnelle lokale Tests. Bei neuen Migrationen mitpflegen. */
final class SqliteSchema
{
    public static function create(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE plans (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
    posts_per_month INTEGER NULL, trial_days INTEGER NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
INSERT INTO plans (code, name) VALUES ('default', 'Standard');
CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, plan_id INTEGER NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE sites (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL, slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL, locale TEXT NOT NULL DEFAULT 'de', timezone TEXT NOT NULL DEFAULT 'Europe/Berlin',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, title TEXT NOT NULL,
    slug TEXT NOT NULL, body_html TEXT, status TEXT NOT NULL DEFAULT 'draft', created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    excerpt TEXT NOT NULL DEFAULT '', category TEXT NOT NULL DEFAULT '', keywords TEXT NOT NULL DEFAULT '',
    meta_description TEXT NOT NULL DEFAULT '', cover_image TEXT NOT NULL DEFAULT '', cover_alt TEXT NOT NULL DEFAULT '',
    image_prompt TEXT NOT NULL DEFAULT '', read_minutes INTEGER NOT NULL DEFAULT 1, topic_id INTEGER NULL, published_at TEXT NULL, updated_at TEXT NULL,
    reviewed_by TEXT NULL, reviewed_at TEXT NULL, review_note TEXT NULL,
    UNIQUE (site_id, slug));
CREATE TABLE topics (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, title TEXT NOT NULL,
    keywords TEXT NOT NULL DEFAULT '', category TEXT NOT NULL DEFAULT '', notes TEXT NULL,
    status TEXT NOT NULL DEFAULT 'active', sort_order INTEGER NOT NULL DEFAULT 0, times_used INTEGER NOT NULL DEFAULT 0,
    last_used_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE site_settings (site_id INTEGER NOT NULL, k TEXT NOT NULL, v TEXT NOT NULL, PRIMARY KEY (site_id, k));
CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL,
    name TEXT NOT NULL, locale TEXT NOT NULL DEFAULT 'de', is_superadmin INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'active', created_at TEXT DEFAULT CURRENT_TIMESTAMP, last_login_at TEXT NULL);
CREATE TABLE account_users (account_id INTEGER NOT NULL, user_id INTEGER NOT NULL, role TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (account_id, user_id));
CREATE TABLE domains (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, host TEXT NOT NULL UNIQUE,
    verified_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE usage_events (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, kind TEXT NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 1, meta TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, account_id INTEGER NULL,
    action TEXT NOT NULL, meta TEXT NULL, ip TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE user_totp (user_id INTEGER PRIMARY KEY, secret_enc TEXT NOT NULL, confirmed_at TEXT NULL,
    last_step INTEGER NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE recovery_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, code_hash TEXT NOT NULL,
    used_at TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE recipients (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, email TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT '', active INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (site_id, email));
CREATE TABLE review_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, post_id INTEGER NOT NULL,
    email TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE runs (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, status TEXT NOT NULL,
    post_id INTEGER NULL, message TEXT NOT NULL DEFAULT '', created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL, ip TEXT NOT NULL,
    succeeded INTEGER NOT NULL, attempted_at TEXT NOT NULL);
SQL);
    }
}
