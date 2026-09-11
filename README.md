# Gelato Spot Restaurant Training Workspace

This project is a restaurant menu-training, employee-development, hiring, and administration workspace built with PHP 8.2+, MySQL/MariaDB, HTML, CSS, and browser JavaScript. The training menu is database-backed and imports directly from the same public REST menu source used by Gelato Spot.

The application shell may use generic **Restaurant Workspace** wording, while restaurant-facing brand defaults, public pages, admin branding, and agent identity use **Gelato Spot**. Legacy Fatso branding is not part of the current install.

## Included

- Database-backed menu study guides, quizzes, flashcards, guided agent training, kitchen verification, certifications, and mistake review
- Owner-facing `admin-menu-import.php` page for importing the current Gelato Spot menu
- CLI REST importer with dry-run support and idempotent database upserts
- Gelato Spot REST menu integration with a built-in default endpoint; no API key is required
- Price normalization that preserves compact live ranges such as `$13-15`
- Optional Gelato Spot MCP endpoint for later read-only agent-facing restaurant knowledge
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
4. Sign in and open `admin-menu-import.php`, then choose **Import current Gelato Spot menu**. The REST endpoint is built in and requires no API key or menu-source configuration.
5. Alternatively, run `php scripts/sync-gelato-menu.php --dry-run`, then `php scripts/sync-gelato-menu.php` from the project directory.
6. Open the training workspace. Menu knowledge is read from the local database.

## Menu source

The importer defaults to:

- REST menu: `https://gelato.spot/api/v1/menu`
- MCP: `https://gelato.spot/mcp` (optional; not used by the database importer)

The REST URL can be overridden in `config.php` or with `--url=<https-url>` for testing, but normal installation does not require configuration. The importer fetches the current published menu, normalizes it, and writes it transactionally into the local menu tables.

MCP remains separate from menu import. The training workspace does not require MCP to install, import menu data, or run its database-backed training modules.

Existing installations should apply the dated migrations they have not already imported. See `docs/INSTALLATION.md`.

## Deployment safety

Never commit or overwrite these runtime-owned paths:

- `config.php`
- `storage/`
- `uploads/`

The repository retains only protected placeholder files in `storage/` and `uploads/`.
