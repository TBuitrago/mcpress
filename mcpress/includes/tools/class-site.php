<?php
/**
 * Site: overview, health, general settings.
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\Context;
use MCPress\MCP\Server;
use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Settings;
use MCPress\Tools\Helpers as H;

defined( 'ABSPATH' ) || exit;

class Site {

	/** Settings exposed by get/update_site_settings: name => [label, risk when changed]. */
	const SETTINGS = array(
		'blogname'               => array( 'Site title', 'write' ),
		'blogdescription'        => array( 'Tagline', 'write' ),
		'admin_email'            => array( 'Administration email (no confirmation email is sent)', 'critical' ),
		'WPLANG'                 => array( 'Site language (locale, "" = English)', 'write' ),
		'timezone_string'        => array( 'Timezone, e.g. America/Bogota', 'write' ),
		'gmt_offset'             => array( 'UTC offset when no timezone_string', 'write' ),
		'date_format'            => array( 'Date format', 'write' ),
		'time_format'            => array( 'Time format', 'write' ),
		'start_of_week'          => array( 'Week starts on (0 = Sunday)', 'write' ),
		'users_can_register'     => array( 'Anyone can register (1/0)', 'critical' ),
		'default_role'           => array( 'Role of new users', 'critical' ),
		'show_on_front'          => array( 'Homepage shows "posts" or "page"', 'write' ),
		'page_on_front'          => array( 'Static homepage ID', 'write' ),
		'page_for_posts'         => array( 'Posts page ID', 'write' ),
		'posts_per_page'         => array( 'Blog pages show at most', 'write' ),
		'blog_public'            => array( 'Visible to search engines (1/0)', 'destructive' ),
		'permalink_structure'    => array( 'Permalink structure, e.g. /%postname%/', 'destructive' ),
		'category_base'          => array( 'Category base', 'destructive' ),
		'tag_base'               => array( 'Tag base', 'destructive' ),
		'default_comment_status' => array( 'Allow comments on new posts (open/closed)', 'write' ),
		'comment_registration'   => array( 'Users must be logged in to comment (1/0)', 'write' ),
		'comment_moderation'     => array( 'Comments must be manually approved (1/0)', 'write' ),
		'comments_notify'        => array( 'Email on new comments (1/0)', 'write' ),
		'moderation_notify'      => array( 'Email when a comment is held (1/0)', 'write' ),
		'thumbnail_size_w'       => array( 'Thumbnail width', 'write' ),
		'thumbnail_size_h'       => array( 'Thumbnail height', 'write' ),
		'medium_size_w'          => array( 'Medium width', 'write' ),
		'medium_size_h'          => array( 'Medium height', 'write' ),
		'large_size_w'           => array( 'Large width', 'write' ),
		'large_size_h'           => array( 'Large height', 'write' ),
		'uploads_use_yearmonth_folders' => array( 'Organise uploads in year/month folders (1/0)', 'write' ),
		'site_icon'              => array( 'Site icon attachment ID', 'write' ),
	);

	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'         => 'site_info',
				'title'        => 'Site overview',
				'group'        => 'site',
				'risk'         => Policy::RISK_READ,
				'description'  => 'WordPress/PHP versions, active theme, plugins, content counts, page builders detected and exactly what this MCP connection may do. Call it first.',
				'input_schema' => H::schema( array() ),
				'callback'     => array( __CLASS__, 'site_info' ),
			)
		);
		$r->register(
			array(
				'name'         => 'site_health',
				'title'        => 'Site Health report',
				'group'        => 'site',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Runs the WordPress Site Health direct tests (security, performance, updates) and optionally returns the debug info sections (server, database, constants).',
				'input_schema' => H::schema(
					array(
						'include_debug_info' => H::bool( 'Also return Tools → Site Health → Info data.', false ),
					)
				),
				'callback'     => array( __CLASS__, 'site_health' ),
			)
		);
		$r->register(
			array(
				'name'         => 'get_site_settings',
				'title'        => 'Read general settings',
				'group'        => 'site',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Title, tagline, language, timezone, formats, homepage, reading, discussion, media sizes and permalink settings.',
				'input_schema' => H::schema( array() ),
				'callback'     => array( __CLASS__, 'get_site_settings' ),
			)
		);
		$r->register(
			array(
				'name'         => 'update_site_settings',
				'title'        => 'Change general settings',
				'group'        => 'site',
				'risk'         => array( __CLASS__, 'settings_risk' ),
				'base_risk'    => Policy::RISK_WRITE,
				'description'  => 'Change one or more general settings (names as returned by get_site_settings). Changing admin_email, users_can_register or default_role is critical; blog_public and permalinks are destructive.',
				'input_schema' => H::schema(
					array( 'settings' => H::obj( 'Map of setting name → new value.' ) ),
					array( 'settings' )
				),
				'callback'     => array( __CLASS__, 'update_site_settings' ),
				'preview'      => array( __CLASS__, 'preview_settings' ),
				'capability'   => 'manage_options',
			)
		);
	}

	public static function site_info( array $args, Context $ctx ) {
		global $wpdb, $wp_version;
		H::admin_includes();

		$theme   = wp_get_theme();
		$plugins = get_plugins();
		$active  = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$active_list = array();
		foreach ( $active as $file ) {
			if ( isset( $plugins[ $file ] ) ) {
				$active_list[] = array(
					'plugin'  => $file,
					'name'    => $plugins[ $file ]['Name'],
					'version' => $plugins[ $file ]['Version'],
				);
			}
		}

		$counts = array();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			$c = wp_count_posts( $type );
			$counts[ $type ] = array(
				'publish' => isset( $c->publish ) ? (int) $c->publish : 0,
				'draft'   => isset( $c->draft ) ? (int) $c->draft : 0,
			);
		}

		$builders = array();
		foreach ( array(
			'elementor'      => defined( 'ELEMENTOR_VERSION' ),
			'elementor_pro'  => defined( 'ELEMENTOR_PRO_VERSION' ),
			'divi'           => defined( 'ET_BUILDER_VERSION' ) || 'Divi' === $theme->get_template(),
			'beaver_builder' => class_exists( 'FLBuilder' ),
			'bricks'         => defined( 'BRICKS_VERSION' ),
			'breakdance'     => defined( '__BREAKDANCE_VERSION' ),
			'wpbakery'       => defined( 'WPB_VC_VERSION' ),
			'seedprod'       => defined( 'SEEDPROD_VERSION' ) || defined( 'SEEDPROD_PRO_VERSION' ),
		) as $name => $on ) {
			if ( $on ) {
				$builders[] = $name;
			}
		}

		$user    = $ctx->user();
		$matrix  = array();
		foreach ( Policy::risks() as $risk ) {
			$matrix[ $risk ] = Policy::MATRIX[ $ctx->mode ][ $risk ];
		}
		$rules = array_filter( (array) Settings::get( 'tool_rules' ) );

		return array(
			'site'       => array(
				'name'             => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'description'      => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
				'url'              => home_url(),
				'admin_url'        => admin_url(),
				'wordpress'        => $wp_version,
				'php'              => PHP_VERSION,
				'database'         => $wpdb->db_server_info(),
				'locale'           => get_locale(),
				'timezone'         => wp_timezone_string(),
				'multisite'        => is_multisite(),
				'https'            => 0 === strpos( home_url(), 'https://' ),
				'permalinks'       => get_option( 'permalink_structure' ) ? get_option( 'permalink_structure' ) : 'plain',
				'environment'      => wp_get_environment_type(),
				'debug'            => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'search_engines'   => (bool) get_option( 'blog_public' ),
				'table_prefix'     => $wpdb->prefix,
			),
			'theme'      => array(
				'name'        => $theme->get( 'Name' ),
				'stylesheet'  => $theme->get_stylesheet(),
				'template'    => $theme->get_template(),
				'version'     => $theme->get( 'Version' ),
				'block_theme' => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : false,
				'path'        => str_replace( ABSPATH, '', $theme->get_stylesheet_directory() ),
			),
			'plugins'    => array(
				'active'         => $active_list,
				'inactive_count' => count( $plugins ) - count( $active_list ),
				'must_use'       => array_values( wp_list_pluck( get_mu_plugins(), 'Name' ) ),
			),
			'builders'   => $builders,
			'woocommerce' => class_exists( 'WooCommerce' ) ? ( defined( 'WC_VERSION' ) ? WC_VERSION : true ) : false,
			'content'    => array(
				'posts' => $counts,
				'users' => (int) count_users()['total_users'],
				'media' => (int) array_sum( (array) wp_count_attachments() ),
			),
			'connection' => array(
				'name'               => $ctx->name,
				'acting_as'          => $user ? array( 'id' => $user->ID, 'login' => $user->user_login, 'roles' => array_values( $user->roles ) ) : null,
				'mode'               => $ctx->mode,
				'mode_explained'     => Server::mode_for_ai( $ctx->mode ),
				'by_risk'            => $matrix,
				'always_allowed'     => $ctx->allowed_tools(),
				'site_rules'         => $rules ? $rules : new \stdClass(),
				'disabled_groups'    => array_values( (array) Settings::get( 'disabled_groups' ) ),
				'sensitive_data'     => Settings::get( 'protect_sensitive' ) ? 'masked' : 'visible',
				'file_mods_allowed'  => wp_is_file_mod_allowed( 'mcpress' ) && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
				'file_edit_allowed'  => ! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
				'php_execution'      => Code::php_allowed(),
				'abilities_api'      => function_exists( 'wp_get_abilities' ),
				'user_can'           => array(
					'edit_posts'        => user_can( $ctx->user_id, 'edit_posts' ),
					'publish_pages'     => user_can( $ctx->user_id, 'publish_pages' ),
					'manage_options'    => user_can( $ctx->user_id, 'manage_options' ),
					'install_plugins'   => user_can( $ctx->user_id, 'install_plugins' ),
					'edit_theme_options' => user_can( $ctx->user_id, 'edit_theme_options' ),
					'edit_users'        => user_can( $ctx->user_id, 'edit_users' ),
					'unfiltered_html'   => user_can( $ctx->user_id, 'unfiltered_html' ),
				),
			),
			'mcpress'    => MCPRESS_VERSION,
		);
	}

	public static function site_health( array $args ) {
		H::admin_includes();
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}
		$health  = \WP_Site_Health::get_instance();
		$tests   = \WP_Site_Health::get_tests();
		$results = array();
		foreach ( (array) $tests['direct'] as $key => $test ) {
			try {
				if ( is_string( $test['test'] ) && method_exists( $health, 'get_test_' . $test['test'] ) ) {
					$res = call_user_func( array( $health, 'get_test_' . $test['test'] ) );
				} elseif ( is_callable( $test['test'] ) ) {
					$res = call_user_func( $test['test'] );
				} else {
					continue;
				}
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( ! is_array( $res ) ) {
				continue;
			}
			$results[] = array(
				'test'        => isset( $res['test'] ) ? $res['test'] : $key,
				'status'      => isset( $res['status'] ) ? $res['status'] : '',
				'label'       => wp_strip_all_tags( isset( $res['label'] ) ? $res['label'] : '' ),
				'category'    => isset( $res['badge']['label'] ) ? wp_strip_all_tags( $res['badge']['label'] ) : '',
				'description' => trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( isset( $res['description'] ) ? $res['description'] : '' ) ) ),
			);
		}
		$order = array( 'critical' => 0, 'recommended' => 1, 'good' => 2 );
		usort(
			$results,
			function ( $a, $b ) use ( $order ) {
				return ( isset( $order[ $a['status'] ] ) ? $order[ $a['status'] ] : 3 ) - ( isset( $order[ $b['status'] ] ) ? $order[ $b['status'] ] : 3 );
			}
		);
		$out = array(
			'summary' => array_count_values( wp_list_pluck( $results, 'status' ) ),
			'tests'   => $results,
			'note'    => 'Asynchronous tests (loopback, REST availability, background updates) are not included.',
		);
		if ( ! empty( $args['include_debug_info'] ) ) {
			if ( ! class_exists( 'WP_Debug_Data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
			}
			$info = array();
			foreach ( \WP_Debug_Data::debug_data() as $section => $data ) {
				if ( in_array( $section, array( 'wp-paths-sizes' ), true ) ) {
					continue; // Slow (directory sizes).
				}
				$fields = array();
				foreach ( (array) ( isset( $data['fields'] ) ? $data['fields'] : array() ) as $k => $f ) {
					if ( ! empty( $f['private'] ) ) {
						continue;
					}
					$fields[ $k ] = isset( $f['debug'] ) ? $f['debug'] : ( isset( $f['value'] ) ? $f['value'] : '' );
				}
				$info[ $section ] = $fields;
			}
			$out['debug_info'] = $info;
		}
		return $out;
	}

	public static function get_site_settings() {
		$out = array();
		foreach ( self::SETTINGS as $name => $meta ) {
			$out[ $name ] = array(
				'value' => get_option( $name ),
				'label' => $meta[0],
			);
		}
		$out['_roles'] = array_keys( wp_roles()->get_names() );
		return $out;
	}

	public static function settings_risk( array $args ) {
		$risk = Policy::RISK_WRITE;
		foreach ( array_keys( (array) ( isset( $args['settings'] ) ? $args['settings'] : array() ) ) as $name ) {
			if ( isset( self::SETTINGS[ $name ] ) && Policy::risk_rank( self::SETTINGS[ $name ][1] ) > Policy::risk_rank( $risk ) ) {
				$risk = self::SETTINGS[ $name ][1];
			}
		}
		return $risk;
	}

	public static function preview_settings( array $args ) {
		$out = array();
		foreach ( (array) $args['settings'] as $name => $value ) {
			$out[ $name ] = array(
				'current' => get_option( $name ),
				'new'     => $value,
			);
		}
		return $out;
	}

	public static function update_site_settings( array $args ) {
		$changed = array();
		$errors  = array();
		foreach ( (array) $args['settings'] as $name => $value ) {
			if ( ! isset( self::SETTINGS[ $name ] ) ) {
				$errors[ $name ] = 'Unknown setting. Use update_option for other options.';
				continue;
			}
			if ( 'default_role' === $name && ! wp_roles()->is_role( (string) $value ) ) {
				$errors[ $name ] = 'Role does not exist.';
				continue;
			}
			if ( in_array( $name, array( 'page_on_front', 'page_for_posts', 'site_icon' ), true ) && (int) $value && ! get_post( (int) $value ) ) {
				$errors[ $name ] = 'No post with that ID.';
				continue;
			}
			if ( 'WPLANG' === $name && '' !== $value ) {
				H::admin_includes();
				if ( ! in_array( $value, get_available_languages(), true ) && function_exists( 'wp_download_language_pack' ) ) {
					$installed = wp_download_language_pack( (string) $value );
					if ( ! $installed ) {
						$errors[ $name ] = 'Could not install that language pack.';
						continue;
					}
				}
			}
			$old = get_option( $name );
			if ( 'permalink_structure' === $name ) {
				global $wp_rewrite;
				$wp_rewrite->set_permalink_structure( (string) $value );
				flush_rewrite_rules( false );
			} elseif ( 'category_base' === $name ) {
				global $wp_rewrite;
				$wp_rewrite->set_category_base( (string) $value );
				flush_rewrite_rules( false );
			} elseif ( 'tag_base' === $name ) {
				global $wp_rewrite;
				$wp_rewrite->set_tag_base( (string) $value );
				flush_rewrite_rules( false );
			} else {
				update_option( $name, is_bool( $value ) ? (int) $value : $value );
			}
			$changed[ $name ] = array(
				'old' => $old,
				'new' => get_option( $name ),
			);
		}
		if ( ! $changed && $errors ) {
			return H::error( 'invalid_input', 'No setting was changed.', null, false, $errors );
		}
		return array(
			'changed' => $changed,
			'errors'  => $errors ? $errors : new \stdClass(),
		);
	}
}
