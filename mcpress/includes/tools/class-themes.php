<?php
/**
 * Themes, global styles (block themes) and theme mods (classic themes).
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Tools\Helpers as H;

defined( 'ABSPATH' ) || exit;

class Themes {

	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'         => 'list_themes',
				'title'        => 'List themes',
				'group'        => 'themes',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Installed themes with active status, parent, version, available update and block-theme flag.',
				'input_schema' => H::schema( array() ),
				'callback'     => array( __CLASS__, 'list_themes' ),
				'capability'   => 'switch_themes',
			)
		);
		$r->register(
			array(
				'name'         => 'search_theme_directory',
				'title'        => 'Search WordPress.org themes',
				'group'        => 'themes',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Search the WordPress.org theme directory.',
				'input_schema' => H::schema( array( 'search' => H::str( 'Search terms or slug.' ), 'per_page' => H::int( 'Results (1-30).', array( 'minimum' => 1, 'maximum' => 30, 'default' => 10 ) ) ), array( 'search' ) ),
				'callback'     => array( __CLASS__, 'search_directory' ),
			)
		);
		$r->register(
			array(
				'name'         => 'install_theme',
				'title'        => 'Install a theme',
				'group'        => 'themes',
				'risk'         => array( __CLASS__, 'install_risk' ),
				'base_risk'    => Policy::RISK_DESTRUCTIVE,
				'description'  => 'Install a theme from WordPress.org (slug) or a .zip URL (critical). Optionally activate it.',
				'input_schema' => H::schema(
					array(
						'slug'     => H::str( 'WordPress.org slug.' ),
						'zip_url'  => H::str( 'Public URL of a theme .zip.' ),
						'activate' => H::bool( 'Activate after installing.', false ),
						'overwrite' => H::bool( 'Replace if already installed (zip_url only).', false ),
					)
				),
				'callback'     => array( __CLASS__, 'install_theme' ),
				'guard'        => array( __CLASS__, 'guard_mods' ),
				'capability'   => 'install_themes',
			)
		);
		$r->register(
			array(
				'name'         => 'activate_theme',
				'title'        => 'Activate a theme',
				'group'        => 'themes',
				'risk'         => Policy::RISK_DESTRUCTIVE,
				'description'  => 'Switch the site to another installed theme (changes the whole look; widgets and menus may need re-assigning).',
				'input_schema' => H::schema( array( 'stylesheet' => H::str( 'Theme folder (stylesheet), see list_themes.' ) ), array( 'stylesheet' ) ),
				'callback'     => array( __CLASS__, 'activate_theme' ),
				'preview'      => array( __CLASS__, 'preview_switch' ),
				'capability'   => 'switch_themes',
			)
		);
		$r->register(
			array(
				'name'         => 'update_theme',
				'title'        => 'Update themes',
				'group'        => 'themes',
				'risk'         => Policy::RISK_DESTRUCTIVE,
				'description'  => 'Update one theme or "all" themes with an available update. Changes made directly in a theme\'s files are lost when it updates (use a child theme).',
				'input_schema' => H::schema( array( 'stylesheet' => H::str( 'Theme folder, or "all".' ) ), array( 'stylesheet' ) ),
				'callback'     => array( __CLASS__, 'update_theme' ),
				'guard'        => array( __CLASS__, 'guard_mods' ),
				'capability'   => 'update_themes',
			)
		);
		$r->register(
			array(
				'name'         => 'delete_theme',
				'title'        => 'Delete a theme',
				'group'        => 'themes',
				'risk'         => Policy::RISK_CRITICAL,
				'description'  => 'Delete an installed theme and its files. The active theme and its parent cannot be deleted.',
				'input_schema' => H::schema( array( 'stylesheet' => H::str( 'Theme folder.' ) ), array( 'stylesheet' ) ),
				'callback'     => array( __CLASS__, 'delete_theme' ),
				'guard'        => array( __CLASS__, 'guard_delete' ),
				'capability'   => 'delete_themes',
			)
		);
		$r->register(
			array(
				'name'         => 'get_global_styles',
				'title'        => 'Read global styles',
				'group'        => 'themes',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Block themes: the user customisations of Styles (colors, typography, spacing, per-block styles) and, optionally, the merged theme.json settings and styles.',
				'input_schema' => H::schema( array( 'include_merged' => H::bool( 'Also return the merged settings/styles (large).', false ) ) ),
				'callback'     => array( __CLASS__, 'get_global_styles' ),
				'capability'   => 'edit_theme_options',
			)
		);
		$r->register(
			array(
				'name'         => 'update_global_styles',
				'title'        => 'Change global styles',
				'group'        => 'themes',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Block themes: set the user Styles (theme.json shape: {"styles": {...}, "settings": {...}}). merge=true deep-merges into the current customisations; false replaces them.',
				'input_schema' => H::schema(
					array(
						'styles'   => H::obj( 'theme.json "styles" object.' ),
						'settings' => H::obj( 'theme.json "settings" object.' ),
						'merge'    => H::bool( 'Deep-merge with the current customisations.', true ),
					)
				),
				'callback'     => array( __CLASS__, 'update_global_styles' ),
				'capability'   => 'edit_theme_options',
			)
		);
		$r->register(
			array(
				'name'         => 'get_theme_mods',
				'title'        => 'Read theme mods',
				'group'        => 'themes',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Classic themes: Customizer settings of the active theme (logo, colors, header, custom CSS…).',
				'input_schema' => H::schema( array() ),
				'callback'     => array( __CLASS__, 'get_theme_mods' ),
				'capability'   => 'edit_theme_options',
			)
		);
		$r->register(
			array(
				'name'         => 'update_theme_mods',
				'title'        => 'Change theme mods',
				'group'        => 'themes',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Classic themes: set Customizer values (name → value; null removes it). Use custom_css to replace the Additional CSS.',
				'input_schema' => H::schema(
					array(
						'mods'       => H::obj( 'Theme mod name → value.' ),
						'custom_css' => H::str( 'New Additional CSS for the active theme.' ),
					)
				),
				'callback'     => array( __CLASS__, 'update_theme_mods' ),
				'capability'   => 'edit_theme_options',
			)
		);
	}

	public static function guard_mods() {
		return H::file_mods_error( 'mcpress_themes' );
	}

	public static function guard_delete( array $args ) {
		$theme = wp_get_theme();
		if ( in_array( $args['stylesheet'], array( $theme->get_stylesheet(), $theme->get_template() ), true ) ) {
			return H::error( 'active_theme', 'The active theme (or its parent) cannot be deleted.', 'Activate another theme first.' );
		}
		return self::guard_mods();
	}

	public static function install_risk( array $args ) {
		return empty( $args['zip_url'] ) ? Policy::RISK_DESTRUCTIVE : Policy::RISK_CRITICAL;
	}

	public static function list_themes() {
		H::admin_includes();
		$active  = wp_get_theme();
		$updates = get_site_transient( 'update_themes' );
		$out     = array();
		foreach ( wp_get_themes() as $slug => $t ) {
			$out[] = array(
				'stylesheet'  => $slug,
				'name'        => $t->get( 'Name' ),
				'version'     => $t->get( 'Version' ),
				'active'      => $slug === $active->get_stylesheet(),
				'parent'      => $t->parent() ? $t->get_template() : null,
				'is_parent_of_active' => $slug === $active->get_template() && $slug !== $active->get_stylesheet(),
				'block_theme' => method_exists( $t, 'is_block_theme' ) ? $t->is_block_theme() : false,
				'update'      => isset( $updates->response[ $slug ]['new_version'] ) ? $updates->response[ $slug ]['new_version'] : null,
				'errors'      => $t->errors() ? $t->errors()->get_error_message() : null,
			);
		}
		return $out;
	}

	public static function search_directory( array $args ) {
		H::admin_includes();
		$res = themes_api( 'query_themes', array( 'search' => $args['search'], 'per_page' => $args['per_page'], 'fields' => array( 'description' => true, 'rating' => true, 'downloaded' => true ) ) );
		if ( is_wp_error( $res ) ) {
			return H::error( 'directory_unreachable', $res->get_error_message(), null, true );
		}
		$out = array();
		foreach ( (array) $res->themes as $t ) {
			$t     = (array) $t;
			$out[] = array(
				'slug'      => $t['slug'],
				'name'      => $t['name'],
				'version'   => $t['version'],
				'rating'    => isset( $t['rating'] ) ? round( $t['rating'] / 20, 1 ) : null,
				'installed' => wp_get_theme( $t['slug'] )->exists(),
				'summary'   => isset( $t['description'] ) ? wp_trim_words( $t['description'], 30 ) : '',
			);
		}
		return array( 'total' => (int) $res->info['results'], 'items' => $out );
	}

	public static function install_theme( array $args ) {
		H::admin_includes();
		if ( ! empty( $args['slug'] ) ) {
			$slug = sanitize_key( $args['slug'] );
			if ( wp_get_theme( $slug )->exists() ) {
				return H::error( 'already_installed', 'That theme is already installed.', 'Use activate_theme or update_theme.' );
			}
			$api = themes_api( 'theme_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
			if ( is_wp_error( $api ) ) {
				return H::error( 'not_found', 'Theme not found on WordPress.org: ' . $api->get_error_message() );
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
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );
		$result   = $upgrader->install( $package, array( 'overwrite_package' => ! empty( $args['overwrite'] ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $skin->get_errors()->has_errors() ) {
			return $skin->get_errors();
		}
		if ( ! $result ) {
			return H::error( 'filesystem', 'The theme could not be installed (filesystem).', 'Check Diagnostics → Filesystem access in MCPress.' );
		}
		$theme = $upgrader->theme_info();
		$out   = array( 'installed' => $theme ? $theme->get_stylesheet() : null );
		if ( $theme && ! empty( $args['activate'] ) ) {
			switch_theme( $theme->get_stylesheet() );
			$out['activated'] = true;
		}
		return $out;
	}

	public static function activate_theme( array $args ) {
		$theme = wp_get_theme( $args['stylesheet'] );
		if ( ! $theme->exists() ) {
			return H::not_found( 'Theme', $args['stylesheet'] );
		}
		if ( ! $theme->is_allowed() ) {
			return H::forbidden( 'This theme is not allowed on this site (multisite).' );
		}
		if ( $theme->errors() ) {
			return H::error( 'broken_theme', $theme->errors()->get_error_message() );
		}
		$previous = wp_get_theme()->get_stylesheet();
		switch_theme( $theme->get_stylesheet() );
		return array( 'active' => $theme->get_stylesheet(), 'previous' => $previous, 'undo' => 'activate_theme with stylesheet "' . $previous . '"' );
	}

	public static function preview_switch( array $args ) {
		$new = wp_get_theme( $args['stylesheet'] );
		return array(
			'from' => wp_get_theme()->get( 'Name' ) . ' (' . wp_get_theme()->get_stylesheet() . ')',
			'to'   => $new->exists() ? $new->get( 'Name' ) . ' (' . $new->get_stylesheet() . ')' : 'not installed',
		);
	}

	public static function update_theme( array $args ) {
		H::admin_includes();
		wp_update_themes();
		$updates = get_site_transient( 'update_themes' );
		$avail   = isset( $updates->response ) ? array_keys( (array) $updates->response ) : array();
		if ( 'all' === $args['stylesheet'] ) {
			$list = $avail;
		} else {
			if ( ! wp_get_theme( $args['stylesheet'] )->exists() ) {
				return H::not_found( 'Theme', $args['stylesheet'] );
			}
			if ( ! in_array( $args['stylesheet'], $avail, true ) ) {
				return array( 'theme' => $args['stylesheet'], 'status' => 'no update available' );
			}
			$list = array( $args['stylesheet'] );
		}
		if ( ! $list ) {
			return array( 'status' => 'everything is up to date' );
		}
		$before = array();
		foreach ( $list as $s ) {
			$before[ $s ] = wp_get_theme( $s )->get( 'Version' );
		}
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );
		$results  = $upgrader->bulk_upgrade( $list );
		wp_clean_themes_cache();
		$out = array();
		foreach ( $list as $s ) {
			$r         = is_array( $results ) && isset( $results[ $s ] ) ? $results[ $s ] : null;
			$out[ $s ] = array(
				'from'  => $before[ $s ],
				'to'    => wp_get_theme( $s )->get( 'Version' ),
				'ok'    => ! is_wp_error( $r ) && false !== $r && null !== $r,
				'error' => is_wp_error( $r ) ? $r->get_error_message() : null,
			);
		}
		return array( 'updated' => $out, 'errors' => $skin->get_error_messages() );
	}

	public static function delete_theme( array $args ) {
		H::admin_includes();
		if ( ! wp_get_theme( $args['stylesheet'] )->exists() ) {
			return H::not_found( 'Theme', $args['stylesheet'] );
		}
		$res = delete_theme( $args['stylesheet'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return $res ? array( 'deleted' => $args['stylesheet'] ) : H::error( 'filesystem', 'Could not delete the theme files.' );
	}

	/* --------------------------------------------------------- Global styles */

	private static function user_styles_post() {
		if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) || ! method_exists( 'WP_Theme_JSON_Resolver', 'get_user_data_from_wp_global_styles' ) ) {
			return null;
		}
		$post = \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
		return ! empty( $post['ID'] ) ? get_post( $post['ID'] ) : null;
	}

	public static function get_global_styles( array $args ) {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return H::error( 'not_block_theme', 'The active theme is a classic theme; global styles do not apply.', 'Use get_theme_mods, or edit the theme CSS files.' );
		}
		$post = self::user_styles_post();
		$user = $post ? json_decode( $post->post_content, true ) : array();
		$out  = array(
			'global_styles_post_id' => $post ? (int) $post->ID : null,
			'user'                  => array(
				'styles'   => isset( $user['styles'] ) ? $user['styles'] : new \stdClass(),
				'settings' => isset( $user['settings'] ) ? $user['settings'] : new \stdClass(),
			),
		);
		if ( ! empty( $args['include_merged'] ) ) {
			$out['merged'] = array(
				'settings' => wp_get_global_settings(),
				'styles'   => wp_get_global_styles(),
			);
		} else {
			$palette = wp_get_global_settings( array( 'color', 'palette' ) );
			$fonts   = wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
			$out['theme_palette']  = $palette;
			$out['theme_fonts']    = $fonts;
		}
		return $out;
	}

	public static function update_global_styles( array $args ) {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return H::error( 'not_block_theme', 'The active theme is a classic theme; global styles do not apply.' );
		}
		$post = self::user_styles_post();
		if ( ! $post ) {
			return H::error( 'unsupported', 'This WordPress version does not expose the global styles post.' );
		}
		$current = json_decode( $post->post_content, true );
		$current = is_array( $current ) ? $current : array();
		$merge   = ! empty( $args['merge'] );
		foreach ( array( 'styles', 'settings' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_array( $args[ $key ] ) ) {
				$current[ $key ] = $merge && isset( $current[ $key ] ) && is_array( $current[ $key ] ) ? array_replace_recursive( $current[ $key ], $args[ $key ] ) : $args[ $key ];
			}
		}
		$current['isGlobalStylesUserThemeJSON'] = true;
		$current['version']                     = class_exists( 'WP_Theme_JSON' ) ? \WP_Theme_JSON::LATEST_SCHEMA : 3;
		$res = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => wp_json_encode( $current ),
				)
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			\WP_Theme_JSON_Resolver::clean_cached_data();
		}
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
		}
		return array(
			'global_styles_post_id' => (int) $post->ID,
			'user'                  => array(
				'styles'   => isset( $current['styles'] ) ? $current['styles'] : new \stdClass(),
				'settings' => isset( $current['settings'] ) ? $current['settings'] : new \stdClass(),
			),
		);
	}

	public static function get_theme_mods() {
		return array(
			'theme'      => get_stylesheet(),
			'mods'       => get_theme_mods() ? get_theme_mods() : new \stdClass(),
			'custom_css' => wp_get_custom_css(),
		);
	}

	public static function update_theme_mods( array $args ) {
		$changed = array();
		foreach ( (array) ( isset( $args['mods'] ) ? $args['mods'] : array() ) as $name => $value ) {
			$changed[ $name ] = array( 'old' => get_theme_mod( $name ) );
			if ( null === $value ) {
				remove_theme_mod( $name );
			} else {
				set_theme_mod( $name, $value );
			}
			$changed[ $name ]['new'] = get_theme_mod( $name );
		}
		if ( isset( $args['custom_css'] ) ) {
			$r = wp_update_custom_css_post( $args['custom_css'] );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$changed['custom_css'] = 'updated';
		}
		return array( 'theme' => get_stylesheet(), 'changed' => $changed );
	}
}
