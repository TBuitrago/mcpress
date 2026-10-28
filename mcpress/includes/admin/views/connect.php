<?php
/**
 * Connect screen: endpoint, per-client instructions, API keys and connections.
 *
 * @package MCPress
 */

use MCPress\Admin\Admin;
use MCPress\Auth\Connections;
use MCPress\Permissions\Policy;
use MCPress\Settings;
use MCPress\Util;

defined( 'ABSPATH' ) || exit;

$mcpress_url     = Util::mcp_url();
$mcpress_new     = get_transient( 'mcpress_new_key_' . get_current_user_id() );
$mcpress_key     = $mcpress_new ? $mcpress_new['key'] : '';
$mcpress_shown   = $mcpress_key ? $mcpress_key : '<YOUR_MCPRESS_KEY>';
$mcpress_oauth   = (bool) Settings::get( 'oauth_enabled' );
$mcpress_https   = 0 === strpos( $mcpress_url, 'https://' );
$mcpress_plain   = false !== strpos( $mcpress_url, '?rest_route=' );
$mcpress_conns   = Connections::all();
$mcpress_default = Settings::get( 'default_mode' );

if ( $mcpress_new ) {
	delete_transient( 'mcpress_new_key_' . get_current_user_id() );
}

$mcpress_json = static function ( $data ) {
	return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
};

$mcpress_clients = array(
	'claude'     => array(
		'label' => 'Claude',
		'oauth' => true,
		'steps' => array(
			__( 'In claude.ai or Claude Desktop open Settings → Connectors → Add custom connector.', 'mcpress' ),
			__( 'Paste the MCP URL and click Add, then Connect.', 'mcpress' ),
			__( 'You will land on this site: log in, pick the permission mode and click "Connect AI".', 'mcpress' ),
		),
		'code'  => $mcpress_url,
	),
	'chatgpt'    => array(
		'label' => 'ChatGPT',
		'oauth' => true,
		'steps' => array(
			__( 'In ChatGPT open Settings → Apps & Connectors → Advanced settings and turn on Developer mode.', 'mcpress' ),
			__( 'Create a connector, paste the MCP URL and choose OAuth as authentication.', 'mcpress' ),
			__( 'You will land on this site: log in, pick the permission mode and click "Connect AI".', 'mcpress' ),
		),
		'code'  => $mcpress_url,
	),
	'claudecode' => array(
		'label' => 'Claude Code',
		'oauth' => false,
		'steps' => array(
			__( 'Create an API key below, then run this in your terminal:', 'mcpress' ),
		),
		'code'  => 'claude mcp add --transport http mcpress ' . $mcpress_url . ' --header "Authorization: Bearer ' . $mcpress_shown . '"',
	),
	'cursor'     => array(
		'label' => 'Cursor',
		'oauth' => false,
		'steps' => array(
			__( 'Create an API key below and add this to ~/.cursor/mcp.json (or .cursor/mcp.json in a project):', 'mcpress' ),
		),
		'code'  => $mcpress_json(
			array(
				'mcpServers' => array(
					'mcpress' => array(
						'url'     => $mcpress_url,
						'headers' => array( 'Authorization' => 'Bearer ' . $mcpress_shown ),
					),
				),
			)
		),
	),
	'vscode'     => array(
		'label' => 'VS Code',
		'oauth' => false,
		'steps' => array(
			__( 'Create an API key below and add this to .vscode/mcp.json:', 'mcpress' ),
		),
		'code'  => $mcpress_json(
			array(
				'servers' => array(
					'mcpress' => array(
						'type'    => 'http',
						'url'     => $mcpress_url,
						'headers' => array( 'Authorization' => 'Bearer ' . $mcpress_shown ),
					),
				),
			)
		),
	),
	'windsurf'   => array(
		'label' => 'Windsurf',
		'oauth' => false,
		'steps' => array(
			__( 'Create an API key below and add this to ~/.codeium/windsurf/mcp_config.json:', 'mcpress' ),
		),
		'code'  => $mcpress_json(
			array(
				'mcpServers' => array(
					'mcpress' => array(
						'serverUrl' => $mcpress_url,
						'headers'   => array( 'Authorization' => 'Bearer ' . $mcpress_shown ),
					),
				),
			)
		),
	),
	'gemini'     => array(
		'label' => 'Gemini CLI',
		'oauth' => false,
		'steps' => array(
			__( 'Create an API key below and add this to ~/.gemini/settings.json:', 'mcpress' ),
		),
		'code'  => $mcpress_json(
			array(
				'mcpServers' => array(
					'mcpress' => array(
						'httpUrl' => $mcpress_url,
						'headers' => array( 'Authorization' => 'Bearer ' . $mcpress_shown ),
					),
				),
			)
		),
	),
	'other'      => array(
		'label' => __( 'Other', 'mcpress' ),
		'oauth' => false,
		'steps' => array(
			__( 'Any client that speaks Streamable HTTP: use the MCP URL and the header "Authorization: Bearer <key>". If your host strips that header, send "X-MCPress-Key: <key>" instead.', 'mcpress' ),
			__( 'Clients that only run local (stdio) servers can use the mcp-remote bridge, for example in claude_desktop_config.json:', 'mcpress' ),
		),
		'code'  => $mcpress_json(
			array(
				'mcpServers' => array(
					'mcpress' => array(
						'command' => 'npx',
						'args'    => array( '-y', 'mcp-remote', $mcpress_url, '--header', 'Authorization:Bearer ' . $mcpress_shown ),
					),
				),
			)
		),
	),
);
?>

