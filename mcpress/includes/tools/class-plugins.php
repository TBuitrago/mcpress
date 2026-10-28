<?php
/**
 * Plugins: list, search WordPress.org, install, activate, deactivate, update, delete.
 *
 * MCPress itself cannot be deactivated or deleted through MCP (guard, every mode).
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Tools\Helpers as H;

defined( 'ABSPATH' ) || exit;

class Plugins {

	public static function register( Tool_Registry $r ) {
		$plugin_arg = H::str( 'Plugin file (akismet/akismet.php) or folder slug (akismet).' );

		$r->register(
			array(
				'name'         => 'list_plugins',
				'title'        => 'List plugins',
				'group'        => 'plugins',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Installed plugins with status, version, available update and auto-update setting.',
				'input_schema' => H::schema( array( 'status' => H::enum( array( 'all', 'active', 'inactive', 'update_available', 'must_use' ), 'Filter.', 'all' ) ) ),
				'callback'     => array( __CLASS__, 'list_plugins' ),
				'capability'   => 'activate_plugins',
			)
		);
		$r->register(
			array(
				'name'         => 'search_plugin_directory',
				'title'        => 'Search WordPress.org plugins',
				'group'        => 'plugins',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Search the WordPress.org plugin directory (rating, installs, last update, compatibility).',
				'input_schema' => H::schema( array( 'search' => H::str( 'Search terms or slug.' ), 'per_page' => H::int( 'Results (1-30).', array( 'minimum' => 1, 'maximum' => 30, 'default' => 10 ) ) ), array( 'search' ) ),
				'callback'     => array( __CLASS__, 'search_directory' ),
			)
		);
		$r->register(
			array(
				'name'         => 'install_plugin',
				'title'        => 'Install a plugin',
				'group'        => 'plugins',
				'risk'         => array( __CLASS__, 'install_risk' ),
				'base_risk'    => Policy::RISK_DESTRUCTIVE,
				'description'  => 'Install a plugin from WordPress.org (slug) or from a .zip URL (critical: unreviewed code). Optionally activate it.',
				'input_schema' => H::schema(
					array(
						'slug'     => H::str( 'WordPress.org slug.' ),
						'zip_url'  => H::str( 'Public URL of a plugin .zip (alternative to slug).' ),
						'activate' => H::bool( 'Activate after installing.', false ),
						'overwrite' => H::bool( 'Replace the plugin if it is already installed (zip_url only).', false ),
					)
				),
				'callback'     => array( __CLASS__, 'install_plugin' ),
				'guard'        => array( __CLASS__, 'guard_mods' ),
				'capability'   => 'install_plugins',
			)
		);
		$r->register(
			array(
				'name'         => 'activate_plugin',
				'title'        => 'Activate a plugin',
				'group'        => 'plugins',
				'risk'         => Policy::RISK_DESTRUCTIVE,
				'description'  => 'Activate an installed plugin. If activation causes a fatal error the plugin stays inactive and the error is returned.',
				'input_schema' => H::schema( array( 'plugin' => $plugin_arg, 'network_wide' => H::bool( 'Multisite: network activate.', false ) ), array( 'plugin' ) ),
				'callback'     => array( __CLASS__, 'activate_plugin' ),
				'capability'   => 'activate_plugins',
			)
		);
		$r->register(
			array(
				'name'         => 'deactivate_plugin',
				'title'        => 'Deactivate a plugin',
				'group'        => 'plugins',
				'risk'         => Policy::RISK_DESTRUCTIVE,
				'description'  => 'Deactivate a plugin (its data stays). MCPress itself cannot be deactivated from here.',
				'input_schema' => H::schema( array( 'plugin' => $plugin_arg ), array( 'plugin' ) ),
				'callback'     => array( __CLASS__, 'deactivate_plugin' ),
				'guard'        => array( __CLASS__, 'guard_self' ),
				'preview'      => array( __CLASS__, 'preview_plugin' ),
				'capability'   => 'activate_plugins',
			)
		);
		$r->register(
			array(
				'name'         => 'update_plugin',
				'title'        => 'Update plugins',
				'group'        => 'plugins',
				'risk'         => Policy::RISK_DESTRUCTIVE,
				'description'  => 'Update one plugin, or "all" plugins with an available update.',
				'input_schema' => H::schema( array( 'plugin' => H::str( 'Plugin file/slug, or "all".' ) ), array( 'plugin' ) ),
				'callback'     => array( __CLASS__, 'update_plugin' ),
				'guard'        => array( __CLASS__, 'guard_mods' ),
				'capability'   => 'update_plugins',
			)
		);
		$r->register(
			array(
				'name'         => 'delete_plugin',
				'title'        => 'Delete a plugin',
				'group'        => 'plugins',
				'risk'         => Policy::RISK_CRITICAL,
				'description'  => 'Delete an inactive plugin and its files (its uninstall routine may also delete its data). Deactivate it first. MCPress itself cannot be deleted from here.',
				'input_schema' => H::schema( array( 'plugin' => $plugin_arg ), array( 'plugin' ) ),
				'callback'     => array( __CLASS__, 'delete_plugin' ),
				'guard'        => array( __CLASS__, 'guard_delete' ),
				'preview'      => array( __CLASS__, 'preview_plugin' ),
				'capability'   => 'delete_plugins',
			)
		);
	}

	/* -------------------------------------------------------------- Helpers */

	public static function resolve( $plugin ) {
		H::admin_includes();
		$plugins = get_plugins();
		$plugin  = trim( (string) $plugin, '/ ' );
		if ( isset( $plugins[ $plugin ] ) ) {
			return $plugin;
		}
		foreach ( $plugins as $file => $data ) {
			if ( dirname( $file ) === $plugin || basename( $file, '.php' ) === $plugin || strtolower( $data['Name'] ) === strtolower( $plugin ) ) {
				return $file;
			}
		}
		return null;
	}

	public static function is_self( $plugin ) {
		$file = self::resolve( $plugin );
		return ( $file && MCPRESS_BASENAME === $file ) || 'mcpress' === trim( (string) $plugin, '/ ' );
	}

	public static function guard_mods() {
		return H::file_mods_error( 'mcpress_plugins' );
	}

	public static function guard_self( array $args ) {
		if ( self::is_self( $args['plugin'] ) ) {
			return H::error( 'self_protection', 'MCPress cannot be deactivated or deleted by an AI (it would cut this connection).', 'The user can do it in wp-admin → Plugins.' );
		}
		return null;
	}

	public static function guard_delete( array $args ) {
		$g = self::guard_self( $args );
		return $g ? $g : self::guard_mods();
	}

	public static function install_risk( array $args ) {
		return empty( $args['zip_url'] ) ? Policy::RISK_DESTRUCTIVE : Policy::RISK_CRITICAL;
	}

	private static function skin() {
		H::admin_includes();
		return new \WP_Ajax_Upgrader_Skin();
	}

	private static function skin_errors( $skin, $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $skin->get_errors()->has_errors() ) {
			return $skin->get_errors();
		}
		if ( false === $result || null === $result ) {
			global $wp_filesystem;
			if ( $wp_filesystem instanceof \WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() ) {
				return $wp_filesystem->errors;
			}
			return H::error( 'filesystem', 'The upgrader could not write to the filesystem.', 'Check Diagnostics → Filesystem access in MCPress.' );
		}
		return null;
	}

	private static function update_info() {
		$updates = get_site_transient( 'update_plugins' );
		return isset( $updates->response ) ? (array) $updates->response : array();
	}

	/* ------------------------------------------------------------ Callbacks */

	public static function list_plugins( array $args ) {
		H::admin_includes();
		$plugins = get_plugins();
		$updates = self::update_info();
		$auto    = (array) get_site_option( 'auto_update_plugins', array() );
		$out     = array();
		if ( 'must_use' === $args['status'] ) {
			foreach ( get_mu_plugins() as $file => $data ) {
				$out[] = array( 'plugin' => $file, 'name' => $data['Name'], 'version' => $data['Version'], 'status' => 'must-use' );
			}
			return $out;
		}
		foreach ( $plugins as $file => $data ) {
			$active = is_plugin_active( $file );
			$update = isset( $updates[ $file ] ) ? $updates[ $file ]->new_version : null;
			if ( ( 'active' === $args['status'] && ! $active ) || ( 'inactive' === $args['status'] && $active ) || ( 'update_available' === $args['status'] && ! $update ) ) {
				continue;
			}
			$out[] = array(
				'plugin'      => $file,
				'name'        => $data['Name'],
				'version'     => $data['Version'],
				'status'      => $active ? ( is_plugin_active_for_network( $file ) ? 'network-active' : 'active' ) : 'inactive',
				'update'      => $update,
				'auto_update' => in_array( $file, $auto, true ),
				'author'      => wp_strip_all_tags( $data['Author'] ),
				'description' => wp_trim_words( wp_strip_all_tags( $data['Description'] ), 24 ),
			);
		}
		return $out;
	}

	public static function search_directory( array $args ) {
		H::admin_includes();
		$res = plugins_api(
			'query_plugins',
			array(
				'search'   => $args['search'],
				'per_page' => $args['per_page'],
				'fields'   => array( 'short_description' => true, 'icons' => false, 'banners' => false, 'sections' => false, 'tags' => false ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return H::error( 'directory_unreachable', $res->get_error_message(), null, true );
		}
		$out = array();
		foreach ( (array) $res->plugins as $p ) {
			$p     = (array) $p;
			$out[] = array(
				'slug'            => $p['slug'],
				'name'            => wp_specialchars_decode( $p['name'] ),
				'version'         => $p['version'],
				'rating'          => isset( $p['rating'] ) ? round( $p['rating'] / 20, 1 ) : null,
				'active_installs' => isset( $p['active_installs'] ) ? (int) $p['active_installs'] : null,
				'last_updated'    => isset( $p['last_updated'] ) ? $p['last_updated'] : null,
				'tested'          => isset( $p['tested'] ) ? $p['tested'] : null,
				'requires_php'    => isset( $p['requires_php'] ) ? $p['requires_php'] : null,
				'installed'       => (bool) self::resolve( $p['slug'] ),
				'summary'         => isset( $p['short_description'] ) ? $p['short_description'] : '',
			);
		}
		return array( 'total' => (int) $res->info['results'], 'items' => $out );
	}

	public static function install_plugin( array $args ) {
		H::admin_includes();
		if ( ! empty( $args['slug'] ) ) {
			$slug = sanitize_key( $args['slug'] );
			if ( self::resolve( $slug ) ) {
				return H::error( 'already_installed', 'That plugin is already installed.', 'Use activate_plugin or update_plugin.' );
			}
			$api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
			if ( is_wp_error( $api ) ) {
				return H::error( 'not_found', 'Plugin not found on WordPress.org: ' . $api->get_error_message(), 'Use search_plugin_directory to find the exact slug.' );
			}
			$package = $api->download_link;
		} elseif ( ! empty( $args['zip_url'] ) ) {
			$package = esc_url_raw( $args['zip_url'] );
			if ( ! wp_http_validate_url( $package ) ) {
				return H::error( 'invalid_url', 'The zip URL is not public or not valid.' );
			}
		} else {
			return H::error( 'invalid_input', 'Send slug or zip_url.' );
		}
		$before   = array_keys( get_plugins() );
		$skin     = self::skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $package, array( 'overwrite_package' => ! empty( $args['overwrite'] ) ) );
		$error    = self::skin_errors( $skin, $result );
		if ( $error ) {
			return $error;
		}
		wp_clean_plugins_cache( false );
		$file = $upgrader->plugin_info();
		if ( ! $file ) {
			$new  = array_diff( array_keys( get_plugins() ), $before );
			$file = $new ? reset( $new ) : null;
		}
		$out = array( 'installed' => $file, 'messages' => $skin->get_upgrade_messages() );
		if ( $file && ! empty( $args['activate'] ) ) {
			$act               = activate_plugin( $file );
			$out['activated']  = ! is_wp_error( $act );
			if ( is_wp_error( $act ) ) {
				$out['activation_error'] = $act->get_error_message();
			}
		}
		return $out;
	}

	public static function activate_plugin( array $args ) {
		$file = self::resolve( $args['plugin'] );
		if ( ! $file ) {
			return H::not_found( 'Plugin', $args['plugin'] );
		}
		if ( is_plugin_active( $file ) ) {
			return array( 'plugin' => $file, 'status' => 'already active' );
		}
		$res = activate_plugin( $file, '', ! empty( $args['network_wide'] ) && is_multisite() );
		if ( is_wp_error( $res ) ) {
			return H::error( 'activation_failed', $res->get_error_message(), 'The plugin stayed inactive. Read the error; read_error_log may show more.' );
		}
		return array( 'plugin' => $file, 'status' => 'active' );
	}

	public static function deactivate_plugin( array $args ) {
		$file = self::resolve( $args['plugin'] );
		if ( ! $file ) {
			return H::not_found( 'Plugin', $args['plugin'] );
		}
		deactivate_plugins( $file, false, is_plugin_active_for_network( $file ) );
		return array( 'plugin' => $file, 'status' => is_plugin_active( $file ) ? 'still active' : 'inactive' );
	}

	public static function update_plugin( array $args ) {
		H::admin_includes();
		wp_update_plugins();
		$updates = self::update_info();
		if ( 'all' === $args['plugin'] ) {
			$files = array_keys( $updates );
		} else {
			$file = self::resolve( $args['plugin'] );
			if ( ! $file ) {
				return H::not_found( 'Plugin', $args['plugin'] );
			}
			if ( ! isset( $updates[ $file ] ) ) {
				return array( 'plugin' => $file, 'status' => 'no update available' );
			}
			$files = array( $file );
		}
		if ( ! $files ) {
			return array( 'status' => 'everything is up to date' );
		}
		$versions = array();
		foreach ( $files as $f ) {
			$versions[ $f ] = get_plugins()[ $f ]['Version'];
		}
		$skin     = self::skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$results  = $upgrader->bulk_upgrade( $files );
		wp_clean_plugins_cache( false );
		$plugins = get_plugins();
		$out     = array();
		foreach ( $files as $f ) {
			$r         = is_array( $results ) && isset( $results[ $f ] ) ? $results[ $f ] : null;
			$out[ $f ] = array(
				'from'   => $versions[ $f ],
				'to'     => isset( $plugins[ $f ] ) ? $plugins[ $f ]['Version'] : null,
				'ok'     => ! is_wp_error( $r ) && false !== $r && null !== $r,
				'error'  => is_wp_error( $r ) ? $r->get_error_message() : null,
			);
		}
		return array( 'updated' => $out, 'messages' => $skin->get_upgrade_messages(), 'errors' => $skin->get_error_messages() );
	}

	public static function delete_plugin( array $args ) {
		H::admin_includes();
		$file = self::resolve( $args['plugin'] );
		if ( ! $file ) {
			return H::not_found( 'Plugin', $args['plugin'] );
		}
		if ( is_plugin_active( $file ) ) {
			return H::error( 'plugin_active', 'Deactivate the plugin before deleting it.', 'Call deactivate_plugin first.', true );
		}
		$res = delete_plugins( array( $file ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return $res ? array( 'deleted' => $file ) : H::error( 'filesystem', 'Could not delete the plugin files.', 'Check Diagnostics → Filesystem access in MCPress.' );
	}

	public static function preview_plugin( array $args ) {
		H::admin_includes();
		$file = self::resolve( $args['plugin'] );
		if ( ! $file ) {
			return 'Plugin not found.';
		}
		$data = get_plugins()[ $file ];
		return array(
			'plugin'  => $file,
			'name'    => $data['Name'],
			'version' => $data['Version'],
			'active'  => is_plugin_active( $file ),
		);
	}
}
