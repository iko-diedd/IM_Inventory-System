# PartsDesk

PartsDesk is a server-rendered inventory and sales application for a small computer-parts retailer. It displays monetary amounts in Philippine pesos (PHP) and uses PHP 8.1+, Bootstrap 5, Supabase Auth, and Supabase PostgreSQL. PHP connects to PostgreSQL with PDO; no XAMPP MySQL/MariaDB database is used.

## Included workflows

- Supabase Auth sign-in with server-side identity validation, token refresh, eight-hour maximum session age, secure/HTTP-only/SameSite cookies, and active profile checks.
- Administrator, manager, cashier, and inventory staff authorization in PHP, plus least-privilege Supabase Data API grants and RLS.
- Product catalog, categories, brands, supplier records and supplier-product cost mappings, search, price changes, specifications, deactivation, and low-stock monitoring.
- Multi-line purchase orders, partial receiving, receipt idempotency, supplier cost history, and weighted-average inventory cost.
- Low-stock reorder suggestions that account for outstanding orders and can generate grouped orders from the lowest-cost active supplier.
- A notification bell for low/out-of-stock products, recent product/receipt activity, and pending orders, with per-user read state.
- Point-of-sale cart, percentage-based discounts, atomic checkout, row locking, non-negative stock, idempotency, price/cost snapshots, and printable receipts.
- Audited stock adjustments from physical counts, controlled returns with manager approval, return quantity reservation, and resalable-only restocking.
- Administrator-managed user accounts and profiles, with role, address, birthdate, and contact details.
- Admin/manager-configured global tax applied to new purchase orders and sales; recent product additions and receipts appear in the notification menu.
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
6. Run [`database/migrations/004_user_profile_extensions.sql`](./database/migrations/004_user_profile_extensions.sql) to add optional gender, hire-date, and emergency-contact profile fields without changing existing records.
7. Run [`database/migrations/005_global_tax.sql`](./database/migrations/005_global_tax.sql) to add the protected global tax-rate setting and purchase tax snapshots. The rate starts at 0% and can be changed only by an administrator or manager in the Sales page. New purchase orders and POS sales use the saved rate; historical totals remain unchanged.
8. Run [`database/migrations/006_sales_discount_percentage.sql`](./database/migrations/006_sales_discount_percentage.sql) to store the percentage used for each new sale separately from its calculated peso discount. Existing sales keep a NULL rate and their stored fixed discount amount; historical discounts are not reinterpreted.
9. Run [`database/migrations/007_notification_reads.sql`](./database/migrations/007_notification_reads.sql) to add backend-only per-user read state for notifications. The app builds alerts from current stock, recent products/receipts, and (for inventory roles) pending purchase orders.
10. Create a dedicated login role for PHP in SQL Editor. Choose a long random password and enter it only in the dashboard/secure secret store:

   ```sql
   CREATE ROLE inventory_php LOGIN INHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
   GRANT inventory_app TO inventory_php;
   ALTER ROLE inventory_php PASSWORD 'REPLACE_IN_DASHBOARD_ONLY';
   ```

   Do not save the replacement password in SQL files or commit it. The group role has no `BYPASSRLS` privilege. Its RLS policy is deliberately limited to the trusted PHP backend; PHP must continue to enforce each user's role before using it. Do not connect PHP as `postgres`.
11. Apply [`database/seeds/demo.sql`](./database/seeds/demo.sql) in SQL Editor. It inserts fictional categories, brands, suppliers, and products idempotently. If at least one active profile exists, it also records a fictional purchase receipt and sale; otherwise those transactions are skipped with a notice and can be seeded by running the script again after provisioning a user.
12. In **Authentication → Users**, create a user for the initial administrator. Copy its Auth user UUID. In SQL Editor, create its profile:

   ```sql
   INSERT INTO app_profiles (user_id, email, full_name, role)
   VALUES ('AUTH-USER-UUID', 'ADMIN-EMAIL', 'Store Administrator', 'administrator');
   ```

   The initial administrator is bootstrapped manually; after signing in, administrators can create, edit, deactivate, and delete application accounts in the Users panel. Deletion removes the Supabase Auth account when no retained business/audit records depend on it; otherwise it disables application access and retains the Auth identity and history. An inactive or deleted profile cannot access the application.
