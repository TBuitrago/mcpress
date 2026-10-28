<?php
/**
 * Unit tests for the pure logic of MCPress (no WordPress, no database).
 *
 *   php tests/unit.php
 *
 * Covers: permission matrix and precedence, argument validation/coercion, secret masking,
 * serialization-safe replace, SQL read detection, REST/option/file risk rules, OAuth helpers,
 * PHP syntax check and the release-notes renderer.
 *
 * @package MCPress
 */

// phpcs:disable

define( 'ABSPATH', sys_get_temp_dir() . '/mcpress-unit/' );
define( 'MCPRESS_DIR', dirname( __DIR__ ) . '/' );
define( 'MCPRESS_VERSION', 'test' );
define( 'MCPRESS_URL', 'https://example.test/wp-content/plugins/mcpress/' );
define( 'MCPRESS_BASENAME', 'mcpress/mcpress.php' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
@mkdir( WP_CONTENT_DIR, 0777, true );

/* ------------------------------------------------------------- WordPress stubs */

$GLOBALS['mcpress_test_options'] = array();
$GLOBALS['wpdb']                 = (object) array( 'prefix' => 'wp_' );

function __( $s ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_kses_post( $s ) { return $s; }
function apply_filters( $tag, $value ) { return $value; }
function do_action() {}
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['mcpress_test_options'] ) ? $GLOBALS['mcpress_test_options'][ $name ] : $default; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_normalize_path( $p ) { return str_replace( '\\', '/', $p ); }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ); }
function is_serialized( $data ) {
	if ( ! is_string( $data ) ) { return false; }
	$data = trim( $data );
	if ( 'N;' === $data ) { return true; }
	if ( strlen( $data ) < 4 || ':' !== $data[1] ) { return false; }
	return (bool) preg_match( '/^([adObis]):/', $data ) && ( false !== @unserialize( $data ) || 'b:0;' === $data );
}

require MCPRESS_DIR . 'includes/class-autoloader.php';
MCPress\Autoloader::register();

use MCPress\Context;
use MCPress\MCP\Schema;
use MCPress\Permissions\Policy;
use MCPress\Redactor;

/* ------------------------------------------------------------------ Harness */

$GLOBALS['mcpress_test_fail'] = 0;
$GLOBALS['mcpress_test_pass'] = 0;
function check( $label, $cond ) {
	if ( $cond ) {
		$GLOBALS['mcpress_test_pass']++;
	} else {
		$GLOBALS['mcpress_test_fail']++;
		echo "FAIL: $label\n";
	}
}
function settings( array $s ) {
	$GLOBALS['mcpress_test_options']['mcpress_settings'] = $s;
	MCPress\Settings::flush_cache();
}
function ctx( $mode, $allowed = array() ) {
	$conn = array( 'id' => 7, 'user_id' => 1, 'mode' => $mode, 'name' => 'test', 'allowed_tools' => json_encode( $allowed ) );
	return new Context( $conn, 1, $mode, 'key' );
}
function tool( $name, $group = 'content', $risk = 'write' ) {
	return array( 'name' => $name, 'group' => $group, 'risk' => $risk, 'base_risk' => $risk );
}

/* ---------------------------------------------------------- Policy matrix */

settings( array() );
$expect = array(
	'readonly' => array( 'allow', 'deny', 'deny', 'deny' ),
	'approval' => array( 'allow', 'ask', 'ask', 'ask' ),
	'auto'     => array( 'allow', 'allow', 'allow', 'ask' ),
	'full'     => array( 'allow', 'allow', 'allow', 'allow' ),
);
foreach ( $expect as $mode => $decisions ) {
	foreach ( Policy::risks() as $i => $risk ) {
		$d = Policy::decide( tool( 'x' ), $risk, ctx( $mode ) )['decision'];
		check( "matrix $mode/$risk = {$decisions[$i]} (got $d)", $d === $decisions[ $i ] );
	}
}

