<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Manages the remote PostgreSQL (cortex) connection scoped to a client schema.
 *
 * Each client's data lives in its own PostgreSQL schema whose name matches
 * the client's username (e.g. schema "client_a" for username "client_a").
 * Calling setSchema() issues a SET search_path statement so that all
 * subsequent queries on the same connection target the correct schema.
 */
class CortexConnectionService
{
    /** The currently active schema name. */
    private string $schema = 'public';

    /**
     * Switch the cortex connection to the given client schema.
     *
     * @param  string  $username  The authenticated user's username (= schema name).
     *
     * @throws RuntimeException if the schema name contains invalid characters.
     */
    public function setSchema(string $username): void
    {
        // Validate: only allow alphanumeric characters and underscores to
        // prevent SQL injection through the schema name.
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            throw new RuntimeException("Invalid schema name: {$username}");
        }

        $this->schema = $username;

        // SET search_path cannot use PDO parameter binding, but the regex
        // above guarantees the value is safe.
        $this->connection()->statement("SET search_path TO \"{$username}\"");
    }

    /**
     * Return the currently active schema name.
     */
    public function currentSchema(): string
    {
        return $this->schema;
    }

    /**
     * Return the raw Illuminate database connection.
     */
    public function connection(): Connection
    {
        return DB::connection('pgsql');
    }

    /**
     * Return the list of platforms (tables) that actually exist in the
     * client's schema — i.e. the platforms they are subscribed to.
     *
     * @param  array<string>  $candidates  Platforms to check (e.g. ['facebook', 'instagram']).
     * @return array<string>
     */
    public function availablePlatforms(array $candidates = ['facebook', 'instagram', 'tiktok', 'twitter']): array
    {
        $ttl = (int) config('cortex.schema_cache_ttl');

        $discover = fn () => array_values(
            array_filter($candidates, fn (string $platform) => $this->tableExists($platform))
        );

        if ($ttl <= 0) {
            return $discover();
        }

        // Keyed on the schema, not just the instance: this service is a
        // singleton whose schema changes with setSchema(), so an instance-scoped
        // memo would hand one client another client's platform list.
        $key = sprintf(
            'cortex_platforms_%s_%s',
            $this->schema,
            md5(json_encode($candidates)),
        );

        return Cache::store('file')->remember($key, now()->addSeconds($ttl), $discover);
    }

    /**
     * Check whether a table exists in the active client schema.
     */
    public function tableExists(string $table): bool
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return false;
        }

        // Query information_schema directly to check the specific schema,
        // instead of relying on search_path which may not be set yet.
        return $this->connection()->table('information_schema.tables')
            ->where('table_schema', $this->schema)
            ->where('table_name', $table)
            ->where('table_type', 'BASE TABLE')
            ->exists();
    }

    /**
     * Return a query builder scoped to the given platform table in the
     * active client schema.
     *
     * Example usage:
     *   $cortex->table('facebook')->whereBetween('created_at', [$start, $end])->get();
     */
    public function table(string $table): \Illuminate\Database\Query\Builder
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new RuntimeException("Invalid table name: {$table}");
        }

        // Fully-qualify as schema.table using DB::raw so Laravel does not
        // add another layer of quoting on top.
        return $this->connection()->table(
            \Illuminate\Support\Facades\DB::raw("\"{$this->schema}\".\"{$table}\"")
        );
    }
}
