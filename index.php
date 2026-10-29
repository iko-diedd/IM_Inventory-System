<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, private');
ob_start();

$page = (string) ($_GET['page'] ?? 'dashboard');
$error = '';
$notice = '';
$authNotice = (string) ($_SESSION['auth_notice'] ?? '');
unset($_SESSION['auth_notice']);
$user = null;
$db = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Security::verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'login') {
            Auth::login(trim((string) ($_POST['email'] ?? '')), (string) ($_POST['password'] ?? ''));
            redirect('dashboard');
        }
        if ($action === 'logout') {
            Auth::logout();
            redirect('login');
        }

        $db = app_db();
        $user = current_user();
        if ($user === null) {
            redirect('login');
        }
        $service = new InventoryService($db);

        switch ($action) {
            case 'product_create':
                require_role(['administrator', 'manager', 'inventory_staff']);
                database_transaction($db, function () use ($db, $user): int {
                    $statement = $db->prepare(
                        'INSERT INTO products (sku, barcode, name, description, category_id, brand_id, model, unit,
                            selling_price, minimum_stock, warranty_months, specifications)
                         VALUES (:sku, NULLIF(:barcode, \'\'), :name, :description, :category, :brand, :model, :unit,
                            :price, :minimum, :warranty, CAST(:specs AS jsonb)) RETURNING product_id'
                    );
                    $statement->execute([
                        'sku' => required_post('sku', 80),
                        'barcode' => trim((string) ($_POST['barcode'] ?? '')),
                        'name' => required_post('name', 250),
                        'description' => trim((string) ($_POST['description'] ?? '')),
                        'category' => ($_POST['category_id'] ?? '') === '' ? null : post_int('category_id', 1),
                        'brand' => ($_POST['brand_id'] ?? '') === '' ? null : post_int('brand_id', 1),
                        'model' => trim((string) ($_POST['model'] ?? '')),
                        'unit' => required_post('unit', 40),
                        'price' => post_money('selling_price'),
                        'minimum' => post_int('minimum_stock'),
                        'warranty' => ($_POST['warranty_months'] ?? '') !== '' ? post_int('warranty_months') : null,
                        'specs' => json_encode(
                            array_filter([
                                'socket' => trim((string) ($_POST['spec_socket'] ?? '')),
                                'capacity' => trim((string) ($_POST['spec_capacity'] ?? '')),
                                'interface' => trim((string) ($_POST['spec_interface'] ?? '')),
                            ]),
                            JSON_THROW_ON_ERROR
                        ),
                    ]);
                    $id = (int) $statement->fetchColumn();
                    audit_action($db, $user['id'], 'product.created', 'product', (string) $id, ['sku' => $_POST['sku'] ?? '']);
                    return $id;
                });
                $notice = 'Product created.';
                $page = 'products';
                break;

            case 'product_update':
                require_role(['administrator', 'manager']);
                $id = post_int('product_id', 1);
                database_transaction($db, function () use ($db, $user, $id): void {
                    $current = $db->prepare('SELECT selling_price, is_active FROM products WHERE product_id = :id FOR UPDATE');
                    $current->execute(['id' => $id]);
                    $before = $current->fetch();
                    if (!$before) {
                        throw new RuntimeException('Product not found.');
                    }
                    $price = post_money('selling_price');
                    $active = isset($_POST['is_active']);
                    $db->prepare(
                        'UPDATE products SET name = :name, description = :description, selling_price = :price,
                         minimum_stock = :minimum, is_active = :active, updated_at = now() WHERE product_id = :id'
                    )->execute([
                        'name' => required_post('name', 250),
                        'description' => trim((string) ($_POST['description'] ?? '')),
                        'price' => $price,
                        'minimum' => post_int('minimum_stock'),
                        'active' => $active ? 'true' : 'false',
                        'id' => $id,
                    ]);
                    audit_action($db, $user['id'], 'product.updated', 'product', (string) $id, [
                        'previous_price' => $before['selling_price'],
                        'new_price' => $price,
                        'previous_active' => $before['is_active'],
                        'new_active' => $active,
                    ]);
                });
                $notice = 'Product updated.';
                $page = 'products';
                break;

            case 'category_create':
                require_role(['administrator', 'manager']);
                database_transaction($db, function () use ($db, $user): void {
                    $name = required_post('name', 150);
                    $statement = $db->prepare(
                        'INSERT INTO categories (name, description) VALUES (:name, :description) RETURNING category_id'
                    );
                    $statement->execute(['name' => $name, 'description' => trim((string) ($_POST['description'] ?? ''))]);
                    audit_action($db, $user['id'], 'category.created', 'category', (string) $statement->fetchColumn(), ['name' => $name]);
                });
                $notice = 'Category created.';
                $page = 'categories';
                break;

            case 'category_update':
                require_role(['administrator', 'manager']);
                $id = post_int('category_id', 1);
                database_transaction($db, function () use ($db, $user, $id): void {
                    $current = $db->prepare('SELECT name, is_active FROM categories WHERE category_id = :id FOR UPDATE');
                    $current->execute(['id' => $id]);
                    $before = $current->fetch();
                    if (!$before) {
                        throw new RuntimeException('Category not found.');
                    }
                    $name = required_post('name', 150);
                    $active = isset($_POST['is_active']);
                    $db->prepare(
                        'UPDATE categories SET name = :name, description = :description, is_active = :active WHERE category_id = :id'
                    )->execute([
                        'name' => $name,
                        'description' => trim((string) ($_POST['description'] ?? '')),
                        'active' => $active ? 'true' : 'false',
                        'id' => $id,
                    ]);
                    audit_action($db, $user['id'], 'category.updated', 'category', (string) $id, [
                        'previous_name' => $before['name'], 'name' => $name,
                        'previous_active' => $before['is_active'], 'is_active' => $active,
                    ]);
                });
                $notice = 'Category updated.';
                $page = 'categories';
                break;

            case 'brand_create':
                require_role(['administrator', 'manager']);
                database_transaction($db, function () use ($db, $user): void {
                    $name = required_post('name', 150);
                    $statement = $db->prepare('INSERT INTO brands (name) VALUES (:name) RETURNING brand_id');
                    $statement->execute(['name' => $name]);
                    audit_action($db, $user['id'], 'brand.created', 'brand', (string) $statement->fetchColumn(), ['name' => $name]);
                });
                $notice = 'Brand created.';
                $page = 'brands';
                break;

            case 'brand_update':
                require_role(['administrator', 'manager']);
                $id = post_int('brand_id', 1);
                database_transaction($db, function () use ($db, $user, $id): void {
                    $current = $db->prepare('SELECT name, is_active FROM brands WHERE brand_id = :id FOR UPDATE');
                    $current->execute(['id' => $id]);
                    $before = $current->fetch();
                    if (!$before) {
                        throw new RuntimeException('Brand not found.');
                    }
                    $name = required_post('name', 150);
                    $active = isset($_POST['is_active']);
                    $db->prepare('UPDATE brands SET name = :name, is_active = :active WHERE brand_id = :id')
                        ->execute(['name' => $name, 'active' => $active ? 'true' : 'false', 'id' => $id]);
                    audit_action($db, $user['id'], 'brand.updated', 'brand', (string) $id, [
                        'previous_name' => $before['name'], 'name' => $name,
                        'previous_active' => $before['is_active'], 'is_active' => $active,
                    ]);
                });
                $notice = 'Brand updated.';
                $page = 'brands';
                break;

            case 'supplier_create':
                require_role(['administrator', 'manager', 'inventory_staff']);
                database_transaction($db, function () use ($db, $user): void {
                    $name = required_post('name', 250);
                    $statement = $db->prepare(
                        'INSERT INTO suppliers (name, contact_person, phone, email, address)
                         VALUES (:name, :contact, :phone, :email, :address) RETURNING supplier_id'
                    );
                    $statement->execute([
                        'name' => $name,
                        'contact' => trim((string) ($_POST['contact_person'] ?? '')),
                        'phone' => trim((string) ($_POST['phone'] ?? '')),
                        'email' => valid_optional_email('email'),
                        'address' => trim((string) ($_POST['address'] ?? '')),
                    ]);
                    audit_action($db, $user['id'], 'supplier.created', 'supplier', (string) $statement->fetchColumn(), ['name' => $name]);
                });
                $notice = 'Supplier created.';
                $page = 'suppliers';
                break;

            case 'supplier_update':
                require_role(['administrator', 'manager', 'inventory_staff']);
                $id = post_int('supplier_id', 1);
                database_transaction($db, function () use ($db, $user, $id): void {
                    $current = $db->prepare('SELECT name, is_active FROM suppliers WHERE supplier_id = :id FOR UPDATE');
                    $current->execute(['id' => $id]);
                    $before = $current->fetch();
                    if (!$before) {
                        throw new RuntimeException('Supplier not found.');
                    }
                    $name = required_post('name', 250);
                    $active = isset($_POST['is_active']);
                    $db->prepare(
                        'UPDATE suppliers SET name = :name, contact_person = :contact, phone = :phone,
                         email = :email, address = :address, is_active = :active WHERE supplier_id = :id'
                    )->execute([
                        'name' => $name,
                        'contact' => trim((string) ($_POST['contact_person'] ?? '')),
                        'phone' => trim((string) ($_POST['phone'] ?? '')),
                        'email' => valid_optional_email('email'),
                        'address' => trim((string) ($_POST['address'] ?? '')),
                        'active' => $active ? 'true' : 'false',
                        'id' => $id,
                    ]);
                    audit_action($db, $user['id'], 'supplier.updated', 'supplier', (string) $id, [
                        'previous_name' => $before['name'], 'name' => $name,
                        'previous_active' => $before['is_active'], 'is_active' => $active,
                    ]);
                });
                $notice = 'Supplier updated.';
                $page = 'suppliers';
                break;

            case 'supplier_product_save':
                require_role(['administrator', 'manager', 'inventory_staff']);
                database_transaction($db, function () use ($db, $user): void {
                    $supplierId = post_int('supplier_id', 1);
                    $productId = post_int('product_id', 1);
                    $cost = post_money('last_unit_cost');
                    $save = $db->prepare(
                        'INSERT INTO supplier_products (supplier_id, product_id, last_unit_cost)
                         SELECT s.supplier_id, p.product_id, :cost
                         FROM suppliers s CROSS JOIN products p
                         WHERE s.supplier_id = :supplier AND s.is_active
                           AND p.product_id = :product AND p.is_active
                         ON CONFLICT (supplier_id, product_id) DO UPDATE
                         SET last_unit_cost = EXCLUDED.last_unit_cost'
                    );
                    $save->execute(['cost' => $cost, 'supplier' => $supplierId, 'product' => $productId]);
                    if ($save->rowCount() !== 1) {
                        throw new RuntimeException('Choose an active supplier and product.');
                    }
                    audit_action($db, $user['id'], 'supplier_product.saved', 'supplier_product',
                        $supplierId . ':' . $productId, ['last_unit_cost' => $cost]);
                });
                $notice = 'Supplier product and quoted cost saved.';
                $page = 'suppliers';
                break;

            case 'supplier_product_delete':
                require_role(['administrator', 'manager', 'inventory_staff']);
                $supplierId = post_int('supplier_id', 1);
                $productId = post_int('product_id', 1);
                database_transaction($db, function () use ($db, $user, $supplierId, $productId): void {
                    $delete = $db->prepare(
                        'DELETE FROM supplier_products WHERE supplier_id = :supplier AND product_id = :product'
                    );
                    $delete->execute(['supplier' => $supplierId, 'product' => $productId]);
                    if ($delete->rowCount() !== 1) {
                        throw new RuntimeException('Supplier-product mapping not found.');
                    }
                    audit_action($db, $user['id'], 'supplier_product.deleted', 'supplier_product',
                        $supplierId . ':' . $productId, []);
                });
                $notice = 'Supplier product mapping removed.';
                $page = 'suppliers';
                break;

            case 'purchase_create':
                require_role(['administrator', 'manager', 'inventory_staff']);
                $productIds = $_POST['product_id'] ?? null;
                $quantities = $_POST['quantity'] ?? null;
                $unitCosts = $_POST['unit_cost'] ?? null;
                if (!is_array($productIds) || !is_array($quantities) || !is_array($unitCosts)
                    || count($productIds) !== count($quantities) || count($productIds) !== count($unitCosts)
                    || count($productIds) > 50) {
                    throw new InvalidArgumentException('Purchase order lines are missing or invalid.');
                }
                $lines = [];
                foreach ($productIds as $index => $productId) {
                    $parsedProductId = filter_var($productId, FILTER_VALIDATE_INT);
                    $parsedQuantity = filter_var($quantities[$index] ?? null, FILTER_VALIDATE_INT);
                    $rawCost = $unitCosts[$index] ?? null;
                    if ($parsedProductId === false || $parsedProductId < 1
                        || $parsedQuantity === false || $parsedQuantity < 1
                        || !is_scalar($rawCost) || trim((string) $rawCost) === '' || !is_numeric($rawCost)) {
                        throw new InvalidArgumentException('Enter a valid product, quantity, and cost for every purchase line.');
                    }
                    $lines[] = [
                        'product_id' => $parsedProductId,
                        'quantity' => $parsedQuantity,
                        'unit_cost' => (float) $rawCost,
                    ];
                }
                $service->createPurchase(post_int('supplier_id', 1), $lines, $user['id']);
                $notice = 'Purchase order created.';
                $page = 'purchases';
                break;

            case 'purchase_receive':
                require_role(['administrator', 'manager', 'inventory_staff']);
                $received = $service->receivePurchase(
                    post_int('purchase_id', 1),
                    post_int('purchase_item_id', 1),
                    post_int('quantity', 1),
                    (string) ($_POST['request_key'] ?? ''),
                    $user['id']
                );
                $notice = $received ? 'Delivery received and inventory updated.' : 'This delivery request was already processed.';
                $page = 'purchases';
                break;

            case 'sale_checkout':
                require_role(['administrator', 'manager', 'cashier']);
                $items = json_decode((string) ($_POST['cart'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($items)) {
                    throw new InvalidArgumentException('Cart data is invalid.');
                }
                $saleId = $service->checkout(
                    $items,
                    post_money('discount'),
                    post_money('tax'),
                    (string) ($_POST['payment_method'] ?? ''),
                    (string) ($_POST['request_key'] ?? ''),
                    $user['id']
                );
                $notice = 'Sale completed successfully.';
                $page = 'receipt';
                $_GET['id'] = (string) $saleId;
                break;

            case 'stock_adjust':
                require_role(['administrator', 'manager']);
                $service->adjustStock(
                    post_int('product_id', 1),
                    post_int('counted_stock'),
                    required_post('reason', 500),
                    $user['id']
                );
                $notice = 'Stock adjustment recorded.';
                $page = 'inventory';
                break;

            case 'return_request':
                require_role(['administrator', 'manager', 'cashier']);
                $service->requestReturn(
                    post_int('sale_id', 1),
                    post_int('sale_item_id', 1),
                    post_int('quantity', 1),
                    (string) ($_POST['condition'] ?? ''),
                    required_post('reason', 500),
                    (string) ($_POST['request_key'] ?? ''),
                    $user['id']
                );
                $notice = 'Return request submitted for approval.';
                $page = 'returns';
                break;

            case 'return_decide':
                require_role(['administrator', 'manager']);
                $service->decideReturn((int) ($_POST['return_id'] ?? 0), ($_POST['decision'] ?? '') === 'approve', $user['id']);
                $notice = 'Return decision recorded.';
                $page = 'returns';
                break;

            case 'profile_create':
                require_role(['administrator']);
                require_user_management_migration($db);
                $name = required_post('full_name', 150);
                $email = valid_optional_email('email');
                if ($email === null) {
                    throw new InvalidArgumentException('Enter an email address for this account.');
                }
                $password = $_POST['password'] ?? null;
                if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72) {
                    throw new InvalidArgumentException('Set an initial password between 12 and 72 characters.');
                }
                $role = (string) ($_POST['role'] ?? '');
                if (!in_array($role, ['administrator', 'manager', 'cashier', 'inventory_staff'], true)) {
                    throw new InvalidArgumentException('Select a valid role.');
                }
                $identity = Auth::createManagedUser($email, $password, $name);
                $target = (string) ($identity['id'] ?? '');
                if (!preg_match('/^[0-9a-f-]{36}$/i', $target)) {
                    throw new RuntimeException('Supabase Auth created an account without returning a valid user ID.');
                }
                try {
                    database_transaction($db, function () use ($db, $user, $target, $name, $email, $role): void {
                        $db->prepare(
                            'INSERT INTO app_profiles (user_id, email, full_name, address, birth_date, phone, role)
                             VALUES (:id, :email, :name, :address, :birth_date, :phone, CAST(:role AS app_role))'
                        )->execute([
                            'id' => $target,
                            'email' => $email,
                            'name' => $name,
                            'address' => optional_post('address', 500),
                            'birth_date' => optional_post_date('birth_date'),
                            'phone' => optional_post('phone', 40),
                            'role' => $role,
                        ]);
                        audit_action($db, $user['id'], 'profile.created', 'profile', $target, [
                            'full_name' => $name,
                            'email' => $email,
                            'role' => $role,
                        ]);
                    });
                } catch (Throwable $exception) {
                    try {
                        Auth::deleteManagedUser($target);
                    } catch (Throwable $cleanupException) {
                        error_log('Could not remove Supabase Auth user after profile creation failed: ' . $cleanupException->getMessage());
                        throw new RuntimeException(
                            'The profile could not be saved and the temporary Auth account could not be removed. Contact system support.',
                            0,
                            $exception
                        );
                    }
                    throw $exception;
                }
                $notice = 'User account created.';
                $page = 'users';
                break;

            case 'profile_update':
                require_role(['administrator']);
                require_user_management_migration($db);
                $target = (string) ($_POST['user_id'] ?? '');
                if (!preg_match('/^[0-9a-f-]{36}$/i', $target)) {
                    throw new InvalidArgumentException('Select a valid user profile.');
                }
                $name = required_post('full_name', 150);
                $email = valid_optional_email('email');
                if ($email === null) {
                    throw new InvalidArgumentException('Enter an email address for this account.');
                }
                $role = (string) ($_POST['role'] ?? '');
                if (!in_array($role, ['administrator', 'manager', 'cashier', 'inventory_staff'], true)) {
                    throw new InvalidArgumentException('Select a valid role.');
                }
                if ($target === $user['id'] && $role !== 'administrator') {
                    throw new RuntimeException('You cannot remove your own administrator role.');
                }
                $password = $_POST['password'] ?? '';
                if (!is_string($password) || ($password !== '' && (strlen($password) < 12 || strlen($password) > 72))) {
                    throw new InvalidArgumentException('A new password must be between 12 and 72 characters.');
                }
                database_transaction($db, function () use ($db, $user, $target, $name, $email, $role, $password): void {
                    $current = $db->prepare('SELECT deleted_at FROM app_profiles WHERE user_id = :id FOR UPDATE');
                    $current->execute(['id' => $target]);
                    $profile = $current->fetch();
                    if (!$profile || $profile['deleted_at'] !== null) {
                        throw new RuntimeException('This user account is unavailable for editing.');
                    }
                    $authChanges = [
                        'email' => $email,
                        'email_confirm' => true,
                        'user_metadata' => ['full_name' => $name],
                    ];
                    if ($password !== '') {
                        $authChanges['password'] = $password;
                    }
                    $db->prepare(
                        'UPDATE app_profiles SET email = :email, full_name = :name, address = :address,
                         birth_date = :birth_date, phone = :phone, role = CAST(:role AS app_role),
                         updated_at = now() WHERE user_id = :id'
                    )->execute([
                        'email' => $email,
                        'name' => $name,
                        'address' => optional_post('address', 500),
                        'birth_date' => optional_post_date('birth_date'),
                        'phone' => optional_post('phone', 40),
                        'role' => $role,
                        'id' => $target,
                    ]);
                    audit_action($db, $user['id'], 'profile.updated', 'profile', $target, [
                        'full_name' => $name,
                        'email' => $email,
                        'role' => $role,
                    ]);
                    Auth::updateManagedUser($target, $authChanges);
                });
                $notice = 'User account updated.';
                $page = 'users';
                break;

            case 'profile_delete':
                require_role(['administrator']);
                require_user_management_migration($db);
                $target = (string) ($_POST['user_id'] ?? '');
                if (!preg_match('/^[0-9a-f-]{36}$/i', $target)) {
                    throw new InvalidArgumentException('Select a valid user profile.');
                }
                if ($target === $user['id']) {
                    throw new RuntimeException('You cannot delete your own account.');
                }
                $authAccountDeleted = true;
                try {
                    Auth::deleteManagedUser($target);
                } catch (RuntimeException $exception) {
                    $authAccountDeleted = false;
                    error_log('Supabase Auth account deletion did not complete; retaining the identity and disabling its profile: ' . $exception->getMessage());
                }
                database_transaction($db, function () use ($db, $user, $target, $authAccountDeleted): void {
                    if (!$authAccountDeleted) {
                        $statement = $db->prepare(
                            'UPDATE app_profiles SET is_active = FALSE, deleted_at = now(), updated_at = now()
                             WHERE user_id = :id AND deleted_at IS NULL'
                        );
                        $statement->execute(['id' => $target]);
                        if ($statement->rowCount() !== 1) {
                            throw new RuntimeException('User account not found or already deleted.');
                        }
                    }
                    audit_action($db, $user['id'], 'profile.deleted', 'profile', $target, [
                        'auth_account_deleted' => $authAccountDeleted,
                        'access_removed' => true,
                        'history_retained' => true,
                    ]);
                });
                $notice = $authAccountDeleted
                    ? 'User account deleted.'
                    : 'User access removed. Supabase retained the Auth account; historical records were preserved.';
                $page = 'users';
                break;

            case 'profile_role':
                require_role(['administrator']);
                $target = (string) ($_POST['user_id'] ?? '');
                $role = (string) ($_POST['role'] ?? '');
                if (!preg_match('/^[0-9a-f-]{36}$/i', $target)) {
                    throw new InvalidArgumentException('Select a valid user profile.');
                }
                if (!in_array($role, ['administrator', 'manager', 'cashier', 'inventory_staff'], true)) {
                    throw new InvalidArgumentException('Select a valid role.');
                }
                if ($target === $user['id'] && $role !== 'administrator') {
                    throw new RuntimeException('You cannot remove your own administrator role.');
                }
                database_transaction($db, function () use ($db, $user, $role, $target): void {
                    $statement = $db->prepare('UPDATE app_profiles SET role = CAST(:role AS app_role), updated_at = now() WHERE user_id = :id');
                    $statement->execute(['role' => $role, 'id' => $target]);
                    if ($statement->rowCount() !== 1) {
                        throw new RuntimeException('User profile not found.');
                    }
                    audit_action($db, $user['id'], 'profile.role_changed', 'profile', $target, ['role' => $role]);
                });
                $notice = 'User role updated.';
                $page = 'users';
                break;

            case 'profile_active':
                require_role(['administrator']);
                $target = (string) ($_POST['user_id'] ?? '');
                $active = ($_POST['is_active'] ?? '') === '1';
                if (!preg_match('/^[0-9a-f-]{36}$/i', $target)) {
                    throw new InvalidArgumentException('Select a valid user profile.');
                }
                if ($target === $user['id'] && !$active) {
                    throw new RuntimeException('You cannot deactivate your own account.');
                }
                database_transaction($db, function () use ($db, $user, $active, $target): void {
                    $statement = $db->prepare(
                        'UPDATE app_profiles SET is_active = :active, updated_at = now()
                         WHERE user_id = :id AND deleted_at IS NULL'
                    );
                    $statement->execute(['active' => $active ? 'true' : 'false', 'id' => $target]);
                    if ($statement->rowCount() !== 1) {
                        throw new RuntimeException('User profile not found.');
                    }
                    audit_action($db, $user['id'], 'profile.active_changed', 'profile', $target, ['is_active' => $active]);
                });
                $notice = 'User status updated.';
                $page = 'users';
                break;

            default:
                throw new InvalidArgumentException('Unknown form action.');
        }
    }

    if ($page !== 'login') {
        if (empty($_SESSION['auth']['access_token'])) {
            $page = 'login';
        } else {
            $db = $db ?? app_db();
            $user = $user ?? current_user(false);
        }
    } elseif (!empty($_SESSION['auth']['access_token'])) {
        $db = app_db();
        $user = current_user(false);
    }
    if ($user !== null) {
        if ($page === 'warranty') {
            $page = 'dashboard';
        }
        $pagePermissions = [
            'categories' => ['administrator', 'manager'],
            'brands' => ['administrator', 'manager'],
            'suppliers' => ['administrator', 'manager', 'inventory_staff'],
            'purchases' => ['administrator', 'manager', 'inventory_staff'],
            'inventory' => ['administrator', 'manager', 'inventory_staff'],
            'returns' => ['administrator', 'manager', 'cashier'],
            'receipt' => ['administrator', 'manager', 'cashier'],
            'reports' => ['administrator', 'manager'],
            'audit' => ['administrator', 'manager'],
            'users' => ['administrator'],
        ];
        if (isset($pagePermissions[$page]) && !in_array($user['role'], $pagePermissions[$page], true)) {
            http_response_code(403);
            throw new RuntimeException('Your account is not authorized to view this page.');
        }
    }
} catch (Throwable $exception) {
    if ($exception instanceof PDOException) {
        error_log('Application database error: ' . $exception->getMessage());
        $error = $exception->getCode() === '23505'
            ? 'A record with the same unique identifier already exists (for example, email address, SKU, or barcode).'
            : 'The database operation failed. Check that migrations are applied and review the server error log.';
    } else {
        $error = $exception->getMessage();
    }
    if (http_response_code() === 403) {
        $page = 'dashboard';
    }
}

