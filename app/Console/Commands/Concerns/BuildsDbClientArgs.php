<?php

namespace App\Console\Commands\Concerns;

/**
 * Shared mysqldump/mysql connection argv builder for the DB snapshot/restore
 * commands. Prefers a unix socket when the connection defines one (common for
 * `localhost` MariaDB — the INSTALL.md setup), else host+port. Kept in one place
 * so snapshot + restore can't drift on how they reach the database.
 */
trait BuildsDbClientArgs
{
    /**
     * @param  array<string, mixed>  $cfg  a database.connections.* config array
     * @return list<string>
     */
    protected static function dbConnectionArgs(array $cfg): array
    {
        $socket = (string) ($cfg['unix_socket'] ?? '');

        $args = $socket !== ''
            ? ['--socket='.$socket]
            : ['--host='.($cfg['host'] ?? '127.0.0.1'), '--port='.($cfg['port'] ?? 3306)];

        $args[] = '--user='.($cfg['username'] ?? 'root');
        $args[] = '--default-character-set='.($cfg['charset'] ?? 'utf8mb4');

        return $args;
    }
}
