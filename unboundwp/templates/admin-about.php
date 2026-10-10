<div class="wrap uwp-wrap">
	<h1><?php esc_html_e( 'UnboundWP: About', 'unboundwp' ); ?></h1>
	<?php UWP_Admin::tabs( 'about' ); ?>

	<div class="uwp-card">
		<h2><?php esc_html_e( 'Free site migration and backup', 'unboundwp' ); ?></h2>
		<p><?php esc_html_e( 'UnboundWP exports, imports, backs up and restores WordPress sites with no size limits and no paywalls. Backups run in small resumable steps, so they never time out.', 'unboundwp' ); ?></p>
		<p><?php esc_html_e( 'Every backup is a single portable .uwp file (rename to .wpress works too). Download, verify, 1-click restore, or delete.', 'unboundwp' ); ?></p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=unboundwp' ) ); ?>"><?php esc_html_e( 'Export', 'unboundwp' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=unboundwp-import' ) ); ?>"><?php esc_html_e( 'Import', 'unboundwp' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=unboundwp-backups' ) ); ?>"><?php esc_html_e( 'Backups', 'unboundwp' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=unboundwp-logs' ) ); ?>"><?php esc_html_e( 'Logs', 'unboundwp' ); ?></a>
		</p>
	</div>

	<div class="uwp-card">
		<h2><?php esc_html_e( 'How it stays reliable', 'unboundwp' ); ?></h2>
		<ul class="uwp-list">
			<li><?php esc_html_e( 'Files stream in 500 KB chunks, so memory stays flat even for huge media libraries.', 'unboundwp' ); ?></li>
			<li><?php esc_html_e( 'Database dumps run in small keyed batches, so speed stays steady on huge tables with no max execution time failures.', 'unboundwp' ); ?></li>
			<li><?php esc_html_e( 'Progress is saved after every step, so refresh and it resumes where it left off.', 'unboundwp' ); ?></li>
			<li><?php esc_html_e( 'Every file carries a CRC32 checksum, so corruption is detected instead of silently restored.', 'unboundwp' ); ?></li>
		</ul>
	</div>

	<div class="uwp-card">
		<h2><?php esc_html_e( 'This installation', 'unboundwp' ); ?></h2>
		<table class="widefat striped">
			<tbody>
				<tr><td><?php esc_html_e( 'Plugin version', 'unboundwp' ); ?></td><td><code><?php echo esc_html( UWP_VERSION ); ?></code></td></tr>
				<tr><td><?php esc_html_e( 'Resurrection loader (MU)', 'unboundwp' ); ?></td><td><code><?php echo esc_html( $mu_version ); ?></code></td></tr>
				<tr><td><?php esc_html_e( 'PHP version', 'unboundwp' ); ?></td><td><code><?php echo esc_html( PHP_VERSION ); ?></code></td></tr>
				<tr><td><?php esc_html_e( 'WordPress version', 'unboundwp' ); ?></td><td><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></td></tr>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'No cloud, no accounts, no upsells. Everything runs on your own server.', 'unboundwp' ); ?></p>
	</div>
</div>