function audit_action(PDO $db, string $actor, string $action, string $entity, string $id, array $details): void
{
    $db->prepare(
        'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, details)
         VALUES (:actor, :action, :entity, :id, CAST(:details AS jsonb))'
    )->execute([
        'actor' => $actor,
        'action' => $action,
        'entity' => $entity,
        'id' => $id,
        'details' => json_encode($details, JSON_THROW_ON_ERROR),
    ]);
}

function user_management_migration_applied(PDO $db): bool
{
    $statement = $db->query(
        "SELECT EXISTS (SELECT 1 FROM schema_migrations WHERE version = '003_user_management')"
    );

    return in_array($statement->fetchColumn(), [true, 't', '1'], true);
}

function require_user_management_migration(PDO $db): void
{
    if (!user_management_migration_applied($db)) {
        throw new RuntimeException(
            'User account management is not ready. Apply database/migrations/003_user_management.sql in the Supabase SQL Editor, then reload this page.'
        );
    }
}

function database_transaction(PDO $db, callable $operation): mixed
{
    $db->beginTransaction();
    try {
        $result = $operation();
        $db->commit();
        return $result;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function required_post(string $key, int $maxLength): string
{
    $raw = $_POST[$key] ?? null;
    if (!is_string($raw)) {
        throw new InvalidArgumentException('Enter a valid text value for ' . str_replace('_', ' ', $key) . '.');
    }
    $value = trim($raw);
    if ($value === '' || strlen($value) > $maxLength) {
        throw new InvalidArgumentException('Enter a value for ' . str_replace('_', ' ', $key) . ' (maximum ' . $maxLength . ' characters).');
    }

    return $value;
}

function post_int(string $key, int $minimum = 0): int
{
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT);
    if ($value === false || $value < $minimum) {
        throw new InvalidArgumentException('Enter a valid integer for ' . str_replace('_', ' ', $key) . '.');
    }

    return $value;
}

function post_money(string $key): float
{
    $raw = $_POST[$key] ?? null;
    if (!is_scalar($raw) || trim((string) $raw) === '' || !is_numeric($raw)) {
        throw new InvalidArgumentException('Enter a valid amount for ' . str_replace('_', ' ', $key) . '.');
    }
    $value = (float) $raw;
    if (!is_finite($value) || $value < 0 || $value > 9999999999.99) {
        throw new InvalidArgumentException('Amount must be between zero and the supported maximum.');
    }

    return round($value, 2);
}

function valid_optional_email(string $key): ?string
{
    $raw = $_POST[$key] ?? '';
    if (!is_string($raw)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    $email = trim($raw);
    if ($email === '') {
        return null;
    }
    if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }

    return $email;
}

function optional_post(string $key, int $maxLength): ?string
{
    $raw = $_POST[$key] ?? '';
    if (!is_string($raw)) {
        throw new InvalidArgumentException('Enter a valid value for ' . str_replace('_', ' ', $key) . '.');
    }
    $value = trim($raw);
    if (strlen($value) > $maxLength) {
        throw new InvalidArgumentException(str_replace('_', ' ', $key) . ' is too long.');
    }

    return $value === '' ? null : $value;
}

function optional_post_date(string $key): ?string
{
    $value = optional_post($key, 10);
    if ($value === null) {
        return null;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)
        || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
        || $value > date('Y-m-d')) {
        throw new InvalidArgumentException('Enter a valid birthdate that is not in the future.');
    }

    return $value;
}

