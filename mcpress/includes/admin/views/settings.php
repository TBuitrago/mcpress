<?php
/**
 * Settings screen.
 *
 * @package MCPress
 */

use MCPress\Admin\Admin;
use MCPress\MCP\Tool_Registry;
use MCPress\Permissions\Policy;
use MCPress\Settings;
use MCPress\Updater;
use MCPress\Util;

defined( 'ABSPATH' ) || exit;

$mcpress_s      = Settings::all();
$mcpress_groups = Tool_Registry::groups();
$mcpress_tools  = Tool_Registry::instance()->all();
$mcpress_by     = array();
foreach ( $mcpress_tools as $mcpress_tool ) {
	$mcpress_by[ $mcpress_tool['group'] ][] = $mcpress_tool;
}
$mcpress_update = Updater::status();
?>
<?php Admin::form_open( 'save_settings', 'mcp-settings' ); ?>

<section class="mcp-card">
	<h2><?php esc_html_e( 'Default mode for new connections', 'mcpress' ); ?></h2>
	<p class="mcp-muted"><?php esc_html_e( 'Each AI keeps its own mode; this is what new connections start with. You can change any connection on the Connect screen.', 'mcpress' ); ?></p>
	<fieldset class="mcp-modes mcp-modes--grid">
		<legend class="screen-reader-text"><?php esc_html_e( 'Default mode', 'mcpress' ); ?></legend>
		<?php foreach ( Policy::modes() as $mcpress_mode ) : ?>
			<label class="mcp-mode mcp-mode--<?php echo esc_attr( $mcpress_mode ); ?>">
				<input type="radio" name="default_mode" value="<?php echo esc_attr( $mcpress_mode ); ?>" <?php checked( $mcpress_s['default_mode'], $mcpress_mode ); ?> <?php echo Policy::MODE_FULL === $mcpress_mode ? 'data-mcp-confirm-check="full"' : ''; ?>>
				<span class="mcp-mode__title"><?php echo esc_html( Policy::mode_label( $mcpress_mode ) ); ?></span>
				<span class="mcp-mode__text"><?php echo esc_html( Policy::mode_description( $mcpress_mode ) ); ?></span>
			</label>
		<?php endforeach; ?>
	</fieldset>
	<table class="mcp-matrix">
		<caption class="screen-reader-text"><?php esc_html_e( 'What each mode does with each risk level', 'mcpress' ); ?></caption>
		<thead><tr><th></th>
			<?php foreach ( Policy::risks() as $mcpress_risk ) : ?>
				<th><?php echo wp_kses_post( Admin::risk_badge( $mcpress_risk ) ); ?></th>
			<?php endforeach; ?>
		</tr></thead>
		<tbody>
		<?php foreach ( Policy::MATRIX as $mcpress_mode => $mcpress_row ) : ?>
			<tr><th><?php echo esc_html( Policy::mode_label( $mcpress_mode ) ); ?></th>
				<?php foreach ( $mcpress_row as $mcpress_dec ) : ?>
					<td class="mcp-matrix__<?php echo esc_attr( $mcpress_dec ); ?>">
						<?php
						echo esc_html(
							array(
								'allow' => __( 'Runs', 'mcpress' ),
								'ask'   => __( 'Asks you', 'mcpress' ),
								'deny'  => __( 'Refused', 'mcpress' ),
							)[ $mcpress_dec ]
						);
						?>
					</td>
				<?php endforeach; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</section>

<section class="mcp-card">
	<h2><?php esc_html_e( 'Approvals', 'mcpress' ); ?></h2>
	<div class="mcp-form-grid">
		<label>
			<span><?php esc_html_e( 'A request expires after (hours)', 'mcpress' ); ?></span>
			<input type="number" min="1" max="720" name="approval_ttl_hours" value="<?php echo esc_attr( $mcpress_s['approval_ttl_hours'] ); ?>">
		</label>
		<label class="mcp-check">
			<input type="checkbox" name="notify_email" value="1" <?php checked( $mcpress_s['notify_email'] ); ?>>
			<span><?php esc_html_e( 'Email me when an AI is waiting for approval', 'mcpress' ); ?></span>
		</label>
		<label>
			<span><?php esc_html_e( 'Send to', 'mcpress' ); ?></span>
			<input type="email" name="notify_email_to" value="<?php echo esc_attr( $mcpress_s['notify_email_to'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
		</label>
	</div>
</section>