13. Copy `.env.example` to `.env`. Fill the Auth URL/key and the PostgreSQL host, port, database, dedicated login username, and password from the Supabase Dashboard. Set `SUPABASE_SERVICE_ROLE_KEY` when administrators need to create, edit, or fully delete accounts; protect `.env` as a server secret. Use the actual host shown in the dashboard; do not infer or invent a hostname. Choose the direct or session-pooler endpoint according to your network. Use `SUPABASE_DB_SSLMODE=require` at minimum. Use `verify-full` only after the local PHP/libpq environment has the required CA certificates configured.
14. Restrict database network access using the Supabase network controls available on your plan. Do not enable public access more broadly than required.

The migration intentionally does not create an administrator or demo password. User IDs and roles must be provisioned by a trusted administrator; no user can grant themselves a role through the Data API.

The ZIP's original `003_user_profiles.sql` is intentionally not used: its migration version conflicts with the configured project and its unconditional `ADD COLUMN` statements are unsafe when the existing user-management migration has already been applied. Apply migrations 004 through 007 after the existing 003 instead.

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

## Vercel deployment (community PHP runtime)

The repository includes a Vercel entry point and routing configuration using the community-maintained [`vercel-php`](https://github.com/vercel-community/php) runtime. This is not Vercel's official PHP runtime. The runtime supplies `pdo_pgsql` and PHP sessions, but Vercel's serverless filesystem is not durable or shared across function instances; therefore native file-based PHP sessions may not reliably persist between requests. Treat this deployment as a preview until session storage is moved to a durable shared store and verified.

1. Push the project to a private GitHub repository. Ensure `.env` is not committed; `.gitignore` already excludes it.
2. In Vercel, import the repository, keep the project root as the **Root Directory**, and deploy with the included `vercel.json`.
3. In **Project Settings → Environment Variables**, add the names from `.env.example` for Production and Preview as appropriate. Set `APP_ENV=production`, `APP_URL` to the HTTPS deployment URL, and `APP_SECURE_COOKIES=true`. Add the Supabase Auth and PostgreSQL connection values. Add `SUPABASE_SERVICE_ROLE_KEY` only if account administration needs it. Never add secrets to the repository or client-side variables.
4. Configure the Supabase network/database connection for serverless traffic; prefer the connection-pooler endpoint shown in the Supabase dashboard. The configured database role must be `inventory_php` (a member of `inventory_app`), not `postgres`; use the SSL-enabled endpoint and `SUPABASE_DB_SSLMODE=require`.
5. Apply any missing migrations `001_core.sql` through `007_notification_reads.sql` to the target Supabase project in order, create the initial administrator, and set Vercel environment variables before redeploying.
6. After deployment, test login/logout across multiple requests and function instances, permissions, purchases, POS checkout, static assets, and reports. If login sessions do not persist reliably, do not use this deployment for production; use a PHP host with durable sessions or add a shared session store first.

## Migrations and sample data

- Apply numbered SQL files in `database/migrations` in order, once per environment, using Supabase SQL Editor or `psql`. Check `schema_migrations` after each application. Do not rerun an already-applied migration: add a new numbered migration for future schema changes.
- Keep each migration transaction-safe and grant new objects only to the backend and Data API roles that need them. Re-evaluate the RLS policies and grants whenever a table or exposed column is added.
- `database/seeds/demo.sql` uses fictional data and `ON CONFLICT`/fixed demonstration transaction identifiers to avoid repeating its sample records. It is not production data.
- The cost method is perpetual weighted-average costing: each confirmed receipt recalculates average cost; sales preserve the applicable unit cost, net selling price, and warranty duration; resalable approved returns restore units at their historical sale cost. Defective, damaged, and pending-inspection returns do not restock. Physical stock counts calculate and record the inventory difference without rewriting historical costs.
- The warranty page allows eligible claims and manager decisions. Serial numbers are collected for claims, but per-unit serials are not captured during receiving or checkout, so the system does not provide serial-level on-hand stock traceability.
- Tax starts at 0% because no jurisdiction or tax rule was supplied. An administrator or manager may configure the shared percentage; new sales and purchase orders record the calculated tax amount. Return refunds reflect the item's net line price and do not include a tax allocation.
- POS discount percentages apply to the eligible sale subtotal before tax; the receipt stores both the percentage and calculated currency amount. Historical fixed discounts are preserved and are not converted to percentages.

## Backups and recovery

- Review the backup and point-in-time recovery features actually enabled for the selected Supabase plan; availability and retention vary by plan.
- For an additional logical backup, install PostgreSQL client tools and run `pg_dump` using the host/port/database/user from `.env`. Omit the password from the command line so the client prompts for it, or use a properly protected PostgreSQL password file. Store encrypted backups outside the web root and test restore into a separate development project.
- Restore only after verifying the target project and backup. Avoid `--clean`/destructive restore options on production. Preserve the migration files and apply schema changes in order before restoring compatible data.
- Do not treat the sample SQL as a backup or use the demo seed in production.

## Testing and verification

PHP 8.2 is available in the workspace. Syntax checks for all PHP files and `assets/app.js`, the security smoke test, and local HTTP smoke tests for the login page, CSRF form, security headers, and visible configuration errors passed. The XAMPP PostgreSQL extensions were enabled in `C:\xampp\php\php.ini`. No Supabase project credentials were supplied, so a live database connection, migrations, Supabase authentication, and database integration tests could not be executed here. Do not consider the application configured until these checks pass locally:

1. `C:\xampp\php\php.exe -l` each PHP file and run `C:\xampp\php\php.exe tests\security-smoke.php` plus `C:\xampp\php\php.exe tests\purchase-validation-smoke.php` for CSRF/output escaping, percentage-discount calculations, and purchase/warranty input validation.
2. `C:\xampp\php\php.exe tools\db-check.php` confirms TLS and migration version; `C:\xampp\php\php.exe tests\verify-schema.php` checks RLS, backend membership, and Data API column grants.
3. Create a product with a unique SKU; attempt a duplicate SKU and confirm it is rejected.
4. Receive a purchase in two partial deliveries; retry one request with the same request key and confirm stock increases only once; exceed remaining quantity and confirm rejection.
5. Complete a checkout and confirm the receipt, movement, historical price/cost, and stock deduction. Attempt insufficient stock and repeat the same idempotency key.
6. Run two overlapping sales against the same limited stock and verify row locking prevents a negative balance.
7. Request a return up to the eligible sold quantity, attempt an over-return, approve resalable vs defective returns, and verify only approved resalable items increase stock.
8. Verify cashier/manager/administrator route access and Supabase Data API RLS behavior with permitted and forbidden users.
9. Create a multi-line purchase order; reject duplicate products in one order; receive each line over multiple deliveries and verify the resulting stock and average costs.
10. Verify supplier/category/brand edits and deactivation, supplier-product cost mappings, automatic reorder suggestions and grouping, date-range report totals, kebab-menu exports, and spreadsheet-formula escaping.
11. Verify only administrators and managers can change the global tax rate; confirm new purchase orders and POS receipts use that rate and older records stay unchanged.
12. Verify POS discount percentages from 0% through 100%, including fractional values; compare the calculated discount and total on screen, receipt, sales history, and CSV export. Confirm pre-migration sales remain displayed as historical fixed amounts.
13. Create one-, three-, and five-line purchase orders; confirm each header appears once, line counts and totals are correct, and all lines/receiving controls appear in View details.
14. Create, edit, deactivate, and delete accounts as an administrator; verify the extended profile fields, role authorization, profile validation, audit details, and that deleted users cannot sign in to the application.
15. Verify notifications include current low/out-of-stock products, recent arrivals, new products, and authorized pending orders; test per-user mark-read/mark-all, the empty state, outside click, Escape, and destinations.

The `InventoryService` is transaction-oriented and uses PostgreSQL row locks. A live concurrent integration run against a dedicated test Supabase project remains necessary before production use.

## Operational boundaries

- No live Supabase credentials were supplied. Connection parameters, migrations, RLS behavior, Auth, and end-to-end operation must be verified in the target project.
- Refund approval is recorded in the system; payment-provider or cash-drawer refund execution is an external/manual step.
- Reports export sales, daily totals, best sellers, inventory, and returns as CSV. PDF/Excel workbook generation is not included.
- Per-unit serials are not captured during receiving or checkout, so warranty claims do not provide serial-level on-hand stock traceability. Notification events are generated from live product, movement, and purchase-order data; per-user read state is persisted.
- Integration/concurrency/RLS tests are described above but were not executed in this environment.