function format_currency(mixed $value): string
{
    return '₱' . number_format((float) $value, 2);
}

function audit_action_label(string $action): string
{
    $labels = [
        'inventory.adjusted' => 'Inventory stock adjusted',
        'sale.completed' => 'Sale completed',
        'purchase.created' => 'Purchase order created',
        'purchase.received' => 'Purchase delivery received',
        'profile.created' => 'User account created',
        'profile.updated' => 'User account updated',
        'profile.deleted' => 'User account deleted',
        'profile.active_changed' => 'User access status changed',
        'profile.role_changed' => 'User role changed',
    ];

    return $labels[$action] ?? ucwords(str_replace(['.', '_'], ' ', $action));
}

function audit_detail_entries(mixed $details): array
{
    if (is_string($details)) {
        $details = json_decode($details, true);
    }
    if (!is_array($details)) {
        return [];
    }

    $entries = [];
    foreach ($details as $key => $value) {
        if (is_bool($value)) {
            $displayValue = $value ? 'Yes' : 'No';
        } elseif ($value === null) {
            $displayValue = '—';
        } elseif (is_scalar($value)) {
            $displayValue = (string) $value;
        } else {
            $displayValue = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }
        $entries[] = [
            'label' => ucwords(str_replace(['_', '.'], ' ', (string) $key)),
            'value' => $displayValue,
        ];
    }

    return $entries;
}

function rows(PDO $db, string $sql, array $params = []): array
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function one(PDO $db, string $sql, array $params = []): array
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetch() ?: [];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . Security::e(Security::csrfToken()) . '">';
}

function request_key_field(): string
{
    return '<input type="hidden" name="request_key" value="' . Security::e(Security::requestKey()) . '">';
}

function role_allows(array $user, array $roles): bool
{
    return in_array($user['role'], $roles, true);
}

