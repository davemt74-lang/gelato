# Gelato Spot Restaurant Training Workspace

This project is a restaurant menu-training, employee-development, hiring, and administration workspace built with PHP 8.2+, MySQL/MariaDB, HTML, CSS, and browser JavaScript. The menu layer is database-backed and can synchronize from the same public REST source used by the live Gelato Spot menu.

## Included

- Database-backed menu study guides, quizzes, flashcards, guided agent training, kitchen verification, certifications, and mistake review
- Live Gelato Spot REST menu synchronization with a dry-run mode before database writes
- Gelato Spot MCP endpoint configuration for read-only agent-facing restaurant knowledge
- Grouped flashcard sets generated from Mistake Review
- Employee, Manager, and Super Admin roles with editable permissions
- Notifications and proactive employee/owner agent workspaces
- Resume intake and standalone resume review pages
- Admin Jobs module, public jobs list, unique job detail pages, and job-linked applications
- Brand image management and encrypted Claude/OpenAI API-key settings
- Database-backed authentication, password recovery, and first-owner setup

## Fresh installation

1. Import `database/install.sql` into an empty MySQL 8+ or MariaDB 10.11+ database.
2. Rename `config-example.php` to `config.php`, enter the new database credentials and set the new subdomain URL. For HTTPS deployments, set `security.cookie_secure` to `true`.
3. Open `setup-first-user.php` and create the first Super Admin/organization.
4. From the project directory run `php scripts/sync-gelato-menu.php --dry-run`. Confirm the live source summary is reasonable and completes without an error.
5. Run `php scripts/sync-gelato-menu.php` to synchronize the current live Gelato Spot menu into the new database. If the database contains more than one active organization, pass `--organization-id=<id>`.
6. Sign in at `login.php`. The training workspace loads its menu knowledge from the local database, not from a bundled static menu file.

The canonical importer source defaults to `https://gelato.spot/api/v1/menu`. The live `gelato.spot/menu/` application has been contract-checked to consume that REST endpoint. `https://gelato.spot/mcp` is configured separately for read-only agent-facing knowledge; it is not used as the transactional database importer.

Existing installations should apply the dated migrations they have not already imported. See `docs/INSTALLATION.md`.

## Deployment safety

Never commit or overwrite these runtime-owned paths:

- `config.php`
- `storage/`
- `uploads/`

The repository retains only protected placeholder files in `storage/` and `uploads/`.