<section class="mcp-card">
	<h2><?php esc_html_e( 'Tools', 'mcpress' ); ?></h2>
	<p class="mcp-muted"><?php esc_html_e( 'Switch whole areas off, or set a rule for a single tool. "Follow the mode" uses the table above. Rules apply to every connection, even in Full access.', 'mcpress' ); ?></p>
	<?php foreach ( $mcpress_groups as $mcpress_group => $mcpress_label ) : ?>
		<?php
		if ( empty( $mcpress_by[ $mcpress_group ] ) ) {
			continue;
		}
		$mcpress_on = Policy::group_enabled( $mcpress_group ) || 'mcpress' === $mcpress_group;
		?>
		<details class="mcp-group"<?php echo $mcpress_on ? '' : ' data-off'; ?>>
			<summary>
				<label class="mcp-switch" onclick="event.stopPropagation()">
					<input type="checkbox" name="groups[]" value="<?php echo esc_attr( $mcpress_group ); ?>" <?php checked( $mcpress_on ); ?> <?php disabled( 'mcpress' === $mcpress_group ); ?>>
					<span class="screen-reader-text"><?php esc_html_e( 'Enabled', 'mcpress' ); ?></span>
				</label>
				<strong><?php echo esc_html( $mcpress_label ); ?></strong>
				<span class="mcp-muted mcp-small">
					<?php
					/* translators: %d: number of tools. */
					printf( esc_html( _n( '%d tool', '%d tools', count( $mcpress_by[ $mcpress_group ] ), 'mcpress' ) ), count( $mcpress_by[ $mcpress_group ] ) );
					?>
				</span>
			</summary>
			<table class="mcp-table mcp-table--tools">
				<tbody>
				<?php foreach ( $mcpress_by[ $mcpress_group ] as $mcpress_tool ) : ?>
					<?php $mcpress_rule = isset( $mcpress_s['tool_rules'][ $mcpress_tool['name'] ] ) ? $mcpress_s['tool_rules'][ $mcpress_tool['name'] ] : ''; ?>
					<tr>
						<td><code><?php echo esc_html( $mcpress_tool['name'] ); ?></code><div class="mcp-muted mcp-small"><?php echo esc_html( $mcpress_tool['title'] ); ?></div></td>
						<td><?php echo wp_kses_post( Admin::risk_badge( $mcpress_tool['base_risk'] ) ); ?><?php echo is_callable( $mcpress_tool['risk'] ) ? ' <span class="mcp-muted mcp-small">' . esc_html__( 'or higher, depending on the call', 'mcpress' ) . '</span>' : ''; ?></td>
						<td class="mcp-right">
							<label class="screen-reader-text" for="mcp-rule-<?php echo esc_attr( $mcpress_tool['name'] ); ?>"><?php esc_html_e( 'Rule', 'mcpress' ); ?></label>
							<select id="mcp-rule-<?php echo esc_attr( $mcpress_tool['name'] ); ?>" name="tool_rules[<?php echo esc_attr( $mcpress_tool['name'] ); ?>]">
								<option value="" <?php selected( $mcpress_rule, '' ); ?>><?php esc_html_e( 'Follow the mode', 'mcpress' ); ?></option>
								<option value="allow" <?php selected( $mcpress_rule, 'allow' ); ?>><?php esc_html_e( 'Always run', 'mcpress' ); ?></option>
								<option value="ask" <?php selected( $mcpress_rule, 'ask' ); ?>><?php esc_html_e( 'Always ask', 'mcpress' ); ?></option>
								<option value="deny" <?php selected( $mcpress_rule, 'deny' ); ?>><?php esc_html_e( 'Never', 'mcpress' ); ?></option>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</details>
	<?php endforeach; ?>
</section>

<section class="mcp-card">
	<h2><?php esc_html_e( 'Security', 'mcpress' ); ?></h2>
	<div class="mcp-form-grid mcp-form-grid--stack">
		<label class="mcp-check">
			<input type="checkbox" name="protect_sensitive" value="1" <?php checked( $mcpress_s['protect_sensitive'] ); ?>>
			<span><strong><?php esc_html_e( 'Protect sensitive data', 'mcpress' ); ?></strong> — <?php esc_html_e( 'masks password hashes, API keys, tokens and similar secrets in everything the AI reads, and blocks reading wp-config.php and .env.', 'mcpress' ); ?></span>
		</label>
		<label class="mcp-check">
			<input type="checkbox" name="oauth_enabled" value="1" <?php checked( $mcpress_s['oauth_enabled'] ); ?>>
			<span><strong><?php esc_html_e( 'Allow OAuth connections', 'mcpress' ); ?></strong> — <?php esc_html_e( 'needed by Claude (claude.ai, Desktop) and ChatGPT connectors.', 'mcpress' ); ?></span>
		</label>
		<div class="mcp-form-grid">
			<label>
				<span><?php esc_html_e( 'OAuth access token lifetime (minutes)', 'mcpress' ); ?></span>
				<input type="number" min="5" max="43200" name="access_token_ttl_minutes" value="<?php echo esc_attr( (int) round( $mcpress_s['access_token_ttl'] / MINUTE_IN_SECONDS ) ); ?>">
			</label>
			<label>
				<span><?php esc_html_e( 'Stay connected without using it for (days)', 'mcpress' ); ?></span>
				<input type="number" min="1" max="3650" name="refresh_token_days" value="<?php echo esc_attr( $mcpress_s['refresh_token_days'] ); ?>">
			</label>
		</div>
		<label class="mcp-check">
			<input type="checkbox" name="allow_url_keys" value="1" <?php checked( $mcpress_s['allow_url_keys'] ); ?>>
			<span><strong><?php esc_html_e( 'Allow keys inside the URL', 'mcpress' ); ?></strong> — <?php esc_html_e( 'for clients that only accept a URL and no OAuth. The key ends up in server logs: use it only when there is no other way.', 'mcpress' ); ?></span>
		</label>
		<label class="mcp-check">
			<input type="checkbox" name="allow_app_passwords" value="1" <?php checked( $mcpress_s['allow_app_passwords'] ); ?>>
			<span><strong><?php esc_html_e( 'Accept WordPress application passwords', 'mcpress' ); ?></strong> — <?php esc_html_e( 'they connect with the default mode and are not listed as connections. MCPress keys are recommended.', 'mcpress' ); ?></span>
		</label>
	</div>
