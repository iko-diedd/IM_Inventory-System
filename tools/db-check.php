<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!extension_loaded('pdo_pgsql')) {
    fwrite(STDERR, "Missing PHP extension: pdo_pgsql. Enable it in the XAMPP php.ini and restart Apache.\n");
    exit(1);
}

try {
    $db = app_db();
    $result = $db->query(
        "SELECT current_database() AS database_name,
                current_user AS connected_role,
                (SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()) AS tls_enabled,
                EXISTS (SELECT 1 FROM schema_migrations WHERE version = '001_core') AS core_ready,
                EXISTS (SELECT 1 FROM schema_migrations WHERE version = '002_warranty_claims') AS warranty_ready,
                EXISTS (SELECT 1 FROM schema_migrations WHERE version = '003_user_management') AS users_ready"
    )->fetch();
    printf("Connected to database: %s\n", $result['database_name']);
    printf("Database role: %s\n", $result['connected_role']);
    printf("TLS: %s\n", in_array($result['tls_enabled'], [true, 't', '1'], true) ? 'enabled' : 'not verified');
    printf("Core migration: %s\n", in_array($result['core_ready'], [true, 't', '1'], true) ? 'applied' : 'not found');
    printf("Warranty claims migration: %s\n", in_array($result['warranty_ready'], [true, 't', '1'], true) ? 'applied' : 'not found');
    printf("User management migration: %s\n", in_array($result['users_ready'], [true, 't', '1'], true) ? 'applied' : 'not found');
    if (!in_array($result['core_ready'], [true, 't', '1'], true)
        || !in_array($result['warranty_ready'], [true, 't', '1'], true)
        || !in_array($result['users_ready'], [true, 't', '1'], true)) {
        exit(1);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "Database check failed: " . $exception->getMessage() . "\n");
    exit(1);
}
