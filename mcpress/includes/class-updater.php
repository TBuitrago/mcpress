<?php
/**
 * Updates from GitHub Releases.
 *
 * The plugin header "Update URI: https://github.com/<owner>/<repo>" makes WordPress ask the
 * update_plugins_github.com filter (WP 5.8+) instead of WordPress.org, so a plugin with the same
 * slug on WordPress.org can never overwrite this one.
 *
 * Package: the release asset named mcpress.zip (built by .github/workflows/release.yml);
 * fallback: the source zipball, from which the folder that contains mcpress.php is extracted.
 *
 * @package MCPress
 */

namespace MCPress;

defined( 'ABSPATH' ) || exit;

class Updater {

	const CACHE_KEY = 'mcpress_update_release';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;
	const ASSET     = 'mcpress.zip';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'pre_download' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_folder' ), 10, 4 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 20, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 10, 2 );
	}

	public static function repo() {
		$repo = defined( 'MCPRESS_GITHUB_REPO' ) ? MCPRESS_GITHUB_REPO : 'TBuitrago/mcpress';
		$repo = (string) apply_filters( 'mcpress_github_repo', $repo );
		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : 'TBuitrago/mcpress';
	}

	/**
	 * Hooked to update_plugins_github.com.
	 *
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file, $locales ) {
		if ( MCPRESS_BASENAME !== $plugin_file ) {
			return $update;
		}
		$release = self::latest_release();
		if ( ! $release ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::repo(),
			'slug'         => 'mcpress',
			'plugin'       => MCPRESS_BASENAME,
			'version'      => $release['version'],
			'new_version'  => $release['version'],
			'url'          => $release['html_url'],
			'package'      => $release['package'],
			'tested'       => $release['tested'],
			'requires_php' => '7.4',
			'icons'        => array(
				'svg' => MCPRESS_URL . 'assets/img/symbol-light.svg',
			),
		);
	}

	/**
	 * The newest usable release (cached).
	 *
	 * @param bool $force Skip the cache.
	 * @return array|null version, package, html_url, body, published, digest, tested
	 */
	public static function latest_release( $force = false ) {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( ! $force && is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$beta = 'beta' === Settings::get( 'update_channel' );
		$url  = 'https://api.github.com/repos/' . self::repo() . ( $beta ? '/releases?per_page=10' : '/releases/latest' );
		$resp = wp_remote_get( $url, array( 'timeout' => 10, 'headers' => self::headers( 'application/vnd.github+json' ) ) );

		$release = null;
		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( $beta && is_array( $data ) ) {
				foreach ( $data as $candidate ) {
					if ( empty( $candidate['draft'] ) ) {
						$data = $candidate;
						break;
					}
				}
			}
			$release = self::normalize( is_array( $data ) ? $data : array() );
		}

		$error = is_wp_error( $resp ) ? $resp->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $resp );
		if ( ! is_wp_error( $resp ) && 404 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			$error = self::token()
				? __( 'HTTP 404: no published release yet, or the token cannot see the repository.', 'mcpress' )
				: __( 'HTTP 404: the repository is private or has no published release yet. Publish a release, or define MCPRESS_GITHUB_TOKEN for a private repository.', 'mcpress' );
		}
		update_option(
			'mcpress_update_status',
			array(
				'checked' => Util::now(),
				'ok'      => (bool) $release,
				'error'   => $release ? '' : $error,
				'version' => $release ? $release['version'] : '',
			),
			false
		);

		// Cache failures for one hour so a GitHub outage does not slow wp-admin down.
		set_site_transient( self::CACHE_KEY, $release ? $release : array( 'version' => '' ), $release ? self::CACHE_TTL : HOUR_IN_SECONDS );
		return $release;
	}

	private static function normalize( array $r ) {
		if ( empty( $r['tag_name'] ) ) {
			return null;
		}
		$version = ltrim( (string) $r['tag_name'], 'vV' );
		if ( ! preg_match( '/^\d+(\.\d+){0,3}([-.+][0-9A-Za-z.-]+)?$/', $version ) ) {
			return null;
		}
		$package = '';
		$digest  = '';
		foreach ( (array) ( isset( $r['assets'] ) ? $r['assets'] : array() ) as $asset ) {
			if ( isset( $asset['name'] ) && self::ASSET === $asset['name'] ) {
				$package = (string) $asset['browser_download_url'];
				$digest  = isset( $asset['digest'] ) ? (string) $asset['digest'] : '';
				if ( self::token() && ! empty( $asset['url'] ) ) {
					$package = (string) $asset['url']; // Private repos: the API URL works with a token.
				}
				break;
			}
		}
		if ( ! $package && ! empty( $r['zipball_url'] ) ) {
			$package = (string) $r['zipball_url'];
		}
		if ( ! $package ) {
			return null;
		}
		$body = isset( $r['body'] ) ? (string) $r['body'] : '';
		$tested = '';
		if ( preg_match( '/Tested up to:\s*([0-9.]+)/i', $body, $m ) ) {
			$tested = $m[1];
		}
		return array(
			'version'   => $version,
			'package'   => $package,
			'digest'    => $digest,
			'html_url'  => isset( $r['html_url'] ) ? (string) $r['html_url'] : 'https://github.com/' . self::repo(),
			'body'      => $body,
			'published' => isset( $r['published_at'] ) ? (string) $r['published_at'] : '',
			'tested'    => $tested ? $tested : get_bloginfo( 'version' ),
			'is_asset'  => false === strpos( $package, '/zipball/' ),
		);
	}

	/** "View details" modal in the plugins screen. */
	public static function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'mcpress' !== $args->slug ) {
			return $result;
		}
		$release = self::latest_release();
		$info    = array(
			'name'          => 'MCPress',
			'slug'          => 'mcpress',
			'version'       => $release ? $release['version'] : MCPRESS_VERSION,
			'author'        => '<a href="https://github.com/TBuitrago/">Tomas Buitrago</a>',
			'homepage'      => 'https://github.com/TBuitrago/mcpress',
			'requires'      => '6.3',
			'requires_php'  => '7.4',
			'tested'        => $release ? $release['tested'] : get_bloginfo( 'version' ),
			'last_updated'  => $release ? $release['published'] : '',
			'download_link' => $release ? $release['package'] : '',
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Connect your AI to WordPress over MCP. Your AI runs WordPress; you set the rules: prior approval or fully automatic.', 'mcpress' ) . '</p>',
				'changelog'   => $release ? self::markdown_to_html( $release['body'] ) : '',
			),
			'banners'       => array(),
		);
		return (object) $info;
	}

	/**
	 * Download our package ourselves: adds the GitHub token for private repos and verifies the
	 * SHA-256 digest GitHub publishes for release assets.
	 */
	public static function pre_download( $reply, $package, $upgrader, $hook_extra = array() ) {
		if ( false !== $reply || ! is_string( $package ) || ! self::is_our_package( $package ) ) {
			return $reply;
		}
		$release = self::latest_release();
		$tmp     = wp_tempnam( 'mcpress.zip' );
		$resp    = wp_remote_get(
			$package,
			array(
				'timeout'  => 120,
				'stream'   => true,
				'filename' => $tmp,
				'headers'  => self::headers( false !== strpos( $package, 'api.github.com' ) && false !== strpos( $package, '/assets/' ) ? 'application/octet-stream' : null ),
			)
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'mcpress_download_failed', __( 'Could not download the MCPress update from GitHub.', 'mcpress' ) );
		}
		if ( $release && $release['digest'] && 0 === strpos( $release['digest'], 'sha256:' ) && $release['package'] === $package ) {
			if ( ! hash_equals( substr( $release['digest'], 7 ), hash_file( 'sha256', $tmp ) ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return new \WP_Error( 'mcpress_digest_mismatch', __( 'The MCPress update did not match its published SHA-256 checksum and was not installed.', 'mcpress' ) );
			}
		}
		return $tmp;
	}

	private static function is_our_package( $package ) {
		$repo = strtolower( self::repo() );
		$p    = strtolower( $package );
		return false !== strpos( $p, 'github.com/' . $repo . '/' ) || false !== strpos( $p, 'api.github.com/repos/' . $repo . '/' ) || false !== strpos( $p, 'codeload.github.com/' . $repo . '/' );
	}

	/**
	 * Make sure the extracted folder is called "mcpress". The zipball has a folder like
	 * Owner-mcpress-abc123/ with the plugin inside mcpress/; a release asset already has mcpress/.
	 */
	public static function fix_source_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || MCPRESS_BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}
		global $wp_filesystem;
		$source = trailingslashit( $source );
		if ( $wp_filesystem->exists( $source . 'mcpress.php' ) ) {
			$found = $source;
		} elseif ( $wp_filesystem->exists( $source . 'mcpress/mcpress.php' ) ) {
			$found = $source . 'mcpress/';
		} else {
			return new \WP_Error( 'mcpress_bad_package', __( 'The downloaded package does not contain mcpress.php.', 'mcpress' ) );
		}
		$target = trailingslashit( $remote_source ) . 'mcpress/';
		if ( untrailingslashit( $found ) === untrailingslashit( $target ) ) {
			return $target;
		}
		if ( untrailingslashit( $found ) === untrailingslashit( $remote_source ) ) {
			return new \WP_Error( 'mcpress_bad_package', __( 'The MCPress package must contain a single "mcpress" folder.', 'mcpress' ) );
		}
		// Zipball: remote_source/Owner-repo-sha/mcpress/ → remote_source/mcpress/.
		if ( $wp_filesystem->exists( $target ) ) {
			$wp_filesystem->delete( $target, true );
		}
		if ( ! $wp_filesystem->move( $found, $target ) ) {
			return new \WP_Error( 'mcpress_move_failed', __( 'Could not prepare the MCPress update folder.', 'mcpress' ) );
		}
		return $target;
	}

	/** WordPress background auto-updates for MCPress (on by default; switch in settings). */
	public static function auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && MCPRESS_BASENAME === $item->plugin ) {
			return (bool) Settings::get( 'auto_update' );
		}
		return $update;
	}

	public static function after_upgrade( $upgrader, $options ) {
		if ( isset( $options['type'], $options['plugins'] ) && 'plugin' === $options['type'] && in_array( MCPRESS_BASENAME, (array) $options['plugins'], true ) ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}

	/** Force a fresh check (button in settings). */
	public static function check_now() {
		delete_site_transient( self::CACHE_KEY );
		$release = self::latest_release( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		return $release;
	}

	public static function status() {
		$s = get_option( 'mcpress_update_status', array() );
		return is_array( $s ) ? $s : array();
	}

	private static function token() {
		return defined( 'MCPRESS_GITHUB_TOKEN' ) ? (string) MCPRESS_GITHUB_TOKEN : '';
	}

	private static function headers( $accept = null ) {
		$h = array( 'User-Agent' => 'MCPress/' . MCPRESS_VERSION . '; ' . home_url() );
		if ( $accept ) {
			$h['Accept'] = $accept;
		}
		if ( self::token() ) {
			$h['Authorization'] = 'Bearer ' . self::token();
		}
		return $h;
	}

	/** Enough Markdown for release notes: headings, lists, bold, code, links. */
	public static function markdown_to_html( $md ) {
		$lines = preg_split( '/\r?\n/', esc_html( (string) $md ) );
		$html  = '';
		$in_ul = false;
		foreach ( $lines as $line ) {
			$line = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $line );
			$line = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $line );
			$line = preg_replace( '/\[([^\]]+)\]\((https?:[^)\s]+)\)/', '<a href="$2">$1</a>', $line );
			if ( preg_match( '/^\s*[-*]\s+(.*)$/', $line, $m ) ) {
				if ( ! $in_ul ) {
					$html .= '<ul>';
					$in_ul = true;
				}
				$html .= '<li>' . $m[1] . '</li>';
				continue;
			}
			if ( $in_ul ) {
				$html .= '</ul>';
				$in_ul = false;
			}
			if ( preg_match( '/^(#{1,4})\s+(.*)$/', $line, $m ) ) {
				$level = min( 4, strlen( $m[1] ) + 2 );
				$html .= "<h$level>" . $m[2] . "</h$level>";
			} elseif ( '' !== trim( $line ) ) {
				$html .= '<p>' . $line . '</p>';
			}
		}
		if ( $in_ul ) {
			$html .= '</ul>';
		}
		return wp_kses_post( $html );
	}
}
