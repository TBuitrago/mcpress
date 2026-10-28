<?php
/**
 * Approvals screen: pending queue, one request in detail, recent decisions.
 *
 * @package MCPress
 */

use MCPress\Admin\Admin;
use MCPress\Permissions\Approvals;
use MCPress\Util;

defined( 'ABSPATH' ) || exit;

$mcpress_id      = isset( $_GET['approval'] ) ? sanitize_text_field( wp_unslash( $_GET['approval'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$mcpress_current = $mcpress_id ? Approvals::get( $mcpress_id ) : null;
$mcpress_pending = Approvals::query( array( 'status' => 'pending' ), 100 );
$mcpress_recent  = array_filter(
	Approvals::query( array(), 30 ),
	static function ( $r ) {
		return 'pending' !== $r['status'];
	}
);
?>

<?php if ( $mcpress_current ) : ?>
	<?php
	$mcpress_is_pending = 'pending' === $mcpress_current['status'] && strtotime( $mcpress_current['expires_at'] . ' UTC' ) > time();
	$mcpress_preview    = $mcpress_is_pending ? Approvals::preview( $mcpress_current ) : null;
	?>
	<section class="mcp-card mcp-approval<?php echo $mcpress_is_pending ? ' mcp-card--pending' : ''; ?>">
		<p class="mcp-eyebrow">■ <?php esc_html_e( 'Request', 'mcpress' ); ?> <code><?php echo esc_html( $mcpress_current['public_id'] ); ?></code></p>
		<h2><?php echo esc_html( $mcpress_current['summary'] ); ?></h2>
		<dl class="mcp-facts">
			<div><dt><?php esc_html_e( 'Status', 'mcpress' ); ?></dt><dd><?php echo wp_kses_post( Admin::status_badge( $mcpress_is_pending || 'pending' !== $mcpress_current['status'] ? $mcpress_current['status'] : 'expired' ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Risk', 'mcpress' ); ?></dt><dd><?php echo wp_kses_post( Admin::risk_badge( $mcpress_current['risk'] ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Tool', 'mcpress' ); ?></dt><dd><code><?php echo esc_html( $mcpress_current['tool'] ); ?></code></dd></div>
			<div><dt><?php esc_html_e( 'Connection', 'mcpress' ); ?></dt><dd><?php echo esc_html( Admin::connection_label( $mcpress_current['connection_id'] ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Requested', 'mcpress' ); ?></dt><dd><?php echo esc_html( Util::local( $mcpress_current['created_at'] ) ); ?></dd></div>
			<?php if ( $mcpress_is_pending ) : ?>
				<div><dt><?php esc_html_e( 'Expires', 'mcpress' ); ?></dt><dd><?php echo esc_html( Util::local( $mcpress_current['expires_at'] ) ); ?></dd></div>
			<?php elseif ( $mcpress_current['decided_at'] ) : ?>
				<div><dt><?php esc_html_e( 'Decided', 'mcpress' ); ?></dt><dd><?php echo esc_html( Util::local( $mcpress_current['decided_at'] ) ); ?><?php echo $mcpress_current['decided_by'] && get_userdata( $mcpress_current['decided_by'] ) ? ' · ' . esc_html( get_userdata( $mcpress_current['decided_by'] )->user_login ) : ''; ?></dd></div>
			<?php endif; ?>
		</dl>

		<?php if ( null !== $mcpress_preview ) : ?>
			<h3><?php esc_html_e( 'What will happen', 'mcpress' ); ?></h3>
			<pre class="mcp-pre mcp-pre--preview"><?php echo esc_html( Admin::pretty_json( $mcpress_preview ) ); ?></pre>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Exact request', 'mcpress' ); ?></h3>
		<pre class="mcp-pre"><?php echo esc_html( Admin::pretty_json( \MCPress\Redactor::redact( Util::json_decode_array( $mcpress_current['arguments'] ) ) ) ); ?></pre>

		<?php if ( $mcpress_is_pending ) : ?>
			<div class="mcp-decide">
				<?php Admin::form_open( 'approve', 'mcp-inline' ); ?>
					<input type="hidden" name="approval" value="<?php echo esc_attr( $mcpress_current['public_id'] ); ?>">
					<button type="submit" class="mcp-key-btn"><?php esc_html_e( 'Approve and run', 'mcpress' ); ?></button>
					<?php if ( (int) $mcpress_current['connection_id'] > 0 ) : ?>
						<button type="submit" name="always" value="1" class="mcp-btn-ghost">
							<?php
							/* translators: %s: tool name. */
							printf( esc_html__( 'Approve and always allow %s for this AI', 'mcpress' ), '<code>' . esc_html( $mcpress_current['tool'] ) . '</code>' );
							?>
						</button>
					<?php endif; ?>
				</form>
				<?php Admin::form_open( 'reject', 'mcp-reject' ); ?>
					<input type="hidden" name="approval" value="<?php echo esc_attr( $mcpress_current['public_id'] ); ?>">
					<label for="mcp-note" class="screen-reader-text"><?php esc_html_e( 'Note for your AI', 'mcpress' ); ?></label>
					<input id="mcp-note" type="text" name="note" placeholder="<?php esc_attr_e( 'Optional: tell your AI why, or what to do instead', 'mcpress' ); ?>" maxlength="500">
					<button type="submit" class="mcp-btn-danger"><?php esc_html_e( 'Reject', 'mcpress' ); ?></button>
				</form>
			</div>
		<?php elseif ( $mcpress_current['result'] ) : ?>
			<h3><?php esc_html_e( 'Result sent to your AI', 'mcpress' ); ?></h3>
			<?php
			$mcpress_res  = Util::json_decode_array( $mcpress_current['result'] );
			$mcpress_text = isset( $mcpress_res['content'][0]['text'] ) ? $mcpress_res['content'][0]['text'] : $mcpress_current['result'];
			?>
			<pre class="mcp-pre"><?php echo esc_html( Util::truncate( Admin::pretty_json( $mcpress_text ), 20000 ) ); ?></pre>
		<?php elseif ( $mcpress_current['note'] ) : ?>
			<h3><?php esc_html_e( 'Your note', 'mcpress' ); ?></h3>
			<p><?php echo esc_html( $mcpress_current['note'] ); ?></p>
		<?php endif; ?>
		<p><a href="<?php echo esc_url( Approvals::admin_url() ); ?>">← <?php esc_html_e( 'All requests', 'mcpress' ); ?></a></p>
	</section>
<?php endif; ?>

<section class="mcp-card">
	<h2><?php esc_html_e( 'Waiting for you', 'mcpress' ); ?></h2>
	<?php if ( ! $mcpress_pending ) : ?>
		<p class="mcp-empty"><?php esc_html_e( 'Nothing to approve. When an AI in "Prior approval" or "Automatic" mode asks for something, it shows up here.', 'mcpress' ); ?></p>
	<?php else : ?>
		<ul class="mcp-queue">
			<?php foreach ( $mcpress_pending as $mcpress_row ) : ?>
				<?php $mcpress_expired = strtotime( $mcpress_row['expires_at'] . ' UTC' ) < time(); ?>
				<li class="<?php echo $mcpress_expired ? 'is-expired' : ''; ?>">
					<a href="<?php echo esc_url( Approvals::admin_url( $mcpress_row['public_id'] ) ); ?>">
						<?php echo wp_kses_post( Admin::risk_badge( $mcpress_row['risk'] ) ); ?>
						<span class="mcp-queue__summary"><?php echo esc_html( $mcpress_row['summary'] ); ?></span>
						<span class="mcp-muted mcp-small"><?php echo esc_html( Admin::connection_label( $mcpress_row['connection_id'] ) ); ?> · <?php echo esc_html( Util::human_diff( $mcpress_row['created_at'] ) ); ?><?php echo $mcpress_expired ? ' · ' . esc_html__( 'expired', 'mcpress' ) : ''; ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<?php if ( $mcpress_recent ) : ?>
<section class="mcp-card">
	<h2><?php esc_html_e( 'Recently decided', 'mcpress' ); ?></h2>
	<div class="mcp-table-wrap">
	<table class="mcp-table">
		<thead><tr>
			<th><?php esc_html_e( 'Request', 'mcpress' ); ?></th>
			<th><?php esc_html_e( 'Risk', 'mcpress' ); ?></th>
			<th><?php esc_html_e( 'Status', 'mcpress' ); ?></th>
			<th><?php esc_html_e( 'Connection', 'mcpress' ); ?></th>
			<th><?php esc_html_e( 'When', 'mcpress' ); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $mcpress_recent as $mcpress_row ) : ?>
			<tr>
				<td><a href="<?php echo esc_url( Approvals::admin_url( $mcpress_row['public_id'] ) ); ?>"><?php echo esc_html( $mcpress_row['summary'] ); ?></a></td>
				<td><?php echo wp_kses_post( Admin::risk_badge( $mcpress_row['risk'] ) ); ?></td>
				<td><?php echo wp_kses_post( Admin::status_badge( $mcpress_row['status'] ) ); ?></td>
				<td><?php echo esc_html( Admin::connection_label( $mcpress_row['connection_id'] ) ); ?></td>
				<td><?php echo esc_html( Util::human_diff( $mcpress_row['decided_at'] ? $mcpress_row['decided_at'] : $mcpress_row['created_at'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
</section>
<?php endif; ?>