// Precedence.
settings( array( 'tool_rules' => array( 'x' => 'deny' ) ) );
check( 'site deny beats full mode', 'deny' === Policy::decide( tool( 'x' ), 'write', ctx( 'full' ) )['decision'] );
settings( array( 'tool_rules' => array( 'x' => 'ask' ) ) );
check( 'site ask applies in full mode', 'ask' === Policy::decide( tool( 'x' ), 'write', ctx( 'full' ) )['decision'] );
check( 'site ask does not affect reads', 'allow' === Policy::decide( tool( 'x' ), 'read', ctx( 'full' ) )['decision'] );
settings( array( 'tool_rules' => array( 'x' => 'allow' ) ) );
check( 'site allow runs in approval mode', 'allow' === Policy::decide( tool( 'x' ), 'critical', ctx( 'approval' ) )['decision'] );
check( 'site allow cannot loosen read-only', 'deny' === Policy::decide( tool( 'x' ), 'write', ctx( 'readonly' ) )['decision'] );
settings( array() );
check( 'always-allowed tool runs in approval mode', 'allow' === Policy::decide( tool( 'x' ), 'write', ctx( 'approval', array( 'x' ) ) )['decision'] );
check( 'always-allowed does not loosen read-only', 'deny' === Policy::decide( tool( 'x' ), 'write', ctx( 'readonly', array( 'x' ) ) )['decision'] );
settings( array( 'disabled_groups' => array( 'files' ) ) );
check( 'disabled group is denied', 'deny' === Policy::decide( tool( 'write_file', 'files' ), 'read', ctx( 'full' ) )['decision'] );
settings( array() );

/* --------------------------------------------------------------- Schema */

$schema = array(
	'type'       => 'object',
	'properties' => array(
		'id'    => array( 'type' => 'integer', 'minimum' => 1 ),
		'force' => array( 'type' => 'boolean', 'default' => false ),
		'tags'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
		'mode'  => array( 'type' => 'string', 'enum' => array( 'a', 'b' ) ),
		'meta'  => array( 'type' => 'object' ),
	),
	'required'   => array( 'id' ),
);
list( $v, $e ) = Schema::validate( array( 'id' => '42', 'force' => 'true', 'tags' => 'solo' ), $schema );
check( 'coerces "42" to int', 42 === $v['id'] && ! $e );
check( 'coerces "true" to bool', true === $v['force'] );
check( 'wraps a single value into a list', array( 'solo' ) === $v['tags'] );
list( $v, $e ) = Schema::validate( array(), $schema );
check( 'required id reported', 1 === count( $e ) && false !== strpos( $e[0], 'id' ) );
list( $v, $e ) = Schema::validate( array( 'id' => 3 ), $schema );
check( 'default applied', false === $v['force'] );
list( $v, $e ) = Schema::validate( array( 'id' => 0, 'mode' => 'c' ), $schema );
check( 'minimum and enum errors', 2 === count( $e ) );
list( $v, $e ) = Schema::validate( array( 'id' => 1, 'meta' => '{"a":1}' ), $schema );
check( 'JSON string accepted for object', array( 'a' => 1 ) === $v['meta'] && ! $e );
list( $v, $e ) = Schema::validate( array( 'id' => 'abc' ), $schema );
check( 'non-numeric id rejected', 1 === count( $e ) );

/* --------------------------------------------------------------- Redactor */

settings( array( 'protect_sensitive' => true ) );
$r = Redactor::redact(
	array(
		'user_login' => 'admin',
		'user_pass'  => '$wp$2y$10$abcdefghijklmnopqrstuvwxyz0123456789',
		'stripe_secret_key' => 'sk_live_123',
		'api_key'    => 'abc',
		'meta_key'   => 'meta_key',
		'rows'       => array( array( 'option_name' => 'mailchimp_api_key', 'option_value' => 'xyz' ), array( 'option_name' => 'blogname', 'option_value' => 'Hi' ) ),
		'post_password' => 'visible',
		'hash_in_list'  => array( '$P$Babcdefghijklmnopqrstuvwxyz012' ),
		'empty_secret'  => '',
	)
);
check( 'user_pass masked', Redactor::MASK === $r['user_pass'] );
check( 'secret key masked', Redactor::MASK === $r['stripe_secret_key'] && Redactor::MASK === $r['api_key'] );
check( 'login kept', 'admin' === $r['user_login'] );
check( 'option row masked by name', Redactor::MASK === $r['rows'][0]['option_value'] && 'Hi' === $r['rows'][1]['option_value'] );
check( 'post_password not treated as secret', 'visible' === $r['post_password'] );
check( 'hash value masked anywhere', Redactor::MASK === $r['hash_in_list'][0] );
check( 'empty secret stays empty', '' === $r['empty_secret'] );
check( 'MCPress options always secret', Redactor::name_is_secret( 'mcpress_settings', false ) );
settings( array( 'protect_sensitive' => false ) );
$r = Redactor::redact( array( 'api_key' => 'abc', 'user_pass' => 'x' ) );
check( 'protection off shows api_key', 'abc' === $r['api_key'] );
check( 'protection off still masks user_pass', Redactor::MASK === $r['user_pass'] );
check( 'keys in free text masked', false === strpos( Redactor::redact_text( 'key mcpk_' . str_repeat( 'a', 48 ) . ' end' ), 'mcpk_' ) );
settings( array() );

