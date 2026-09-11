# Fatso Restaurant Training Workspace

Fatso is a restaurant menu-training, employee-development, hiring, and administration workspace built with PHP 8.2+, MySQL/MariaDB, HTML, CSS, and browser JavaScript.

## Included

- Menu study guides, quizzes, flashcards, guided agent training, kitchen verification, certifications, and module-aware mistake review
- Grouped flashcard sets generated from Mistake Review
- Employee, Manager, and Super Admin roles with editable permissions
- Notifications and proactive employee/owner agent workspaces
- Resume intake and standalone resume review pages
- Admin Jobs module, public jobs list, unique job detail pages, and job-linked applications
- Brand image management and encrypted Claude/OpenAI API-key settings
- Database-backed authentication, password recovery, and first-owner setup

## Fresh installation

1. Import `database/install.sql` into an empty MySQL 8+ or MariaDB 10.11+ database.
2. Rename `config-example.php` to `config.php` and enter the environment settings.
3. Open `setup-first-user.php` and create the first Super Admin.
4. Sign in at `login.php`.

Existing installations should apply the dated migrations they have not already imported. See `docs/INSTALLATION.md`.

## Deployment safety

Never commit or overwrite these runtime-owned paths:

- `config.php`
- `storage/`
- `uploads/`

The repository retains only protected placeholder files in `storage/` and `uploads/`.