<?php if ( ! $mcpress_https ) : ?>
	<div class="notice notice-warning"><p><?php esc_html_e( 'This site is not served over HTTPS. Your key would travel in clear text: enable HTTPS before connecting an AI on a public network. Claude and ChatGPT only accept https:// URLs.', 'mcpress' ); ?></p></div>
<?php endif; ?>
<?php if ( $mcpress_plain ) : ?>
	<div class="notice notice-warning"><p><?php esc_html_e( 'Your permalinks are set to "Plain". API keys work, but OAuth connections (Claude, ChatGPT) need pretty permalinks: Settings → Permalinks.', 'mcpress' ); ?></p></div>
<?php endif; ?>

<?php if ( $mcpress_key ) : ?>
	<section class="mcp-card mcp-card--signal" id="mcp-new-key">
		<p class="mcp-eyebrow">■ <?php esc_html_e( 'Your new key', 'mcpress' ); ?></p>
		<h2><?php esc_html_e( 'Copy it now: it will not be shown again', 'mcpress' ); ?></h2>
		<div class="mcp-copy">
			<code class="mcp-copy__value" data-mcp-copy-source><?php echo esc_html( $mcpress_key ); ?></code>
			<button type="button" class="mcp-btn-ghost" data-mcp-copy><?php esc_html_e( 'Copy', 'mcpress' ); ?></button>
		</div>
		<?php if ( ! empty( $mcpress_new['url_key'] ) ) : ?>
			<p class="mcp-muted"><?php esc_html_e( 'URL with the key inside (for clients that only accept a URL). Anyone with this URL controls the connection:', 'mcpress' ); ?></p>
			<div class="mcp-copy">
				<code class="mcp-copy__value" data-mcp-copy-source><?php echo esc_html( trailingslashit( $mcpress_url ) . 'k/' . $mcpress_key ); ?></code>
				<button type="button" class="mcp-btn-ghost" data-mcp-copy><?php esc_html_e( 'Copy', 'mcpress' ); ?></button>
			</div>
		<?php endif; ?>
		<p class="mcp-muted"><?php esc_html_e( 'The instructions below already include it.', 'mcpress' ); ?></p>
	</section>
<?php endif; ?>

<section class="mcp-card">
	<p class="mcp-eyebrow">■ <?php esc_html_e( 'MCP URL of this site', 'mcpress' ); ?></p>
	<div class="mcp-copy mcp-copy--big">
		<code class="mcp-copy__value" data-mcp-copy-source><?php echo esc_html( $mcpress_url ); ?></code>
		<button type="button" class="mcp-key-btn" data-mcp-copy><?php esc_html_e( 'Copy URL', 'mcpress' ); ?></button>
	</div>
	<p class="mcp-muted">
		<?php
		/* translators: %s: mode label. */
		printf( esc_html__( 'New connections start in %s. You choose the mode for each AI, and you can change it any time.', 'mcpress' ), wp_kses_post( Admin::mode_badge( $mcpress_default ) ) );
		?>
	</p>

	<div class="mcp-clients" data-mcp-tabs>
		<div class="mcp-clients__tabs" role="tablist">
			<?php $mcpress_first = true; ?>
			<?php foreach ( $mcpress_clients as $mcpress_slug => $mcpress_c ) : ?>
				<?php
				if ( $mcpress_c['oauth'] && ! $mcpress_oauth ) {
					continue;
				}
				?>
				<button type="button" role="tab" class="mcp-clients__tab<?php echo $mcpress_first ? ' is-active' : ''; ?>" aria-selected="<?php echo $mcpress_first ? 'true' : 'false'; ?>" data-mcp-tab="<?php echo esc_attr( $mcpress_slug ); ?>"><?php echo esc_html( $mcpress_c['label'] ); ?></button>
				<?php $mcpress_first = false; ?>
			<?php endforeach; ?>
		</div>
		<?php $mcpress_first = true; ?>
		<?php foreach ( $mcpress_clients as $mcpress_slug => $mcpress_c ) : ?>
			<?php
			if ( $mcpress_c['oauth'] && ! $mcpress_oauth ) {
				continue;
			}
			?>
			<div class="mcp-clients__panel" role="tabpanel" data-mcp-panel="<?php echo esc_attr( $mcpress_slug ); ?>"<?php echo $mcpress_first ? '' : ' hidden'; ?>>
				<ol>
					<?php foreach ( $mcpress_c['steps'] as $mcpress_step ) : ?>
						<li><?php echo esc_html( $mcpress_step ); ?></li>
					<?php endforeach; ?>
				</ol>
				<div class="mcp-code">
					<pre data-mcp-copy-source><?php echo esc_html( $mcpress_c['code'] ); ?></pre>
					<button type="button" class="mcp-btn-ghost mcp-code__copy" data-mcp-copy><?php esc_html_e( 'Copy', 'mcpress' ); ?></button>
				</div>
				<?php if ( $mcpress_c['oauth'] ) : ?>
					<p class="mcp-muted"><?php esc_html_e( 'No key needed: the connection is authorised with your WordPress login (OAuth).', 'mcpress' ); ?></p>
				<?php endif; ?>
			</div>
			<?php $mcpress_first = false; ?>
		<?php endforeach; ?>
	</div>
