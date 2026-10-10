<?php
declare(strict_types=1);

use FloCMS\Api\Database\Connection;
use FloCMS\Api\Database\SchemaInstaller;
use FloCMS\CLI\Database\Migration;
use FloCMS\Core\Database;

/**
 * The API tables (api_keys, api_tokens, api_idempotency), from
 * database/schema/<driver>.sql. Run by `php flo migrate` or
 * `php flo api:install-schema`. The statements use IF NOT EXISTS, so sites
 * that installed the schema with flocms-api 1.0/1.1 just get it recorded.
 */
return new class extends Migration
{
    public bool $transactional = false;

    public function up(Database $db): void
    {
        (new SchemaInstaller(new Connection($db->pdo())))->install();
    }

    public function down(Database $db): void
    {
        foreach (['api_idempotency', 'api_tokens', 'api_keys'] as $table) {
            $db->pdo()->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }
};
