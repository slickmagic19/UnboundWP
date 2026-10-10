<div class="wrap uwp-wrap">
	<h1><?php esc_html_e( 'UnboundWP: Logs', 'unboundwp' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Every job keeps its own complete log: lifecycle, decisions, warnings, skips and errors, newest first, never truncated. Download one and support can see exactly what happened.', 'unboundwp' ); ?></p>
	<?php UWP_Admin::tabs( 'logs' ); ?>

	<?php if ( '' !== $notice ) : ?>
		<div class="notice notice-info"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<div class="uwp-card">
		<?php if ( empty( $logs ) ) : ?>
			<p><?php esc_html_e( 'No logs yet. Run an export, import or verification and its full log will appear here.', 'unboundwp' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Log', 'unboundwp' ); ?></th>
					<th><?php esc_html_e( 'Job', 'unboundwp' ); ?></th>
					<th><?php esc_html_e( 'Size', 'unboundwp' ); ?></th>
					<th><?php esc_html_e( 'Updated', 'unboundwp' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'unboundwp' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $logs as $l ) : ?>
					<?php $dl = admin_url( 'admin-ajax.php?action=uwp_log_download&file=' . rawurlencode( $l['name'] ) . '&nonce=' . $download_nonce ); ?>
					<?php $vw = admin_url( 'admin.php?page=unboundwp-logs&log=' . rawurlencode( $l['name'] ) ); ?>
					<tr>
						<td><code><?php echo esc_html( $l['name'] ); ?></code></td>
						<td><?php echo '' !== $l['job'] ? esc_html( $l['job'] ) : esc_html__( 'shared', 'unboundwp' ); ?></td>
						<td><?php echo esc_html( size_format( $l['size'] ) ); ?></td>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $l['mtime'] ) ); ?></td>
						<td class="uwp-actions">
							<a class="button button-small" href="<?php echo esc_url( $vw ); ?>"><?php esc_html_e( 'View full', 'unboundwp' ); ?></a>
							<a class="button button-small" href="<?php echo esc_url( $dl ); ?>"><?php esc_html_e( 'Download', 'unboundwp' ); ?></a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=unboundwp-logs' ) ); ?>" style="display:inline">
								<?php wp_nonce_field( 'uwp_logs' ); ?>
								<input type="hidden" name="log" value="<?php echo esc_attr( $l['name'] ); ?>">
								<button type="submit" name="uwp_log_delete" value="1" class="button button-small button-link-delete" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete this log?', 'unboundwp' ) ) ); ?>)"><?php esc_html_e( 'Delete', 'unboundwp' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<?php if ( '' !== $view ) : ?>
	<div class="uwp-card">
		<h2><?php echo esc_html( sprintf( __( 'Full log: %s', 'unboundwp' ), $view ) ); ?></h2>
		<?php if ( '' === $view_content ) : ?>
			<p><?php esc_html_e( 'Empty or unreadable.', 'unboundwp' ); ?></p>
		<?php else : ?>
			<pre class="uwp-logview"><?php echo esc_html( $view_content ); ?></pre>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<div class="uwp-card">
		<h2><?php esc_html_e( 'Diagnostics', 'unboundwp' ); ?></h2>
		<p class="description"><?php esc_html_e( 'If jobs keep failing, run this first: it tests every subsystem a job needs and names the broken one.', 'unboundwp' ); ?></p>
		<p><button type="button" class="button" id="uwp-diag-run"><?php esc_html_e( 'Run diagnostics', 'unboundwp' ); ?></button></p>
		<div id="uwp-diag-result" aria-live="polite"></div>
	</div>
</div>
