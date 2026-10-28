<?php
/**
 * WordPress core and translation updates.
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Tools\Helpers as H;

defined( 'ABSPATH' ) || exit;

class Updates {

	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'         => 'check_updates',
				'title'        => 'Check for updates',
				'group'        => 'updates',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Available updates for WordPress core, plugins, themes and translations (asks WordPress.org now when refresh=true).',
				'input_schema' => H::schema( array( 'refresh' => H::bool( 'Ask WordPress.org now instead of using the cached result.', true ) ) ),
				'callback'     => array( __CLASS__, 'check_updates' ),
				'capability'   => 'update_plugins',
			)
		);
		$r->register(
			array(
				'name'         => 'update_core',
				'title'        => 'Update WordPress',
				'group'        => 'updates',
				'risk'         => Policy::RISK_CRITICAL,
				'description'  => 'Update WordPress core to the latest version (or a given one offered by WordPress.org). Take a backup first.',
				'input_schema' => H::schema( array( 'version' => H::str( 'Exact version from check_updates (default: latest).' ) ) ),
				'callback'     => array( __CLASS__, 'update_core' ),
				'guard'        => array( __CLASS__, 'guard' ),
				'capability'   => 'update_core',
			)
		);
		$r->register(
			array(
				'name'         => 'update_translations',
				'title'        => 'Update translations',
				'group'        => 'updates',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Install every available translation update (core, plugins, themes).',
				'input_schema' => H::schema( array() ),
				'callback'     => array( __CLASS__, 'update_translations' ),
				'guard'        => array( __CLASS__, 'guard' ),
				'capability'   => 'update_languages',
			)
		);
	}

	public static function guard() {
		return H::file_mods_error( 'mcpress_core' );
	}

	public static function check_updates( array $args ) {
		global $wp_version;
		H::admin_includes();
		if ( ! empty( $args['refresh'] ) ) {
			delete_site_transient( 'update_core' );
			delete_site_transient( 'update_plugins' );
			delete_site_transient( 'update_themes' );
			wp_version_check( array(), true );
			wp_update_plugins();
			wp_update_themes();
		}
		$core = array();
		foreach ( (array) get_core_updates() as $u ) {
			if ( isset( $u->response ) && 'upgrade' === $u->response ) {
				$core[] = array( 'version' => $u->version, 'locale' => $u->locale, 'php_required' => isset( $u->php_version ) ? $u->php_version : null );
			}
		}
		$plugins = array();
		foreach ( (array) get_plugin_updates() as $file => $p ) {
			$plugins[] = array( 'plugin' => $file, 'name' => $p->Name, 'from' => $p->Version, 'to' => $p->update->new_version ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}
		$themes = array();
		foreach ( (array) get_theme_updates() as $slug => $t ) {
			$themes[] = array( 'stylesheet' => $slug, 'name' => $t->get( 'Name' ), 'from' => $t->get( 'Version' ), 'to' => $t->update['new_version'] );
		}
		return array(
			'wordpress'    => array( 'installed' => $wp_version, 'updates' => $core ),
			'plugins'      => $plugins,
			'themes'       => $themes,
			'translations' => count( (array) wp_get_translation_updates() ),
		);
	}

	public static function update_core( array $args ) {
		global $wp_version;
		H::admin_includes();
		wp_version_check( array(), true );
		$updates = get_core_updates();
		$target  = null;
		foreach ( (array) $updates as $u ) {
			if ( isset( $u->response ) && 'upgrade' === $u->response && ( empty( $args['version'] ) || $u->version === $args['version'] ) ) {
				$target = $u;
				break;
			}
		}
		if ( ! $target ) {
			return array( 'status' => empty( $args['version'] ) ? 'WordPress is up to date' : 'That version is not offered', 'installed' => $wp_version );
		}
		$from     = $wp_version;
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Core_Upgrader( $skin );
		$result   = $upgrader->upgrade( $target );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return H::error( 'update_failed', 'The core update failed.', 'Check Diagnostics → Filesystem access.', false, $skin->get_error_messages() );
		}
		return array( 'from' => $from, 'to' => $result, 'note' => 'Database upgrades, if any, run on the next wp-admin visit.' );
	}

	public static function update_translations() {
		H::admin_includes();
		$updates = wp_get_translation_updates();
		if ( ! $updates ) {
			return array( 'status' => 'translations are up to date' );
		}
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Language_Pack_Upgrader( $skin );
		$result   = $upgrader->bulk_upgrade( $updates );
		return array( 'updated' => is_array( $result ) ? count( array_filter( $result ) ) : 0, 'errors' => $skin->get_error_messages() );
	}
}
