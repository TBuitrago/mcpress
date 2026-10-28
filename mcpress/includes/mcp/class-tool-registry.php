<?php
/**
 * Registry of MCP tools.
 *
 * A tool definition:
 *   name         snake_case, unique, ≤ 64 chars.
 *   title        Short human title.
 *   description  What it does, written for the AI (English).
 *   group        One of groups() — lets the owner switch whole areas off.
 *   risk         read | write | destructive | critical, or callable( array $args ): string.
 *   base_risk    Risk shown before arguments are known (defaults to risk when it is a string, else 'write').
 *   input_schema JSON Schema for the arguments (type object).
 *   callback     callable( array $args, Context $ctx ): mixed|WP_Error.
 *   preview      Optional callable( array $args ): array|string, shown on the approval screen.
 *   guard        Optional callable( array $args, Context $ctx ): WP_Error|null. Hard limits, all modes.
 *   capability   Optional capability checked before running.
 *
 * @package MCPress
 */

namespace MCPress\MCP;

use MCPress\Permissions\Policy;

defined( 'ABSPATH' ) || exit;

class Tool_Registry {

	/** @var Tool_Registry|null */
	private static $instance;

	private $tools  = array();
	private $loaded = false;

	/** Built-in tool providers, each with a static register( Tool_Registry $r ). */
	const PROVIDERS = array(
		'MCPress\\Tools\\Site',
		'MCPress\\Tools\\Content',
		'MCPress\\Tools\\Taxonomies',
		'MCPress\\Tools\\Media',
		'MCPress\\Tools\\Comments',
		'MCPress\\Tools\\Users',
		'MCPress\\Tools\\Options',
		'MCPress\\Tools\\Plugins',
		'MCPress\\Tools\\Themes',
		'MCPress\\Tools\\Updates',
		'MCPress\\Tools\\Menus',
		'MCPress\\Tools\\Files',
		'MCPress\\Tools\\Database',
		'MCPress\\Tools\\Code',
		'MCPress\\Tools\\Maintenance',
		'MCPress\\Tools\\Rest',
		'MCPress\\Tools\\Abilities',
		'MCPress\\Tools\\Elementor',
		'MCPress\\Tools\\Mcpress',
	);

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function groups() {
		return array(
			'site'        => __( 'Site and settings', 'mcpress' ),
			'content'     => __( 'Posts, pages and custom post types', 'mcpress' ),
			'taxonomies'  => __( 'Categories, tags and taxonomies', 'mcpress' ),
			'media'       => __( 'Media library', 'mcpress' ),
			'comments'    => __( 'Comments', 'mcpress' ),
			'users'       => __( 'Users and roles', 'mcpress' ),
			'options'     => __( 'Options (wp_options)', 'mcpress' ),
			'plugins'     => __( 'Plugins', 'mcpress' ),
			'themes'      => __( 'Themes and global styles', 'mcpress' ),
			'updates'     => __( 'WordPress updates', 'mcpress' ),
			'menus'       => __( 'Menus', 'mcpress' ),
			'files'       => __( 'Files', 'mcpress' ),
			'database'    => __( 'Database', 'mcpress' ),
			'code'        => __( 'PHP execution', 'mcpress' ),
			'maintenance' => __( 'Cache, rewrites and cron', 'mcpress' ),
			'rest'        => __( 'REST API bridge (any plugin)', 'mcpress' ),
			'abilities'   => __( 'Abilities API', 'mcpress' ),
			'elementor'   => __( 'Elementor', 'mcpress' ),
			'mcpress'     => __( 'MCPress (status and approvals)', 'mcpress' ),
		);
	}

	/** Load built-in tools and let other plugins add theirs. */
	private function load() {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;
		foreach ( self::PROVIDERS as $class ) {
			if ( class_exists( $class ) ) {
				$class::register( $this );
			}
		}
		/**
		 * Register extra tools: $registry->register( array( ... ) ) or mcpress_register_tool().
		 *
		 * @param Tool_Registry $registry
		 */
		do_action( 'mcpress_register_tools', $this );
	}

	public function register( array $tool ) {
		$tool = wp_parse_args(
			$tool,
			array(
				'name'         => '',
				'title'        => '',
				'description'  => '',
				'group'        => 'site',
				'risk'         => Policy::RISK_WRITE,
				'base_risk'    => null,
				'input_schema' => array(),
				'callback'     => null,
				'preview'      => null,
				'guard'        => null,
				'capability'   => null,
			)
		);
		if ( ! preg_match( '/^[a-z][a-z0-9_]{1,63}$/', $tool['name'] ) || ! is_callable( $tool['callback'] ) ) {
			_doing_it_wrong( __METHOD__, esc_html( 'Invalid MCPress tool: ' . $tool['name'] ), '0.1.0' );
			return false;
		}
		if ( null === $tool['base_risk'] ) {
			$tool['base_risk'] = is_string( $tool['risk'] ) ? $tool['risk'] : Policy::RISK_WRITE;
		}
		if ( ! array_key_exists( $tool['group'], self::groups() ) && ! has_filter( 'mcpress_tool_groups' ) ) {
			$tool['group'] = 'site';
		}
		$tool['input_schema'] = self::normalize_schema( $tool['input_schema'] );
		$this->tools[ $tool['name'] ] = $tool;
		return true;
	}

	public function unregister( $name ) {
		unset( $this->tools[ $name ] );
	}

	public function get( $name ) {
		$this->load();
		return isset( $this->tools[ $name ] ) ? $this->tools[ $name ] : null;
	}

	public function all() {
		$this->load();
		/**
		 * Filters every registered tool definition.
		 */
		return apply_filters( 'mcpress_tools', $this->tools );
	}

	/** Risk of one specific call. */
	public static function risk_for( array $tool, array $args ) {
		$risk = is_callable( $tool['risk'] ) ? call_user_func( $tool['risk'], $args ) : $tool['risk'];
		return in_array( $risk, Policy::risks(), true ) ? $risk : Policy::RISK_WRITE;
	}

	/** MCP annotations derived from the base risk. */
	public static function annotations( array $tool ) {
		$risk = $tool['base_risk'];
		return array(
			'title'           => $tool['title'],
			'readOnlyHint'    => Policy::RISK_READ === $risk,
			'destructiveHint' => Policy::risk_rank( $risk ) >= Policy::risk_rank( Policy::RISK_DESTRUCTIVE ),
			'idempotentHint'  => Policy::RISK_READ === $risk,
			'openWorldHint'   => in_array( $tool['group'], array( 'rest', 'plugins', 'themes', 'updates', 'media' ), true ),
		);
	}

	/** Ensure an object schema that encodes to valid JSON (empty "properties" must be {}). */
	public static function normalize_schema( $schema ) {
		$schema = is_array( $schema ) ? $schema : array();
		if ( empty( $schema['type'] ) ) {
			$schema['type'] = 'object';
		}
		if ( empty( $schema['properties'] ) ) {
			$schema['properties'] = new \stdClass();
		}
		return $schema;
	}
}
