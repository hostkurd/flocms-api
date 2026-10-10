-- flocms-api tables for MySQL 5.7+ / MariaDB 10.3+.
-- Install with `php flo api:install-schema`, or import this file in phpMyAdmin.
-- Every statement is safe to run again.

-- API keys for server-to-server clients (X-API-Key header).
-- The key is shown once when created; only its SHA-256 hash is stored.
CREATE TABLE IF NOT EXISTS api_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    secret_hash CHAR(64) NOT NULL,
    scopes TEXT NULL,
    user_id BIGINT UNSIGNED NULL,
    last_used_at DATETIME NULL,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY api_keys_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Personal access tokens for user-facing apps (Authorization: Bearer).
CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    secret_hash CHAR(64) NOT NULL,
    scopes TEXT NULL,
    last_used_at DATETIME NULL,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY api_tokens_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stored responses for Idempotency-Key (IdempotencyMiddleware with
-- DatabaseIdempotencyStore). Rows expire after 24 hours; `flo api:gc --database` purges them.
CREATE TABLE IF NOT EXISTS api_idempotency (
    idempotency_key CHAR(64) NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    status VARCHAR(10) NOT NULL,
    response MEDIUMTEXT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (idempotency_key),
    KEY api_idempotency_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