/* ----------------------------------------------------- Serialized replace */

$pairs   = array( 'http://old.test' => 'https://new.example.com' );
$skipped = 0;
$ser     = serialize( array( 'url' => 'http://old.test/a', 'n' => 5, 'deep' => array( 'http://old.test' ) ) );
$out     = MCPress\Tools\Database::replace_value( $ser, $pairs, true, $skipped );
$back    = unserialize( $out );
check( 'serialized replace keeps data valid', is_array( $back ) && 'https://new.example.com/a' === $back['url'] && 'https://new.example.com' === $back['deep'][0] );
$obj = serialize( (object) array( 'u' => 'http://old.test' ) );
check( 'objects are skipped untouched', $obj === MCPress\Tools\Database::replace_value( $obj, $pairs, true, $skipped ) && 1 === $skipped );
check( 'plain string replace', 'go https://new.example.com' === MCPress\Tools\Database::replace_value( 'go http://old.test', $pairs, true, $skipped ) );
$double = serialize( serialize( array( 'x' => 'http://old.test' ) ) );
check( 'double-serialized replace', 'https://new.example.com' === unserialize( unserialize( MCPress\Tools\Database::replace_value( $double, $pairs, true, $skipped ) ) )['x'] );

/* ------------------------------------------------------------- SQL rules */

check( 'SELECT is read', MCPress\Tools\Database::is_read( 'SELECT * FROM wp_posts' ) );
check( 'commented SELECT is read', MCPress\Tools\Database::is_read( "/* hi */ SELECT 1" ) );
check( 'SHOW is read', MCPress\Tools\Database::is_read( 'show tables' ) );
check( 'UPDATE is write', ! MCPress\Tools\Database::is_read( 'UPDATE wp_posts SET x=1' ) );
check( 'SELECT INTO OUTFILE is write', ! MCPress\Tools\Database::is_read( "SELECT * FROM wp_users INTO OUTFILE '/tmp/x'" ) );
check( 'SLEEP() not a plain read', ! MCPress\Tools\Database::is_read( 'SELECT SLEEP(100)' ) );
check( 'CTE with DELETE is write', ! MCPress\Tools\Database::is_read( 'WITH a AS (SELECT 1) DELETE FROM wp_posts' ) );
check( 'sql risk read/critical', 'read' === MCPress\Tools\Database::query_risk( array( 'sql' => 'SELECT 1' ) ) && 'critical' === MCPress\Tools\Database::query_risk( array( 'sql' => 'DELETE FROM {prefix}posts' ) ) );
$g = MCPress\Tools\Database::guard_query( array( 'sql' => "SELECT 1; DELETE FROM wp_posts" ) );
check( 'second statement refused', $g instanceof WP_Error );
$g = MCPress\Tools\Database::guard_query( array( 'sql' => "SELECT ';' FROM wp_posts" ) );
check( 'semicolon inside a string is fine', null === $g );
$g = MCPress\Tools\Database::guard_query( array( 'sql' => 'SELECT * FROM {prefix}mcpress_tokens' ) );
check( 'MCPress tables refused', $g instanceof WP_Error );
check( 'search_replace dry run is read', 'read' === MCPress\Tools\Database::replace_risk( array() ) && 'critical' === MCPress\Tools\Database::replace_risk( array( 'dry_run' => false ) ) );

/* ------------------------------------------------------------- REST risk */

