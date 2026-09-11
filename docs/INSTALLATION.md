# Installation and First Super Admin

## Fresh database installation

Create an empty MySQL 8.0+ or MariaDB 10.11+ database and import only:

```text
database/install.sql
```

`install.sql` contains the complete current schema and baseline seed records. Do not import `schema.sql`, `seed.sql`, or dated migrations after using the current `install.sql` on an empty database.

## Configuration

Copy or rename:

```text
config-example.php -> config.php
```

Edit `config.php` with the database connection, application URL, timezone, and secure-cookie setting. There is no setup key. The release package intentionally excludes `config.php` so updates do not overwrite live credentials.

## First owner

Open:

```text
/setup-first-user.php
```

The page verifies that `config.php` exists, the database is reachable, required tables are installed, and no active Super Admin exists. It then creates the organization, first location, owner account, default roles and permissions, restaurant positions, branding, public page records, application form, owner agent, starter jobs, and audit event in one transaction.

The setup page locks after the first active Super Admin is created. Sign in at `/login.php`; there are no default production credentials.

## Updating an existing installation

Back up the database first. Import each migration that the installation has not already received, in date order:

```text
database/20260803_brand_images_llm_keys.sql
database/20260804_jobs_module.sql
```

The Jobs migration creates the `jobs` table, links resume submissions to jobs, adds Jobs permissions, and grants those permissions to Super Admin and Manager roles.

## Deployment safety

Preserve these environment-controlled paths during every update:

```text
config.php
storage/
uploads/
```

`storage/` contains the server-side LLM encryption key. `uploads/` contains brand images and other runtime uploads. Never replace `config.php` with `config-example.php` during an update.

## Public hiring routes

```text
landing.html               public landing page and featured jobs
jobs.html                  searchable published-jobs list
job.php?id=<job-id>        unique public job detail page
apply.html?job=<job-id>    application form with selected job preloaded
```

## Requirements

- PHP 8.2+
- PDO MySQL extension
- MySQL 8.0+ or MariaDB 10.11+
- HTTPS in production
