# Gelato Spot Restaurant Training Workspace

This project is a restaurant menu-training, employee-development, hiring, and administration workspace built with PHP 8.2+, MySQL/MariaDB, HTML, CSS, and browser JavaScript. The training menu is database-backed. A bundled scanned Gelato Spot menu snapshot is the install-safe default, while the public REST and MCP services remain optional live integrations.

## Included

- Database-backed menu study guides, quizzes, flashcards, guided agent training, kitchen verification, certifications, and mistake review
- Bundled scanned menu snapshot in `data/gelato-menu-scan.json` for zero-configuration menu import
- Owner-facing `admin-menu-import.php` page for manual scanned-menu import or optional live REST refresh
- CLI importer with dry-run support and idempotent database upserts
- Optional Gelato Spot REST synchronization for current published menu data
- Optional Gelato Spot MCP endpoint for read-only agent-facing restaurant knowledge
- Grouped flashcard sets generated from Mistake Review
- Employee, Manager, and Super Admin roles with editable permissions
- Notifications and proactive employee/owner agent workspaces
- Resume intake and standalone resume review pages
- Admin Jobs module, public jobs list, unique job detail pages, and job-linked applications
- Brand image management and encrypted Claude/OpenAI API-key settings
- Database-backed authentication, password recovery, and first-owner setup

## Fresh installation

1. Import `database/install.sql` into an empty MySQL 8+ or MariaDB 10.11+ database.
2. Rename `config-example.php` to `config.php`, enter the new database credentials, and set the new subdomain URL. For HTTPS deployments, set `security.cookie_secure` to `true`.
3. Open `setup-first-user.php` and create the first Super Admin/organization.
4. Sign in and open `admin-menu-import.php`, then choose **Import scanned menu**. This requires no REST or MCP configuration.
5. Alternatively, from the project directory run `php scripts/sync-gelato-menu.php --dry-run`, then `php scripts/sync-gelato-menu.php`. With no source flags, both commands use `data/gelato-menu-scan.json`.
6. Open the training workspace. Menu knowledge is read from the local database.

The bundled snapshot currently contains the stable scanned menu structure and core offerings. Rotating or seasonal menu data can be refreshed later from the optional live REST source.

## Optional live REST and MCP

The source adapter has safe built-in defaults, so these endpoints do not have to be added to `config.php` unless you want to override them:

- REST menu: `https://gelato.spot/api/v1/menu`
- MCP: `https://gelato.spot/mcp`

Use `php scripts/sync-gelato-menu.php --live --dry-run` to inspect the live REST payload without writing to the database. Use `php scripts/sync-gelato-menu.php --live` to replace the active database menu with the current live REST inventory. You can also choose **Refresh from live REST** on `admin-menu-import.php`.

MCP is separate from transactional menu import. The training workspace does not require MCP to install, import the scanned menu, or run its database-backed training modules.

Existing installations should apply the dated migrations they have not already imported. See `docs/INSTALLATION.md`.

## Deployment safety

Never commit or overwrite these runtime-owned paths:

- `config.php`
- `storage/`
- `uploads/`

The repository retains only protected placeholder files in `storage/` and `uploads/`.
