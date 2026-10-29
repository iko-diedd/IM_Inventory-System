# PartsDesk

PartsDesk is a server-rendered inventory and sales application for a small computer-parts retailer. It displays monetary amounts in Philippine pesos (PHP) and uses PHP 8.1+, Bootstrap 5, Supabase Auth, and Supabase PostgreSQL. PHP connects to PostgreSQL with PDO; no XAMPP MySQL/MariaDB database is used.

## Included workflows

- Supabase Auth sign-in with server-side identity validation, token refresh, eight-hour maximum session age, secure/HTTP-only/SameSite cookies, and active profile checks.
- Administrator, manager, cashier, and inventory staff authorization in PHP, plus least-privilege Supabase Data API grants and RLS.
- Product catalog, categories, brands, supplier records and supplier-product cost mappings, search, price changes, specifications, deactivation, and low-stock monitoring.
- Multi-line purchase orders, partial receiving, receipt idempotency, supplier cost history, and weighted-average inventory cost.
- Point-of-sale cart, atomic checkout, row locking, non-negative stock, idempotency, price/cost snapshots, and printable receipts.
- Audited stock adjustments from physical counts, controlled returns with manager approval, return quantity reservation, and resalable-only restocking.
- Administrator-managed user accounts and profiles, with role, address, birthdate, and contact details.
- Human-readable audit activity with translated action labels and readable change details.
- Database-driven dashboard, daily/date-range sales and gross-profit reporting, best sellers, inventory and returns reports, CSV exports, and audit history.
- PostgreSQL schema migration and fictional, rerunnable sample catalog/transactions.

The application uses a trusted PHP backend connection. Supabase Auth tokens remain server-side in the PHP session; the browser receives only the session cookie. Database credentials, the Supabase service-role key, and privileged connection strings must remain server-side and must never be exposed to the browser.

## Requirements

- XAMPP with PHP 8.1 or later and Apache.
- PHP extensions `pdo_pgsql`, `pgsql`, and `openssl` enabled in the PHP configuration used by Apache/CLI.
- A Supabase project with PostgreSQL and Supabase Auth enabled.
- PostgreSQL client tools such as `psql` for backups/restores (optional).
- Internet access in the browser for the pinned Bootstrap and Chart.js CDN assets.

## Supabase initialization

1. Create a Supabase project. Do not copy credentials into source files.
2. In **Project Settings → API**, copy the project URL and the publishable/anon key. This public key is used only for the documented Auth endpoints. To create and update accounts in the Users panel, configure the legacy **service_role JWT** from the API settings as `SUPABASE_SERVICE_ROLE_KEY` in the server-only `.env`. The anon/publishable key and newer `sb_secret_...` API keys are not bearer JWTs for these Admin Auth endpoints; never expose the service-role JWT in browser code or commit it.
3. Open **SQL Editor**, run [`database/migrations/001_core.sql`](./database/migrations/001_core.sql) once in a clean project, and confirm the query completes. The migration creates the core schema, constraints, RLS policies, an `inventory_app` non-login access role, and records version `001_core` in `schema_migrations`.
4. Run [`database/migrations/002_warranty_claims.sql`](./database/migrations/002_warranty_claims.sql) after the core migration. It snapshots warranty months on each sold line and adds audited claims with serial number, eligibility, quantity reservation, and manager approval.
5. Run [`database/migrations/003_user_management.sql`](./database/migrations/003_user_management.sql) to add user contact/profile fields. Existing profiles keep a blank email until an administrator updates them; this migration intentionally does not read the protected Supabase `auth` schema.
6. Create a dedicated login role for PHP in SQL Editor. Choose a long random password and enter it only in the dashboard/secure secret store:

   ```sql
   CREATE ROLE inventory_php LOGIN INHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
   GRANT inventory_app TO inventory_php;
   ALTER ROLE inventory_php PASSWORD 'REPLACE_IN_DASHBOARD_ONLY';
   ```

   Do not save the replacement password in SQL files or commit it. The group role has no `BYPASSRLS` privilege. Its RLS policy is deliberately limited to the trusted PHP backend; PHP must continue to enforce each user's role before using it. Do not connect PHP as `postgres`.
