# Gelato Spot Restaurant Training Workspace

This project is a restaurant menu-training, employee-development, hiring, administration, facility-planning, and equipment-operations workspace built with PHP 8.2+, MySQL/MariaDB, HTML, CSS, and browser JavaScript. The training menu is database-backed and imports directly from the same public REST menu source used by Gelato Spot.

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
- Permission-controlled, database-backed **Floor Planner** with real dimensions, seating analytics, revenue-per-layout estimates, and restaurant/gelato equipment components
- Permission-controlled **Equipment Catalog** with purpose, category/type, brand, manufacturer, model, serial/asset tags, age, condition, criticality, location, utility requirements, dimensions, capacity, manuals, warranty, purchase/replacement cost, cleaning/operating/safety notes, and maintenance scheduling
- Equipment **service contacts** for preferred vendors, warranty providers, emergency service, specialties, account/contract numbers, phone, email, and notes
- Equipment **service history** with maintenance/repair/inspection records, technicians, parts, cost, downtime, next-service dates, and automatic last/next-service updates
- Authenticated internal **Equipment Agent Brain** with structured knowledge projection and skills for equipment search, asset context, maintenance-due checks, service-contact lookup, service history, and internal knowledge search

## Fresh installation

1. Import `database/install.sql` into an empty MySQL 8+ or MariaDB 10.11+ database.
2. Import `database/20260912_floor_planner.sql` to add the Floor Planner layout table and its permissions.
3. Import `database/20260912_equipment_catalog_brain.sql` to add equipment assets, service contacts/history, the internal Agent Brain knowledge index, and equipment/agent permissions.
4. Rename `config-example.php` to `config.php`, enter the new database credentials, and set the new subdomain URL. For HTTPS deployments, set `security.cookie_secure` to `true`.
5. Open `setup-first-user.php` and create the first Super Admin/organization.
6. Sign in and open `admin-menu-import.php`, then choose **Import current Gelato Spot menu**. The REST endpoint is built in and requires no API key or menu-source configuration.
7. Alternatively, run `php scripts/sync-gelato-menu.php --dry-run`, then `php scripts/sync-gelato-menu.php` from the project directory.
8. Open the training workspace. Menu knowledge is read from the local database.

The Floor Planner and Equipment Catalog permissions appear automatically in **Account Types & Permissions** after their migrations are imported. Super Admin remains unrestricted. Non-owner roles need `equipment.view` plus `agent.equipment_skills` before the authenticated agent may retrieve equipment knowledge on their behalf.

## AI knowledge boundaries

The public website assistant and the authenticated internal equipment brain are deliberately separated.

- Public restaurant knowledge continues to use the existing public Knowledge Center and public-agent retrieval path.
- Equipment records, service contacts, maintenance schedules, costs, serial numbers, warranties, operational notes, and service history are projected into `agent_knowledge_records` with **internal** visibility.
- Equipment changes, contact changes, and service events refresh the internal knowledge projection automatically, so the agent uses current database records instead of a second manually maintained equipment dataset.
- The internal agent skill API enforces the signed-in user's equipment and agent permissions before returning equipment knowledge.
- Equipment skills currently include `equipment.search`, `equipment.asset_context`, `equipment.maintenance_due`, `equipment.service_contacts`, and `knowledge.search`.

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
