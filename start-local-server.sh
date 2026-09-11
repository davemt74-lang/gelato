#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")"
if ! command -v php >/dev/null 2>&1; then
  printf '%s\n' 'PHP was not found. Install PHP 8.2+ with the PDO MySQL extension, or deploy this folder to a PHP web server.' >&2
  exit 1
fi
printf '%s\n' 'Open http://127.0.0.1:8080/setup-first-user.php'
php -S 127.0.0.1:8080