</section>

<section class="mcp-card">
	<h2><?php esc_html_e( 'Activity log', 'mcpress' ); ?></h2>
	<div class="mcp-form-grid">
		<label>
			<span><?php esc_html_e( 'Keep entries for (days)', 'mcpress' ); ?></span>
			<input type="number" min="1" max="3650" name="log_retention_days" value="<?php echo esc_attr( $mcpress_s['log_retention_days'] ); ?>">
		</label>
		<label class="mcp-check">
			<input type="checkbox" name="log_arguments" value="1" <?php checked( $mcpress_s['log_arguments'] ); ?>>
			<span><?php esc_html_e( 'Record the arguments of each call (secrets are always masked)', 'mcpress' ); ?></span>
		</label>
	</div>
</section>

<section class="mcp-card">
	<h2><?php esc_html_e( 'Updates', 'mcpress' ); ?></h2>
	<p class="mcp-muted">
		<?php
		/* translators: 1: version, 2: GitHub repository. */
		printf( esc_html__( 'Installed: %1$s. Updates come from GitHub (%2$s).', 'mcpress' ), '<strong>' . esc_html( MCPRESS_VERSION ) . '</strong>', '<code>' . esc_html( Updater::repo() ) . '</code>' );
		if ( ! empty( $mcpress_update['checked'] ) ) {
			echo ' ';
			/* translators: %s: time ago. */
			printf( esc_html__( 'Last check: %s.', 'mcpress' ), esc_html( Util::human_diff( $mcpress_update['checked'] ) ) );
			if ( ! empty( $mcpress_update['version'] ) ) {
				echo ' ';
				/* translators: %s: version. */
				printf( esc_html__( 'Latest release: %s.', 'mcpress' ), '<strong>' . esc_html( $mcpress_update['version'] ) . '</strong>' );
			} elseif ( ! empty( $mcpress_update['error'] ) ) {
				echo ' <span class="mcp-status mcp-status--error">' . esc_html( $mcpress_update['error'] ) . '</span>';
			}
		}
		?>
	</p>
	<div class="mcp-form-grid">
		<label class="mcp-check">
			<input type="checkbox" name="auto_update" value="1" <?php checked( $mcpress_s['auto_update'] ); ?>>
			<span><?php esc_html_e( 'Install new versions automatically', 'mcpress' ); ?></span>
		</label>
		<label>
			<span><?php esc_html_e( 'Channel', 'mcpress' ); ?></span>
			<select name="update_channel">
				<option value="stable" <?php selected( $mcpress_s['update_channel'], 'stable' ); ?>><?php esc_html_e( 'Stable releases', 'mcpress' ); ?></option>
				<option value="beta" <?php selected( $mcpress_s['update_channel'], 'beta' ); ?>><?php esc_html_e( 'Include pre-releases (beta)', 'mcpress' ); ?></option>
			</select>
		</label>
	</div>
</section>

<p class="mcp-save"><button type="submit" class="mcp-key-btn"><?php esc_html_e( 'Save settings', 'mcpress' ); ?></button></p>
</form>

<section class="mcp-card">
	<h2><?php esc_html_e( 'Check for updates', 'mcpress' ); ?></h2>
	<?php Admin::form_open( 'check_updates', 'mcp-inline' ); ?>
		<button type="submit" class="mcp-btn-ghost"><?php esc_html_e( 'Check GitHub now', 'mcpress' ); ?></button>
	</form>
</section>

<section class="mcp-card mcp-card--danger">
	<h2><?php esc_html_e( 'Danger zone', 'mcpress' ); ?></h2>
	<p class="mcp-muted"><?php esc_html_e( 'Disconnect every AI at once. Keys and OAuth sessions stop working immediately.', 'mcpress' ); ?></p>
	<?php Admin::form_open( 'revoke_all', 'mcp-inline' ); ?>
		<button type="submit" class="mcp-btn-danger" data-mcp-confirm="all"><?php esc_html_e( 'Disconnect all AIs', 'mcpress' ); ?></button>
	</form>
</section>
