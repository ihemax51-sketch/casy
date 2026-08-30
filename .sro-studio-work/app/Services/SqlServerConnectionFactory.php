<?php

namespace App\Services;

use App\Models\ServerProfile;
use PDO;
use RuntimeException;

final class SqlServerConnectionFactory
{
    public function connect(ServerProfile $profile, ?string $database = null): PDO
    {
        if (! extension_loaded('pdo_sqlsrv')) {
            throw new RuntimeException('The pdo_sqlsrv PHP extension is not enabled.');
        }

        $database ??= $profile->shard_database ?: 'master';
        $this->assertIdentifier($database);

        $server = trim($profile->host);
        if ($profile->port) {
            $server .= ','.(int) $profile->port;
        }

        $dsn = implode(';', [
            'sqlsrv:Server='.$server,
            'Database='.$database,
            'Encrypt='.($profile->encrypt_connection ? '1' : '0'),
            'TrustServerCertificate='.($profile->trust_server_certificate ? '1' : '0'),
            'LoginTimeout=6',
        ]);

        return new PDO($dsn, $profile->username, $profile->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }

    public function quoteIdentifier(string $identifier): string
    {
        $this->assertIdentifier($identifier);

        return '['.str_replace(']', ']]', $identifier).']';
    }

    private function assertIdentifier(string $identifier): void
    {
        if (! preg_match('/^[A-Za-z0-9_$-]+$/', $identifier)) {
            throw new RuntimeException('Unsafe SQL Server identifier.');
        }
    }
}
