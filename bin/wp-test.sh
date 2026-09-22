#!/usr/bin/env bash
#
# Runs the integration suite against the throwaway WordPress in .wp-test/.
# Builds it first if it is not there yet.
#
# Usage: bin/wp-test.sh [--fresh]

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_DIR="$ROOT/.wp-test"
PORT="${RAYNET_TEST_PORT:-8765}"

if [[ ! -d "$TEST_DIR/wp" || "${1:-}" == "--fresh" ]]; then
	"$ROOT/bin/wp-test-setup.sh" "${1:-}"
else
	# Pick up the working copy of the plugin. It is copied rather than linked so
	# plugin_basename() resolves inside the WordPress root.
	rm -rf "$TEST_DIR/wp/wp-content/plugins/raynet-lead-api-integration"
	cp -R "$ROOT/raynet-lead-api-integration" "$TEST_DIR/wp/wp-content/plugins/raynet-lead-api-integration"
	cp "$ROOT/tests/integration/mu-raynet-test-double.php" "$TEST_DIR/wp/wp-content/mu-plugins/"
fi

cd "$TEST_DIR"

rm -f wp/wp-content/debug.log

if ! curl -s -o /dev/null "http://localhost:$PORT/"; then
	php -S "localhost:$PORT" -t wp > server.log 2>&1 &
	SERVER_PID=$!
	trap 'kill $SERVER_PID 2>/dev/null || true' EXIT

	for _ in $(seq 1 20); do
		curl -s -o /dev/null "http://localhost:$PORT/" && break
		sleep 0.3
	done
fi

RAYNET_TEST_URL="http://localhost:$PORT" \
	php wp-cli.phar --path=wp eval-file "$ROOT/tests/integration/run.php" 2>/dev/null