7. Apply [`database/seeds/demo.sql`](./database/seeds/demo.sql) in SQL Editor. It inserts fictional categories, brands, suppliers, and products idempotently. If at least one active profile exists, it also records a fictional purchase receipt and sale; otherwise those transactions are skipped with a notice and can be seeded by running the script again after provisioning a user.
8. In **Authentication → Users**, create a user for the initial administrator. Copy its Auth user UUID. In SQL Editor, create its profile:

   ```sql
   INSERT INTO app_profiles (user_id, email, full_name, role)
   VALUES ('AUTH-USER-UUID', 'ADMIN-EMAIL', 'Store Administrator', 'administrator');
   ```

   The initial administrator is bootstrapped manually; after signing in, administrators can create, edit, deactivate, and delete application accounts in the Users panel. Deletion removes the Supabase Auth account when no retained business/audit records depend on it; otherwise it disables application access and retains the Auth identity and history. An inactive or deleted profile cannot access the application.
9. Copy `.env.example` to `.env`. Fill the Auth URL/key and the PostgreSQL host, port, database, dedicated login username, and password from the Supabase Dashboard. Set `SUPABASE_SERVICE_ROLE_KEY` when administrators need to create, edit, or fully delete accounts; protect `.env` as a server secret. Use the actual host shown in the dashboard; do not infer or invent a hostname. Choose the direct or session-pooler endpoint according to your network. Use `SUPABASE_DB_SSLMODE=require` at minimum. Use `verify-full` only after the local PHP/libpq environment has the required CA certificates configured.
10. Restrict database network access using the Supabase network controls available on your plan. Do not enable public access more broadly than required.

The migration intentionally does not create an administrator or demo password. User IDs and roles must be provisioned by a trusted administrator; no user can grant themselves a role through the Data API.

## XAMPP setup

1. Enable `pdo_pgsql`, `pgsql`, and `openssl` in the `php.ini` used by XAMPP. The active file is shown by `php --ini` for CLI; check Apache's PHP configuration separately if it differs. Restart Apache after editing the file.
2. Copy the project folder into `C:\xampp\htdocs\partsdesk` (or configure an Apache virtual host pointing to this folder). Keep `.env` in the project root and ensure it is not publicly served.
3. Copy `.env.example` to `.env`, insert the Supabase values, and set `APP_SECURE_COOKIES=false` only for local HTTP development. Set it to `true` behind HTTPS in deployment.
4. Start Apache from the XAMPP Control Panel. Browse to `http://localhost/partsdesk/`.
5. Run the connectivity/schema check from PowerShell or Command Prompt:

   ```powershell
   php C:\xampp\htdocs\partsdesk\tools\db-check.php
   ```

   It checks the active DB role, TLS state, and whether the required migrations are recorded. It does not print passwords or tokens.

`.htaccess` disables directory listings and denies direct HTTP access to environment/configuration, SQL migration, and seed folders. Confirm Apache `AllowOverride All` is enabled for the project directory.

## Migrations and sample data

- Apply numbered SQL files in `database/migrations` in order, once per environment, using Supabase SQL Editor or `psql`. Check `schema_migrations` after each application. Do not rerun an already-applied migration: add a new numbered migration for future schema changes.
- Keep each migration transaction-safe and grant new objects only to the backend and Data API roles that need them. Re-evaluate the RLS policies and grants whenever a table or exposed column is added.
- `database/seeds/demo.sql` uses fictional data and `ON CONFLICT`/fixed demonstration transaction identifiers to avoid repeating its sample records. It is not production data.
- The cost method is perpetual weighted-average costing: each confirmed receipt recalculates average cost; sales preserve the applicable unit cost, net selling price, and warranty duration; resalable approved returns restore units at their historical sale cost. Defective, damaged, and pending-inspection returns do not restock. Physical stock counts calculate and record the inventory difference without rewriting historical costs.
- The warranty migration remains part of the schema for existing historical and return-reservation data; warranty claim management is not shown in the application dashboard.
- Tax defaults to zero because no jurisdiction or tax rule was supplied. The UI accepts a tax amount at checkout, but tax calculation is not configured. Return refunds reflect the item's net line price and do not include a tax allocation.