</section>

<section class="mcp-card">
	<h2><?php esc_html_e( 'Create an API key', 'mcpress' ); ?></h2>
	<p class="mcp-muted"><?php esc_html_e( 'For Claude Code, Cursor, VS Code and other clients that accept a header. Each key is a separate connection with its own mode.', 'mcpress' ); ?></p>
	<?php Admin::form_open( 'create_key', 'mcp-form-grid' ); ?>
		<label>
			<span><?php esc_html_e( 'Name', 'mcpress' ); ?></span>
			<input type="text" name="name" placeholder="<?php esc_attr_e( 'e.g. Claude Code on my laptop', 'mcpress' ); ?>" maxlength="120" required>
		</label>
		<label>
			<span><?php esc_html_e( 'Mode', 'mcpress' ); ?></span>
			<select name="mode" data-mcp-mode-select>
				<?php foreach ( Policy::modes() as $mcpress_mode ) : ?>
					<option value="<?php echo esc_attr( $mcpress_mode ); ?>" <?php selected( $mcpress_default, $mcpress_mode ); ?>><?php echo esc_html( Policy::mode_label( $mcpress_mode ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label>
			<span><?php esc_html_e( 'Expires', 'mcpress' ); ?></span>
			<select name="expires_days">
				<option value="0"><?php esc_html_e( 'Never', 'mcpress' ); ?></option>
				<option value="1"><?php esc_html_e( 'In 1 day', 'mcpress' ); ?></option>
				<option value="7"><?php esc_html_e( 'In 7 days', 'mcpress' ); ?></option>
				<option value="30"><?php esc_html_e( 'In 30 days', 'mcpress' ); ?></option>
				<option value="90"><?php esc_html_e( 'In 90 days', 'mcpress' ); ?></option>
				<option value="365"><?php esc_html_e( 'In 1 year', 'mcpress' ); ?></option>
			</select>
		</label>
		<?php if ( Settings::get( 'allow_url_keys' ) ) : ?>
			<label class="mcp-check">
				<input type="checkbox" name="url_key" value="1">
				<span><?php esc_html_e( 'Also allow this key inside the URL', 'mcpress' ); ?></span>
			</label>
		<?php endif; ?>
		<div><button type="submit" class="mcp-key-btn"><?php esc_html_e( 'Create key', 'mcpress' ); ?></button></div>
	</form>
	<dl class="mcp-modes-legend">
		<?php foreach ( Policy::modes() as $mcpress_mode ) : ?>
			<div><dt><?php echo wp_kses_post( Admin::mode_badge( $mcpress_mode ) ); ?></dt><dd><?php echo esc_html( Policy::mode_description( $mcpress_mode ) ); ?></dd></div>
		<?php endforeach; ?>
	</dl>
</section>

<section class="mcp-card">
	<h2><?php esc_html_e( 'Connected AIs', 'mcpress' ); ?></h2>
	<?php if ( ! $mcpress_conns ) : ?>
		<p class="mcp-empty"><?php esc_html_e( 'No AI is connected yet. Follow the steps above.', 'mcpress' ); ?></p>
	<?php else : ?>
		<div class="mcp-table-wrap">
		<table class="mcp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Connection', 'mcpress' ); ?></th>
					<th><?php esc_html_e( 'Mode', 'mcpress' ); ?></th>
					<th><?php esc_html_e( 'Acts as', 'mcpress' ); ?></th>
					<th><?php esc_html_e( 'Last used', 'mcpress' ); ?></th>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'mcpress' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $mcpress_conns as $mcpress_conn ) : ?>
				<?php
				$mcpress_user    = get_userdata( (int) $mcpress_conn['user_id'] );
				$mcpress_expired = ! Connections::is_active( $mcpress_conn );
				$mcpress_tools   = Util::json_decode_array( $mcpress_conn['allowed_tools'] );
				?>
				<tr<?php echo ! empty( $_GET['created'] ) && (int) $_GET['created'] === (int) $mcpress_conn['id'] ? ' class="is-new"' : ''; // phpcs:ignore WordPress.Security.NonceVerification ?>>
					<td>
						<strong><?php echo esc_html( $mcpress_conn['name'] ); ?></strong>
						<div class="mcp-muted mcp-small">
							<?php echo 'oauth' === $mcpress_conn['type'] ? esc_html__( 'OAuth', 'mcpress' ) : esc_html__( 'API key', 'mcpress' ) . ' <code>' . esc_html( $mcpress_conn['key_prefix'] ) . '…</code>'; ?>
							· <?php echo esc_html( Util::local( $mcpress_conn['created_at'], get_option( 'date_format' ) ) ); ?>
							<?php if ( $mcpress_conn['expires_at'] ) : ?>
								· <?php echo $mcpress_expired ? esc_html__( 'expired', 'mcpress' ) : esc_html( sprintf( /* translators: %s: date. */ __( 'expires %s', 'mcpress' ), Util::local( $mcpress_conn['expires_at'], get_option( 'date_format' ) ) ) ); ?>
							<?php endif; ?>
						</div>
						<?php if ( $mcpress_tools ) : ?>
							<div class="mcp-small mcp-always">
								<?php esc_html_e( 'Always allowed:', 'mcpress' ); ?>
								<?php foreach ( $mcpress_tools as $mcpress_tool ) : ?>
									<?php Admin::form_open( 'update_connection', 'mcp-inline' ); ?>
										<input type="hidden" name="connection_id" value="<?php echo esc_attr( $mcpress_conn['id'] ); ?>">
										<input type="hidden" name="disallow_tool" value="<?php echo esc_attr( $mcpress_tool ); ?>">
										<button type="submit" class="mcp-chip" title="<?php esc_attr_e( 'Stop allowing without asking', 'mcpress' ); ?>"><code><?php echo esc_html( $mcpress_tool ); ?></code> ✕</button>
									</form>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</td>
					<td>
						<?php Admin::form_open( 'update_connection', 'mcp-inline' ); ?>
							<input type="hidden" name="connection_id" value="<?php echo esc_attr( $mcpress_conn['id'] ); ?>">
							<label class="screen-reader-text" for="mcp-mode-<?php echo esc_attr( $mcpress_conn['id'] ); ?>"><?php esc_html_e( 'Mode', 'mcpress' ); ?></label>
							<select id="mcp-mode-<?php echo esc_attr( $mcpress_conn['id'] ); ?>" name="mode" data-mcp-autosubmit data-mcp-mode-select class="mcp-mode-select mcp-mode-select--<?php echo esc_attr( $mcpress_conn['mode'] ); ?>">
								<?php foreach ( Policy::modes() as $mcpress_mode ) : ?>
									<option value="<?php echo esc_attr( $mcpress_mode ); ?>" <?php selected( $mcpress_conn['mode'], $mcpress_mode ); ?>><?php echo esc_html( Policy::mode_label( $mcpress_mode ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<noscript><button type="submit" class="button"><?php esc_html_e( 'Save', 'mcpress' ); ?></button></noscript>
						</form>
					</td>
					<td><?php echo $mcpress_user ? esc_html( $mcpress_user->user_login ) : '—'; ?></td>
					<td>
						<?php echo esc_html( Util::human_diff( $mcpress_conn['last_used_at'] ) ); ?>
						<?php if ( $mcpress_conn['last_ip'] ) : ?>
							<div class="mcp-muted mcp-small"><?php echo esc_html( $mcpress_conn['last_ip'] ); ?></div>
						<?php endif; ?>
					</td>
					<td class="mcp-right">
						<?php Admin::form_open( 'revoke_connection', 'mcp-inline' ); ?>
							<input type="hidden" name="connection_id" value="<?php echo esc_attr( $mcpress_conn['id'] ); ?>">
							<button type="submit" class="mcp-btn-danger" data-mcp-confirm="revoke"><?php esc_html_e( 'Disconnect', 'mcpress' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	<?php endif; ?>
</section>
