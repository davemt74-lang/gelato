RESTAURANT TRAINING, ADMIN, AND HIRING WORKSPACE
================================================

This package contains the restaurant training workspace, public hiring pages, a MySQL/MariaDB database schema, and the first-owner installation flow.

FRESH INSTALLATION
------------------
1. Create one empty MySQL 8.0+ or MariaDB 10.11+ database.

2. Import this ONE file:

   database/install.sql

   Do not also import schema.sql or seed.sql when install.sql is used. install.sql already contains both.

3. Rename:

   config-example.php  ->  config.php

4. Edit config.php and set:
   - database host
   - database port
   - database name
   - database username
   - database password
   - app URL
   - timezone

5. Open:

   /setup-first-user.php

6. Create the store owner. The setup page creates:
   - organization and first location
   - first Super Admin account
   - Manager and Employee account types
   - permission grants
   - restaurant positions
   - brand settings
   - public landing-page record
   - resume application form
   - Owner System Agent and baseline rules
   - starter published jobs for the public careers pages

7. After setup, sign in at:

   /login.php

IMPORTANT CONFIG RULE
---------------------
Only config-example.php is included in deployment ZIP files. Your live config.php is intentionally excluded so application updates do not overwrite database credentials or environment settings.

When deploying an update, preserve the existing config.php.

EXISTING INSTALLATION UPDATE
----------------------------
For an installation created with an older package, import each applicable migration once, in date order:

   database/20260803_brand_images_llm_keys.sql
   database/20260804_jobs_module.sql

The first migration adds brand-image references, encrypted Claude/OpenAI credential storage, and the related permissions. The second adds the Jobs module, public job records, job-linked resume submissions, and Jobs permissions.

Do not import these migrations after a fresh installation made with the current database/install.sql; the current install file already contains both changes.

Preserve these paths when deploying future updates:
- config.php
- storage/
- uploads/

SQL FILES
---------
database/install.sql
  Canonical fresh-install file. Import this file into an empty database.

database/schema.sql
  Schema-only reference for development and controlled migrations.

database/seed.sql
  Baseline permissions and major-allergen records. Already included in install.sql.

FIRST SUPER ADMIN
-----------------
The first owner is not inserted through SQL and there are no default production credentials.

Create the owner through setup-first-user.php after config.php is present and install.sql has been imported. Passwords are stored with PHP password_hash(), and the page locks itself after an active owner exists.

SECURE LOGIN
------------
login.php
  Database-backed login with password verification, account status checks, temporary lockout, CSRF protection, secure session cookies, login auditing, and password rehash support.

logout.php
  Clears the server session and writes a logout audit event.

index.php
  Authenticated entry point for the current workspace UI. It loads the signed-in database user and permission set into the existing frontend shell.

index.html
  Frontend template. For configured deployments, use index.php rather than opening index.html directly.

LOCAL PHP SERVER
----------------
Windows:
  start-local-server.bat

macOS/Linux:
  ./start-local-server.sh

Then open:
  http://127.0.0.1:8080/setup-first-user.php

The PHP PDO MySQL extension must be enabled.

PUBLIC PAGES
------------
landing.html
  Public restaurant and recruitment landing page with published job cards.

jobs.html
  Searchable public list of currently published job openings.

job.php?id=<job-id>
  Unique public detail page for each published job.

apply.html
  Public resume form with a published-job selection dropdown and job preselection support.

BACKEND STATUS
--------------
The first-owner installer and database login are server-side and database-backed.

Brand image uploads, encrypted LLM credentials, account-type permission editing, login, password recovery, notifications, and the first-owner setup are database-backed. Some training, account editor, form builder, landing-page editor, and resume write paths still retain prototype/local-browser behavior and should move to permission-checked PHP endpoints in the next backend phase.

SECURITY NOTES
--------------
- Never upload config.php to a public repository.
- Set cookie_secure=true when HTTPS is enabled.
- Complete setup-first-user.php immediately after deployment; it is open only until the first active Super Admin exists.
- Use a dedicated database account with only the permissions required by this application.
- Store uploaded resumes outside the public web root.
- Disable display_errors in production.
- Back up the database before importing future migrations.
- Preserve storage/ and uploads/ during deployments. storage/ holds the server encryption key; uploads/ holds brand images.

GUIDED AGENT TRAINING
The Agent Workspace can lead a complete deterministic training conversation without an external LLM. Choose a position and click "Train me in Agent Workspace," use the recommended next action, or open the + training launcher in the sticky chat bar. During a session, the employee only answers the current question. The agent scores the response, shows the correct answer, explains the menu source, and automatically asks the next question. Type hint, repeat, skip, or stop training when needed.

OWNER SYSTEM AGENT
------------------
The Super Admin dashboard includes a proactive owner-agent workspace with the same floating chat composer used by employee training. It continuously prioritizes new resumes, overdue training, low quiz scores, certification candidates, unread notifications, invitations, and account setup. The + launcher provides one-click administrative prompts, and each response includes the next recommended action.

This owner-agent upgrade does not require an additional SQL migration.
LATEST TRAINING REVIEW UPDATE
-----------------------------
The Mistake Review Center now captures missed work from Agent Workspace guided training, flashcards marked Needs Review, formal learning quizzes, daily training, and kitchen ticket verification. Each record includes the source module and menu section. Resume submissions open on unique resume.php detail pages without the workspace sidebar.



JOBS AND APPLICATIONS
---------------------
Super Admins and Managers with Jobs permissions can create, edit, publish, pause, and review job openings from Admin > Jobs. Published openings appear on landing.html and jobs.html, and each opening links to its own job.php page. The public application form loads the current openings into Position of Interest and records the selected job with the resume submission.

For an existing database, import database/20260804_jobs_module.sql once before using the database-backed Jobs API.

MISTAKE REVIEW FLASHCARD SETS
-----------------------------
The Mistake Review Center's "Make flashcards from open mistakes" action now saves a named, grouped study set. These sets appear directly on the Flashcards page under Focused flashcard sets, where employees can study or delete each set without losing the source-module context.