## Backups and recovery

- Review the backup and point-in-time recovery features actually enabled for the selected Supabase plan; availability and retention vary by plan.
- For an additional logical backup, install PostgreSQL client tools and run `pg_dump` using the host/port/database/user from `.env`. Omit the password from the command line so the client prompts for it, or use a properly protected PostgreSQL password file. Store encrypted backups outside the web root and test restore into a separate development project.
- Restore only after verifying the target project and backup. Avoid `--clean`/destructive restore options on production. Preserve the migration files and apply schema changes in order before restoring compatible data.
- Do not treat the sample SQL as a backup or use the demo seed in production.

## Testing and verification

PHP 8.2 is available in the workspace. Syntax checks for all PHP files and `assets/app.js`, the security smoke test, and local HTTP smoke tests for the login page, CSRF form, security headers, and visible configuration errors passed. The XAMPP PostgreSQL extensions were enabled in `C:\xampp\php\php.ini`. No Supabase project credentials were supplied, so a live database connection, migrations, Supabase authentication, and database integration tests could not be executed here. Do not consider the application configured until these checks pass locally:

1. `C:\xampp\php\php.exe -l` each PHP file and run `C:\xampp\php\php.exe tests\security-smoke.php` plus `C:\xampp\php\php.exe tests\purchase-validation-smoke.php` for CSRF/output escaping and purchase/warranty input validation.
2. `C:\xampp\php\php.exe tools\db-check.php` confirms TLS and migration version; `C:\xampp\php\php.exe tests\verify-schema.php` checks RLS, backend membership, and Data API column grants.
3. Create a product with a unique SKU; attempt a duplicate SKU and confirm it is rejected.
4. Receive a purchase in two partial deliveries; retry one request with the same request key and confirm stock increases only once; exceed remaining quantity and confirm rejection.
5. Complete a checkout and confirm the receipt, movement, historical price/cost, and stock deduction. Attempt insufficient stock and repeat the same idempotency key.
6. Run two overlapping sales against the same limited stock and verify row locking prevents a negative balance.
7. Request a return up to the eligible sold quantity, attempt an over-return, approve resalable vs defective returns, and verify only approved resalable items increase stock.
8. Verify cashier/manager/administrator route access and Supabase Data API RLS behavior with permitted and forbidden users.
9. Create a multi-line purchase order; reject duplicate products in one order; receive each line over multiple deliveries and verify the resulting stock and average costs.
10. Verify supplier/category/brand edits and deactivation, supplier-product cost mappings, date-range report totals, each CSV output beginning with its CSV header (not HTML), and spreadsheet-formula escaping.
11. Create, edit, deactivate, and delete accounts as an administrator; verify role authorization, profile validation, audit details, and that deleted users cannot sign in to the application.

The `InventoryService` is transaction-oriented and uses PostgreSQL row locks. A live concurrent integration run against a dedicated test Supabase project remains necessary before production use.

## Operational boundaries

- No live Supabase credentials were supplied. Connection parameters, migrations, RLS behavior, Auth, and end-to-end operation must be verified in the target project.
- Refund approval is recorded in the system; payment-provider or cash-drawer refund execution is an external/manual step.
- Reports export sales, daily totals, best sellers, inventory, and returns as CSV. PDF/Excel workbook generation is not included.
- Warranty claim records and sold-line warranty snapshots remain in the database; the application does not currently expose a warranty-claim screen. Per-unit serials are not captured during receiving or checkout, so the system does not provide serial-level on-hand stock traceability.
- Integration/concurrency/RLS tests are described above but were not executed in this environment.
