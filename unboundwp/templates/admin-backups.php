<div class="wrap uwp-wrap">
	<h1><?php esc_html_e( 'UnboundWP: Backups', 'unboundwp' ); ?></h1>
	<?php UWP_Admin::tabs( 'backups' ); ?>

	<div class="uwp-card">
		<?php if ( empty( $backups ) ) : ?>
			<p><?php esc_html_e( 'No backups yet.', 'unboundwp' ); ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=unboundwp' ) ); ?>"><?php esc_html_e( 'Create backup', 'unboundwp' ); ?></a></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Backup', 'unboundwp' ); ?></th>
					<th><?php esc_html_e( 'Size', 'unboundwp' ); ?></th>
					<th><?php esc_html_e( 'Created', 'unboundwp' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'unboundwp' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $backups as $b ) : ?>
					<?php $dl = admin_url( 'admin-ajax.php?action=uwp_backup_download&file=' . rawurlencode( $b['name'] ) . '&nonce=' . wp_create_nonce( 'uwp_nonce' ) ); ?>
					<tr>
						<td><code><?php echo esc_html( $b['name'] ); ?></code></td>
						<td><?php echo esc_html( size_format( $b['size'] ) ); ?></td>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $b['mtime'] ) ); ?></td>
						<td class="uwp-actions">
							<a class="button button-small" href="<?php echo esc_url( $dl ); ?>"><?php esc_html_e( 'Download', 'unboundwp' ); ?></a>
							<button type="button" class="button button-small uwp-verify" data-file="<?php echo esc_attr( $b['name'] ); ?>"><?php esc_html_e( 'Verify', 'unboundwp' ); ?></button>
							<button type="button" class="button button-small button-primary uwp-restore" data-file="<?php echo esc_attr( $b['name'] ); ?>"><?php esc_html_e( 'Restore', 'unboundwp' ); ?></button>
							<button type="button" class="button button-small button-link-delete uwp-delete" data-file="<?php echo esc_attr( $b['name'] ); ?>"><?php esc_html_e( 'Delete', 'unboundwp' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<div id="uwp-verify-box" class="uwp-verify-box" hidden>
			<div class="uwp-bar"><div class="uwp-fill" id="uwp-verify-fill"></div></div>
			<p id="uwp-verify-status" aria-live="polite"></p>
		</div>
		<div id="uwp-backups-result"></div>
	</div>
</div>
<script>
(function(){
	var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	var nonce = <?php echo wp_json_encode( wp_create_nonce( 'uwp_nonce' ) ); ?>;
	var jobSecret = '';
	function post(action, data, cb){
		var f = new FormData();
		f.append('action', action);
		f.append('nonce', nonce);
		f.append('job_secret', jobSecret);
		for (var k in data) f.append(k, data[k]);
		fetch(ajaxUrl, {method:'POST', body:f, credentials:'same-origin'})
			.then(function(r){
				return r.text().then(function(t){
					var j;
					try { j = JSON.parse(t); } catch (e) { j = null; }
					if (!j || typeof j !== 'object') {
						j = {success:false, data:{message:''}};
						var snippet = t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 160);
						if (snippet) {
							j.data.message = snippet;
						}
					}
					j._status = r.status;
					return j;
				});
			})
			.then(function(j){ cb(j); })
			.catch(function(){ cb({success:false, data:{message:'Request failed.'}, _transport:true}); });
	}
	document.addEventListener('click', function(e){
		var del = e.target.closest ? e.target.closest('.uwp-delete') : null;
		if (del) {
			if (!confirm(<?php echo wp_json_encode( __( 'Delete this backup permanently?', 'unboundwp' ) ); ?>)) return;
			post('uwp_backup_delete', {file: del.getAttribute('data-file')}, function(j){
				if (j.success) location.reload();
				else alert((j.data && j.data.message) || 'Failed.');
			});
		}
		var res = e.target.closest ? e.target.closest('.uwp-restore') : null;
		if (res) {
			if (!confirm(<?php echo wp_json_encode( __( 'This will overwrite your site with the backup. Continue?', 'unboundwp' ) ); ?>)) return;
			window.location.href = <?php echo wp_json_encode( admin_url( 'admin.php?page=unboundwp-import' ) ); ?>;
		}
		var ver = e.target.closest ? e.target.closest('.uwp-verify') : null;
		if (ver) {
			startVerify(ver);
		}
	});

	// Read-only verification: streams the archive and checks every
	// file's checksum. Ticks until done, safe for huge backups.
	var verifyBox = document.getElementById('uwp-verify-box');
	var verifyFill = document.getElementById('uwp-verify-fill');
	var verifyStatus = document.getElementById('uwp-verify-status');
	var verifyResult = document.getElementById('uwp-backups-result');
	var verifying = false;
	var verifyFails = 0;

	function keepNonce(d){
		if (d && d.nonce) {
			nonce = d.nonce;
		}
	}

	function showVerify(pct, msg){
		verifyBox.hidden = false;
		verifyFill.style.width = pct + '%';
		verifyStatus.textContent = msg + ' (' + pct + '%)';
	}

	function startVerify(btn){
		if (verifying) return;
		verifying = true;
		verifyFails = 0;
		verifyResult.innerHTML = '';
		btn.disabled = true;
		showVerify(0, <?php echo wp_json_encode( __( 'Starting verification…', 'unboundwp' ) ); ?>);
		post('uwp_backup_verify_start', {file: btn.getAttribute('data-file')}, function(j){
			if (!j.success) {
				verifying = false;
				btn.disabled = false;
				showVerify(0, (j.data && j.data.message) || 'Failed.');
				return;
			}
			if (j.data && j.data.secret) {
				jobSecret = j.data.secret;
			}
			verifyTick(btn);
		});
	}

	function verifyTick(btn){
		if (!verifying) return;
		post('uwp_backup_verify_tick', {}, function(j){
			if (j._transport) {
				// Network-level failure: stubborn retries with growing
				// backoff (the server kept state, so retries resume).
				verifyFails++;
				if (verifyFails > 120) {
					verifying = false;
					btn.disabled = false;
					showVerify(0, 'Request failed.');
					return;
				}
				setTimeout(function(){ verifyTick(btn); }, Math.min(30000, 2000 * verifyFails));
				return;
			}
			if (!j.success) {
				verifying = false;
				btn.disabled = false;
				if (j._status === 401 || j._status === 403) {
					showVerify(0, <?php echo wp_json_encode( __( 'Your login session expired. Log in again (or refresh this page), then run Verify again.', 'unboundwp' ) ); ?>);
				} else {
					showVerify(0, (j.data && j.data.message) || 'Failed.');
				}
				if (j.data && j.data.log_tail) {
					var det = document.createElement('details');
					var sum = document.createElement('summary');
					sum.textContent = 'Technical details (for support)';
					det.appendChild(sum);
					var pre = document.createElement('pre');
					pre.textContent = j.data.log_tail;
					pre.style.whiteSpace = 'pre-wrap';
					det.appendChild(pre);
					verifyResult.appendChild(det);
				}
				return;
			}
			verifyFails = 0;
			var d = j.data;
			keepNonce(d);
			if (d.done) {
				verifying = false;
				jobSecret = ''; // job is gone; don't send a stale token
				btn.disabled = false;
				showVerify(100, d.message);
				showVerifyReport(d);
				return;
			}
			if (d.busy) {
				// Another worker holds the lock; just keep watching.
				setTimeout(function(){ verifyTick(btn); }, 2500);
				return;
			}
			showVerify(d.percent || 0, d.message || '');
			setTimeout(function(){ verifyTick(btn); }, 300);
		});
	}

	function showVerifyReport(d){
		verifyResult.innerHTML = '';
		var box = document.createElement('div');
		box.className = 'notice ' + (d.healthy ? 'notice-success' : 'notice-error');
		var p = document.createElement('p');
		var strong = document.createElement('strong');
		strong.textContent = d.message;
		p.appendChild(strong);
		box.appendChild(p);

		if (d.meta && (d.meta.site || d.meta.created || d.meta.wp)) {
			var meta = document.createElement('p');
			var bits = [];
			if (d.meta.site) bits.push('Site: ' + d.meta.site);
			if (d.meta.wp) bits.push('WordPress: ' + d.meta.wp);
			if (d.meta.created) bits.push('Created: ' + d.meta.created);
			meta.textContent = bits.join('  |  ');
			box.appendChild(meta);
		}

		function list(items){
			var ul = document.createElement('ul');
			ul.className = 'uwp-list';
			items.forEach(function(t){
				var li = document.createElement('li');
				li.textContent = t;
				ul.appendChild(li);
			});
			box.appendChild(ul);
		}
		if (d.warnings && d.warnings.length) list(d.warnings);
		if (d.errors && d.errors.length) list(d.errors);
		verifyResult.appendChild(box);
	}
})();
</script>
