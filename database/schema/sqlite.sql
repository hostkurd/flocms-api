-- flocms-api tables for SQLite 3.
-- Install with `php flo api:install-schema`. Every statement is safe to run again.

CREATE TABLE IF NOT EXISTS api_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL,
    secret_hash CHAR(64) NOT NULL,
    scopes TEXT NULL,
    user_id INTEGER NULL,
    last_used_at DATETIME NULL,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL
);
CREATE INDEX IF NOT EXISTS api_keys_user_id ON api_keys (user_id);

CREATE TABLE IF NOT EXISTS api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name VARCHAR(100) NOT NULL,
    secret_hash CHAR(64) NOT NULL,
    scopes TEXT NULL,
    last_used_at DATETIME NULL,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL
);
CREATE INDEX IF NOT EXISTS api_tokens_user_id ON api_tokens (user_id);

CREATE TABLE IF NOT EXISTS api_idempotency (
    idempotency_key CHAR(64) NOT NULL PRIMARY KEY,
    fingerprint CHAR(64) NOT NULL,
    status VARCHAR(10) NOT NULL,
    response TEXT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL
);
CREATE INDEX IF NOT EXISTS api_idempotency_expires_at ON api_idempotency (expires_at);
