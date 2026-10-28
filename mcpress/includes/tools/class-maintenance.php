<?php
/**
 * Caches, rewrite rules, cron and the PHP error log.
 *
 * @package MCPress
 */

namespace MCPress\Tools;

use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Redactor;
use MCPress\Tools\Helpers as H;

defined( 'ABSPATH' ) || exit;

class Maintenance {

	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'         => 'flush_cache',
				'title'        => 'Clear caches',
				'group'        => 'maintenance',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Clear the object cache, expired transients and the page cache of common plugins (LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache, SiteGround, WP Fastest Cache, Breeze, Autoptimize, Cache Enabler, Hummingbird, Nginx Helper) plus Elementor CSS.',
				'input_schema' => H::schema( array( 'what' => H::enum( array( 'all', 'object', 'page', 'transients', 'elementor' ), 'What to clear.', 'all' ) ) ),
				'callback'     => array( __CLASS__, 'flush_cache' ),
				'capability'   => 'manage_options',
			)
		);
		$r->register(
			array(
				'name'         => 'flush_rewrite_rules',
				'title'        => 'Flush permalinks',
				'group'        => 'maintenance',
				'risk'         => Policy::RISK_WRITE,
				'description'  => 'Rebuild rewrite rules (fixes 404s after adding post types or changing slugs).',
				'input_schema' => H::schema( array( 'hard' => H::bool( 'Also rewrite .htaccess.', false ) ) ),
				'callback'     => array( __CLASS__, 'flush_rewrites' ),
				'capability'   => 'manage_options',
			)
		);
		$r->register(
			array(
				'name'         => 'list_cron_events',
				'title'        => 'List scheduled tasks',
				'group'        => 'maintenance',
				'risk'         => Policy::RISK_READ,
				'description'  => 'WP-Cron events with next run, recurrence and arguments.',
				'input_schema' => H::schema( array( 'hook' => H::str( 'Only this hook.' ) ) ),
				'callback'     => array( __CLASS__, 'list_cron' ),
				'capability'   => 'manage_options',
			)
		);
		$r->register(
			array(
				'name'         => 'run_cron_event',
				'title'        => 'Run a scheduled task now',
				'group'        => 'maintenance',
				'risk'         => Policy::RISK_DESTRUCTIVE,
				'description'  => 'Run a scheduled WP-Cron hook immediately (it stays scheduled).',
				'input_schema' => H::schema( array( 'hook' => H::str( 'Hook name from list_cron_events.' ), 'args' => H::arr( new \stdClass(), 'Arguments, as listed.' ) ), array( 'hook' ) ),
				'callback'     => array( __CLASS__, 'run_cron' ),
				'guard'        => array( __CLASS__, 'guard_cron' ),
				'capability'   => 'manage_options',
			)
		);
		$r->register(
			array(
				'name'         => 'delete_cron_event',
				'title'        => 'Unschedule a task',
				'group'        => 'maintenance',
				'risk'         => Policy::RISK_DESTRUCTIVE,
				'description'  => 'Remove scheduled occurrences of a hook (all, or the one at a timestamp).',
				'input_schema' => H::schema(
					array(
						'hook'      => H::str( 'Hook name.' ),
						'timestamp' => H::int( 'Only the occurrence at this Unix time.' ),
						'args'      => H::arr( new \stdClass(), 'Arguments of the event (with timestamp).' ),
					),
					array( 'hook' )
				),
				'callback'     => array( __CLASS__, 'delete_cron' ),
				'guard'        => array( __CLASS__, 'guard_cron' ),
				'capability'   => 'manage_options',
			)
		);
		$r->register(
			array(
				'name'         => 'read_error_log',
				'title'        => 'Read the PHP error log',
				'group'        => 'maintenance',
				'risk'         => Policy::RISK_READ,
				'description'  => 'Last lines of wp-content/debug.log (WP_DEBUG_LOG) or the PHP error_log, to diagnose fatal errors and warnings.',
				'input_schema' => H::schema(
					array(
						'lines'  => H::int( 'Lines from the end (1-1000).', array( 'minimum' => 1, 'maximum' => 1000, 'default' => 100 ) ),
						'filter' => H::str( 'Only lines containing this text.' ),
					)
				),
				'callback'     => array( __CLASS__, 'read_error_log' ),
				'capability'   => 'manage_options',
			)
		);
	}

	public static function flush_cache( array $args ) {
		$what = $args['what'];
		$done = array();
		if ( in_array( $what, array( 'all', 'object' ), true ) ) {
			wp_cache_flush();
			$done[] = 'object cache';
		}
		if ( in_array( $what, array( 'all', 'transients' ), true ) && function_exists( 'delete_expired_transients' ) ) {
			delete_expired_transients( true );
			$done[] = 'expired transients';
		}
		if ( in_array( $what, array( 'all', 'page' ), true ) ) {
			$page = array(
				'LiteSpeed Cache'  => function () {
					if ( defined( 'LSCWP_V' ) ) {
						do_action( 'litespeed_purge_all' );
						return true;
					}
				},
				'WP Rocket'        => function () {
					if ( function_exists( 'rocket_clean_domain' ) ) {
						rocket_clean_domain();
						if ( function_exists( 'rocket_clean_minify' ) ) {
							rocket_clean_minify();
						}
						return true;
					}
				},
				'W3 Total Cache'   => function () {
					if ( function_exists( 'w3tc_flush_all' ) ) {
						w3tc_flush_all();
						return true;
					}
				},
				'WP Super Cache'   => function () {
					if ( function_exists( 'wp_cache_clear_cache' ) ) {
						wp_cache_clear_cache();
						return true;
					}
				},
				'SiteGround'       => function () {
					if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
						sg_cachepress_purge_cache();
						return true;
					}
				},
				'WP Fastest Cache' => function () {
					if ( isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
						$GLOBALS['wp_fastest_cache']->deleteCache( true );
						return true;
					}
				},
				'Breeze'           => function () {
					if ( class_exists( 'Breeze_PurgeCache' ) || defined( 'BREEZE_VERSION' ) ) {
						do_action( 'breeze_clear_all_cache' );
						return true;
					}
				},
				'Autoptimize'      => function () {
					if ( class_exists( 'autoptimizeCache' ) ) {
						\autoptimizeCache::clearall();
						return true;
					}
				},
				'Cache Enabler'    => function () {
					if ( class_exists( 'Cache_Enabler' ) ) {
						do_action( 'cache_enabler_clear_complete_cache' );
						return true;
					}
				},
				'Hummingbird'      => function () {
					if ( class_exists( 'Hummingbird\\WP_Hummingbird' ) || defined( 'WPHB_VERSION' ) ) {
						do_action( 'wphb_clear_page_cache' );
						return true;
					}
				},
				'Nginx Helper'     => function () {
					if ( isset( $GLOBALS['nginx_purger'] ) ) {
						do_action( 'rt_nginx_helper_purge_all' );
						return true;
					}
				},
			);
			foreach ( $page as $name => $fn ) {
				try {
					if ( $fn() ) {
						$done[] = $name;
					}
				} catch ( \Throwable $e ) {
					$done[] = $name . ' (failed: ' . $e->getMessage() . ')';
				}
			}
		}
		if ( in_array( $what, array( 'all', 'elementor' ), true ) && class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
			$done[] = 'Elementor CSS';
		}
		return array( 'cleared' => $done );
	}

	public static function flush_rewrites( array $args ) {
		flush_rewrite_rules( ! empty( $args['hard'] ) );
		return array( 'flushed' => true, 'structure' => get_option( 'permalink_structure' ) );
	}

	public static function list_cron( array $args ) {
		$crons     = _get_cron_array();
		$schedules = wp_get_schedules();
		$out       = array();
		foreach ( (array) $crons as $ts => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				if ( ! empty( $args['hook'] ) && $hook !== $args['hook'] ) {
					continue;
				}
				foreach ( (array) $events as $event ) {
					$out[] = array(
						'hook'      => $hook,
						'next_run'  => gmdate( 'Y-m-d H:i:s', $ts ) . ' UTC',
						'timestamp' => (int) $ts,
						'in'        => human_time_diff( time(), $ts ) . ( $ts < time() ? ' overdue' : '' ),
						'schedule'  => $event['schedule'] ? ( isset( $schedules[ $event['schedule'] ] ) ? $schedules[ $event['schedule'] ]['display'] : $event['schedule'] ) : 'once',
						'args'      => $event['args'],
					);
				}
			}
		}
		return array(
			'events'       => $out,
			'wp_cron'      => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'disabled (a system cron must call wp-cron.php)' : 'enabled',
		);
	}

	public static function guard_cron( array $args ) {
		if ( 0 === strpos( (string) $args['hook'], 'mcpress' ) ) {
			return H::error( 'self_protection', 'MCPress scheduled tasks are managed by MCPress.' );
		}
		return null;
	}

	public static function run_cron( array $args ) {
		$found = false;
		foreach ( (array) _get_cron_array() as $hooks ) {
			if ( isset( $hooks[ $args['hook'] ] ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return H::error( 'not_scheduled', 'That hook is not scheduled.', 'Only scheduled events can be run. Call list_cron_events.' );
		}
		$start = microtime( true );
		do_action_ref_array( $args['hook'], isset( $args['args'] ) ? (array) $args['args'] : array() );
		return array( 'ran' => $args['hook'], 'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ) );
	}

	public static function delete_cron( array $args ) {
		if ( ! empty( $args['timestamp'] ) ) {
			$ok = wp_unschedule_event( (int) $args['timestamp'], $args['hook'], isset( $args['args'] ) ? (array) $args['args'] : array(), true );
			return is_wp_error( $ok ) ? $ok : array( 'unscheduled' => $args['hook'], 'timestamp' => (int) $args['timestamp'] );
		}
		$n = wp_unschedule_hook( $args['hook'] );
		return array( 'unscheduled' => $args['hook'], 'events_removed' => is_int( $n ) ? $n : 0 );
	}

	public static function read_error_log( array $args ) {
		$candidates = array();
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			$candidates[] = WP_DEBUG_LOG;
		}
		$candidates[] = WP_CONTENT_DIR . '/debug.log';
		$ini          = ini_get( 'error_log' );
		if ( $ini ) {
			$candidates[] = $ini;
		}
		$file = null;
		foreach ( $candidates as $c ) {
			if ( is_readable( $c ) && is_file( $c ) ) {
				$file = $c;
				break;
			}
		}
		if ( ! $file ) {
			return array(
				'log'  => null,
				'hint' => 'No readable log. To start one, WP_DEBUG and WP_DEBUG_LOG must be true in wp-config.php (with WP_DEBUG_DISPLAY false). Ask the user, or edit wp-config.php with edit_file if this connection may.',
				'debug' => array( 'WP_DEBUG' => defined( 'WP_DEBUG' ) && WP_DEBUG, 'WP_DEBUG_LOG' => defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false ),
			);
		}
		$want  = (int) $args['lines'];
		$size  = filesize( $file );
		$fh    = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$chunk = min( $size, max( 65536, $want * 400 ) );
		fseek( $fh, -$chunk, SEEK_END );
		$data = (string) fread( $fh, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$lines = preg_split( '/\r?\n/', $data );
		if ( $chunk < $size ) {
			array_shift( $lines ); // First line is probably cut.
		}
		if ( ! empty( $args['filter'] ) ) {
			$lines = array_values( array_filter( $lines, function ( $l ) use ( $args ) {
				return false !== stripos( $l, $args['filter'] );
			} ) );
		}
		$lines = array_slice( array_filter( $lines, 'strlen' ), -$want );
		return array(
			'log'     => str_replace( ABSPATH, '', $file ),
			'size_kb' => (int) round( $size / 1024 ),
			'lines'   => array_map( array( 'MCPress\\Redactor', 'redact_text' ), array_values( $lines ) ),
		);
	}
}
