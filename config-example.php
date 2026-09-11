<?php
/**
 * Restaurant Training Workspace configuration template.
 *
 * INSTALLATION:
 * 1. Rename this file to config.php.
 * 2. Enter the database credentials for the database where database/install.sql was imported.
 * 3. Keep the Gelato Spot menu endpoints below unless intentionally connecting a different source.
 *
 * IMPORTANT: config.php is intentionally excluded from deployment packages and must not be
 * committed to source control or placed in a publicly downloadable directory.
 */
return [
    'app' => [
        'name' => 'Restaurant Training Workspace',
        'url' => 'http://127.0.0.1:8080',
        'timezone' => 'America/Phoenix',
        'debug' => false,
    ],

    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'restaurant_training',
        'username' => 'restaurant_app',
        'password' => 'change-this-database-password',
        'charset' => 'utf8mb4',
        // Optional: provide a full PDO DSN instead of host/port/name.
        // 'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=restaurant_training;charset=utf8mb4',
    ],

    'menu_source' => [
        // This REST endpoint is the canonical source used by the live gelato.spot menu app.
        'rest_url' => 'https://gelato.spot/api/v1/menu',
        // Read-only MCP endpoint for agent-facing Gelato Spot menu/business knowledge.
        'mcp_url' => 'https://gelato.spot/mcp',
        'timeout_seconds' => 20,
    ],

    'security' => [
        'session_name' => 'restaurant_workspace_session',
        'session_lifetime_seconds' => 28800,
        'cookie_secure' => false, // Set true when the site uses HTTPS.
        'cookie_samesite' => 'Lax',
    ],
];
