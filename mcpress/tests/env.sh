#!/usr/bin/env bash
# MCPress local test environment: WordPress (Apache, PHP 8.3) + MariaDB in Docker.
#
#   bash tests/env.sh up      → http://localhost:8088 (admin / admin-test-pass), plugin mounted from this folder
#   bash tests/env.sh keys    → creates one key per mode and writes tests/.keys.json (git-ignored)
#   bash tests/env.sh e2e     → runs tests/e2e.py against it
#   bash tests/env.sh wp …    → WP-CLI inside the environment
#   bash tests/env.sh down    → removes containers and volumes
#
# Never run "wp plugin delete/uninstall mcpress" here: the plugin folder is your working copy.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN="$(cd "$HERE/.." && pwd)"
NET=mcpress-test
DB=mcpress-db
WP=mcpress-wp
CLI=mcpress-cli
VOL=mcpress-wpdata
PORT="${MCPRESS_TEST_PORT:-8088}"

wp() { docker exec -i "$CLI" wp --path=/var/www/html "$@"; }

case "${1:-}" in
	up)
		docker network create "$NET" >/dev/null 2>&1 || true
		docker volume create "$VOL" >/dev/null
		docker run -d --name "$DB" --network "$NET" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp \
			-e MARIADB_USER=wp -e MARIADB_PASSWORD=wp --tmpfs /var/lib/mysql mariadb:11 >/dev/null
		# Apache listens on the same port inside and outside, so the site can call itself (loopback checks).
		docker run -d --name "$WP" --network "$NET" -p "$PORT:$PORT" \
			-e WORDPRESS_DB_HOST="$DB" -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
			-e WORDPRESS_DEBUG=1 \
			-e "WORDPRESS_CONFIG_EXTRA=define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false); define('FS_METHOD', 'direct');" \
			-v "$PLUGIN:/var/www/html/wp-content/plugins/mcpress" -v "$VOL:/var/www/html" \
			wordpress:php8.3-apache bash -c "sed -i 's/^Listen 80\$/Listen $PORT/' /etc/apache2/ports.conf && sed -i 's/:80>/:$PORT>/' /etc/apache2/sites-enabled/000-default.conf && docker-entrypoint.sh apache2-foreground" >/dev/null
		docker run -d --name "$CLI" --network "$NET" --user 33:33 \
			-e WORDPRESS_DB_HOST="$DB" -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
			-v "$PLUGIN:/var/www/html/wp-content/plugins/mcpress" -v "$VOL:/var/www/html" \
			--entrypoint sleep wordpress:cli-php8.3 infinity >/dev/null
		sleep 10
		wp core install --url="http://localhost:$PORT" --title="MCPress Test" --admin_user=admin \
			--admin_password=admin-test-pass --admin_email=admin@example.test --skip-email
		wp rewrite structure '/%postname%/' --hard
		wp plugin activate mcpress
		echo "Ready: http://localhost:$PORT/wp-admin (admin / admin-test-pass)"
		;;
	keys)
		wp eval '$o = array(); foreach ( array( "full", "approval", "readonly", "auto" ) as $m ) { $c = MCPress\Auth\Connections::create_key( "e2e " . $m, 1, $m ); $o[ $m ] = $c["key"]; } echo json_encode( $o );' > "$HERE/.keys.json"
		echo "Keys written to tests/.keys.json"
		;;
	e2e)
		[ -f "$HERE/.keys.json" ] || "$0" keys
		MCPRESS_URL="http://localhost:$PORT/wp-json/mcpress/v1/mcp" MCPRESS_KEYS="$HERE/.keys.json" python3 "$HERE/e2e.py"
		;;
	wp)
		shift
		wp "$@"
		;;
	down)
		docker rm -f "$CLI" "$WP" "$DB" >/dev/null 2>&1 || true
		docker volume rm "$VOL" >/dev/null 2>&1 || true
		docker network rm "$NET" >/dev/null 2>&1 || true
		rm -f "$HERE/.keys.json"
		echo "Removed."
		;;
	*)
		echo "Usage: bash tests/env.sh up|keys|e2e|wp …|down"
		exit 1
		;;
esac
