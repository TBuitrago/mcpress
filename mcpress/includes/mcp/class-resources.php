<?php
/**
 * MCP resources: a usage guide for the model and a site snapshot.
 *
 * @package MCPress
 */

namespace MCPress\MCP;

use MCPress\Context;
use MCPress\Permissions\Policy;
use MCPress\Util;

defined( 'ABSPATH' ) || exit;

class Resources {

	public static function all() {
		return array(
			array(
				'uri'         => 'mcpress://guide',
				'name'        => 'guide',
				'title'       => 'How to work with this site through MCPress',
				'description' => 'Permission mode, approval flow and tips for each area of WordPress.',
				'mimeType'    => 'text/markdown',
			),
			array(
				'uri'         => 'mcpress://site',
				'name'        => 'site',
				'title'       => 'Site snapshot',
				'description' => 'WordPress and PHP versions, theme, plugins and this connection\'s permissions.',
				'mimeType'    => 'application/json',
			),
		);
	}

	public static function read( $uri, Context $ctx ) {
		if ( 'mcpress://guide' === $uri ) {
			return array(
				'uri'      => $uri,
				'mimeType' => 'text/markdown',
				'text'     => self::guide( $ctx ),
			);
		}
		if ( 'mcpress://site' === $uri ) {
			wp_set_current_user( $ctx->user_id );
			return array(
				'uri'      => $uri,
				'mimeType' => 'application/json',
				'text'     => Util::json( \MCPress\Tools\Site::site_info( array(), $ctx ), true ),
			);
		}
		return null;
	}

	public static function guide( Context $ctx ) {
		$mode = Server::mode_for_ai( $ctx->mode );
		$md   = <<<MD
# Working with this WordPress site through MCPress

**Connection mode:** {$mode}

## Permission modes
- **readonly** – reads only.
- **approval** – every change waits for the owner. The tool answers `status: approval_required` with an `approval_id` and an `approve_url`. Tell the user, then call `check_approval` (use `wait_seconds` ≤ 25, repeat if still pending). When approved, the action runs exactly as you requested and `check_approval` returns its result. Do not resend the same call.
- **auto** – changes run immediately; *critical* actions (run_php, SQL writes, search_replace, wp-config/.htaccess edits, creating administrators or changing roles/passwords, deleting users, permanent deletions, core updates, installing plugins from a URL) wait for approval.
- **full** – everything runs immediately. The owner trusts you with the keys: confirm with the user before anything irreversible.

Every call is recorded in the owner's activity log.

## Risk levels
`read` < `write` (reversible) < `destructive` (removes or replaces, recoverable with effort) < `critical` (irreversible or security-sensitive). Some tools compute the risk from the arguments, e.g. `delete_post` to the trash is *write*, with `force: true` it is *critical*.

## Tips
- Begin with `site_info`. It lists the theme, plugins, versions and what this connection may do.
- Content: `get_post` returns raw block markup. For small changes use `edit_post_content` (exact find & replace) instead of sending the whole content again. Revisions are kept; `restore_revision` undoes.
- Files: paths are relative to the WordPress root (e.g. `wp-content/themes/mytheme/style.css`). `edit_file` replaces an exact unique snippet. PHP is syntax-checked before writing and a backup is taken first (`list_file_backups`, `restore_file_backup`).
- Plugins with their own data (WooCommerce, ACF, Yoast, Rank Math, Gravity Forms…): use `list_rest_routes` and `rest_request`. They run inside WordPress as the connected user, with the plugin's own validation and hooks.
- Elementor pages: use `elementor_get_page` / `elementor_update_page`, never write `_elementor_data` by hand with `update_post_meta`.
- Database: `db_query` runs SELECT freely; anything else is a critical write. Prefer the dedicated tools, which fire WordPress hooks and clear caches.
- After visual changes, `flush_cache` purges WordPress and common page-cache plugins.

## Hard limits (every mode)
- MCPress' own settings, keys, tables and files cannot be read or changed. Mode changes happen only in wp-admin.
- You cannot delete or demote the user you act as, nor the last administrator, nor deactivate/delete MCPress.
- `DISALLOW_FILE_MODS` / `DISALLOW_FILE_EDIT` in wp-config.php are respected; ask the user if they need to change them.
MD;
		if ( \MCPress\Settings::get( 'protect_sensitive' ) ) {
			$md .= "\n- Sensitive data protection is ON: password hashes, API keys and similar secrets are masked as `[redacted]`, and wp-config.php / .env cannot be read.\n";
		}
		return $md;
	}
}
