/**
 * UnboundWP export runner.
 * One tick at a time - each response schedules the next until done.
 * State lives in server-side files, so anything short of a cancelled job
 * can be resumed: closed tab, dead network, even an expired login.
 */
(function () {
	'use strict';

	var startBtn = document.getElementById('uwp-export-start');
	var cancelBtn = document.getElementById('uwp-export-cancel');
	var box = document.getElementById('uwp-export-progress');
	var fill = document.getElementById('uwp-export-fill');
	var status = document.getElementById('uwp-export-status');
	var result = document.getElementById('uwp-export-result');
	var resumeBox = document.getElementById('uwp-export-resume');
	var running = false;
	var transportFails = 0;
	var maxTransportFails = 120; // exports are stubborn: progress is saved, so keep trying a long time before giving up
	// The same script also runs on the Logs page (diagnostics live there
	// now): export-runner controls only exist on the Export page itself.
	var isExportPage = !!(startBtn && cancelBtn && box);
	function showCancel() { if (cancelBtn) cancelBtn.hidden = false; }
	function hideCancel() { if (cancelBtn) cancelBtn.hidden = true; }

	// post() always resolves with {success, data, _status} - HTTP errors
	// (expired login, dead nonce) arrive as data, never as exceptions,
	// so the runner can tell "log in again" apart from "network glitch".
	function post(action, extra) {
		var data = new FormData();
		data.append('action', action);
		data.append('nonce', UWP.nonce);
		// Job secret authenticates ticks without any login session
		// (Ai1WM's secret_key approach) - set after start/resume.
		data.append('job_secret', UWP.jobSecret || '');
		if (extra) {
			for (var k in extra) data.append(k, extra[k]);
		}
		return fetch(UWP.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) {
				return r.text().then(function (t) {
					var j;
					try {
						j = JSON.parse(t);
					} catch (e) {
						j = null;
					}
					if (!j || typeof j !== 'object') {
						// Bare `0`, empty body or HTML (e.g. logged-out
						// admin-ajax): a real answer, not a transport
						// failure. Coerce it - assigning _status to a
						// number throws under strict mode and used to
						// trap the runner in an endless retry loop.
						j = { success: false, data: { message: '' } };
						// Keep a plain-text snippet: server error pages
						// (e.g. maintenance 503s) then diagnose themselves.
						var snippet = t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 160);
						if (snippet) {
							j.data.message = snippet;
						}
					}
					j._status = r.status;
					return j;
				});
			});
	}

	function isAuthFailure(j) {
		return j._status === 401 || j._status === 403;
	}

	function stopWithMessage(msg, tail) {
		running = false;
		startBtn.disabled = false;
		hideCancel();
		status.textContent = msg;
		showLogTail(result, tail);
	}

	function showLogTail(box, tail) {
		if (!tail || !box) return;
		var det = document.createElement('details');
		var sum = document.createElement('summary');
		sum.textContent = 'Technical details (for support)';
		det.appendChild(sum);
		var pre = document.createElement('pre');
		pre.textContent = tail;
		pre.style.whiteSpace = 'pre-wrap';
		det.appendChild(pre);
		box.appendChild(det);
	}

	function setProgress(pct, msg) {
		box.hidden = false;
		fill.style.width = pct + '%';
		status.textContent = msg + ' (' + pct + '%)';
	}

	function keepNonce(d) {
		// Every tick hands back a fresh nonce - adopt it so long jobs
		// never die to the nonce printed at page load.
		if (d && d.nonce) {
			UWP.nonce = d.nonce;
		}
	}

	function formatSize(bytes) {
		bytes = parseInt(bytes, 10) || 0;
		if (bytes < 1024) return bytes + ' B';
		var units = ['KB', 'MB', 'GB', 'TB'];
		var i = Math.floor(Math.log(bytes) / Math.log(1024));
		i = Math.min(i, units.length);
		return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i - 1];
	}

	/**
	 * Post-export download prompt, like the original plugin:
	 * file name + size plus a prominent Download button.
	 */
	function showDownload(d) {
		result.innerHTML = '';
		var title = document.createElement('p');
		var strong = document.createElement('strong');
		strong.textContent = d.message || 'Export complete.';
		title.appendChild(strong);
		result.appendChild(title);

		var info = document.createElement('p');
		var code = document.createElement('code');
		code.textContent = d.file || '';
		info.appendChild(code);
		if (d.size) {
			info.appendChild(document.createTextNode(' (' + formatSize(d.size) + ')'));
		}
		result.appendChild(info);

		if (d.download_url) {
			var btn = document.createElement('a');
			btn.href = d.download_url;
			btn.className = 'button button-primary button-hero';
			btn.textContent = 'Download Backup';
			result.appendChild(btn);
		}
	}

	function tick() {
		driveTick();
	}

	// DRIVE fires work ticks; POLL watches status when the server chained
	// the next slice to itself. A busy tick or a chained response drops
	// into polling; a stalled chain is taken over by driving. See import.js.
	var runMode = 'drive';
	var lastPct = -1;
	var lastAdvance = 0;
	// Auto-drive only after explicit start/resume - page-load polling only
	// watches, and a stalled job it didn't start just shows the banner.
	var allowAutoDrive = false;

	function noteProgress(pct) {
		if (pct !== lastPct) {
			lastPct = pct;
			lastAdvance = Date.now();
		}
	}

	function finishExport(d) {
		running = false;
		runMode = 'drive';
		allowAutoDrive = false;
		UWP.jobSecret = ''; // job is gone; don't send a stale token
		startBtn.disabled = false;
		hideCancel();
		hideResume();
		setProgress(100, d.message);
		showDownload(d);
	}

	function transportFail(op) {
		// First failure retries almost immediately (stale-socket resets
		// are usually one-shot); sustained outages back off.
		transportFails++;
		if (transportFails > maxTransportFails) {
			watchPassively();
			return;
		}
		var delay = (transportFails === 1) ? 300 : Math.min(30000, 2000 * transportFails);
		status.textContent = (UWP.i18n.retrying || 'Retrying…') + ' (' + transportFails + '/' + maxTransportFails + '). Safe to leave this tab open.';
		setTimeout(op, delay);
	}

	// Passive watch: keep polling lightweight status once a minute until
	// Cancel. Rejoins driving the moment the server shows progress or
	// completion, so a flaky connection can never strand a live job.
	function watchPassively() {
		if (!running) return;
		status.textContent = (UWP.i18n.gaveUp || UWP.i18n.failed) + ' Watching for progress…';
		showResume();
		(function watch() {
			if (!running) return;
			post('uwp_job_status', { job: 'export' }).then(function (j) {
				if (j.success && j.data) {
					keepNonce(j.data);
					if (j.data.secret) {
						UWP.jobSecret = j.data.secret;
					}
					if (j.data.done) {
						finishExport(j.data);
						return;
					}
					if (j.data.active && typeof j.data.percent === 'number' && j.data.percent >= 0) {
						transportFails = 0;
						setProgress(j.data.percent, j.data.message || '');
						noteProgress(j.data.percent);
						pollStatus();
						return;
					}
				}
				setTimeout(watch, 60000);
			}).catch(function () {
				setTimeout(watch, 60000);
			});
		})();
	}

	function driveTick() {
		if (!running) return;
		runMode = 'drive';
		post('uwp_export_tick').then(function (j) {
			if (!j.success) {
				if (isAuthFailure(j)) {
					stopWithMessage(UWP.i18n.sessionExpired);
					showResume();
					return;
				}
				var msg = (j.data && j.data.message) || '';
				if (!msg && j._status >= 500) {
					msg = UWP.i18n.serverError + ' (HTTP ' + j._status + ')';
				}
				stopWithMessage(msg || UWP.i18n.failed, j.data && j.data.log_tail);
				showResume();
				return;
			}
			transportFails = 0;
			var d = j.data;
			keepNonce(d);
			if (d.done) {
				finishExport(d);
				return;
			}
			if (d.busy) {
				pollStatus();
				return;
			}
			if (typeof d.percent === 'number' && d.percent >= 0) {
				setProgress(d.percent, d.message || '');
				noteProgress(d.percent);
			}
			if (d.chained) {
				pollStatus();
			} else {
				setTimeout(driveTick, 150);
			}
		}).catch(function () {
			transportFail(driveTick);
		});
	}

	function pollStatus() {
		if (!running) return;
		runMode = 'poll';
		post('uwp_job_status', { job: 'export' }).then(function (j) {
			if (!j.success) {
				if (isAuthFailure(j)) {
					stopWithMessage(UWP.i18n.sessionExpired);
					showResume();
					return;
				}
				transportFail(pollStatus);
				return;
			}
			transportFails = 0;
			var d = j.data;
			keepNonce(d);
			if (d.secret) {
				UWP.jobSecret = d.secret;
			}
			if (d.done) {
				finishExport(d);
				return;
			}
			if (!d.active) {
				driveTick();
				return;
			}
			if (typeof d.percent === 'number' && d.percent >= 0) {
				setProgress(d.percent, d.message || '');
				noteProgress(d.percent);
			}
			if (Date.now() - lastAdvance > 20000) {
				if (allowAutoDrive) {
					driveTick();
				} else {
					showResume(resumeInfoText(d));
				}
			} else {
				setTimeout(pollStatus, 2500);
			}
		}).catch(function () {
			transportFail(pollStatus);
		});
	}

	function beginLoop() {
		result.innerHTML = '';
		transportFails = 0;
		lastPct = -1;
		lastAdvance = Date.now();
		allowAutoDrive = true;
		running = true;
		startBtn.disabled = true;
		showCancel();
		hideResume();
	}

	if (isExportPage) {
	startBtn.addEventListener('click', function () {
		beginLoop();
		setProgress(0, UWP.i18n.starting);
		var passInput = document.getElementById('uwp-export-password');
		post('uwp_export_start', {
			password: passInput ? passInput.value : ''
		}).then(function (j) {
			if (!j.success) {
				if (isAuthFailure(j)) {
					stopWithMessage(UWP.i18n.sessionExpired);
				} else {
					stopWithMessage((j.data && j.data.message) || UWP.i18n.failed);
				}
				return;
			}
			keepNonce(j.data);
			if (j.data && j.data.secret) {
				UWP.jobSecret = j.data.secret;
			}
			driveTick();
		}).catch(function () {
			stopWithMessage(UWP.i18n.failed);
		});
	});

	// Resume continues the saved server-side job without restarting it.
	function resumeLoop() {
		beginLoop();
		setProgress(0, UWP.i18n.resuming || UWP.i18n.starting);
		driveTick();
	}

	cancelBtn.addEventListener('click', function () {
		running = false;
		allowAutoDrive = false;
		UWP.jobSecret = '';
		post('uwp_export_cancel').then(function () {
			startBtn.disabled = false;
			hideCancel();
			hideResume();
			status.textContent = 'Cancelled.';
		}).catch(function () {
			startBtn.disabled = false;
			hideCancel();
			hideResume();
			status.textContent = 'Cancelled.';
		});
	});
	} // end isExportPage

	function showResume(info) {
		if (resumeBox) resumeBox.hidden = false;
		var el = document.getElementById('uwp-export-resume-info');
		if (el) {
			el.textContent = info || '';
		}
	}

	function resumeInfoText(d) {
		var bits = [];
		if (d.created) {
			var when = new Date(d.created * 1000);
			if (!isNaN(when.getTime())) {
				bits.push('started ' + when.toLocaleString());
			}
		}
		if (d.step) {
			bits.push('step: ' + d.step);
		}
		if (typeof d.percent === 'number' && d.percent >= 0) {
			bits.push(d.percent + '%');
		}
		return bits.length ? '(' + bits.join(', ') + ') ' : '';
	}

	function hideResume() {
		if (resumeBox) resumeBox.hidden = true;
	}

	var resumeBtn = document.getElementById('uwp-export-resume-btn');
	var discardBtn = document.getElementById('uwp-export-discard-btn');
	if (resumeBtn) {
		resumeBtn.addEventListener('click', resumeLoop);
	}
	if (discardBtn) {
		discardBtn.addEventListener('click', function () {
			UWP.jobSecret = '';
			post('uwp_export_cancel').then(function () {
				hideResume();
			}).catch(function () {
				hideResume();
			});
		});
	}

	// On page load: if a job is active (e.g. still running server-side via
	// loopback chaining), watch it - polling takes over driving only for
	// jobs this page started; others just show the resume banner.
	if (isExportPage) {
	post('uwp_job_status', { job: 'export' }).then(function (j) {
		if (j.success && j.data && j.data.active) {
			keepNonce(j.data);
			if (j.data.secret) {
				UWP.jobSecret = j.data.secret;
			}
			running = true;
			transportFails = 0;
			lastPct = -1;
			lastAdvance = Date.now();
			startBtn.disabled = true;
			showCancel();
			setProgress(j.data.percent || 0, j.data.message || '');
			showResume(resumeInfoText(j.data));
			pollStatus();
		} else if (j.success && j.data && j.data.done) {
			finishExport(j.data);
		}
	}).catch(function () { /* status check is best-effort only */ });
	}

	// Diagnostics battery: one click, isolated probes, names the culprit.
	var diagBtn = document.getElementById('uwp-diag-run');
	var diagBox = document.getElementById('uwp-diag-result');
	if (diagBtn && diagBox) {
		diagBtn.addEventListener('click', function () {
			diagBtn.disabled = true;
			diagBox.innerHTML = '';
			var p = document.createElement('p');
			p.textContent = 'Running…';
			diagBox.appendChild(p);
			post('uwp_diag', {}).then(function (j) {
				diagBtn.disabled = false;
				diagBox.innerHTML = '';
				if (!j.success || !j.data || !j.data.lines) {
					var fail = document.createElement('p');
					fail.textContent = (j.data && j.data.message) || UWP.i18n.failed;
					diagBox.appendChild(fail);
					return;
				}
				var ul = document.createElement('ul');
				ul.className = 'uwp-list';
				j.data.lines.forEach(function (line) {
					var li = document.createElement('li');
					// Severity: ok green, warn amber (degraded/history),
					// fail red. Older responses without level fall back to ok.
					var level = line.level || (line.ok ? 'ok' : 'fail');
					var label = level === 'ok' ? 'OK  ' : (level === 'warn' ? 'WARN  ' : 'FAIL  ');
					li.textContent = label + line.name + ' - ' + line.detail;
					li.style.color = level === 'ok' ? '#1a7f37' : (level === 'warn' ? '#9a6700' : '#b42318');
					ul.appendChild(li);
				});
				diagBox.appendChild(ul);
			}).catch(function () {
				diagBtn.disabled = false;
				diagBox.innerHTML = '';
				var fail = document.createElement('p');
				fail.textContent = UWP.i18n.failed;
				diagBox.appendChild(fail);
			});
		});
	}
})();
