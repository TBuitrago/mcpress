<?php
/**
 * Activity screen: every tool call, filterable.
 *
 * @package MCPress
 */

use MCPress\Admin\Admin;
use MCPress\Audit_Log;
use MCPress\Auth\Connections;
use MCPress\Settings;
use MCPress\Util;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification
$mcpress_filters = array(
	'tool'          => isset( $_GET['tool'] ) ? sanitize_key( wp_unslash( $_GET['tool'] ) ) : '',
	'decision'      => isset( $_GET['decision'] ) ? sanitize_key( wp_unslash( $_GET['decision'] ) ) : '',
	'status'        => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
	'connection_id' => isset( $_GET['connection'] ) ? absint( $_GET['connection'] ) : 0,
	'search'        => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
);
$mcpress_page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
// phpcs:enable
$mcpress_per     = 50;
$mcpress_data    = Audit_Log::query( $mcpress_filters, $mcpress_per, $mcpress_page );
$mcpress_pages   = max( 1, (int) ceil( $mcpress_data['total'] / $mcpress_per ) );
$mcpress_conns   = Connections::all( true );
$mcpress_dec     = array(
	'allowed'  => __( 'Ran directly', 'mcpress' ),
	'approved' => __( 'Approved', 'mcpress' ),
	'pending'  => __( 'Sent for approval', 'mcpress' ),
	'rejected' => __( 'Rejected', 'mcpress' ),
	'denied'   => __( 'Refused', 'mcpress' ),
	'blocked'  => __( 'Blocked by a guard', 'mcpress' ),
);
?>
<section class="mcp-card">
	<h2><?php esc_html_e( 'Activity', 'mcpress' ); ?></h2>
	<p class="mcp-muted">
		<?php
		/* translators: %d: number of days. */
		printf( esc_html__( 'Every call your AIs made, whatever the outcome. Kept for %d days.', 'mcpress' ), (int) Settings::get( 'log_retention_days' ) );
		?>
	</p>
	<form method="get" class="mcp-filters">
		<input type="hidden" name="page" value="mcpress-activity">
		<select name="tool">
			<option value=""><?php esc_html_e( 'All tools', 'mcpress' ); ?></option>
			<?php foreach ( Audit_Log::distinct_tools() as $mcpress_t ) : ?>
				<option value="<?php echo esc_attr( $mcpress_t ); ?>" <?php selected( $mcpress_filters['tool'], $mcpress_t ); ?>><?php echo esc_html( $mcpress_t ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="decision">
			<option value=""><?php esc_html_e( 'Any decision', 'mcpress' ); ?></option>
			<?php foreach ( $mcpress_dec as $mcpress_k => $mcpress_l ) : ?>
				<option value="<?php echo esc_attr( $mcpress_k ); ?>" <?php selected( $mcpress_filters['decision'], $mcpress_k ); ?>><?php echo esc_html( $mcpress_l ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="status">
			<option value=""><?php esc_html_e( 'Any result', 'mcpress' ); ?></option>
			<option value="ok" <?php selected( $mcpress_filters['status'], 'ok' ); ?>><?php esc_html_e( 'Done', 'mcpress' ); ?></option>
			<option value="error" <?php selected( $mcpress_filters['status'], 'error' ); ?>><?php esc_html_e( 'Error', 'mcpress' ); ?></option>
			<option value="pending" <?php selected( $mcpress_filters['status'], 'pending' ); ?>><?php esc_html_e( 'Pending', 'mcpress' ); ?></option>
		</select>
		<select name="connection">
			<option value=""><?php esc_html_e( 'All connections', 'mcpress' ); ?></option>
			<?php foreach ( $mcpress_conns as $mcpress_c ) : ?>
				<option value="<?php echo esc_attr( $mcpress_c['id'] ); ?>" <?php selected( $mcpress_filters['connection_id'], (int) $mcpress_c['id'] ); ?>><?php echo esc_html( $mcpress_c['name'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="search" name="s" value="<?php echo esc_attr( $mcpress_filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search arguments and results', 'mcpress' ); ?>">
		<button type="submit" class="mcp-btn-ghost"><?php esc_html_e( 'Filter', 'mcpress' ); ?></button>
	</form>

	<?php if ( ! $mcpress_data['rows'] ) : ?>
		<p class="mcp-empty"><?php esc_html_e( 'No activity yet.', 'mcpress' ); ?></p>
	<?php else : ?>
		<div class="mcp-table-wrap">
		<table class="mcp-table mcp-table--log">
			<thead><tr>
				<th><?php esc_html_e( 'When', 'mcpress' ); ?></th>
				<th><?php esc_html_e( 'Tool', 'mcpress' ); ?></th>
				<th><?php esc_html_e( 'Risk', 'mcpress' ); ?></th>
				<th><?php esc_html_e( 'Decision', 'mcpress' ); ?></th>
				<th><?php esc_html_e( 'Result', 'mcpress' ); ?></th>
				<th><?php esc_html_e( 'Connection', 'mcpress' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $mcpress_data['rows'] as $mcpress_row ) : ?>
				<tr>
					<td title="<?php echo esc_attr( Util::local( $mcpress_row['created_at'] ) ); ?>"><?php echo esc_html( Util::human_diff( $mcpress_row['created_at'] ) ); ?></td>
					<td>
						<details>
							<summary><code><?php echo esc_html( $mcpress_row['tool'] ); ?></code></summary>
							<div class="mcp-log-detail">
								<strong><?php esc_html_e( 'Arguments', 'mcpress' ); ?></strong>
								<pre class="mcp-pre"><?php echo esc_html( Admin::pretty_json( $mcpress_row['arguments'] ) ); ?></pre>
								<strong><?php esc_html_e( 'Result', 'mcpress' ); ?></strong>
								<pre class="mcp-pre"><?php echo esc_html( Admin::pretty_json( $mcpress_row['result'] ) ); ?></pre>
								<span class="mcp-muted mcp-small"><?php echo esc_html( $mcpress_row['duration_ms'] ); ?> ms · <?php echo esc_html( $mcpress_row['ip'] ); ?></span>
							</div>
						</details>
					</td>
					<td><?php echo $mcpress_row['risk'] ? wp_kses_post( Admin::risk_badge( $mcpress_row['risk'] ) ) : ''; ?></td>
					<td><?php echo esc_html( isset( $mcpress_dec[ $mcpress_row['decision'] ] ) ? $mcpress_dec[ $mcpress_row['decision'] ] : $mcpress_row['decision'] ); ?></td>
					<td><?php echo wp_kses_post( Admin::status_badge( $mcpress_row['status'] ) ); ?></td>
					<td><?php echo esc_html( Admin::connection_label( $mcpress_row['connection_id'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php if ( $mcpress_pages > 1 ) : ?>
			<nav class="mcp-pagination">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $mcpress_page,
							'total'   => $mcpress_pages,
						)
					)
				);
				?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>

	<?php Admin::form_open( 'clear_log', 'mcp-inline mcp-right-block' ); ?>
		<button type="submit" class="mcp-btn-danger" data-mcp-confirm="clear"><?php esc_html_e( 'Clear log', 'mcpress' ); ?></button>
	</form>
</section>
