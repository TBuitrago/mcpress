<?php
/**
 * Classic navigation menus. (Block themes keep navigation in wp_navigation posts: use the content tools.)
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Tools\Helpers as H;

defined( 'ABSPATH' ) || exit;

class Menus {

	public static function register( Tool_Registry $r ) {
		$item_fields = array(
			'type'      => H::enum( array( 'custom', 'post_type', 'taxonomy' ), 'custom = any URL; post_type = a page/post; taxonomy = a category/term.' ),
			'title'     => H::str( 'Label (defaults to the object title).' ),
			'url'       => H::str( 'URL (custom links).' ),
			'object'    => H::str( 'Post type or taxonomy name (e.g. page, category).' ),
			'object_id' => H::int( 'ID of the page/post/term.' ),
			'parent_id' => H::int( 'Parent menu item ID (0 = top level).' ),
			'position'  => H::int( 'Order in the menu.' ),
			'target'    => H::enum( array( '', '_blank' ), 'Open in a new tab: _blank.' ),
			'classes'   => H::str( 'CSS classes separated by spaces.' ),
			'attr_title' => H::str( 'Title attribute.' ),
		);

		$r->register(
			array(
				'name'         => 'list_menus',
				'title'        => 'List menus',
				'group'        => 'menus',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Classic menus with item counts, theme menu locations and which menu each location shows. Block themes: see wp_navigation posts with list_posts.',
				'input_schema' => H::schema( array() ),
				'callback'     => array( __CLASS__, 'list_menus' ),
				'capability'   => 'edit_theme_options',
			)
		);
		$r->register(
			array(
				'name'         => 'get_menu',
				'title'        => 'Read a menu',
				'group'        => 'menus',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Items of a menu in order, with their hierarchy.',
				'input_schema' => H::schema( array( 'menu' => H::str( 'Menu ID, slug or name.' ) ), array( 'menu' ) ),
				'callback'     => array( __CLASS__, 'get_menu' ),
				'capability'   => 'edit_theme_options',
			)
		);
		$r->register(
			array(
				'name'         => 'create_menu',
				'title'        => 'Create a menu',
				'group'        => 'menus',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Create an empty menu and optionally assign it to a theme location.',
				'input_schema' => H::schema( array( 'name' => H::str( 'Menu name.' ), 'location' => H::str( 'Theme location slug.' ) ), array( 'name' ) ),
				'callback'     => array( __CLASS__, 'create_menu' ),
				'capability'   => 'edit_theme_options',
			)
		);
		$r->register(
			array(
				'name'         => 'delete_menu',
				'title'        => 'Delete a menu',
				'group'        => 'menus',
				'risk'         => Policy::RISK_DESTRUCTIVE,
				'description'  => 'Delete a menu and all its items.',
				'input_schema' => H::schema( array( 'menu' => H::str( 'Menu ID, slug or name.' ) ), array( 'menu' ) ),
				'callback'     => array( __CLASS__, 'delete_menu' ),
				'capability'   => 'edit_theme_options',
			)
		);
		$r->register(
			array(
				'name'         => 'add_menu_item',
				'title'        => 'Add a menu item',
				'group'        => 'menus',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Add a link, page/post or term to a menu.',
				'input_schema' => H::schema( array_merge( array( 'menu' => H::str( 'Menu ID, slug or name.' ) ), $item_fields ), array( 'menu', 'type' ) ),
				'callback'     => array( __CLASS__, 'add_menu_item' ),
				'capability'   => 'edit_theme_options',
			)
		);
		$r->register(
			array(
				'name'         => 'update_menu_item',
				'title'        => 'Update a menu item',
				'group'        => 'menus',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Change label, URL, parent, position, target or classes of a menu item. Fields not sent keep their value.',
				'input_schema' => H::schema( array_merge( array( 'item_id' => H::int( 'Menu item ID.' ) ), $item_fields ), array( 'item_id' ) ),
				'callback'     => array( __CLASS__, 'update_menu_item' ),
				'capability'   => 'edit_theme_options',
			)
		);
		$r->register(
			array(
				'name'         => 'delete_menu_item',
				'title'        => 'Remove a menu item',
				'group'        => 'menus',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Remove an item from its menu (children move up one level).',
				'input_schema' => H::schema( array( 'item_id' => H::int( 'Menu item ID.' ) ), array( 'item_id' ) ),
				'callback'     => array( __CLASS__, 'delete_menu_item' ),
				'capability'   => 'edit_theme_options',
			)
		);
		$r->register(
			array(
				'name'         => 'assign_menu_location',
				'title'        => 'Assign a menu to a location',
				'group'        => 'menus',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Show a menu in a theme location (header, footer…), or empty the location with menu = 0.',
				'input_schema' => H::schema( array( 'location' => H::str( 'Theme location slug.' ), 'menu' => H::str( 'Menu ID, slug or name, or 0.' ) ), array( 'location', 'menu' ) ),
				'callback'     => array( __CLASS__, 'assign_location' ),
				'capability'   => 'edit_theme_options',
			)
		);
	}

	private static function menu( $ref ) {
		$menu = wp_get_nav_menu_object( is_numeric( $ref ) ? (int) $ref : (string) $ref );
		return $menu ? $menu : H::not_found( 'Menu', $ref );
	}

	private static function item_summary( $i ) {
		return array(
			'item_id'   => (int) $i->ID,
			'title'     => $i->title,
			'url'       => $i->url,
			'type'      => $i->type,
			'object'    => $i->object,
			'object_id' => (int) $i->object_id,
			'parent_id' => (int) $i->menu_item_parent,
			'position'  => (int) $i->menu_order,
			'target'    => $i->target,
			'classes'   => trim( implode( ' ', (array) $i->classes ) ),
		);
	}

	public static function list_menus() {
		$locations = get_nav_menu_locations();
		$out       = array( 'menus' => array(), 'locations' => array() );
		foreach ( wp_get_nav_menus() as $m ) {
			$out['menus'][] = array(
				'id'    => (int) $m->term_id,
				'name'  => $m->name,
				'slug'  => $m->slug,
				'items' => (int) $m->count,
			);
		}
		foreach ( get_registered_nav_menus() as $slug => $label ) {
			$out['locations'][] = array(
				'location' => $slug,
				'label'    => $label,
				'menu_id'  => isset( $locations[ $slug ] ) ? (int) $locations[ $slug ] : 0,
			);
		}
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$out['note'] = 'The active theme is a block theme: its navigation lives in wp_navigation posts (list_posts post_type=wp_navigation).';
		}
		return $out;
	}

	public static function get_menu( array $args ) {
		$menu = self::menu( $args['menu'] );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$items = wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) );
		return array(
			'id'    => (int) $menu->term_id,
			'name'  => $menu->name,
			'items' => array_map( array( __CLASS__, 'item_summary' ), (array) $items ),
		);
	}

	public static function create_menu( array $args ) {
		$id = wp_create_nav_menu( $args['name'] );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! empty( $args['location'] ) ) {
			self::assign_location( array( 'location' => $args['location'], 'menu' => $id ) );
		}
		return array( 'created' => array( 'id' => (int) $id, 'name' => $args['name'] ) );
	}

	public static function delete_menu( array $args ) {
		$menu = self::menu( $args['menu'] );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		$res = wp_delete_nav_menu( $menu->term_id );
		return is_wp_error( $res ) ? $res : array( 'deleted' => (int) $menu->term_id );
	}

	private static function item_data( array $args, array $base = array() ) {
		$map  = array(
			'type'       => 'menu-item-type',
			'title'      => 'menu-item-title',
			'url'        => 'menu-item-url',
			'object'     => 'menu-item-object',
			'object_id'  => 'menu-item-object-id',
			'parent_id'  => 'menu-item-parent-id',
			'position'   => 'menu-item-position',
			'target'     => 'menu-item-target',
			'classes'    => 'menu-item-classes',
			'attr_title' => 'menu-item-attr-title',
		);
		$data = $base;
		foreach ( $map as $from => $to ) {
			if ( array_key_exists( $from, $args ) ) {
				$data[ $to ] = $args[ $from ];
			}
		}
		$data['menu-item-status'] = 'publish';
		return $data;
	}

	public static function add_menu_item( array $args ) {
		$menu = self::menu( $args['menu'] );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		if ( 'custom' === $args['type'] && empty( $args['url'] ) ) {
			return H::error( 'invalid_input', 'Custom links need a url.' );
		}
		if ( 'custom' !== $args['type'] && ( empty( $args['object'] ) || empty( $args['object_id'] ) ) ) {
			return H::error( 'invalid_input', 'Page/post and term items need object and object_id.' );
		}
		$id = wp_update_nav_menu_item( $menu->term_id, 0, self::item_data( $args ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return array( 'added' => self::item_summary( wp_setup_nav_menu_item( get_post( $id ) ) ) );
	}

	public static function update_menu_item( array $args ) {
		$post = get_post( (int) $args['item_id'] );
		if ( ! $post || 'nav_menu_item' !== $post->post_type ) {
			return H::not_found( 'Menu item', $args['item_id'] );
		}
		$item  = wp_setup_nav_menu_item( $post );
		$menus = wp_get_object_terms( $post->ID, 'nav_menu', array( 'fields' => 'ids' ) );
		if ( empty( $menus ) || is_wp_error( $menus ) ) {
			return H::error( 'orphan_item', 'This item does not belong to any menu.' );
		}
		// wp_update_nav_menu_item() resets every field not passed, so start from the current values.
		$base = array(
			'menu-item-db-id'       => $item->ID,
			'menu-item-object-id'   => $item->object_id,
			'menu-item-object'      => $item->object,
			'menu-item-parent-id'   => $item->menu_item_parent,
			'menu-item-position'    => $item->menu_order,
			'menu-item-type'        => $item->type,
			'menu-item-title'       => $item->title,
			'menu-item-url'         => $item->url,
			'menu-item-description' => $item->description,
			'menu-item-attr-title'  => $item->attr_title,
			'menu-item-target'      => $item->target,
			'menu-item-classes'     => implode( ' ', (array) $item->classes ),
			'menu-item-xfn'         => $item->xfn,
		);
		$res = wp_update_nav_menu_item( (int) $menus[0], $item->ID, self::item_data( $args, $base ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'updated' => self::item_summary( wp_setup_nav_menu_item( get_post( $item->ID ) ) ) );
	}

	public static function delete_menu_item( array $args ) {
		$post = get_post( (int) $args['item_id'] );
		if ( ! $post || 'nav_menu_item' !== $post->post_type ) {
			return H::not_found( 'Menu item', $args['item_id'] );
		}
		return wp_delete_post( $post->ID, true ) ? array( 'removed' => (int) $post->ID ) : H::error( 'failed', 'Could not remove the item.' );
	}

	public static function assign_location( array $args ) {
		$registered = get_registered_nav_menus();
		if ( ! isset( $registered[ $args['location'] ] ) ) {
			return H::error( 'invalid_location', 'The active theme has no such menu location.', 'Call list_menus to see the locations.' );
		}
		$menu_id = 0;
		if ( (string) $args['menu'] !== '0' ) {
			$menu = self::menu( $args['menu'] );
			if ( is_wp_error( $menu ) ) {
				return $menu;
			}
			$menu_id = (int) $menu->term_id;
		}
		$locations                      = get_nav_menu_locations();
		$locations[ $args['location'] ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		return array( 'location' => $args['location'], 'menu_id' => $menu_id );
	}
}
