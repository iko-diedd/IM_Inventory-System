<?php
declare(strict_types=1);

final class Database
{
    public static function connect(): PDO
    {
        $host = getenv('SUPABASE_DB_HOST') ?: '';
        $port = getenv('SUPABASE_DB_PORT') ?: '5432';
        $name = getenv('SUPABASE_DB_NAME') ?: 'postgres';
        $user = getenv('SUPABASE_DB_USER') ?: '';
        $password = getenv('SUPABASE_DB_PASSWORD') ?: '';
        $sslmode = getenv('SUPABASE_DB_SSLMODE') ?: 'require';

        if ($host === '' || $user === '' || $password === '') {
            throw new RuntimeException('Database configuration is incomplete. Set the SUPABASE_DB_* values in .env.');
        }
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $host)
            || !preg_match('/^[A-Za-z0-9_-]+$/', $name)
            || !preg_match('/^[A-Za-z0-9._-]+$/', $user)
            || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException('Database host, name, user, or port has an invalid format.');
        }

        if (!in_array($sslmode, ['require', 'verify-ca', 'verify-full'], true)) {
            throw new RuntimeException('SUPABASE_DB_SSLMODE must be require, verify-ca, or verify-full.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
            $host,
            $port,
            $name,
            $sslmode
        );

        try {
            return new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $exception) {
            error_log('PostgreSQL connection failed: ' . $exception->getMessage());
            throw new RuntimeException('Could not connect to Supabase PostgreSQL. Verify the host, credentials, network access, and pdo_pgsql extension.');
        }
    }
}
