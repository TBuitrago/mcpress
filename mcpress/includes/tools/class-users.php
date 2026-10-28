<?php
/**
 * Users and roles.
 *
 * Guards (every mode): the user the connection acts as cannot be deleted or have its role
 * changed, and the last administrator cannot be deleted or demoted.
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\Context;
use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Tools\Helpers as H;

defined( 'ABSPATH' ) || exit;

class Users {

	/** Capabilities that make a role "powerful": giving them is critical. */
	const POWER_CAPS = array( 'manage_options', 'edit_users', 'promote_users', 'create_users', 'delete_users', 'install_plugins', 'activate_plugins', 'edit_plugins', 'edit_themes', 'update_core', 'unfiltered_upload', 'manage_network' );

	public static function register( Tool_Registry $r ) {
		$profile = array(
			'email'        => H::str( 'Email.' ),
			'role'         => H::str( 'Role slug (see list_roles).' ),
			'password'     => H::str( 'New password.' ),
			'first_name'   => H::str( 'First name.' ),
			'last_name'    => H::str( 'Last name.' ),
			'display_name' => H::str( 'Public display name.' ),
			'nickname'     => H::str( 'Nickname.' ),
			'description'  => H::str( 'Biographical info.' ),
			'url'          => H::str( 'Website.' ),
			'locale'       => H::str( 'Admin language for this user ("" = site default).' ),
		);

		$r->register(
			array(
				'name'         => 'list_users',
				'title'        => 'List users',
				'group'        => 'users',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Users with login, email, display name and roles.',
				'input_schema' => H::schema(
					array_merge(
						array(
							'role'    => H::str( 'Only this role.' ),
							'search'  => H::str( 'Search login, email, URL or name.' ),
							'orderby' => H::enum( array( 'registered', 'login', 'email', 'display_name', 'post_count' ), 'Order by.', 'registered' ),
						),
						H::paging()
					)
				),
				'callback'     => array( __CLASS__, 'list_users' ),
				'capability'   => 'list_users',
			)
		);
		$r->register(
			array(
				'name'         => 'get_user',
				'title'        => 'Read a user',
				'group'        => 'users',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Profile, roles, extra capabilities and post count of a user, by ID, login or email.',
				'input_schema' => H::schema(
					array(
						'id'    => H::int( 'User ID.' ),
						'login' => H::str( 'Or login / email.' ),
					)
				),
				'callback'     => array( __CLASS__, 'get_user' ),
				'capability'   => 'list_users',
			)
		);
		$r->register(
			array(
				'name'         => 'create_user',
				'title'        => 'Create a user',
				'group'        => 'users',
				'risk'         => array( __CLASS__, 'create_risk' ),
				'base_risk'    => Policy::RISK_DESTRUCTIVE,
				'description'  => 'Create a user account. Without a password, a secure one is generated and the user gets a "set your password" email. Creating a role with admin-level capabilities is critical.',
				'input_schema' => H::schema( array_merge( array( 'login' => H::str( 'Username (cannot be changed later).' ), 'send_email' => H::bool( 'Send the new-user email.', true ) ), $profile ), array( 'login', 'email' ) ),
				'callback'     => array( __CLASS__, 'create_user' ),
				'preview'      => array( __CLASS__, 'preview_user' ),
				'capability'   => 'create_users',
			)
		);
		$r->register(
			array(
				'name'         => 'update_user',
				'title'        => 'Update a user',
				'group'        => 'users',
				'risk'         => array( __CLASS__, 'update_risk' ),
				'base_risk'    => Policy::RISK_WRITE,
				'description'  => 'Change profile fields. Changing role, email or password is critical. You cannot change the role of the user this connection acts as, nor demote the last administrator.',
				'input_schema' => H::schema( array_merge( array( 'id' => H::int( 'User ID.' ) ), $profile ), array( 'id' ) ),
				'callback'     => array( __CLASS__, 'update_user' ),
				'guard'        => array( __CLASS__, 'guard_update' ),
				'preview'      => array( __CLASS__, 'preview_user' ),
				'capability'   => 'edit_users',
			)
		);
		$r->register(
			array(
				'name'         => 'delete_user',
				'title'        => 'Delete a user',
				'group'        => 'users',
				'risk'         => Policy::RISK_CRITICAL,
				'description'  => 'Delete a user. Their content goes to reassign_to; if you really want their posts deleted too, set delete_content=true. Not possible for the user this connection acts as, nor for the last administrator.',
				'input_schema' => H::schema(
					array(
						'id'             => H::int( 'User ID.' ),
						'reassign_to'    => H::int( 'User ID that receives their posts.' ),
						'delete_content' => H::bool( 'Delete their posts instead of reassigning them.', false ),
					),
					array( 'id' )
				),
				'callback'     => array( __CLASS__, 'delete_user' ),
				'guard'        => array( __CLASS__, 'guard_delete' ),
				'preview'      => array( __CLASS__, 'preview_delete' ),
				'capability'   => 'delete_users',
			)
		);
		$r->register(
			array(
				'name'         => 'list_roles',
				'title'        => 'List roles',
				'group'        => 'users',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Roles with number of users and, optionally, their capabilities.',
				'input_schema' => H::schema( array( 'include_capabilities' => H::bool( 'Include the capability list of each role.', false ) ) ),
				'callback'     => array( __CLASS__, 'list_roles' ),
				'capability'   => 'list_users',
			)
		);
		$r->register(
			array(
				'name'         => 'update_role',
				'title'        => 'Create or change a role',
				'group'        => 'users',
				'risk'         => Policy::RISK_CRITICAL,
				'description'  => 'Create a role, delete a role, or add/remove capabilities. The administrator role cannot lose capabilities, and the role of the connected user cannot be deleted.',
				'input_schema' => H::schema(
					array(
						'action'       => H::enum( array( 'create', 'delete', 'add_caps', 'remove_caps' ), 'What to do.' ),
						'role'         => H::str( 'Role slug.' ),
						'name'         => H::str( 'Display name (create).' ),
						'capabilities' => H::arr( 'string', 'Capabilities to add/remove, or the initial ones on create.' ),
						'clone_from'   => H::str( 'On create, start with the capabilities of this role.' ),
					),
					array( 'action', 'role' )
				),
				'callback'     => array( __CLASS__, 'update_role' ),
				'guard'        => array( __CLASS__, 'guard_role' ),
				'capability'   => 'promote_users',
			)
		);
	}

	/* -------------------------------------------------------------- Risks */

	public static function role_is_powerful( $role ) {
		$obj = get_role( (string) $role );
		if ( ! $obj ) {
			return false;
		}
		foreach ( self::POWER_CAPS as $cap ) {
			if ( ! empty( $obj->capabilities[ $cap ] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function create_risk( array $args ) {
		$role = isset( $args['role'] ) ? $args['role'] : get_option( 'default_role' );
		return self::role_is_powerful( $role ) ? Policy::RISK_CRITICAL : Policy::RISK_DESTRUCTIVE;
	}

	public static function update_risk( array $args ) {
		foreach ( array( 'role', 'email', 'password' ) as $k ) {
			if ( isset( $args[ $k ] ) && '' !== $args[ $k ] ) {
				return Policy::RISK_CRITICAL;
			}
		}
		return Policy::RISK_WRITE;
	}

	/* ------------------------------------------------------------- Guards */

	private static function admins() {
		return get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 2,
			)
		);
	}

	public static function guard_update( array $args, Context $ctx ) {
		$id = (int) $args['id'];
		if ( ! empty( $args['role'] ) ) {
			if ( $id === $ctx->user_id ) {
				return H::error( 'lockout_guard', 'MCPress does not let an AI change the role of the account it is connected as.', 'Ask the user to do it in wp-admin → Users.' );
			}
			$user = get_userdata( $id );
			if ( $user && in_array( 'administrator', (array) $user->roles, true ) && 'administrator' !== $args['role'] && count( self::admins() ) < 2 ) {
				return H::error( 'lockout_guard', 'This is the last administrator: demoting it would lock everyone out.', 'Promote another user to administrator first.' );
			}
			if ( ! wp_roles()->is_role( $args['role'] ) ) {
				return H::error( 'invalid_role', 'Role does not exist.', 'Call list_roles.' );
			}
		}
		return null;
	}

	public static function guard_delete( array $args, Context $ctx ) {
		$id = (int) $args['id'];
		if ( $id === $ctx->user_id ) {
			return H::error( 'lockout_guard', 'MCPress does not let an AI delete the account it is connected as.', 'Ask the user to do it in wp-admin → Users.' );
		}
		$user = get_userdata( $id );
		if ( $user && in_array( 'administrator', (array) $user->roles, true ) && count( self::admins() ) < 2 ) {
			return H::error( 'lockout_guard', 'This is the last administrator and cannot be deleted.' );
		}
		if ( is_multisite() && $user && is_super_admin( $id ) ) {
			return H::error( 'lockout_guard', 'Super administrators cannot be deleted from here.' );
		}
		if ( empty( $args['reassign_to'] ) && empty( $args['delete_content'] ) && $user && count_user_posts( $id, array_keys( get_post_types() ) ) > 0 ) {
			return H::error( 'invalid_input', 'This user has content. Set reassign_to (a user ID) or, to delete it all, delete_content=true.', null, true );
		}
		return null;
	}

	public static function guard_role( array $args, Context $ctx ) {
		$role = (string) $args['role'];
		if ( 'administrator' === $role && in_array( $args['action'], array( 'delete', 'remove_caps' ), true ) ) {
			return H::error( 'lockout_guard', 'The administrator role cannot be deleted or lose capabilities through MCPress.' );
		}
		$user = $ctx->user();
		if ( 'delete' === $args['action'] && $user && in_array( $role, (array) $user->roles, true ) ) {
			return H::error( 'lockout_guard', 'This role belongs to the account the AI is connected as.' );
		}
		if ( 'remove_caps' === $args['action'] && $user && in_array( $role, (array) $user->roles, true ) && array_intersect( (array) $args['capabilities'], array( 'manage_options', 'promote_users', 'edit_users' ) ) ) {
			return H::error( 'lockout_guard', 'Removing that capability would disconnect this AI from the site.' );
		}
		return null;
	}

	/* ---------------------------------------------------------- Callbacks */

	public static function list_users( array $args ) {
		list( $per, $page ) = H::paginate( $args );
		$q = array(
			'number'      => $per,
			'paged'       => $page,
			'orderby'     => $args['orderby'],
			'count_total' => true,
		);
		if ( ! empty( $args['role'] ) ) {
			$q['role'] = $args['role'];
		}
		if ( ! empty( $args['search'] ) ) {
			$q['search'] = '*' . $args['search'] . '*';
		}
		$query = new \WP_User_Query( $q );
		return array(
			'total' => (int) $query->get_total(),
			'page'  => $page,
			'items' => array_map( array( 'MCPress\\Tools\\Helpers', 'user_summary' ), $query->get_results() ),
		);
	}

	private static function find_user( array $args ) {
		if ( ! empty( $args['id'] ) ) {
			$user = get_userdata( (int) $args['id'] );
		} elseif ( ! empty( $args['login'] ) ) {
			$user = is_email( $args['login'] ) ? get_user_by( 'email', $args['login'] ) : get_user_by( 'login', $args['login'] );
		} else {
			return H::error( 'invalid_input', 'Send id or login.' );
		}
		return $user ? $user : H::not_found( 'User', ! empty( $args['id'] ) ? $args['id'] : $args['login'] );
	}

	public static function get_user( array $args ) {
		$user = self::find_user( $args );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$out                 = H::user_summary( $user );
		$out['first_name']   = $user->first_name;
		$out['last_name']    = $user->last_name;
		$out['nickname']     = $user->nickname;
		$out['description']  = $user->description;
		$out['url']          = $user->user_url;
		$out['locale']       = get_user_meta( $user->ID, 'locale', true );
		$out['posts']        = (int) count_user_posts( $user->ID, array_keys( get_post_types( array( 'public' => true ) ) ) );
		$out['extra_caps']   = array_keys( array_diff_key( array_filter( (array) $user->caps ), array_flip( (array) $user->roles ) ) );
		$out['super_admin']  = is_multisite() ? is_super_admin( $user->ID ) : null;
		return $out;
	}

	public static function create_user( array $args ) {
		$role = isset( $args['role'] ) ? $args['role'] : get_option( 'default_role' );
		if ( ! wp_roles()->is_role( $role ) ) {
			return H::error( 'invalid_role', 'Role does not exist.', 'Call list_roles.' );
		}
		if ( ! current_user_can( 'promote_users' ) && $role !== get_option( 'default_role' ) ) {
			return H::forbidden( 'The connected user cannot assign roles.' );
		}
		$generated = empty( $args['password'] );
		$data      = array(
			'user_login'   => $args['login'],
			'user_email'   => $args['email'],
			'user_pass'    => $generated ? wp_generate_password( 24, true, true ) : $args['password'],
			'role'         => $role,
			'first_name'   => isset( $args['first_name'] ) ? $args['first_name'] : '',
			'last_name'    => isset( $args['last_name'] ) ? $args['last_name'] : '',
			'display_name' => isset( $args['display_name'] ) ? $args['display_name'] : '',
			'nickname'     => isset( $args['nickname'] ) ? $args['nickname'] : '',
			'description'  => isset( $args['description'] ) ? $args['description'] : '',
			'user_url'     => isset( $args['url'] ) ? $args['url'] : '',
			'locale'       => isset( $args['locale'] ) ? $args['locale'] : '',
		);
		$id = wp_insert_user( wp_slash( array_filter( $data, 'strlen' ) + array( 'role' => $role ) ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! empty( $args['send_email'] ) ) {
			wp_new_user_notification( $id, null, 'user' );
		}
		return array(
			'created'  => H::user_summary( get_userdata( $id ) ),
			'password_status' => $generated ? 'Generated and not shown. ' . ( ! empty( $args['send_email'] ) ? 'The user received an email to set it.' : 'Use update_user or the lost-password link to set one.' ) : 'Set to the value you provided.',
		);
	}

	public static function update_user( array $args ) {
		$user = get_userdata( (int) $args['id'] );
		if ( ! $user ) {
			return H::not_found( 'User', $args['id'] );
		}
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return H::forbidden( 'The connected user cannot edit this user.' );
		}
		$map  = array(
			'email'        => 'user_email',
			'password'     => 'user_pass',
			'first_name'   => 'first_name',
			'last_name'    => 'last_name',
			'display_name' => 'display_name',
			'nickname'     => 'nickname',
			'description'  => 'description',
			'url'          => 'user_url',
			'locale'       => 'locale',
		);
		$data = array( 'ID' => $user->ID );
		foreach ( $map as $from => $to ) {
			if ( array_key_exists( $from, $args ) ) {
				$data[ $to ] = $args[ $from ];
			}
		}
		if ( isset( $args['role'] ) ) {
			if ( ! current_user_can( 'promote_user', $user->ID ) ) {
				return H::forbidden( 'The connected user cannot change roles.' );
			}
			$data['role'] = $args['role'];
		}
		$res = wp_update_user( wp_slash( $data ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'updated'          => H::user_summary( get_userdata( $user->ID ) ),
			'password_changed' => isset( $args['password'] ),
		);
	}

	public static function delete_user( array $args ) {
		H::admin_includes();
		$user = get_userdata( (int) $args['id'] );
		if ( ! $user ) {
			return H::not_found( 'User', $args['id'] );
		}
		if ( ! current_user_can( 'delete_user', $user->ID ) ) {
			return H::forbidden( 'The connected user cannot delete this user.' );
		}
		$reassign = ! empty( $args['reassign_to'] ) ? (int) $args['reassign_to'] : null;
		if ( $reassign && ( ! get_userdata( $reassign ) || $reassign === $user->ID ) ) {
			return H::error( 'invalid_input', 'reassign_to must be another existing user.' );
		}
		$summary = H::user_summary( $user );
		$ok      = wp_delete_user( $user->ID, $reassign );
		return $ok ? array( 'deleted' => $summary, 'content' => $reassign ? 'reassigned to user ' . $reassign : 'deleted' ) : H::error( 'failed', 'Could not delete the user.' );
	}

	public static function list_roles( array $args ) {
		$counts = count_users();
		$out    = array();
		foreach ( wp_roles()->roles as $slug => $role ) {
			$item = array(
				'role'     => $slug,
				'name'     => translate_user_role( $role['name'] ),
				'users'    => isset( $counts['avail_roles'][ $slug ] ) ? (int) $counts['avail_roles'][ $slug ] : 0,
				'powerful' => self::role_is_powerful( $slug ),
			);
			if ( ! empty( $args['include_capabilities'] ) ) {
				$item['capabilities'] = array_keys( array_filter( (array) $role['capabilities'] ) );
			}
			$out[] = $item;
		}
		return $out;
	}

	public static function update_role( array $args ) {
		$slug = sanitize_key( $args['role'] );
		$caps = array_map( 'sanitize_key', (array) ( isset( $args['capabilities'] ) ? $args['capabilities'] : array() ) );
		switch ( $args['action'] ) {
			case 'create':
				if ( get_role( $slug ) ) {
					return H::error( 'exists', 'That role already exists.', 'Use add_caps / remove_caps.' );
				}
				$base = array();
				if ( ! empty( $args['clone_from'] ) && get_role( $args['clone_from'] ) ) {
					$base = get_role( $args['clone_from'] )->capabilities;
				}
				$all = array_merge( $base, array_fill_keys( $caps, true ) );
				add_role( $slug, isset( $args['name'] ) ? $args['name'] : ucfirst( $slug ), $all );
				break;
			case 'delete':
				if ( ! get_role( $slug ) ) {
					return H::not_found( 'Role', $slug );
				}
				$users = get_users( array( 'role' => $slug, 'fields' => 'ID' ) );
				foreach ( $users as $uid ) {
					( new \WP_User( $uid ) )->set_role( get_option( 'default_role' ) );
				}
				remove_role( $slug );
				return array( 'deleted' => $slug, 'users_moved_to' => get_option( 'default_role' ), 'users' => count( $users ) );
			case 'add_caps':
			case 'remove_caps':
				$role = get_role( $slug );
				if ( ! $role ) {
					return H::not_found( 'Role', $slug );
				}
				foreach ( $caps as $cap ) {
					if ( 'add_caps' === $args['action'] ) {
						$role->add_cap( $cap );
					} else {
						$role->remove_cap( $cap );
					}
				}
				break;
		}
		$role = get_role( $slug );
		return array(
			'role'         => $slug,
			'capabilities' => $role ? array_keys( array_filter( $role->capabilities ) ) : array(),
		);
	}

	/* ----------------------------------------------------------- Previews */

	public static function preview_user( array $args ) {
		$out = $args;
		if ( isset( $out['password'] ) ) {
			$out['password'] = '(will be changed)';
		}
		if ( ! empty( $args['id'] ) && get_userdata( (int) $args['id'] ) ) {
			$out = array( 'current' => H::user_summary( get_userdata( (int) $args['id'] ) ), 'changes' => $out );
		}
		$role = isset( $args['role'] ) ? $args['role'] : null;
		if ( $role && self::role_is_powerful( $role ) ) {
			$out['warning'] = sprintf( 'The role "%s" has administrator-level capabilities.', $role );
		}
		return $out;
	}

	public static function preview_delete( array $args ) {
		$user = get_userdata( (int) $args['id'] );
		if ( ! $user ) {
			return 'User not found.';
		}
		return array(
			'user'    => H::user_summary( $user ),
			'posts'   => (int) count_user_posts( $user->ID, array_keys( get_post_types() ) ),
			'content' => ! empty( $args['reassign_to'] ) ? 'reassigned to user ' . (int) $args['reassign_to'] : 'DELETED with the user',
		);
	}
}