try {
$loginPage = $page === 'login' || $user === null;
$roleLabels = [
    'administrator' => 'Administrator',
    'manager' => 'Manager',
    'cashier' => 'Cashier',
    'inventory_staff' => 'Inventory Staff',
];
$nav = [
    'dashboard' => 'Dashboard',
    'products' => 'Products',
    'categories' => 'Categories',
    'brands' => 'Brands',
    'suppliers' => 'Suppliers',
    'purchases' => 'Purchases',
    'sales' => 'Sales / POS',
    'inventory' => 'Inventory',
    'returns' => 'Returns',
    'reports' => 'Reports',
    'audit' => 'Audit Logs',
    'users' => 'Users',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= Security::e(Security::csrfToken()) ?>">
    <title><?= $loginPage ? 'Sign in' : Security::e($page === 'receipt' ? 'Receipt' : ($nav[$page] ?? 'Inventory')) ?> · PartsDesk</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/app.css" rel="stylesheet">
</head>
<body>
<?php if ($loginPage): ?>
    <main class="login-shell">
        <section class="login-card">
            <div class="login-brand">PartsDesk</div>
            <p class="eyebrow">INVENTORY SYSTEM</p>
            <h1>Welcome back</h1>
            <p class="text-secondary">Sign in with your Supabase Auth account.</p>
            <?php if ($authNotice !== ''): ?><div class="alert alert-warning"><?= Security::e($authNotice) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert alert-danger"><?= Security::e($error) ?></div><?php endif; ?>
            <?php if ($user !== null): ?>
                <a class="btn btn-primary w-100" href="?page=dashboard">Open dashboard</a>
            <?php else: ?>
                <form method="post" class="vstack gap-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="login">
                    <label class="form-label">Email
                        <input class="form-control" type="email" name="email" autocomplete="username" required>
                    </label>
                    <label class="form-label">Password
                        <input class="form-control" type="password" name="password" autocomplete="current-password" required>
                    </label>
                    <button class="btn btn-primary" type="submit">Sign in</button>
                </form>
            <?php endif; ?>
            <p class="small text-secondary mt-4 mb-0">Sign in with your PartsDesk account.</p>
        </section>
    </main>
<?php else: ?>
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="?page=dashboard"><span class="brand-name">PartsDesk<small>Inventory system</small></span></a>
            <div class="nav-caption">WORKSPACE</div>
            <nav class="nav flex-column">
                <?php foreach ($nav as $key => $label):
                    $visible = match ($key) {
                        'categories', 'brands', 'audit' => role_allows($user, ['administrator', 'manager']),
                        'users' => role_allows($user, ['administrator']),
                        'suppliers', 'purchases', 'inventory' => role_allows($user, ['administrator', 'manager', 'inventory_staff']),
                        'sales' => role_allows($user, ['administrator', 'manager', 'cashier']),
                        'returns' => role_allows($user, ['administrator', 'manager', 'cashier']),
                        default => true,
                    };
                    if (!$visible) continue;
                ?>
                    <a class="nav-link <?= $page === $key ? 'active' : '' ?>" href="?page=<?= Security::e($key) ?>">
                        <span class="nav-dot"></span><?= Security::e($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-footer">
                <div class="avatar"><?= Security::e(strtoupper(substr($user['name'], 0, 1))) ?></div>
                <div class="user-summary"><strong><?= Security::e($user['name']) ?></strong><small><?= Security::e($roleLabels[$user['role']] ?? $user['role']) ?></small></div>
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="logout">
                    <button class="btn btn-sm btn-outline-secondary" title="Sign out" aria-label="Sign out">↗</button>
                </form>
            </div>
        </aside>
        <main class="main-content">
            <header class="topbar">
                <div><p class="eyebrow mb-1">INVENTORY OPERATIONS</p><h1><?= Security::e($page === 'receipt' ? 'Receipt' : ($nav[$page] ?? 'Dashboard')) ?></h1></div>
                <div class="today-label"><?= date('D, M j, Y') ?></div>
            </header>
            <section class="content">
                <?php if ($error !== ''): ?><div class="alert alert-danger"><?= Security::e($error) ?></div><?php endif; ?>
                <?php if ($notice !== ''): ?><div class="alert alert-success"><?= Security::e($notice) ?></div><?php endif; ?>
                <?php if ($user === null || $db === null): ?>
                    <div class="alert alert-warning">Your session is not active. <a href="?page=login">Sign in</a>.</div>
                <?php else: ?>
                <?php
                $canManage = role_allows($user, ['administrator', 'manager']);
                $canInventory = role_allows($user, ['administrator', 'manager', 'inventory_staff']);
                $today = date('Y-m-d');
                $search = trim((string) ($_GET['q'] ?? ''));
                $pageNumber = max(1, (int) ($_GET['p'] ?? 1));
                $offset = ($pageNumber - 1) * 25;

                if ($page === 'dashboard'):
                    $stats = one($db,
                        "SELECT
                            (SELECT count(*) FROM products WHERE is_active) AS active_products,
                            (SELECT COALESCE(sum(stock_quantity), 0) FROM products WHERE is_active) AS stock_units,
                            (SELECT count(*) FROM products WHERE is_active AND stock_quantity <= minimum_stock AND stock_quantity > 0) AS low_stock,
                            (SELECT count(*) FROM products WHERE is_active AND stock_quantity = 0) AS out_of_stock,
                            (SELECT COALESCE(sum(total), 0) FROM sales WHERE status = 'completed' AND created_at::date = CURRENT_DATE) AS today_sales,
                            (SELECT count(*) FROM purchases WHERE status IN ('ordered', 'partially_received')) AS pending_purchases"
                    );
                    $profit = one($db,
                        "SELECT COALESCE(sum(COALESCE(costs.revenue, 0) - COALESCE(costs.cost, 0)
                            - COALESCE(refunds.refund, 0) + COALESCE(refunds.returned_cost, 0)), 0) AS gross_profit
                         FROM sales s
                         LEFT JOIN (
                             SELECT sale_id, sum(line_total) AS revenue, sum(quantity * unit_cost) AS cost
                             FROM sale_items GROUP BY sale_id
                         ) costs USING (sale_id)
                         LEFT JOIN (
                             SELECT r.sale_id, sum(r.refund_amount) AS refund,
                                    sum(ri.quantity * si.unit_cost) FILTER (WHERE ri.inventory_disposition = 'restock') AS returned_cost
                             FROM returns r JOIN return_items ri USING (return_id)
                             JOIN sale_items si USING (sale_item_id)
                             WHERE r.status = 'approved' AND r.created_at::date = CURRENT_DATE
                             GROUP BY r.sale_id
                         ) refunds USING (sale_id)
                         WHERE s.status = 'completed' AND s.created_at::date = CURRENT_DATE"
                    );
                    $lowStock = rows($db,
                        'SELECT p.sku, p.name, p.stock_quantity, p.minimum_stock, c.name AS category
                         FROM products p LEFT JOIN categories c USING (category_id)
                         WHERE p.is_active AND p.stock_quantity <= p.minimum_stock
                         ORDER BY p.stock_quantity ASC, p.name LIMIT 8'
                    );
                    $recentSales = rows($db,
                        "SELECT s.receipt_number, s.total, s.payment_method, s.created_at, p.full_name
                         FROM sales s JOIN app_profiles p ON p.user_id = s.cashier_id
                         WHERE s.status = :status" . ($canManage ? '' : ' AND s.cashier_id = :cashier') . "
                         ORDER BY s.created_at DESC LIMIT 6",
                        $canManage ? ['status' => 'completed'] : ['status' => 'completed', 'cashier' => $user['id']]
                    );
                    $recentMovements = rows($db,
                        'SELECT m.transaction_type, m.quantity_change, m.new_stock, m.created_at,
                                p.name AS product, p.sku, ap.full_name AS responsible
                         FROM inventory_movements m JOIN products p USING (product_id)
                         JOIN app_profiles ap ON ap.user_id = m.responsible_user
                         ORDER BY m.created_at DESC LIMIT 6'
                    );
                    $trend = rows($db,
                        "SELECT created_at::date AS day, sum(total)::numeric(14,2) AS total
                         FROM sales WHERE status = 'completed'" . ($canManage ? '' : ' AND cashier_id = :cashier') . "
                           AND created_at >= CURRENT_DATE - INTERVAL '6 days'
                         GROUP BY created_at::date ORDER BY day",
                        $canManage ? [] : ['cashier' => $user['id']]
                    );
                ?>
                    <div class="row g-3 mb-4">
                        <?php foreach ([
                            ['Active products', $stats['active_products'], 'Catalog'],
                            ['Stock units', $stats['stock_units'], 'Across all active SKUs'],
                            ['Low stock', $stats['low_stock'], 'At or below minimum'],
                            ['Out of stock', $stats['out_of_stock'], 'Needs replenishment'],
                            ["Today's sales", '₱' . number_format((float) $stats['today_sales'], 2), 'Completed receipts'],
                            ["Today's gross profit", '₱' . number_format((float) $profit['gross_profit'], 2), 'Weighted-average cost'],
                        ] as $index => [$label, $value, $hint]): ?>
                            <?php if ($index < 4 || $canManage): ?><div class="col-6 col-xl-2"><article class="stat-card"><div class="stat-icon tone-<?= $index % 4 ?>"><?= ['↗', '▦', '!', '○', '₱', '⌁'][$index] ?></div><div class="stat-value"><?= Security::e($value) ?></div><div class="stat-label"><?= Security::e($label) ?></div><small><?= Security::e($hint) ?></small></article></div><?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-7"><article class="panel h-100"><div class="panel-heading"><div><h2>Sales trend</h2><p>Completed sales · last 7 days</p></div><a href="?page=reports">View reports</a></div><div class="chart-wrap"><canvas id="salesChart" aria-label="Sales trend chart"></canvas></div></article></div>
                        <div class="col-lg-5"><article class="panel h-100"><div class="panel-heading"><div><h2>Stock watch</h2><p><?= Security::e($stats['pending_purchases']) ?> open purchase orders</p></div><a href="?page=products">Products</a></div>
                            <?php if (!$lowStock): ?><div class="empty-state">All products are above their minimum stock levels.</div><?php else: ?>
                                <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Product</th><th>On hand</th><th>Status</th></tr></thead><tbody>
                                <?php foreach ($lowStock as $product): ?><tr><td><strong><?= Security::e($product['name']) ?></strong><small class="d-block text-secondary"><?= Security::e($product['sku']) ?></small></td><td><?= Security::e($product['stock_quantity']) ?></td><td><span class="badge <?= (int) $product['stock_quantity'] === 0 ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= (int) $product['stock_quantity'] === 0 ? 'Out' : 'Low' ?></span></td></tr><?php endforeach; ?>
                                </tbody></table></div>
                            <?php endif; ?>
                        </article></div>
                    </div>
                    <article class="panel mt-3"><div class="panel-heading"><div><h2>Recent sales</h2><p>Latest completed receipts</p></div><a href="?page=sales">Sales history</a></div>
                        <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Receipt</th><th>Cashier</th><th>Payment</th><th>Date</th><th class="text-end">Total</th></tr></thead><tbody>
                        <?php foreach ($recentSales as $sale): ?><tr><td><?= Security::e($sale['receipt_number']) ?></td><td><?= Security::e($sale['full_name']) ?></td><td><?= Security::e(ucwords(str_replace('_', ' ', $sale['payment_method']))) ?></td><td><?= Security::e(date('M j, H:i', strtotime($sale['created_at']))) ?></td><td class="text-end">₱<?= number_format((float) $sale['total'], 2) ?></td></tr><?php endforeach; ?>
                        <?php if (!$recentSales): ?><tr><td colspan="5" class="text-secondary">No sales recorded yet.</td></tr><?php endif; ?>
                        </tbody></table></div>
                    </article>
                    <article class="panel mt-3"><div class="panel-heading"><div><h2>Recent stock activity</h2><p>Receipts, sales, returns, and adjustments</p></div><a href="?page=inventory">Inventory movements</a></div>
                        <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Product</th><th>Movement</th><th>Balance</th><th>By</th><th>Date</th></tr></thead><tbody>
                        <?php foreach ($recentMovements as $movement): ?><tr><td><?= Security::e($movement['product']) ?><small class="d-block text-secondary"><?= Security::e($movement['sku']) ?></small></td><td><?= Security::e(ucwords(str_replace('_', ' ', $movement['transaction_type']))) ?> · <?= (int) $movement['quantity_change'] > 0 ? '+' : '' ?><?= Security::e($movement['quantity_change']) ?></td><td><?= Security::e($movement['new_stock']) ?></td><td><?= Security::e($movement['responsible']) ?></td><td><?= Security::e(date('M j, H:i', strtotime($movement['created_at']))) ?></td></tr><?php endforeach; ?>
                        <?php if (!$recentMovements): ?><tr><td colspan="5" class="text-secondary">No inventory movements recorded yet.</td></tr><?php endif; ?>
                        </tbody></table></div>
                    </article>
                    <script>window.salesTrend = <?= json_encode($trend, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>

                <?php elseif ($page === 'products'):
                    $query = $db->prepare(
                        'SELECT p.*, CASE WHEN p.is_active THEN 1 ELSE 0 END AS active_int,
                                c.name AS category_name, b.name AS brand_name FROM products p
                         LEFT JOIN categories c USING (category_id) LEFT JOIN brands b USING (brand_id)
                         WHERE p.name ILIKE :search_name OR p.sku ILIKE :search_sku
                            OR COALESCE(p.barcode, \'\') ILIKE :search_barcode
                            OR COALESCE(p.model, \'\') ILIKE :search_model OR COALESCE(b.name, \'\') ILIKE :search_brand
                         ORDER BY p.name LIMIT 25 OFFSET :offset'
                    );
                    foreach (['name', 'sku', 'barcode', 'model', 'brand'] as $field) {
                        $query->bindValue('search_' . $field, '%' . $search . '%');
                    }
                    $query->bindValue('offset', $offset, PDO::PARAM_INT);
                    $query->execute();
                    $products = $query->fetchAll();
                    $categories = rows($db, 'SELECT category_id, name FROM categories WHERE is_active ORDER BY name');
                    $brands = rows($db, 'SELECT brand_id, name FROM brands WHERE is_active ORDER BY name');
                ?>
                    <div class="page-toolbar"><form class="search-form"><input type="hidden" name="page" value="products"><input class="form-control" type="search" name="q" value="<?= Security::e($search) ?>" placeholder="Search name, SKU, barcode, model…"><button class="btn btn-outline-primary">Search</button></form>
                    <?php if ($canInventory): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal">＋ Add product</button><?php endif; ?></div>
                    <article class="panel"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Product</th><th>Category / Brand</th><th>SKU</th><th>Stock</th><th>Price</th><th>Status</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead><tbody>
                    <?php foreach ($products as $product): ?><tr><td><strong><?= Security::e($product['name']) ?></strong><small class="d-block text-secondary"><?= Security::e($product['model'] ?? '') ?></small></td><td><?= Security::e(($product['category_name'] ?? 'Uncategorized') . ' · ' . ($product['brand_name'] ?? 'No brand')) ?></td><td><code><?= Security::e($product['sku']) ?></code></td><td><?= Security::e($product['stock_quantity']) ?> <small class="text-secondary"><?= Security::e($product['unit']) ?></small></td><td>₱<?= number_format((float) $product['selling_price'], 2) ?></td>                    <td><span class="badge <?= $product['active_int'] ? ((int) $product['stock_quantity'] <= (int) $product['minimum_stock'] ? 'text-bg-warning' : 'text-bg-success') : 'text-bg-secondary' ?>"><?= $product['active_int'] ? ((int) $product['stock_quantity'] <= (int) $product['minimum_stock'] ? 'Low stock' : 'Active') : 'Inactive' ?></span></td>
                        <?php if ($canManage): ?><td><button class="btn btn-sm btn-outline-secondary" data-product="<?= Security::e(json_encode($product, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>" onclick="editProduct(this)">Edit</button></td><?php endif; ?></tr><?php endforeach; ?>
                    <?php if (!$products): ?><tr><td colspan="7" class="text-secondary">No products match this search.</td></tr><?php endif; ?>
                    </tbody></table></div><div class="panel-foot"><span>Page <?= $pageNumber ?></span><div><a class="btn btn-sm btn-outline-secondary <?= $pageNumber <= 1 ? 'disabled' : '' ?>" href="?page=products&q=<?= rawurlencode($search) ?>&p=<?= max(1, $pageNumber - 1) ?>">Previous</a> <a class="btn btn-sm btn-outline-secondary" href="?page=products&q=<?= rawurlencode($search) ?>&p=<?= $pageNumber + 1 ?>">Next</a></div></div></article>
                    <?php if ($canInventory): ?><div class="modal fade" id="productModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="product_create"><div class="modal-header"><h2 class="modal-title fs-5">Add product</h2><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3">
                        <div class="col-md-4"><label class="form-label">SKU<input class="form-control" name="sku" required maxlength="80"></label></div><div class="col-md-4"><label class="form-label">Barcode<input class="form-control" name="barcode"></label></div><div class="col-md-4"><label class="form-label">Product name<input class="form-control" name="name" required></label></div>
                        <div class="col-md-4"><label class="form-label">Category<select class="form-select" name="category_id"><option value="">None</option><?php foreach ($categories as $c): ?><option value="<?= $c['category_id'] ?>"><?= Security::e($c['name']) ?></option><?php endforeach; ?></select></label></div><div class="col-md-4"><label class="form-label">Brand<select class="form-select" name="brand_id"><option value="">None</option><?php foreach ($brands as $b): ?><option value="<?= $b['brand_id'] ?>"><?= Security::e($b['name']) ?></option><?php endforeach; ?></select></label></div><div class="col-md-4"><label class="form-label">Model<input class="form-control" name="model"></label></div>
                        <div class="col-md-3"><label class="form-label">Selling price<input class="form-control" name="selling_price" type="number" step=".01" min="0" required></label></div><div class="col-md-3"><label class="form-label">Minimum stock<input class="form-control" name="minimum_stock" type="number" min="0" value="0"></label></div><div class="col-md-3"><label class="form-label">Warranty (months)<input class="form-control" name="warranty_months" type="number" min="0"></label></div><div class="col-md-3"><label class="form-label">Unit<input class="form-control" name="unit" value="unit" required></label></div>
                        <div class="col-md-4"><label class="form-label">Socket spec<input class="form-control" name="spec_socket"></label></div><div class="col-md-4"><label class="form-label">Capacity spec<input class="form-control" name="spec_capacity"></label></div><div class="col-md-4"><label class="form-label">Interface spec<input class="form-control" name="spec_interface"></label></div><div class="col-12"><label class="form-label">Description<textarea class="form-control" name="description" rows="2"></textarea></label></div>
                    </div><p class="small text-secondary mt-3">Initial inventory must enter through a purchase receipt or authorized adjustment.</p></div><div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save product</button></div></form></div></div></div><?php endif; ?>
                    <?php if ($canManage): ?><div class="modal fade" id="editProductModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="product_update"><input type="hidden" id="edit-product-id" name="product_id"><div class="modal-header"><h2 class="modal-title fs-5">Edit product</h2><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label class="form-label">Name<input class="form-control" id="edit-product-name" name="name" required></label><label class="form-label">Description<textarea class="form-control" id="edit-product-description" name="description"></textarea></label><label class="form-label">Selling price<input class="form-control" id="edit-product-price" name="selling_price" type="number" min="0" step=".01" required></label><label class="form-label">Minimum stock<input class="form-control" id="edit-product-min" name="minimum_stock" type="number" min="0" required></label><label class="form-check"><input class="form-check-input" id="edit-product-active" name="is_active" type="checkbox" value="1"> Active for sale</label></div><div class="modal-footer"><button class="btn btn-primary">Save changes</button></div></form></div></div></div><?php endif; ?>

                <?php elseif ($page === 'categories' || $page === 'brands'):
                    $isCategory = $page === 'categories';
                    $entities = rows($db, $isCategory
                        ? "SELECT *, CASE WHEN is_active THEN 1 ELSE 0 END AS active_int FROM categories ORDER BY name"
                        : "SELECT *, CASE WHEN is_active THEN 1 ELSE 0 END AS active_int FROM brands ORDER BY name");
                ?>
                    <?php if ($canManage): ?><article class="panel mb-3"><form method="post" class="inline-create"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $isCategory ? 'category_create' : 'brand_create' ?>"><div><h2>Add <?= $isCategory ? 'category' : 'brand' ?></h2><p>Names must be unique.</p></div><input class="form-control" name="name" placeholder="<?= $isCategory ? 'e.g. Processors' : 'e.g. Northstar Components' ?>" required><button class="btn btn-primary">Add</button><?php if ($isCategory): ?><input class="form-control" name="description" placeholder="Description"><?php endif; ?></form></article><?php endif; ?>
                    <article class="panel"><div class="table-responsive"><table class="table"><thead><tr><th>Name</th><?php if ($isCategory): ?><th>Description</th><?php endif; ?><th>Status</th><th>Manage</th></tr></thead><tbody><?php foreach ($entities as $entity): ?><tr><td><?= Security::e($entity['name']) ?></td><?php if ($isCategory): ?><td><?= Security::e($entity['description'] ?? '') ?></td><?php endif; ?><td><?= $entity['active_int'] ? 'Active' : 'Inactive' ?></td><td><details><summary class="btn btn-sm btn-outline-secondary">Edit</summary><form method="post" class="vstack gap-2 mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $isCategory ? 'category_update' : 'brand_update' ?>"><input type="hidden" name="<?= $isCategory ? 'category_id' : 'brand_id' ?>" value="<?= (int) $entity[$isCategory ? 'category_id' : 'brand_id'] ?>"><label class="form-label mb-0">Name<input class="form-control" name="name" maxlength="150" value="<?= Security::e($entity['name']) ?>" required></label><?php if ($isCategory): ?><label class="form-label mb-0">Description<textarea class="form-control" name="description" rows="2"><?= Security::e($entity['description'] ?? '') ?></textarea></label><?php endif; ?><label class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?= $entity['active_int'] ? 'checked' : '' ?>> Active</label><button class="btn btn-sm btn-primary">Save</button></form></details></td></tr><?php endforeach; ?></tbody></table></div></article>

                <?php elseif ($page === 'suppliers'):
                    $suppliers = rows($db, 'SELECT *, CASE WHEN is_active THEN 1 ELSE 0 END AS active_int FROM suppliers ORDER BY name');
                    $supplierProducts = rows($db,
                        'SELECT sp.supplier_id, sp.product_id, sp.last_unit_cost, s.name AS supplier, p.sku, p.name AS product
                         FROM supplier_products sp JOIN suppliers s USING (supplier_id)
                         JOIN products p USING (product_id) ORDER BY s.name, p.name'
                    );
                    $products = rows($db, 'SELECT product_id, sku, name FROM products WHERE is_active ORDER BY name');
                ?>
                    <?php if ($canInventory): ?><article class="panel mb-3"><h2>Add supplier</h2><form method="post" class="row g-3 mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="supplier_create"><div class="col-md-3"><input class="form-control" name="name" placeholder="Supplier name" required></div><div class="col-md-2"><input class="form-control" name="contact_person" placeholder="Contact person"></div><div class="col-md-2"><input class="form-control" name="phone" placeholder="Phone"></div><div class="col-md-2"><input class="form-control" name="email" type="email" placeholder="Email"></div><div class="col-md-2"><input class="form-control" name="address" placeholder="Address"></div><div class="col-md-1"><button class="btn btn-primary">Add</button></div></form></article><?php endif; ?>
                    <article class="panel"><div class="table-responsive"><table class="table"><thead><tr><th>Supplier</th><th>Contact</th><th>Phone</th><th>Email</th><th>Address</th><th>Status</th><th>Manage</th></tr></thead><tbody><?php foreach ($suppliers as $supplier): ?><tr><td><?= Security::e($supplier['name']) ?></td><td><?= Security::e($supplier['contact_person'] ?? '') ?></td><td><?= Security::e($supplier['phone'] ?? '') ?></td><td><?= Security::e($supplier['email'] ?? '') ?></td><td><?= Security::e($supplier['address'] ?? '') ?></td><td><?= $supplier['active_int'] ? 'Active' : 'Inactive' ?></td><td><details><summary class="btn btn-sm btn-outline-secondary">Edit</summary><form method="post" class="vstack gap-2 mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="supplier_update"><input type="hidden" name="supplier_id" value="<?= (int) $supplier['supplier_id'] ?>"><label class="form-label mb-0">Name<input class="form-control" name="name" maxlength="250" value="<?= Security::e($supplier['name']) ?>" required></label><label class="form-label mb-0">Contact<input class="form-control" name="contact_person" value="<?= Security::e($supplier['contact_person'] ?? '') ?>"></label><label class="form-label mb-0">Phone<input class="form-control" name="phone" value="<?= Security::e($supplier['phone'] ?? '') ?>"></label><label class="form-label mb-0">Email<input class="form-control" name="email" type="email" value="<?= Security::e($supplier['email'] ?? '') ?>"></label><label class="form-label mb-0">Address<input class="form-control" name="address" value="<?= Security::e($supplier['address'] ?? '') ?>"></label><label class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?= $supplier['active_int'] ? 'checked' : '' ?>> Active</label><button class="btn btn-sm btn-primary">Save supplier</button></form></details></td></tr><?php endforeach; ?></tbody></table></div></article>
                    <?php if ($canInventory): ?><article class="panel mt-3"><h2>Supplier product catalog</h2><p>Maintain the supplier's SKU relationships and latest quoted unit cost.</p><form method="post" class="row g-3 align-items-end mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="supplier_product_save"><div class="col-md-4"><label class="form-label">Supplier<select class="form-select" name="supplier_id" required><option value="">Select supplier</option><?php foreach ($suppliers as $supplier): ?><?php if ($supplier['active_int']): ?><option value="<?= (int) $supplier['supplier_id'] ?>"><?= Security::e($supplier['name']) ?></option><?php endif; ?><?php endforeach; ?></select></label></div><div class="col-md-5"><label class="form-label">Product<select class="form-select" name="product_id" required><option value="">Select product</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['product_id'] ?>"><?= Security::e($product['sku'] . ' · ' . $product['name']) ?></option><?php endforeach; ?></select></label></div><div class="col-md-2"><label class="form-label">Latest unit cost<input class="form-control" name="last_unit_cost" type="number" min="0" step=".01" required></label></div><div class="col-md-1"><button class="btn btn-primary">Save</button></div></form><div class="table-responsive"><table class="table"><thead><tr><th>Supplier</th><th>Product</th><th>Latest unit cost</th><th></th></tr></thead><tbody><?php foreach ($supplierProducts as $mapping): ?><tr><td><?= Security::e($mapping['supplier']) ?></td><td><?= Security::e($mapping['sku'] . ' · ' . $mapping['product']) ?></td><td><?= $mapping['last_unit_cost'] === null ? '—' : '₱' . number_format((float) $mapping['last_unit_cost'], 2) ?></td><td><form method="post" data-confirm="Remove this supplier-product mapping?"><?= csrf_field() ?><input type="hidden" name="action" value="supplier_product_delete"><input type="hidden" name="supplier_id" value="<?= (int) $mapping['supplier_id'] ?>"><input type="hidden" name="product_id" value="<?= (int) $mapping['product_id'] ?>"><button class="btn btn-sm btn-outline-danger">Remove</button></form></td></tr><?php endforeach; ?><?php if (!$supplierProducts): ?><tr><td colspan="4" class="text-secondary">No supplier products mapped.</td></tr><?php endif; ?></tbody></table></div></article><?php endif; ?>

                <?php elseif ($page === 'purchases'):
                    $suppliers = rows($db, 'SELECT supplier_id, name FROM suppliers WHERE is_active ORDER BY name');
                    $products = rows($db, 'SELECT product_id, sku, name FROM products WHERE is_active ORDER BY name');
                    $purchaseRows = rows($db,
                        'SELECT p.*, s.name AS supplier, ap.full_name AS creator, pi.purchase_item_id, pi.product_id, pi.ordered_quantity, pi.received_quantity, pi.unit_cost, pr.name AS product, pr.sku
                         FROM purchases p JOIN suppliers s USING (supplier_id) JOIN app_profiles ap ON ap.user_id = p.created_by
                         JOIN purchase_items pi USING (purchase_id) JOIN products pr USING (product_id)
                         WHERE p.purchase_id IN (SELECT purchase_id FROM purchases ORDER BY created_at DESC LIMIT 100)
                         ORDER BY p.created_at DESC, pi.purchase_item_id'
                    );
                    $purchases = [];
                    foreach ($purchaseRows as $purchaseRow) {
                        $purchaseId = (int) $purchaseRow['purchase_id'];
                        if (!isset($purchases[$purchaseId])) {
                            $purchases[$purchaseId] = ['header' => $purchaseRow, 'items' => []];
                        }
                        $purchases[$purchaseId]['items'][] = $purchaseRow;
                    }
                ?>
                    <?php if ($canInventory): ?><article class="panel mb-3"><h2>Create purchase order</h2><form method="post" class="mt-1" id="purchase-form"><?= csrf_field() ?><input type="hidden" name="action" value="purchase_create"><div class="row g-3"><div class="col-md-5"><label class="form-label">Supplier<select class="form-select" name="supplier_id" required><option value="">Select supplier</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int) $supplier['supplier_id'] ?>"><?= Security::e($supplier['name']) ?></option><?php endforeach; ?></select></label></div><div class="col-md-7 d-flex align-items-end"><p class="small text-secondary mb-2">Add up to 50 distinct products. Each line can be received in multiple deliveries.</p></div></div><div id="purchase-lines" class="vstack gap-2 mt-2"><div class="row g-2 align-items-end purchase-line"><div class="col-md-6"><label class="form-label">Product<select class="form-select" name="product_id[]" required><option value="">Select product</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['product_id'] ?>"><?= Security::e($product['sku'] . ' · ' . $product['name']) ?></option><?php endforeach; ?></select></label></div><div class="col-md-2"><label class="form-label">Quantity<input class="form-control" name="quantity[]" type="number" min="1" required></label></div><div class="col-md-2"><label class="form-label">Unit cost<input class="form-control" name="unit_cost[]" type="number" min="0" max="9999999999.99" step=".01" required></label></div><div class="col-md-2"><button type="button" class="btn btn-outline-danger remove-purchase-line" disabled>Remove</button></div></div></div><template id="purchase-line-template"><div class="row g-2 align-items-end purchase-line"><div class="col-md-6"><label class="form-label">Product<select class="form-select" name="product_id[]" required><option value="">Select product</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['product_id'] ?>"><?= Security::e($product['sku'] . ' · ' . $product['name']) ?></option><?php endforeach; ?></select></label></div><div class="col-md-2"><label class="form-label">Quantity<input class="form-control" name="quantity[]" type="number" min="1" required></label></div><div class="col-md-2"><label class="form-label">Unit cost<input class="form-control" name="unit_cost[]" type="number" min="0" max="9999999999.99" step=".01" required></label></div><div class="col-md-2"><button type="button" class="btn btn-outline-danger remove-purchase-line">Remove</button></div></div></template><div class="d-flex gap-2 mt-3"><button type="button" class="btn btn-outline-primary" id="add-purchase-line">Add product line</button><button class="btn btn-primary">Create order</button></div></form></article><?php endif; ?>
                    <article class="panel"><h2>Purchase orders</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Purchase</th><th>Supplier</th><th>Product</th><th>Ordered / Received</th><th>Cost</th><th>Status</th><?php if ($canInventory): ?><th>Receive</th><?php endif; ?></tr></thead><?php foreach ($purchases as $purchaseGroup): ?><?php $purchase = $purchaseGroup['header']; ?><tbody><tr class="table-light"><th colspan="<?= $canInventory ? 7 : 6 ?>"><?= Security::e($purchase['purchase_number']) ?> · <?= Security::e($purchase['supplier']) ?><small class="d-block text-secondary"><?= Security::e(date('M j, Y', strtotime($purchase['created_at']))) ?> · <?= count($purchaseGroup['items']) ?> item(s) · Order total $<?= number_format((float) $purchase['total_amount'], 2) ?></small></th></tr><?php foreach ($purchaseGroup['items'] as $item): ?><tr><td><?= Security::e($purchase['purchase_number']) ?></td><td><?= Security::e($purchase['supplier']) ?></td><td><?= Security::e($item['sku'] . ' · ' . $item['product']) ?></td><td><?= Security::e($item['ordered_quantity']) ?> / <?= Security::e($item['received_quantity']) ?></td><td>₱<?= number_format((float) $item['unit_cost'], 2) ?></td><td><span class="badge text-bg-light"><?= Security::e(str_replace('_', ' ', $purchase['status'])) ?></span></td><?php if ($canInventory): ?><td><?php if (!in_array($purchase['status'], ['received', 'cancelled'], true) && (int) $item['received_quantity'] < (int) $item['ordered_quantity']): ?><form method="post" class="receive-form"><?= csrf_field() ?><input type="hidden" name="action" value="purchase_receive"><input type="hidden" name="purchase_id" value="<?= (int) $purchase['purchase_id'] ?>"><input type="hidden" name="purchase_item_id" value="<?= (int) $item['purchase_item_id'] ?>"><?= request_key_field() ?><div class="input-group input-group-sm"><input class="form-control" name="quantity" type="number" min="1" max="<?= (int) $item['ordered_quantity'] - (int) $item['received_quantity'] ?>" placeholder="Qty" required><button class="btn btn-outline-primary">Receive</button></div></form><?php else: ?>—<?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?></tbody><?php endforeach; ?><?php if (!$purchases): ?><tbody><tr><td colspan="<?= $canInventory ? 7 : 6 ?>" class="text-secondary">No purchase orders yet.</td></tr></tbody><?php endif; ?></table></div></article>

                <?php elseif ($page === 'sales'):
                    $products = rows($db, 'SELECT product_id, sku, name, selling_price, stock_quantity FROM products WHERE is_active AND stock_quantity > 0 ORDER BY name');
                    $sales = rows($db,
                        "SELECT s.*, p.full_name FROM sales s JOIN app_profiles p ON p.user_id = s.cashier_id
                         WHERE TRUE" . ($canManage ? '' : ' AND s.cashier_id = :cashier') . "
                         ORDER BY s.created_at DESC LIMIT 100",
                        $canManage ? [] : ['cashier' => $user['id']]
                    );
                ?>
                    <?php if (role_allows($user, ['administrator', 'manager', 'cashier'])): ?><article class="panel mb-3"><div class="panel-heading"><div><h2>Point of sale</h2><p>Stock is revalidated and reserved inside checkout transaction.</p></div></div>
                    <form id="checkout-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sale_checkout"><?= request_key_field() ?><input type="hidden" name="cart" id="cart-json">
                        <div class="row g-3 align-items-end"><div class="col-md-6"><label class="form-label">Product<select id="pos-product" class="form-select"><option value="">Choose an in-stock product</option><?php foreach ($products as $p): ?><option value="<?= $p['product_id'] ?>" data-price="<?= Security::e($p['selling_price']) ?>" data-stock="<?= $p['stock_quantity'] ?>"><?= Security::e($p['sku'] . ' · ' . $p['name']) ?> · <?= $p['stock_quantity'] ?> available · $<?= number_format((float) $p['selling_price'], 2) ?></option><?php endforeach; ?></select></label></div><div class="col-md-2"><label class="form-label">Qty<input class="form-control" type="number" id="pos-quantity" min="1" value="1"></label></div><div class="col-md-2"><button type="button" class="btn btn-outline-primary" id="add-to-cart">Add item</button></div></div>
                        <div class="table-responsive mt-3"><table class="table" id="cart-table"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th><th></th></tr></thead><tbody><tr class="cart-empty"><td colspan="5" class="text-secondary">Your cart is empty.</td></tr></tbody></table></div>
                        <div class="row g-3 justify-content-end"><div class="col-md-2"><label class="form-label">Discount<input class="form-control" name="discount" type="number" min="0" step=".01" value="0"></label></div><div class="col-md-2"><label class="form-label">Tax<input class="form-control" name="tax" type="number" min="0" step=".01" value="0"></label></div><div class="col-md-2"><label class="form-label">Payment<select name="payment_method" class="form-select"><option value="cash">Cash</option><option value="card">Card</option><option value="bank_transfer">Bank transfer</option><option value="other">Other</option></select></label></div><div class="col-md-2"><button class="btn btn-primary w-100" id="complete-sale">Complete sale</button></div></div>
                    </form></article><?php endif; ?>
                    <article class="panel"><h2>Sales history</h2><div class="table-responsive"><table class="table"><thead><tr><th>Receipt</th><th>Cashier</th><th>Payment</th><th>Status</th><th>Date</th><th class="text-end">Total</th></tr></thead><tbody><?php foreach ($sales as $sale): ?><tr><td><a href="?page=receipt&id=<?= (int) $sale['sale_id'] ?>"><?= Security::e($sale['receipt_number']) ?></a></td><td><?= Security::e($sale['full_name']) ?></td><td><?= Security::e(ucwords(str_replace('_', ' ', $sale['payment_method']))) ?></td><td><?= Security::e($sale['status']) ?></td><td><?= Security::e(date('M j, Y H:i', strtotime($sale['created_at']))) ?></td><td class="text-end">₱<?= number_format((float) $sale['total'], 2) ?></td></tr><?php endforeach; ?></tbody></table></div></article>

                <?php elseif ($page === 'receipt'):
                    $receiptId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
                    if ($receiptId === false || $receiptId < 1) {
                        throw new InvalidArgumentException('Choose a valid receipt.');
                    }
                    $receiptParams = ['id' => $receiptId];
                    $receiptScope = '';
                    if (!$canManage) {
                        $receiptScope = ' AND s.cashier_id = :cashier';
                        $receiptParams['cashier'] = $user['id'];
                    }
                    $receipt = one($db,
                        "SELECT s.*, p.full_name FROM sales s JOIN app_profiles p ON p.user_id = s.cashier_id
                         WHERE s.sale_id = :id" . $receiptScope,
                        $receiptParams
                    );
                    if (!$receipt) {
                        throw new RuntimeException('Receipt not found or access denied.');
                    }
                    $receiptItems = rows($db,
                        'SELECT si.*, p.name AS product, p.sku FROM sale_items si JOIN products p USING (product_id)
                         WHERE si.sale_id = :id ORDER BY si.sale_item_id',
                        ['id' => $receiptId]
                    );
                ?>
                    <article class="panel receipt-panel"><div class="receipt-head"><div class="receipt-brand">PartsDesk</div><div><h2>Receipt</h2><p><?= Security::e($receipt['receipt_number']) ?></p></div><button class="btn btn-outline-primary ms-auto" onclick="window.print()">Print receipt</button></div><hr>
                        <div class="row small text-secondary mb-3"><div class="col-6"><strong>Cashier:</strong> <?= Security::e($receipt['full_name']) ?></div><div class="col-6 text-end"><strong>Date:</strong> <?= Security::e(date('M j, Y H:i', strtotime($receipt['created_at']))) ?></div></div>
                        <div class="table-responsive"><table class="table"><thead><tr><th>Item</th><th>Qty</th><th>Unit price</th><th>Discount</th><th class="text-end">Line total</th></tr></thead><tbody><?php foreach ($receiptItems as $item): ?><tr><td><?= Security::e($item['product']) ?><small class="d-block text-secondary"><?= Security::e($item['sku']) ?></small></td><td><?= Security::e($item['quantity']) ?></td><td>₱<?= number_format((float) $item['unit_price'], 2) ?></td><td>₱<?= number_format((float) $item['discount'], 2) ?></td><td class="text-end">₱<?= number_format((float) $item['line_total'], 2) ?></td></tr><?php endforeach; ?></tbody></table></div>
                        <div class="receipt-totals"><div><span>Subtotal</span><strong>₱<?= number_format((float) $receipt['subtotal'], 2) ?></strong></div><div><span>Discount</span><strong>−₱<?= number_format((float) $receipt['discount'], 2) ?></strong></div><div><span>Tax</span><strong>₱<?= number_format((float) $receipt['tax'], 2) ?></strong></div><div class="receipt-grand"><span>Total paid · <?= Security::e(ucwords(str_replace('_', ' ', $receipt['payment_method']))) ?></span><strong>₱<?= number_format((float) $receipt['total'], 2) ?></strong></div></div>
                        <a class="btn btn-outline-secondary mt-4" href="?page=sales">Back to sales</a>
                    </article>

                <?php elseif ($page === 'inventory'):
                    $movements = rows($db,
                        'SELECT m.*, p.name AS product, p.sku, ap.full_name FROM inventory_movements m
                         JOIN products p USING (product_id) JOIN app_profiles ap ON ap.user_id = m.responsible_user
                         ORDER BY m.created_at DESC LIMIT 100'
                    );
                    $products = rows($db, 'SELECT product_id, sku, name, stock_quantity FROM products WHERE is_active ORDER BY name');
                ?>
                    <?php if ($canManage): ?><article class="panel mb-3"><h2>Adjust inventory count</h2><p>Enter the physical count. The system calculates and records the difference automatically.</p><form method="post" class="row g-3 align-items-end"><?= csrf_field() ?><input type="hidden" name="action" value="stock_adjust"><div class="col-md-4"><label class="form-label">Product<select class="form-select" name="product_id" id="stock-product-select" required><option value="">Select product</option><?php foreach ($products as $p): ?><option value="<?= $p['product_id'] ?>" data-stock="<?= (int) $p['stock_quantity'] ?>"><?= Security::e($p['sku'] . ' · ' . $p['name']) ?> (on hand: <?= (int) $p['stock_quantity'] ?>)</option><?php endforeach; ?></select></label></div><div class="col-md-2"><label class="form-label">Counted stock<input class="form-control" name="counted_stock" id="stock-counted-stock" type="number" min="0" step="1" placeholder="Physical count" required></label></div><div class="col-md-2"><div class="form-label">Calculated change</div><output id="stock-adjustment-delta" class="adjustment-preview">Select a product</output></div><div class="col-md-3"><label class="form-label">Reason<input class="form-control" name="reason" maxlength="500" placeholder="Reason for count difference" required></label></div><div class="col-md-1"><button class="btn btn-primary">Save</button></div></form></article><?php endif; ?>
                    <article class="panel"><h2>Recent inventory movements</h2><div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Product</th><th>Type</th><th>Change</th><th>Previous</th><th>New</th><th>By / Reason</th></tr></thead><tbody><?php foreach ($movements as $movement): ?><tr><td><?= Security::e(date('M j H:i', strtotime($movement['created_at']))) ?></td><td><?= Security::e($movement['product']) ?><small class="d-block text-secondary"><?= Security::e($movement['sku']) ?></small></td><td><?= Security::e(ucwords(str_replace('_', ' ', $movement['transaction_type']))) ?></td><td class="<?= (int) $movement['quantity_change'] < 0 ? 'text-danger' : 'text-success' ?>"><?= (int) $movement['quantity_change'] > 0 ? '+' : '' ?><?= Security::e($movement['quantity_change']) ?></td><td><?= Security::e($movement['previous_stock']) ?></td><td><?= Security::e($movement['new_stock']) ?></td><td><?= Security::e($movement['full_name']) ?><small class="d-block text-secondary"><?= Security::e($movement['reason'] ?? '') ?></small></td></tr><?php endforeach; ?></tbody></table></div></article>

                <?php elseif ($page === 'returns'):
                    $returnSql = 'SELECT r.*, s.receipt_number, requester.full_name AS requester, approver.full_name AS approver
                         FROM returns r JOIN sales s USING (sale_id) JOIN app_profiles requester ON requester.user_id = r.requested_by
                         LEFT JOIN app_profiles approver ON approver.user_id = r.approved_by
                         WHERE TRUE' . ($canManage ? '' : ' AND r.requested_by = :requester') . '
                         ORDER BY r.created_at DESC LIMIT 100';
                    $returns = rows($db,
                        $returnSql,
                        $canManage ? [] : ['requester' => $user['id']]
                    );
                    $saleItems = rows($db,
                        "SELECT s.sale_id, s.receipt_number, si.sale_item_id, p.name AS product, si.quantity, si.unit_price
                         FROM sales s JOIN sale_items si USING (sale_id) JOIN products p USING (product_id)
                         WHERE s.status = :status" . ($canManage ? '' : ' AND s.cashier_id = :cashier') . "
                         ORDER BY s.created_at DESC LIMIT 250",
                        $canManage ? ['status' => 'completed'] : ['status' => 'completed', 'cashier' => $user['id']]
                    );
                ?>
                    <article class="panel mb-3"><h2>Request a return</h2><p>Returns are pending until an administrator or manager approves them.</p><form method="post" class="row g-3"><?= csrf_field() ?><input type="hidden" name="action" value="return_request"><?= request_key_field() ?><div class="col-md-5"><label class="form-label">Sold item<select class="form-select" name="sale_item_id" required><?php foreach ($saleItems as $item): ?><option value="<?= $item['sale_item_id'] ?>" data-sale="<?= $item['sale_id'] ?>"><?= Security::e($item['receipt_number'] . ' · ' . $item['product'] . ' · sold ' . $item['quantity']) ?></option><?php endforeach; ?></select></label><input type="hidden" name="sale_id" value="<?= Security::e($saleItems[0]['sale_id'] ?? '') ?>"></div><div class="col-md-2"><label class="form-label">Quantity<input class="form-control" name="quantity" type="number" min="1" value="1" required></label></div><div class="col-md-2"><label class="form-label">Condition<select class="form-select" name="condition"><option value="resalable">Resalable</option><option value="defective">Defective</option><option value="damaged">Damaged</option><option value="pending_inspection">Pending inspection</option></select></label></div><div class="col-md-3"><label class="form-label">Reason<input class="form-control" name="reason" required></label></div><div class="col-12"><button class="btn btn-primary">Submit return</button></div></form></article>
                    <article class="panel"><h2>Return requests</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Return</th><th>Sale</th><th>Reason</th><th>Requested by</th><th>Refund</th><th>Status</th><?php if ($canManage): ?><th>Decision</th><?php endif; ?></tr></thead><tbody><?php foreach ($returns as $return): ?><tr><td><?= Security::e($return['return_number']) ?></td><td><?= Security::e($return['receipt_number']) ?></td><td><?= Security::e($return['reason']) ?></td><td><?= Security::e($return['requester']) ?></td><td>₱<?= number_format((float) $return['refund_amount'], 2) ?></td><td><?= Security::e($return['status']) ?></td><?php if ($canManage): ?><td><?php if ($return['status'] === 'pending'): ?><form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="return_decide"><input type="hidden" name="return_id" value="<?= $return['return_id'] ?>"><button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button><button class="btn btn-sm btn-outline-danger" name="decision" value="reject">Reject</button></form><?php else: ?><?= Security::e($return['approver'] ?? '') ?><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div></article>

                <?php elseif ($page === 'warranty'):
                    $eligibleParams = [];
                    $eligibleScope = '';
                    $claimParams = [];
                    $claimScope = '';
                    if (!$canManage) {
                        $eligibleScope = ' AND s.cashier_id = :cashier';
                        $eligibleParams['cashier'] = $user['id'];
                        $claimScope = ' WHERE wc.requested_by = :requester';
                        $claimParams['requester'] = $user['id'];
                    }
                    $eligibleItems = rows($db,
                        "SELECT si.sale_item_id, s.receipt_number, p.sku, p.name, si.quantity,
                               (s.created_at + (si.warranty_months * INTERVAL '1 month'))::date AS warranty_expires_at
                         FROM sale_items si JOIN sales s USING (sale_id) JOIN products p USING (product_id)
                        WHERE s.status = 'completed' AND si.warranty_months > 0
                          AND s.created_at + (si.warranty_months * INTERVAL '1 month') >= now()
                          AND si.quantity > (SELECT count(*) FROM warranty_claims wc
                                             WHERE wc.sale_item_id = si.sale_item_id AND wc.status <> 'rejected')
                              + (SELECT COALESCE(sum(ri.quantity),0) FROM return_items ri
                                 JOIN returns r USING (return_id)
                                 WHERE ri.sale_item_id = si.sale_item_id AND r.status IN ('pending', 'approved'))"
                            . $eligibleScope . ' ORDER BY s.created_at DESC, p.name LIMIT 200',
                        $eligibleParams
                    );
                    $claims = rows($db,
                        "SELECT wc.*, s.receipt_number, p.sku, p.name AS product,
                                requester.full_name AS requester, decider.full_name AS decider
                         FROM warranty_claims wc JOIN sale_items si USING (sale_item_id)
                         JOIN sales s USING (sale_id) JOIN products p USING (product_id)
                         JOIN app_profiles requester ON requester.user_id = wc.requested_by
                         LEFT JOIN app_profiles decider ON decider.user_id = wc.decided_by"
                            . $claimScope . ' ORDER BY wc.created_at DESC LIMIT 200',
                        $claimParams
                    );
                ?>
                    <?php if (role_allows($user, ['administrator', 'manager', 'cashier'])): ?><article class="panel mb-3"><h2>Submit a warranty claim</h2><p>Claims require a completed sale, an in-warranty product, and a serial number. Claim count is limited to the number of units sold.</p><?php if ($eligibleItems): ?><form method="post" class="row g-3 mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="warranty_request"><div class="col-md-5"><label class="form-label">Sold item<select class="form-select" name="sale_item_id" required><?php foreach ($eligibleItems as $item): ?><option value="<?= (int) $item['sale_item_id'] ?>"><?= Security::e($item['receipt_number'] . ' · ' . $item['sku'] . ' · ' . $item['name'] . ' · sold ' . $item['quantity'] . ' · warranty to ' . $item['warranty_expires_at']) ?></option><?php endforeach; ?></select></label></div><div class="col-md-3"><label class="form-label">Serial number<input class="form-control" name="serial_number" maxlength="120" required></label></div><div class="col-md-4"><label class="form-label">Issue description<textarea class="form-control" name="issue_description" maxlength="2000" rows="2" required></textarea></label></div><div class="col-12"><button class="btn btn-primary">Submit claim</button></div></form><?php else: ?><div class="empty-state">No eligible warranty items are available for this account.</div><?php endif; ?></article><?php endif; ?>
                    <article class="panel"><h2>Warranty claim history</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Claim</th><th>Sale / item</th><th>Serial</th><th>Issue</th><th>Warranty through</th><th>Requested by</th><th>Status / resolution</th><?php if ($canManage): ?><th>Decision</th><?php endif; ?></tr></thead><tbody><?php foreach ($claims as $claim): ?><tr><td><?= Security::e($claim['claim_number']) ?><small class="d-block text-secondary"><?= Security::e(date('M j, Y', strtotime($claim['created_at']))) ?></small></td><td><?= Security::e($claim['receipt_number']) ?><small class="d-block text-secondary"><?= Security::e($claim['sku'] . ' · ' . $claim['product']) ?></small></td><td><code><?= Security::e($claim['serial_number']) ?></code></td><td><?= Security::e($claim['issue_description']) ?></td><td><?= Security::e($claim['warranty_expires_at']) ?></td><td><?= Security::e($claim['requester']) ?></td><td><span class="badge <?= $claim['status'] === 'approved' ? 'text-bg-success' : ($claim['status'] === 'rejected' ? 'text-bg-danger' : 'text-bg-warning') ?>"><?= Security::e(ucfirst($claim['status'])) ?></span><?php if ($claim['resolution']): ?><small class="d-block"><?= Security::e($claim['resolution']) ?></small><?php endif; ?></td><?php if ($canManage): ?><td><?php if ($claim['status'] === 'pending'): ?><form method="post" class="vstack gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="warranty_decide"><input type="hidden" name="warranty_claim_id" value="<?= (int) $claim['warranty_claim_id'] ?>"><input class="form-control form-control-sm" name="resolution" maxlength="2000" placeholder="Decision note" required><div class="d-flex gap-1"><button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button><button class="btn btn-sm btn-outline-danger" name="decision" value="reject">Reject</button></div></form><?php else: ?><?= Security::e($claim['decider'] ?? '') ?><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?><?php if (!$claims): ?><tr><td colspan="<?= $canManage ? 8 : 7 ?>" class="text-secondary">No warranty claims recorded.</td></tr><?php endif; ?></tbody></table></div></article>

                <?php elseif ($page === 'reports'):
                    $startDate = (string) ($_GET['start'] ?? date('Y-m-01'));
                    $endDate = (string) ($_GET['end'] ?? date('Y-m-d'));
                    $startParts = array_map('intval', explode('-', $startDate));
                    $endParts = array_map('intval', explode('-', $endDate));
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)
                        || count($startParts) !== 3 || count($endParts) !== 3
                        || !checkdate($startParts[1], $startParts[2], $startParts[0])
                        || !checkdate($endParts[1], $endParts[2], $endParts[0])
                        || $startDate > $endDate) {
                        throw new InvalidArgumentException('Choose a valid date range.');
                    }
                    $dailySales = rows($db,
                        "SELECT created_at::date AS day, count(*) AS receipts, sum(total)::numeric(14,2) AS revenue
                         FROM sales WHERE status = 'completed' AND created_at::date BETWEEN :start AND :end
                         GROUP BY created_at::date ORDER BY day",
                        ['start' => $startDate, 'end' => $endDate]
                    );
                    $profitRows = rows($db,
                        "SELECT s.sale_id, s.receipt_number, s.created_at, s.total, s.discount,
                                COALESCE(sum(si.line_total),0) AS line_revenue,
                                COALESCE(sum(si.quantity * si.unit_cost),0) AS cost
                         FROM sales s LEFT JOIN sale_items si USING (sale_id)
                         WHERE s.status = 'completed' AND s.created_at::date BETWEEN :start AND :end
                         GROUP BY s.sale_id ORDER BY s.created_at DESC",
                        ['start' => $startDate, 'end' => $endDate]
                    );
                    $approvedRefunds = one($db,
                        "SELECT
                            (SELECT COALESCE(sum(refund_amount),0) FROM returns
                             WHERE status = 'approved' AND created_at::date BETWEEN :refund_start AND :refund_end) AS total,
                            (SELECT COALESCE(sum(ri.quantity * si.unit_cost),0)
                             FROM return_items ri JOIN returns r USING (return_id)
                             JOIN sale_items si USING (sale_item_id)
                             WHERE r.status = 'approved' AND ri.inventory_disposition = 'restock'
                               AND r.created_at::date BETWEEN :cost_start AND :cost_end) AS returned_cost",
                        [
                            'refund_start' => $startDate, 'refund_end' => $endDate,
                            'cost_start' => $startDate, 'cost_end' => $endDate,
                        ]
                    );
                    $returnRows = rows($db,
                        "SELECT r.return_number, r.created_at, r.status, r.reason, r.refund_amount,
                                s.receipt_number, requester.full_name AS requester,
                                approver.full_name AS approver,
                                COALESCE(sum(ri.quantity),0) AS units,
                                COALESCE(sum(ri.quantity * si.unit_cost) FILTER (
                                    WHERE ri.inventory_disposition = 'restock' AND r.status = 'approved'),0) AS restocked_cost
                         FROM returns r JOIN sales s USING (sale_id)
                         JOIN app_profiles requester ON requester.user_id = r.requested_by
                         LEFT JOIN app_profiles approver ON approver.user_id = r.approved_by
                         LEFT JOIN return_items ri USING (return_id)
                         LEFT JOIN sale_items si USING (sale_item_id)
                         WHERE r.created_at::date BETWEEN :start AND :end
                         GROUP BY r.return_id, s.receipt_number, requester.full_name, approver.full_name
                         ORDER BY r.created_at DESC",
                        ['start' => $startDate, 'end' => $endDate]
                    );
                    $bestSellers = rows($db,
                        "SELECT p.name, p.sku, sum(si.quantity) AS units, sum(si.line_total)::numeric(14,2) AS revenue
                         FROM sale_items si JOIN sales s USING (sale_id) JOIN products p USING (product_id)
                         WHERE s.status = 'completed' AND s.created_at::date BETWEEN :start AND :end
                         GROUP BY p.product_id ORDER BY units DESC LIMIT 10",
                        ['start' => $startDate, 'end' => $endDate]
                    );
                    $inventory = rows($db, 'SELECT sku, name, stock_quantity, average_cost, selling_price, minimum_stock FROM products WHERE is_active ORDER BY name');
                    $totalRevenue = array_sum(array_map(static fn(array $r): float => (float) $r['line_revenue'], $profitRows));
                    $shippedCost = array_sum(array_map(static fn(array $r): float => (float) $r['cost'], $profitRows));
                    $totalCost = $shippedCost - (float) $approvedRefunds['returned_cost'];
                    $grossProfit = $totalRevenue - $shippedCost - (float) $approvedRefunds['total'] + (float) $approvedRefunds['returned_cost'];
                    $export = (string) ($_GET['export'] ?? '');
                    $exportData = [
                        'sales' => [
                            'sales-report.csv',
                            ['Receipt', 'Date', 'Receipt total', 'Discount', 'Net merchandise revenue', 'Cost basis'],
                            array_map(static fn(array $record): array => [
                                $record['receipt_number'], $record['created_at'], $record['total'],
                                $record['discount'], $record['line_revenue'], $record['cost'],
                            ], $profitRows),
                        ],
                        'daily' => [
                            'daily-sales-report.csv',
                            ['Date', 'Receipts', 'Revenue'],
                            array_map(static fn(array $record): array => [
                                $record['day'], $record['receipts'], $record['revenue'],
                            ], $dailySales),
                        ],
                        'best_sellers' => [
                            'best-sellers-report.csv',
                            ['SKU', 'Product', 'Units sold', 'Net line revenue'],
                            array_map(static fn(array $record): array => [
                                $record['sku'], $record['name'], $record['units'], $record['revenue'],
                            ], $bestSellers),
                        ],
                        'inventory' => [
                            'inventory-report.csv',
                            ['SKU', 'Product', 'Stock', 'Average cost', 'Selling price', 'Minimum stock'],
                            array_map(static fn(array $record): array => [
                                $record['sku'], $record['name'], $record['stock_quantity'], $record['average_cost'],
                                $record['selling_price'], $record['minimum_stock'],
                            ], $inventory),
                        ],
                        'returns' => [
                            'returns-report.csv',
                            ['Return', 'Date', 'Receipt', 'Status', 'Reason', 'Requester', 'Approver', 'Units', 'Refund', 'Restocked cost'],
                            array_map(static fn(array $record): array => [
                                $record['return_number'], $record['created_at'], $record['receipt_number'],
                                $record['status'], $record['reason'], $record['requester'], $record['approver'] ?? '',
                                $record['units'], $record['refund_amount'], $record['restocked_cost'],
                            ], $returnRows),
                        ],
                    ];
                    if ($export !== '') {
                        if (!isset($exportData[$export])) {
                            throw new InvalidArgumentException('Choose a supported report export.');
                        }
                        while (ob_get_level() > 0) {
                            ob_end_clean();
                        }
                        [$filename, $headers, $records] = $exportData[$export];
                        header('Content-Type: text/csv; charset=utf-8');
                        header('Content-Disposition: attachment; filename="' . $filename . '"');
                        $output = fopen('php://output', 'wb');
                        if ($output === false) {
                            throw new RuntimeException('Could not create the report download.');
                        }
                        fputcsv($output, $headers);
                        foreach ($records as $record) {
                            fputcsv($output, array_map([Security::class, 'csvCell'], $record));
                        }
                        fclose($output);
                        exit;
                    }
                ?>
                    <article class="panel mb-3"><form class="row g-3 align-items-end"><input type="hidden" name="page" value="reports"><div class="col-md-3"><label class="form-label">From<input class="form-control" type="date" name="start" value="<?= Security::e($startDate) ?>"></label></div><div class="col-md-3"><label class="form-label">To<input class="form-control" type="date" name="end" value="<?= Security::e($endDate) ?>"></label></div><div class="col-md-2"><button class="btn btn-primary">Apply range</button></div><div class="col-md-4 d-flex flex-wrap gap-2"><?php foreach (['sales' => 'Sales', 'daily' => 'Daily', 'best_sellers' => 'Best sellers', 'inventory' => 'Inventory', 'returns' => 'Returns'] as $exportKey => $exportLabel): ?><a class="btn btn-sm btn-outline-secondary" href="?page=reports&start=<?= rawurlencode($startDate) ?>&end=<?= rawurlencode($endDate) ?>&export=<?= rawurlencode($exportKey) ?>">Export <?= Security::e($exportLabel) ?></a><?php endforeach; ?></div></form></article>
                    <div class="row g-3 mb-3"><div class="col-md-4"><article class="stat-card"><div class="stat-value">₱<?= number_format($totalRevenue, 2) ?></div><div class="stat-label">Recorded revenue</div><small><?= count($profitRows) ?> receipts</small></article></div><div class="col-md-4"><article class="stat-card"><div class="stat-value">₱<?= number_format($totalCost, 2) ?></div><div class="stat-label">Cost of goods sold</div><small>Historical weighted-average basis</small></article></div><div class="col-md-4"><article class="stat-card"><div class="stat-value">₱<?= number_format($grossProfit, 2) ?></div><div class="stat-label">Estimated gross profit</div><small>Less approved refunds</small></article></div></div>
                    <div class="row g-3"><div class="col-lg-6"><article class="panel"><h2>Daily sales</h2><div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Receipts</th><th>Revenue</th></tr></thead><tbody><?php foreach ($dailySales as $day): ?><tr><td><?= Security::e($day['day']) ?></td><td><?= Security::e($day['receipts']) ?></td><td>₱<?= number_format((float) $day['revenue'], 2) ?></td></tr><?php endforeach; ?></tbody></table></div></article></div><div class="col-lg-6"><article class="panel"><h2>Best sellers</h2><div class="table-responsive"><table class="table"><thead><tr><th>Product</th><th>Units</th><th>Revenue</th></tr></thead><tbody><?php foreach ($bestSellers as $product): ?><tr><td><?= Security::e($product['name']) ?><small class="d-block text-secondary"><?= Security::e($product['sku']) ?></small></td><td><?= Security::e($product['units']) ?></td><td>₱<?= number_format((float) $product['revenue'], 2) ?></td></tr><?php endforeach; ?></tbody></table></div></article></div></div>
                    <article class="panel mt-3"><h2>Current inventory</h2><div class="table-responsive"><table class="table"><thead><tr><th>SKU</th><th>Product</th><th>Stock</th><th>Avg cost</th><th>Sell price</th><th>Minimum</th></tr></thead><tbody><?php foreach ($inventory as $item): ?><tr><td><?= Security::e($item['sku']) ?></td><td><?= Security::e($item['name']) ?></td><td><?= Security::e($item['stock_quantity']) ?></td><td>₱<?= number_format((float) $item['average_cost'], 2) ?></td><td>₱<?= number_format((float) $item['selling_price'], 2) ?></td><td><?= Security::e($item['minimum_stock']) ?></td></tr><?php endforeach; ?></tbody></table></div></article>

                <?php elseif ($page === 'audit'):
                    $logs = rows($db,
                        'SELECT a.*, p.full_name FROM audit_logs a LEFT JOIN app_profiles p ON p.user_id = a.actor_id
                         ORDER BY a.created_at DESC LIMIT 200'
                    );
                ?>
                    <article class="panel"><div class="panel-heading"><div><h2>Audit activity</h2><p>Recent actions, who performed them, and the affected records.</p></div><span class="badge text-bg-light">Latest 200</span></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Date and time</th><th>Performed by</th><th>Activity</th><th>Record</th><th>What changed</th></tr></thead><tbody><?php foreach ($logs as $log): ?><tr><td><?= Security::e(date('M j, Y · g:i A', strtotime($log['created_at']))) ?></td><td><?= Security::e($log['full_name'] ?? 'System') ?></td><td><span class="audit-action"><?= Security::e(audit_action_label($log['action'])) ?></span></td><td><?= Security::e(ucwords(str_replace('_', ' ', $log['entity_type']))) ?><small class="d-block text-secondary"><?= Security::e($log['entity_id'] ?? '—') ?></small></td><td><?php $details = audit_detail_entries($log['details']); ?><?php if ($details): ?><dl class="audit-details"><?php foreach ($details as $detail): ?><div><dt><?= Security::e($detail['label']) ?></dt><dd><?= Security::e($detail['value']) ?></dd></div><?php endforeach; ?></dl><?php else: ?><span class="text-secondary">No additional details</span><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$logs): ?><tr><td colspan="5" class="text-secondary">No audit activity has been recorded.</td></tr><?php endif; ?></tbody></table></div></article>

                <?php elseif ($page === 'users'):
                    $usersMigrationReady = user_management_migration_applied($db);
                    $profiles = rows($db, $usersMigrationReady
                        ? 'SELECT user_id, full_name, email, address, birth_date, phone, role, is_active, deleted_at, CASE WHEN is_active THEN 1 ELSE 0 END AS active_int, created_at FROM app_profiles ORDER BY full_name'
                        : 'SELECT user_id, full_name, NULL AS email, NULL AS address, NULL AS birth_date, NULL AS phone, role, is_active, NULL AS deleted_at, CASE WHEN is_active THEN 1 ELSE 0 END AS active_int, created_at FROM app_profiles ORDER BY full_name');
                    $managedAuthReady = (string) getenv('SUPABASE_SERVICE_ROLE_KEY') !== '';
                ?>
                    <?php if (!$usersMigrationReady): ?><div class="alert alert-danger"><strong>User management schema is not installed.</strong> Run <code>database/migrations/003_user_management.sql</code> in the Supabase SQL Editor, then reload this page. Existing users are shown below; account editing stays disabled until the migration is applied.</div><?php endif; ?>
                    <?php if (!$managedAuthReady): ?><div class="alert alert-warning">Account creation and editing require the server-only <code>SUPABASE_SERVICE_ROLE_KEY</code>. Add it to the protected <code>.env</code> file before managing accounts.</div><?php endif; ?>
                    <?php if ($usersMigrationReady): ?><article class="panel mb-3"><div class="panel-heading"><div><h2>Create a user account</h2><p>Set the user's contact details, sign-in email, initial password, and access role.</p></div></div><form method="post" class="row g-3"><?= csrf_field() ?><input type="hidden" name="action" value="profile_create"><div class="col-md-4"><label class="form-label">Full name<input class="form-control" name="full_name" maxlength="150" autocomplete="name" required></label></div><div class="col-md-4"><label class="form-label">Email<input class="form-control" name="email" type="email" maxlength="254" autocomplete="email" required></label></div><div class="col-md-4"><label class="form-label">Initial password<input class="form-control" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required><small class="text-secondary">At least 12 characters.</small></label></div><div class="col-md-3"><label class="form-label">Role<select class="form-select" name="role" required><?php foreach ($roleLabels as $key => $label): ?><option value="<?= Security::e($key) ?>" <?= $key === 'cashier' ? 'selected' : '' ?>><?= Security::e($label) ?></option><?php endforeach; ?></select></label></div><div class="col-md-3"><label class="form-label">Phone<input class="form-control" name="phone" maxlength="40" autocomplete="tel"></label></div><div class="col-md-3"><label class="form-label">Birthdate<input class="form-control" name="birth_date" type="date" max="<?= date('Y-m-d') ?>"></label></div><div class="col-md-3"><label class="form-label">Address<input class="form-control" name="address" maxlength="500" autocomplete="street-address"></label></div><div class="col-12"><button class="btn btn-primary" <?= $managedAuthReady ? '' : 'disabled' ?>>Create account</button></div></form></article><?php endif; ?>
                    <article class="panel"><div class="panel-heading"><div><h2>Users</h2><p>Update account details and access. Deleted users lose access; their transaction and audit history is retained.</p></div><span class="badge text-bg-light"><?= count($profiles) ?> accounts</span></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>User</th><th>Contact and profile</th><th>Role</th><th>Status</th><th>Manage account</th></tr></thead><tbody><?php foreach ($profiles as $profile): ?><tr><td><strong><?= Security::e($profile['full_name']) ?></strong><small class="d-block text-secondary"><?= Security::e($profile['user_id']) ?></small></td><td><?= Security::e($profile['email'] ?? 'Email not on file') ?><small class="d-block text-secondary"><?= Security::e($profile['phone'] ?: 'No phone') ?> · <?= Security::e($profile['birth_date'] ?: 'No birthdate') ?></small><small class="d-block text-secondary"><?= Security::e($profile['address'] ?: 'No address') ?></small></td><td><?= Security::e($roleLabels[$profile['role']] ?? $profile['role']) ?></td><td><?php if ($profile['deleted_at'] !== null): ?><span class="badge text-bg-dark">Deleted</span><?php elseif ($profile['active_int']): ?><span class="badge text-bg-success">Active</span><?php else: ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?></td><td><?php if ($usersMigrationReady && $profile['deleted_at'] === null): ?><?php if ($managedAuthReady): ?><details class="user-edit"><summary class="btn btn-sm btn-outline-primary">Edit</summary><form method="post" class="user-edit-form"><?= csrf_field() ?><input type="hidden" name="action" value="profile_update"><input type="hidden" name="user_id" value="<?= Security::e($profile['user_id']) ?>"><label class="form-label">Full name<input class="form-control" name="full_name" maxlength="150" value="<?= Security::e($profile['full_name']) ?>" required></label><label class="form-label">Email<input class="form-control" name="email" type="email" maxlength="254" value="<?= Security::e($profile['email'] ?? '') ?>" required></label><label class="form-label">Role<select class="form-select" name="role"><?php foreach ($roleLabels as $key => $label): ?><option value="<?= Security::e($key) ?>" <?= $profile['role'] === $key ? 'selected' : '' ?>><?= Security::e($label) ?></option><?php endforeach; ?></select></label><label class="form-label">Phone<input class="form-control" name="phone" maxlength="40" value="<?= Security::e($profile['phone'] ?? '') ?>"></label><label class="form-label">Birthdate<input class="form-control" name="birth_date" type="date" max="<?= date('Y-m-d') ?>" value="<?= Security::e($profile['birth_date'] ?? '') ?>"></label><label class="form-label">Address<input class="form-control" name="address" maxlength="500" value="<?= Security::e($profile['address'] ?? '') ?>"></label><label class="form-label">New password <small class="text-secondary">(leave blank to keep current)</small><input class="form-control" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password"></label><button class="btn btn-primary">Save changes</button></form></details><?php else: ?><span class="text-secondary">Add the service-role key to edit</span><?php endif; ?><div class="d-flex gap-2 mt-2"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="profile_active"><input type="hidden" name="user_id" value="<?= Security::e($profile['user_id']) ?>"><input type="hidden" name="is_active" value="<?= $profile['active_int'] ? '0' : '1' ?>"><button class="btn btn-sm btn-outline-secondary" <?= $profile['user_id'] === $user['id'] ? 'disabled' : '' ?>><?= $profile['active_int'] ? 'Deactivate' : 'Activate' ?></button></form><form method="post" data-confirm="Delete this user? PartsDesk will remove the Auth account when possible; if records require it, access will be disabled and history preserved."><?= csrf_field() ?><input type="hidden" name="action" value="profile_delete"><input type="hidden" name="user_id" value="<?= Security::e($profile['user_id']) ?>"><button class="btn btn-sm btn-outline-danger" <?= $profile['user_id'] === $user['id'] ? 'disabled' : '' ?>>Delete</button></form></div><?php else: ?><span class="text-secondary">Migration required</span><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$profiles): ?><tr><td colspan="5" class="text-secondary">No user accounts have been created.</td></tr><?php endif; ?></tbody></table></div></article>

                <?php else: ?>
                    <article class="panel"><h2>Page not found</h2><p>Choose an item from the navigation.</p><a class="btn btn-primary" href="?page=dashboard">Go to dashboard</a></article>
                <?php endif; ?>
                <?php endif; ?>
            </section>
        </main>
    </div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
<?php
} catch (Throwable $renderException) {
    if ($renderException instanceof PDOException) {
        error_log('Page rendering database error: ' . $renderException->getMessage());
        $message = 'The page could not load its database records. Verify the migration state and server log.';
    } else {
        $message = $renderException->getMessage();
    }
    if (ob_get_level() > 0) {
        ob_clean();
    }
    if (http_response_code() < 400) {
        http_response_code(500);
    }
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Application error · PartsDesk</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><main class="container py-5"><div class="alert alert-danger"><h1 class="h5">Unable to load this page</h1><p class="mb-0"><?= Security::e($message) ?></p></div><a href="?page=dashboard">Return to dashboard</a></main></body></html>
<?php } ?>
