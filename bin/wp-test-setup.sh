#!/usr/bin/env bash
#
# Builds a throwaway WordPress on SQLite in .wp-test/ so the plugin can be
# exercised against the real core: hook order, the admin menu, capabilities,
# and full HTTP requests. None of that is reachable from the stubbed suite.
#
# Usage: bin/wp-test-setup.sh [--fresh]

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_DIR="$ROOT/.wp-test"
PORT="${RAYNET_TEST_PORT:-8765}"

if [[ "${1:-}" == "--fresh" ]]; then
	rm -rf "$TEST_DIR"
fi

mkdir -p "$TEST_DIR"
cd "$TEST_DIR"

if [[ ! -d wp ]]; then
	echo "Stahuji WordPress…"
	curl -sL -o wp.tar.gz https://wordpress.org/latest.tar.gz
	tar xzf wp.tar.gz
	mv wordpress wp
	rm wp.tar.gz
fi

if [[ ! -d wp/wp-content/plugins/sqlite-database-integration ]]; then
	echo "Stahuji SQLite integraci…"
	curl -sL -o sqlite.zip https://github.com/WordPress/sqlite-database-integration/archive/refs/heads/main.zip
	unzip -q sqlite.zip
	mv sqlite-database-integration-main wp/wp-content/plugins/sqlite-database-integration
	rm sqlite.zip
fi

if [[ ! -f wp-cli.phar ]]; then
	echo "Stahuji wp-cli…"
	curl -sL -o wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
	chmod +x wp-cli.phar
fi

sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$TEST_DIR/wp/wp-content/plugins/sqlite-database-integration#" \
    -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
    wp/wp-content/plugins/sqlite-database-integration/db.copy > wp/wp-content/db.php

cat > wp/wp-config.php <<CFG
<?php
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'AUTH_KEY',         'raynet-test-auth' );
define( 'SECURE_AUTH_KEY',  'raynet-test-secure' );
define( 'LOGGED_IN_KEY',    'raynet-test-login' );
define( 'NONCE_KEY',        'raynet-test-nonce' );
define( 'AUTH_SALT',        'raynet-test-auth-salt' );
define( 'SECURE_AUTH_SALT', 'raynet-test-secure-salt' );
define( 'LOGGED_IN_SALT',   'raynet-test-login-salt' );
define( 'NONCE_SALT',       'raynet-test-nonce-salt' );

\$table_prefix = 'wp_';

define( 'WP_DEBUG',         true );
define( 'WP_DEBUG_LOG',     true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_HOME',    'http://localhost:$PORT' );
define( 'WP_SITEURL', 'http://localhost:$PORT' );
define( 'AUTOMATIC_UPDATER_DISABLED', true );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
CFG

wpc() { php wp-cli.phar --path=wp "$@" 2>/dev/null; }

if ! wpc core is-installed 2>/dev/null; then
	echo "Instaluji WordPress…"
	wpc core install --url="http://localhost:$PORT" --title="RAYNET test" \
		--admin_user=admin --admin_password=admin --admin_email=test@example.com --skip-email
fi

# The plugin is copied, not linked: a symlink outside the WordPress root makes
# plugin_basename() and plugin_dir_url() resolve to the wrong place.
rm -rf wp/wp-content/plugins/raynet-lead-api-integration
cp -R "$ROOT/raynet-lead-api-integration" wp/wp-content/plugins/raynet-lead-api-integration

mkdir -p wp/wp-content/mu-plugins
cp "$ROOT/tests/integration/mu-raynet-test-double.php" wp/wp-content/mu-plugins/

wpc plugin activate raynet-lead-api-integration >/dev/null 2>&1 || true

echo "Hotovo. WordPress $(wpc core version) v $TEST_DIR"
