<div class="wrap uwp-wrap">
	<h1><?php esc_html_e( 'UnboundWP: Export', 'unboundwp' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Create a full backup of your site (.uwp, fully compatible with .wpress). No size limits, no paywalls. Exports run in small steps so they never time out.', 'unboundwp' ); ?>
	</p>
	<p class="description">
		<?php esc_html_e( 'Backups include WordPress core files, so a restore reproduces the exact version, upgrade or downgrade included.', 'unboundwp' ); ?>
	</p>
	<?php UWP_Admin::tabs( 'export' ); ?>

	<div class="uwp-card">
		<h2><?php esc_html_e( '1. Export your site', 'unboundwp' ); ?></h2>
		<div class="notice notice-warning inline" id="uwp-export-resume" hidden>
			<p><strong><?php esc_html_e( 'An interrupted export was found.', 'unboundwp' ); ?></strong>
			<span id="uwp-export-resume-info"></span>
			<?php esc_html_e( 'You can continue where it left off instead of starting over.', 'unboundwp' ); ?></p>
			<p>
				<button type="button" class="button button-primary" id="uwp-export-resume-btn"><?php esc_html_e( 'Resume export', 'unboundwp' ); ?></button>
				<button type="button" class="button" id="uwp-export-discard-btn"><?php esc_html_e( 'Discard it', 'unboundwp' ); ?></button>
			</p>
		</div>
		<p>
			<label for="uwp-export-password"><?php esc_html_e( 'Password (optional, encrypts the backup):', 'unboundwp' ); ?></label><br>
			<input type="password" id="uwp-export-password" class="regular-text" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Leave empty for a normal backup', 'unboundwp' ); ?>">
		</p>
		<p>
			<button type="button" class="button button-primary button-hero" id="uwp-export-start">
				<?php esc_html_e( 'Export Now', 'unboundwp' ); ?>
			</button>
			<button type="button" class="button" id="uwp-export-cancel" hidden>
				<?php esc_html_e( 'Cancel', 'unboundwp' ); ?>
			</button>
		</p>
		<div class="uwp-progress" id="uwp-export-progress" hidden>
			<div class="uwp-bar"><div class="uwp-fill" id="uwp-export-fill"></div></div>
			<p id="uwp-export-status" aria-live="polite"></p>
		</div>
		<div id="uwp-export-result"></div>
		<p class="description">UnboundWP <?php echo esc_html( UWP_VERSION ); ?></p>
	</div>
</div>
