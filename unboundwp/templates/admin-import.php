<div class="wrap uwp-wrap">
	<h1><?php esc_html_e( 'UnboundWP: Import', 'unboundwp' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Restore any .uwp or .wpress backup. Uploads are sliced into 2 MB pieces, so there is no file-size limit and no gateway timeouts, ever.', 'unboundwp' ); ?>
	</p>
	<?php UWP_Admin::tabs( 'import' ); ?>

	<div class="uwp-card">
		<h2><?php esc_html_e( '1. Choose a backup file', 'unboundwp' ); ?></h2>
		<div id="uwp-drop" class="uwp-drop">
			<p><strong><?php esc_html_e( 'Drag & drop your .uwp / .wpress file here', 'unboundwp' ); ?></strong></p>
			<p><?php esc_html_e( 'or', 'unboundwp' ); ?></p>
			<p><label class="button button-primary" for="uwp-file"><?php esc_html_e( 'Select File', 'unboundwp' ); ?></label>
			<input type="file" id="uwp-file" accept=".uwp,.wpress" hidden></p>
			<p class="description"><?php esc_html_e( 'Unlimited size. The file never loads fully into memory.', 'unboundwp' ); ?></p>
		</div>
		<p>
			<label for="uwp-password"><?php esc_html_e( 'Password (only if the backup is encrypted):', 'unboundwp' ); ?></label><br>
			<input type="password" id="uwp-password" class="regular-text" autocomplete="off" placeholder="<?php esc_attr_e( 'Leave empty for normal backups', 'unboundwp' ); ?>">
		</p>
		<fieldset class="uwp-opts">
			<legend><?php esc_html_e( 'Restore options', 'unboundwp' ); ?></legend>
			<label class="uwp-opt">
				<input type="checkbox" id="uwp-opt-keep-core">
				<span>
					<strong><?php esc_html_e( "Keep this site's WordPress core", 'unboundwp' ); ?></strong><br>
					<span class="description"><?php esc_html_e( "Don't replace wp-admin / wp-includes: only content, themes and plugins come over. Best when both sites run the same WordPress version.", 'unboundwp' ); ?></span>
				</span>
			</label>
			<label class="uwp-opt">
				<input type="checkbox" id="uwp-opt-no-plugins">
				<span>
					<strong><?php esc_html_e( 'Leave plugins inactive', 'unboundwp' ); ?></strong><br>
					<span class="description"><?php esc_html_e( 'Skip reactivation so you can review and enable plugins yourself afterwards. Safest for unfamiliar backups.', 'unboundwp' ); ?></span>
				</span>
			</label>
		</fieldset>
		<div class="uwp-progress" id="uwp-upload-progress" hidden>
			<div class="uwp-bar"><div class="uwp-fill" id="uwp-upload-fill"></div></div>
			<p id="uwp-upload-status" aria-live="polite"></p>
		</div>
	</div>

	<div class="uwp-card">
		<h2><?php esc_html_e( '2. Or restore a backup already on this server (1-click)', 'unboundwp' ); ?></h2>
		<?php if ( empty( $backups ) ) : ?>
			<p><?php esc_html_e( 'No backups on this server yet. Export one first, or upload above.', 'unboundwp' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Backup', 'unboundwp' ); ?></th>
					<th><?php esc_html_e( 'Size', 'unboundwp' ); ?></th>
					<th><?php esc_html_e( 'Date', 'unboundwp' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $backups as $b ) : ?>
					<tr>
						<td><code><?php echo esc_html( $b['name'] ); ?></code></td>
						<td><?php echo esc_html( size_format( $b['size'] ) ); ?></td>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $b['mtime'] ) ); ?></td>
						<td><button type="button" class="button uwp-restore" data-file="<?php echo esc_attr( $b['name'] ); ?>"><?php esc_html_e( 'Restore', 'unboundwp' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div class="uwp-card" id="uwp-import-box" hidden>
		<h2><?php esc_html_e( '3. Restoring…', 'unboundwp' ); ?></h2>
		<div class="notice notice-warning inline" id="uwp-import-resume" hidden>
			<p><strong><?php esc_html_e( 'An interrupted restore was found.', 'unboundwp' ); ?></strong>
			<span id="uwp-import-resume-info"></span>
			<?php esc_html_e( 'You can continue where it left off instead of starting over.', 'unboundwp' ); ?></p>
			<p>
				<button type="button" class="button button-primary" id="uwp-import-resume-btn"><?php esc_html_e( 'Resume restore', 'unboundwp' ); ?></button>
				<button type="button" class="button" id="uwp-import-discard-btn"><?php esc_html_e( 'Discard it', 'unboundwp' ); ?></button>
			</p>
		</div>
		<div class="uwp-bar"><div class="uwp-fill" id="uwp-import-fill"></div></div>
		<p id="uwp-import-status" aria-live="polite"></p>
		<p id="uwp-import-cancel-row"><button type="button" class="button" id="uwp-import-cancel"><?php esc_html_e( 'Cancel', 'unboundwp' ); ?></button></p>
		<div id="uwp-import-done-actions" class="uwp-actions" hidden></div>
		<div id="uwp-import-result"></div>
		<p class="description">UnboundWP <?php echo esc_html( UWP_VERSION ); ?></p>
	</div>
</div>
