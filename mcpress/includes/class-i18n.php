<?php
/**
 * Translations. Every Spanish locale (es_CO, es_ES, es_MX…) uses the bundled Spanish file.
 *
 * @package MCPress
 */

namespace MCPress;

defined( 'ABSPATH' ) || exit;

class I18n {

	public static function init() {
		add_filter( 'load_textdomain_mofile', array( __CLASS__, 'spanish_fallback' ), 10, 2 );
		add_filter( 'load_translation_file', array( __CLASS__, 'spanish_fallback' ), 10, 2 );
		load_plugin_textdomain( 'mcpress', false, dirname( MCPRESS_BASENAME ) . '/languages' );
	}

	/**
	 * Map languages/mcpress-es_XX.(mo|l10n.php) to languages/mcpress-es.* when the exact file is missing.
	 */
	public static function spanish_fallback( $file, $domain ) {
		if ( 'mcpress' !== $domain || is_readable( $file ) ) {
			return $file;
		}
		if ( preg_match( '#mcpress-(es_[A-Z]{2}(?:_[a-z]+)?)\.(mo|l10n\.php)$#', $file, $m ) ) {
			$candidate = MCPRESS_DIR . 'languages/mcpress-es.' . $m[2];
			if ( is_readable( $candidate ) ) {
				return $candidate;
			}
		}
		return $file;
	}
}