$rest = 'MCPress\\Tools\\Rest';
check( 'GET is read', 'read' === $rest::risk( array( 'method' => 'GET', 'route' => '/wp/v2/users' ) ) );
check( 'POST post is write', 'write' === $rest::risk( array( 'method' => 'POST', 'route' => '/wp/v2/posts' ) ) );
check( 'POST users is critical', 'critical' === $rest::risk( array( 'method' => 'POST', 'route' => '/wp/v2/users' ) ) );
check( 'DELETE is destructive', 'destructive' === $rest::risk( array( 'method' => 'DELETE', 'route' => '/wp/v2/posts/5' ) ) );
check( 'DELETE force is critical', 'critical' === $rest::risk( array( 'method' => 'DELETE', 'route' => '/wp/v2/posts/5', 'params' => array( 'force' => true ) ) ) );
check( 'Woo payment gateways critical', 'critical' === $rest::risk( array( 'method' => 'PUT', 'route' => '/wc/v3/payment_gateways/stripe' ) ) );
check( 'wp-json prefix normalised', 'critical' === $rest::risk( array( 'method' => 'POST', 'route' => '/wp-json/wp/v2/plugins' ) ) );
check( 'MCPress route refused', $rest::guard( array( 'method' => 'GET', 'route' => '/mcpress/v1/oauth/metadata' ) ) instanceof WP_Error );

/* ---------------------------------------------------------- Options risk */

$opt = 'MCPress\\Tools\\Options';
check( 'siteurl critical', $opt::is_critical( 'siteurl' ) );
check( 'user_roles critical', $opt::is_critical( 'wp_user_roles' ) );
check( 'gateway settings critical', $opt::is_critical( 'woocommerce_stripe_settings' ) );
check( 'blogname not critical', ! $opt::is_critical( 'blogname' ) );
check( 'MCPress option guarded', $opt::guard( array( 'name' => 'mcpress_settings' ) ) instanceof WP_Error );

/* ---------------------------------------------------------------- Others */

check( 'delete_post trash write / force critical', 'write' === MCPress\Tools\Content::delete_risk( array() ) && 'critical' === MCPress\Tools\Content::delete_risk( array( 'force' => true ) ) );
check( 'role/email/password change critical', 'critical' === MCPress\Tools\Users::update_risk( array( 'email' => 'a@b.c' ) ) && 'write' === MCPress\Tools\Users::update_risk( array( 'first_name' => 'A' ) ) );
check( 'PHP syntax error detected', null !== MCPress\Tools\Files::php_syntax_error( '<?php function ( {' ) );
check( 'valid PHP accepted', null === MCPress\Tools\Files::php_syntax_error( '<?php echo 1;' ) );
check( 'run_php refuses MCPress code', MCPress\Tools\Code::guard( array( 'code' => 'update_option("MCPRESS_settings", 1);' ) ) instanceof WP_Error );

$oauth = 'MCPress\\Auth\\OAuth';
check( 'https redirect ok', $oauth::valid_redirect_uri( 'https://claude.ai/api/mcp/auth_callback' ) );
check( 'loopback http ok', $oauth::valid_redirect_uri( 'http://127.0.0.1:33418/callback' ) );
check( 'custom scheme ok', $oauth::valid_redirect_uri( 'cursor://anysphere.cursor-retrieval/oauth/callback' ) );
check( 'public http refused', ! $oauth::valid_redirect_uri( 'http://evil.example.com/cb' ) );
check( 'javascript: refused', ! $oauth::valid_redirect_uri( 'javascript:alert(1)' ) );
check( 'fragment refused', ! $oauth::valid_redirect_uri( 'https://a.test/cb#x' ) );
check( 'PKCE S256 (RFC 7636 vector)', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM' === $oauth::pkce_challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' ) );

$html = MCPress\Updater::markdown_to_html( "## Changes\n- **Bold** item\n- `code`\n\nText [link](https://x.test)" );
check( 'release notes markdown', false !== strpos( $html, '<h4>Changes</h4>' ) && false !== strpos( $html, '<li><strong>Bold</strong> item</li>' ) && false !== strpos( $html, '<a href="https://x.test">link</a>' ) );

check( 'token prefixes', 'key' === MCPress\Auth\Tokens::kind_of( 'mcpk_abc' ) && 'refresh' === MCPress\Auth\Tokens::kind_of( 'mcpr_abc' ) && null === MCPress\Auth\Tokens::kind_of( 'jwt.abc' ) );

echo sprintf( "%d passed, %d failed\n", $GLOBALS['mcpress_test_pass'], $GLOBALS['mcpress_test_fail'] );
exit( $GLOBALS['mcpress_test_fail'] ? 1 : 0 );

/** Minimal WP_Error for guards. */
class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
}
