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
- Permission-controlled, database-backed **Operational Floor Planner** with real dimensions, seating analytics, saved layouts, and live equipment placement
- Canonical Floor Planner ↔ Equipment Catalog integration: tables/walls/seating stay in layout JSON, while every oven, mixer, refrigerator, gelato machine, dishwasher, sink, bar appliance, POS unit, and other serviceable asset is rendered from its single Equipment Catalog record
- Permission-controlled **Equipment Catalog** with purpose, category/type, brand, manufacturer, model, serial/asset tags, age, condition, criticality, location, utility requirements, dimensions, capacity, manuals, warranty, purchase/replacement cost, cleaning/operating/safety notes, and maintenance scheduling
- Equipment **service contacts** for preferred vendors, warranty providers, emergency service, specialties, account/contract numbers, phone, email, and notes
- Equipment **service history** with maintenance/repair/inspection records, technicians, parts, cost, downtime, next-service dates, and automatic last/next-service updates
- Authenticated internal **Equipment Agent Brain** with structured knowledge projection and skills for equipment search, asset context, floor-plan placement, maintenance-due checks, service-contact lookup, service history, and internal knowledge search
- Dedicated equipment asset detail page linking floor placement, service history, service contacts, operating knowledge, and Agent Brain context

## Fresh installation

1. Import `database/install.sql` into an empty MySQL 8+ or MariaDB 10.11+ database.
2. Import `database/20260912_floor_planner.sql` to add the Floor Planner layout table and its permissions.
3. Import `database/20260912_equipment_catalog_brain.sql` to add equipment assets, service contacts/history, the internal Agent Brain knowledge index, and equipment/agent permissions.
4. Import `database/20260912_canonical_floor_equipment.sql` to make Equipment Catalog assets the canonical floor-plan equipment objects and add protected placement coordinates/rotation/locking.
5. Rename `config-example.php` to `config.php`, enter the database credentials, and set the deployment URL. For HTTPS deployments, set `security.cookie_secure` to `true`.
6. Open `setup-first-user.php` and create the first Super Admin/organization.
7. Sign in and open `admin-menu-import.php`, then choose **Import current Gelato Spot menu**. The REST endpoint is built in and requires no API key or menu-source configuration.
8. Alternatively, run `php scripts/sync-gelato-menu.php --dry-run`, then `php scripts/sync-gelato-menu.php` from the project directory.
9. Open the training workspace. Menu knowledge is read from the local database.

The Floor Planner and Equipment Catalog permissions appear automatically in **Account Types & Permissions** after their migrations are imported. Super Admin remains unrestricted. Non-owner roles need `equipment.view` plus `agent.equipment_skills` before the authenticated agent may retrieve equipment knowledge on their behalf.

## Canonical floor-plan equipment model

The Equipment Catalog is the source of truth for equipment. The floor plan no longer creates a second editable copy of an oven, mixer, freezer, gelato case, or other serviceable asset.

- Structural objects such as walls, aisles, tables, chairs, host stands, bars, and zones are stored in `floor_plans.plan_json`.
- Serviceable equipment is stored once in `equipment_assets` and placed on a plan using `floor_plan_public_id`, X/Y coordinates in feet, rotation, Z order, and a lock flag.
- Equipment footprint dimensions shown on the plan come from the same `width_inches` and `depth_inches` used by the Equipment Catalog. Resizing live equipment from the planner updates the catalog record instead of creating plan-only dimensions.
- Moving, rotating, locking, or removing live equipment uses `api/equipment-placement.php`, which enforces organization scope, CSRF protection, Floor Planner permissions, and database-level placement guards.
- The general Equipment Catalog remains responsible for identity, lifecycle, dimensions, utilities, maintenance, contacts, and service history. It cannot silently overwrite canonical floor placement fields.
- The legacy `floor-planner.php` route redirects to `floor-planner-ops.php` so all users reach the canonical operational planner.
- Old equipment blocks found in historical plan JSON are displayed as legacy ghosts until the user replaces them with real Equipment Catalog assets and saves the canonical layout.

## AI knowledge boundaries

The public website assistant and the authenticated internal equipment brain are deliberately separated.

- Public restaurant knowledge continues to use the existing public Knowledge Center and public-agent retrieval path.
- Equipment records, service contacts, maintenance schedules, costs, serial numbers, warranties, operational notes, service history, and floor-plan placement are projected into internal `agent_knowledge_records`.
- Equipment changes, contact changes, service events, and placement changes refresh the internal knowledge projection automatically, so the agent uses current database records instead of a second manually maintained equipment dataset.
- The internal agent skill API enforces the signed-in user's equipment and agent permissions before returning equipment knowledge.
- Equipment skills include `equipment.search`, `equipment.asset_context`, `equipment.floor_plan`, `equipment.maintenance_due`, `equipment.service_contacts`, and `knowledge.search`.
- Floor-plan questions such as “where is the mixer?”, “what equipment is on this layout?”, or “which floor-plan equipment needs service?” are routed through the same Equipment Brain used by the Owner Agent.

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
