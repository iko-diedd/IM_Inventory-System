<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!extension_loaded('pdo_pgsql')) {
    fwrite(STDERR, "FAIL pdo_pgsql is not enabled.\n");
    exit(1);
}

try {
    $db = app_db();
    $requiredTables = [
        'app_profiles', 'categories', 'brands', 'suppliers', 'products', 'supplier_products',
        'purchases', 'purchase_items', 'purchase_receipts', 'purchase_receipt_items', 'sales',
        'sale_items', 'returns', 'return_items', 'inventory_movements', 'audit_logs', 'warranty_claims',
        'app_settings', 'app_notification_reads',
    ];
    $failures = [];

    foreach ($requiredTables as $table) {
        $check = $db->prepare(
            'SELECT COALESCE((SELECT relrowsecurity FROM pg_class WHERE oid = to_regclass(:table)), false)'
        );
        $check->execute(['table' => 'public.' . $table]);
        $rls = $check->fetchColumn();
        if (!in_array($rls, [true, 't', '1'], true)) {
            $failures[] = $table . ' is missing or does not have RLS enabled';
        }
    }

    $migration = $db->query("SELECT EXISTS (SELECT 1 FROM schema_migrations WHERE version = '001_core')")->fetchColumn();
    if (!in_array($migration, [true, 't', '1'], true)) {
        $failures[] = 'migration 001_core is not recorded';
    }
    $warrantyMigration = $db->query("SELECT EXISTS (SELECT 1 FROM schema_migrations WHERE version = '002_warranty_claims')")->fetchColumn();
    if (!in_array($warrantyMigration, [true, 't', '1'], true)) {
        $failures[] = 'migration 002_warranty_claims is not recorded';
    }
    $usersMigration = $db->query("SELECT EXISTS (SELECT 1 FROM schema_migrations WHERE version = '003_user_management')")->fetchColumn();
    if (!in_array($usersMigration, [true, 't', '1'], true)) {
        $failures[] = 'migration 003_user_management is not recorded';
    }
    foreach (['004_user_profile_extensions', '005_global_tax', '006_sales_discount_percentage', '007_notification_reads'] as $version) {
        $applied = $db->prepare('SELECT EXISTS (SELECT 1 FROM schema_migrations WHERE version = :version)');
        $applied->execute(['version' => $version]);
        if (!in_array($applied->fetchColumn(), [true, 't', '1'], true)) {
            $failures[] = 'migration ' . $version . ' is not recorded';
        }
    }
    $profileColumns = $db->query(
        "SELECT count(*) FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'app_profiles'
           AND column_name IN ('email', 'address', 'birth_date', 'phone', 'deleted_at')"
    )->fetchColumn();
    if ((int) $profileColumns !== 5) {
        $failures[] = 'user contact/profile fields are missing';
    }
    $extendedProfileColumns = $db->query(
        "SELECT count(*) FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'app_profiles'
           AND column_name IN ('gender', 'hire_date', 'emergency_contact_name', 'emergency_contact_phone')"
    )->fetchColumn();
    if ((int) $extendedProfileColumns !== 4) {
        $failures[] = 'extended user profile fields are missing';
    }
    $purchaseTaxColumn = $db->query(
        "SELECT EXISTS (SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'purchases' AND column_name = 'tax_amount')"
    )->fetchColumn();
    if (!in_array($purchaseTaxColumn, [true, 't', '1'], true)) {
        $failures[] = 'purchase tax snapshot is missing';
    }
    $discountRateColumn = $db->query(
        "SELECT EXISTS (SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'sales' AND column_name = 'discount_rate')"
    )->fetchColumn();
    if (!in_array($discountRateColumn, [true, 't', '1'], true)) {
        $failures[] = 'sales discount percentage snapshot is missing';
    }
    $settingsColumn = $db->query(
        "SELECT has_table_privilege('authenticated', 'public.app_settings', 'SELECT')"
    )->fetchColumn();
    if (in_array($settingsColumn, [true, 't', '1'], true)) {
        $failures[] = 'global application settings are exposed to the Supabase authenticated Data API role';
    }
    $notificationReadTable = $db->query(
        "SELECT has_table_privilege('authenticated', 'public.app_notification_reads', 'SELECT')"
    )->fetchColumn();
    if (in_array($notificationReadTable, [true, 't', '1'], true)) {
        $failures[] = 'per-user notification read state is exposed to the Supabase authenticated Data API role';
    }
    $warrantySnapshot = $db->query(
        "SELECT EXISTS (SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'sale_items' AND column_name = 'warranty_months')"
    )->fetchColumn();
    if (!in_array($warrantySnapshot, [true, 't', '1'], true)) {
        $failures[] = 'sale_items warranty duration snapshot is missing';
    }

    $backendRole = $db->query("SELECT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'inventory_app')")->fetchColumn();
    if (!in_array($backendRole, [true, 't', '1'], true)) {
        $failures[] = 'inventory_app backend role is missing';
    }

    $membership = $db->query("SELECT pg_has_role(current_user, 'inventory_app', 'member')")->fetchColumn();
    if (!in_array($membership, [true, 't', '1'], true)) {
        $failures[] = 'connected database role is not a member of inventory_app';
    }

    $costColumn = $db->query("SELECT has_column_privilege('authenticated', 'public.products', 'average_cost', 'SELECT')")->fetchColumn();
    if (in_array($costColumn, [true, 't', '1'], true)) {
        $failures[] = 'average_cost is exposed to the Supabase authenticated Data API role';
    }
    $saleCostColumn = $db->query("SELECT has_column_privilege('authenticated', 'public.sale_items', 'unit_cost', 'SELECT')")->fetchColumn();
    if (in_array($saleCostColumn, [true, 't', '1'], true)) {
        $failures[] = 'historical unit_cost is exposed to the Supabase authenticated Data API role';
    }

    $drift = $db->query(
        'SELECT count(*) FROM (
            SELECT p.product_id
            FROM products p LEFT JOIN inventory_movements m USING (product_id)
            GROUP BY p.product_id, p.stock_quantity
            HAVING p.stock_quantity <> COALESCE(sum(m.quantity_change), 0)
        ) mismatches'
    )->fetchColumn();
    if ((int) $drift !== 0) {
        $failures[] = $drift . ' product inventory balance(s) do not reconcile to movements';
    }

    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL " . $failure . "\n");
    }
    if ($failures !== []) {
        exit(1);
    }

    printf("PASS %d application tables have RLS enabled.\n", count($requiredTables));
    echo "PASS core, warranty, user-management, profile-extension, tax, percentage-discount, and notification-read migrations and trusted backend role membership.\n";
    echo "PASS administrator user profile fields, purchase tax snapshots, and sale discount percentages are available.\n";
    echo "PASS global application settings are not granted to Data API authenticated users.\n";
    echo "PASS per-user notification read state is not granted to Data API authenticated users.\n";
    echo "PASS product average_cost is not granted to Data API authenticated users.\n";
    echo "PASS historical sale cost is not granted to Data API authenticated users.\n";
    echo "PASS sold warranty durations are snapshotted for claim eligibility.\n";
    echo "PASS product balances reconcile to inventory movements.\n";
    echo "This verifies schema configuration only; it is not a substitute for Auth/RLS and workflow integration tests.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Schema verification failed: " . $exception->getMessage() . "\n");
    exit(1);
}
