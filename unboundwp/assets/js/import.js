/**
 * UnboundWP import runner: chunked upload + chunked restore.
 *
 * Upload: file.slice() into 2 MB pieces, POSTed one by one. No piece ever
 * hits PHP's upload limits, so backups of ANY size upload fine.
 * Restore: same tick-loop as export - small steps, resumable, no timeouts.
 */
(function () {
	'use strict';

	var drop = document.getElementById('uwp-drop');
	var fileInput = document.getElementById('uwp-file');
	var passInput = document.getElementById('uwp-password');
	var upBox = document.getElementById('uwp-upload-progress');
	var upFill = document.getElementById('uwp-upload-fill');
	var upStatus = document.getElementById('uwp-upload-status');
	var impBox = document.getElementById('uwp-import-box');
	var impFill = document.getElementById('uwp-import-fill');
	var impStatus = document.getElementById('uwp-import-status');
	var impResult = document.getElementById('uwp-import-result');
	var impCancel = document.getElementById('uwp-import-cancel');
	var importing = false;
	var transportFails = 0;
	var maxTransportFails = 120; // restores are stubborn: progress is saved, so keep trying a long time before giving up

	// post() always resolves with {success, data, _status} - HTTP errors
	// (expired login, dead nonce) arrive as data, never as exceptions.
	function post(action, extra, fileSlice) {
		var data = new FormData();
		data.append('action', action);
		data.append('nonce', UWP.nonce);
		// Job secret authenticates ticks without any login session
		// (Ai1WM's secret_key approach) - set after start/resume.
		data.append('job_secret', UWP.jobSecret || '');
		for (var k in extra) data.append(k, extra[k]);
		if (fileSlice) data.append('slice', fileSlice);
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

	function keepNonce(d) {
		// Every tick hands back a fresh nonce - adopt it so long restores
		// never die to the nonce printed at page load.
		if (d && d.nonce) {
			UWP.nonce = d.nonce;
		}
	}

	function setUpload(pct, msg) {
		upBox.hidden = false;
		upFill.style.width = pct + '%';
		upStatus.textContent = msg + ' (' + pct + '%)';
	}

	function setImport(pct, msg) {
		impBox.hidden = false;
		impFill.style.width = pct + '%';
		impStatus.textContent = msg + ' (' + pct + '%)';
	}

	function uploadFile(file) {
		var chunkSize = UWP.chunkSize || (2 * 1024 * 1024);
		var minChunk = 256 * 1024;
		var maxFails = 10;
		var sent = 0;   // bytes the server has confirmed (always obeyed)
		var fails = 0;  // consecutive failures for the current position
		setUpload(0, UWP.i18n.uploading);

		function failFatal(msg) {
			upStatus.textContent = msg || UWP.i18n.failed;
		}

		function sendSlice() {
			var end = Math.min(file.size, sent + chunkSize);
			var slice = file.slice(sent, end);
			post('uwp_upload_chunk', {
				file_name: file.name,
				offset: String(sent),
				total_size: String(file.size)
			}, slice).then(function (j) {
				if (!j.success) {
					var info = (j.data || {});
					if (isAuthFailure(j)) {
						failFatal(UWP.i18n.sessionExpired);
						return;
					}
					// Server asks for smaller pieces (strict host limits).
					if (info.shrink && chunkSize > minChunk) {
						chunkSize = Math.max(minChunk, Math.floor(chunkSize / 2));
						fails = 0;
						setTimeout(sendSlice, 500);
						return;
					}
					// Transient server hiccup - resend the same bytes.
					if (info.retryable && fails < maxFails) {
						fails++;
						upStatus.textContent = (info.message || UWP.i18n.failed) + ' ' + (UWP.i18n.retrying || 'Retrying…');
						setTimeout(sendSlice, Math.min(15000, 1000 * fails));
						return;
					}
					// Asked to restart from scratch (out-of-sync guard).
					if (info.restart) {
						sent = 0;
						fails = 0;
						setTimeout(sendSlice, 500);
						return;
					}
					failFatal(info.message);
					return;
				}
				var d = j.data;
				fails = 0;
				// Always follow the server's count - this heals gaps and
				// absorbs duplicates instead of failing the whole upload.
				if (typeof d.received === 'number') {
					sent = d.received;
				} else {
					sent = end;
				}
				if (d.done) {
					setUpload(100, d.message);
					startImport(d.file);
					return;
				}
				setUpload(Math.round(100 * sent / file.size), UWP.i18n.uploading);
				sendSlice();
			}).catch(function () {
				// Network-level failure (timeout, dropped connection):
				// the server may or may not have landed this slice, but the
				// offset protocol makes resending safe either way.
				fails++;
				if (fails > maxFails) {
					failFatal(UWP.i18n.failed);
					return;
				}
				// After a few network failures, assume a strict host and
				// shrink the pieces before continuing.
				if (fails === 4 && chunkSize > minChunk) {
					chunkSize = Math.max(minChunk, Math.floor(chunkSize / 2));
				}
				upStatus.textContent = (UWP.i18n.retrying || 'Retrying…') + ' (' + fails + '/' + maxFails + ')';
				setTimeout(sendSlice, Math.min(15000, 1000 * fails));
			});
		}
		sendSlice();
	}

	function importTick() {
		driveTick();
	}

	// Two modes, one goal:
	// - DRIVE: fire a work tick ourselves (needed when server loopbacks
	//   are blocked, and the fastest path when the network is healthy).
	// - POLL: the server chained the next slice to itself, so just watch
	//   lightweight status. If progress stalls, take over by driving.
	// A busy tick (lock held elsewhere) also drops into polling.
	var runMode = 'drive';
	var lastPct = -1;
	var lastAdvance = 0;
	// Auto-drive (doing work unprompted) only after the user explicitly
	// started or resumed. Page-load polling only watches; a stalled job
	// it didn't start just shows the resume banner.
	var allowAutoDrive = false;

	function noteProgress(pct) {
		if (pct !== lastPct) {
			lastPct = pct;
			lastAdvance = Date.now();
		}
	}

	function finishImport(message) {
		importing = false;
		runMode = 'drive';
		allowAutoDrive = false;
		UWP.jobSecret = ''; // job is gone; don't send a stale token
		hideResume();
		// Done state: no Cancel anymore - show what happened plus next steps.
		var cancelRow = document.getElementById('uwp-import-cancel-row');
		if (cancelRow) cancelRow.hidden = true;
		impFill.classList.add('done');
		var parts = splitMessage(message);
		setImport(100, parts.head);
		impResult.innerHTML = '';
		var wrap = document.createElement('div');
		wrap.className = 'uwp-done';
		var h = document.createElement('p');
		h.className = 'uwp-done-head';
		var strong = document.createElement('strong');
		strong.textContent = parts.head;
		h.appendChild(strong);
		wrap.appendChild(h);
		if (parts.tail) {
			var det = document.createElement('details');
			det.className = 'uwp-notes';
			var sum = document.createElement('summary');
			sum.textContent = (UWP.i18n.restoreNotes || 'What changed');
			det.appendChild(sum);
			var notes = document.createElement('p');
			notes.textContent = parts.tail;
			det.appendChild(notes);
			wrap.appendChild(det);
		}
		impResult.appendChild(wrap);
		showDoneActions();
	}

	// First sentence becomes the heading; the rest becomes expandable notes.
	// Full text always stays in the job log - nothing is lost by collapsing.
	function splitMessage(message) {
		message = (message || '').trim();
		var idx = message.indexOf('. ');
		if (idx < 0) {
			return { head: message, tail: '' };
		}
		return {
			head: message.substring(0, idx + 1),
			tail: message.substring(idx + 2).trim()
		};
	}

	function showDoneActions() {
		var box = document.getElementById('uwp-import-done-actions');
		if (!box) return;
		box.innerHTML = '';
		box.hidden = false;
		if (UWP.homeUrl) {
			var view = document.createElement('a');
			view.className = 'button button-primary';
			view.href = UWP.homeUrl;
			view.target = '_blank';
			view.rel = 'noopener';
			view.textContent = (UWP.i18n.viewSite || 'View site');
			box.appendChild(view);
		}
		var again = document.createElement('button');
		again.type = 'button';
		again.className = 'button';
		again.textContent = (UWP.i18n.newRestore || 'Start new restore');
		again.addEventListener('click', resetRunUI);
		box.appendChild(again);
		if (UWP.logsUrl) {
			var logs = document.createElement('a');
			logs.className = 'button';
			logs.href = UWP.logsUrl;
			logs.textContent = (UWP.i18n.openLogs || 'Open full logs');
			box.appendChild(logs);
		}
	}

	// Back to a fresh run state (used by Start-new-restore and new starts).
	function resetRunUI() {
		impResult.innerHTML = '';
		impFill.classList.remove('done');
		var cancelRow = document.getElementById('uwp-import-cancel-row');
		if (cancelRow) cancelRow.hidden = false;
		var box = document.getElementById('uwp-import-done-actions');
		if (box) {
			box.innerHTML = '';
			box.hidden = true;
		}
		hideResume();
	}

	function stopForResume(message, tail) {
		importing = false;
		runMode = 'drive';
		impStatus.textContent = message;
		showResume();
		showLogTail(impResult, tail);
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

	function transportFail(op) {
		// First failure retries almost immediately: a reset on a stale
		// pooled connection is usually one-shot, and a fresh socket
		// succeeds. Only sustained outages back off.
		transportFails++;
		if (transportFails > maxTransportFails) {
			watchPassively();
			return;
		}
		var delay = (transportFails === 1) ? 300 : Math.min(30000, 2000 * transportFails);
		impStatus.textContent = (UWP.i18n.retrying || 'Retrying…') + ' (' + transportFails + '/' + maxTransportFails + '). Safe to leave this tab open.';
		setTimeout(op, delay);
	}

	// Passive watch: the connection keeps failing, but the server may be
	// progressing on its own (loopback chain). Poll lightweight status
	// once a minute forever (until Cancel) and rejoin the moment anything
	// moves or finishes. Never abandons server-side progress.
	function watchPassively() {
		if (!importing) return;
		impStatus.textContent = (UWP.i18n.gaveUp || UWP.i18n.failed) + ' Watching for progress…';
		showResume();
		(function watch() {
			if (!importing) return;
			post('uwp_job_status', { job: 'import' }).then(function (j) {
				if (j.success && j.data) {
					keepNonce(j.data);
					if (j.data.secret) {
						UWP.jobSecret = j.data.secret;
					}
					if (j.data.done) {
						finishImport(j.data.message);
						return;
					}
					if (j.data.active && typeof j.data.percent === 'number' && j.data.percent >= 0) {
						transportFails = 0;
						setImport(j.data.percent, j.data.message || '');
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
		if (!importing) return;
		runMode = 'drive';
		post('uwp_import_tick').then(function (j) {
			if (!j.success) {
				if (isAuthFailure(j)) {
					// With job-secret auth this should rarely happen; if it
					// does, the login really is gone - resume needs it back.
					stopForResume(UWP.i18n.sessionExpired);
					return;
				}
				var msg = (j.data && j.data.message) || '';
				if (!msg && j._status >= 500) {
					msg = UWP.i18n.serverError + ' (HTTP ' + j._status + ')';
				}
				stopForResume(msg || UWP.i18n.failed, j.data && j.data.log_tail);
				return;
			}
			transportFails = 0;
			var d = j.data;
			keepNonce(d);
			if (d.done) {
				finishImport(d.message);
				return;
			}
			if (d.busy) {
				pollStatus(); // someone else holds the lock; watch instead.
				return;
			}
			if (typeof d.percent === 'number' && d.percent >= 0) {
				setImport(d.percent, d.message || '');
				noteProgress(d.percent);
			}
			if (d.chained) {
				pollStatus(); // server continues itself; just watch.
			} else {
				setTimeout(driveTick, 150);
			}
		}).catch(function () {
			transportFail(driveTick);
		});
	}

	function pollStatus() {
		if (!importing) return;
		runMode = 'poll';
		post('uwp_job_status', { job: 'import' }).then(function (j) {
			if (!j.success) {
				if (isAuthFailure(j)) {
					stopForResume(UWP.i18n.sessionExpired);
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
				finishImport(d.message);
				return;
			}
			if (!d.active) {
				driveTick(); // nothing to watch - take over driving.
				return;
			}
			if (typeof d.percent === 'number' && d.percent >= 0) {
				setImport(d.percent, d.message || '');
				noteProgress(d.percent);
			}
			if (Date.now() - lastAdvance > 20000) {
				if (allowAutoDrive) {
					driveTick(); // stalled chain - take over driving.
				} else {
					showResume(resumeInfoText(d)); // not ours to drive - offer resume instead.
				}
			} else {
				setTimeout(pollStatus, 2500);
			}
		}).catch(function () {
			transportFail(pollStatus);
		});
	}

	function startImport(fileName) {
		impResult.innerHTML = '';
		resetRunUI();
		importing = true;
		allowAutoDrive = true;
		transportFails = 0;
		lastPct = -1;
		lastAdvance = Date.now();
		UWP.jobSecret = ''; // fresh job gets a fresh secret below
		hideResume();
		setImport(0, UWP.i18n.starting);
		var keepCore = document.getElementById('uwp-opt-keep-core');
		var noPlugins = document.getElementById('uwp-opt-no-plugins');
		post('uwp_import_start', {
			archive: fileName,
			password: passInput ? passInput.value : '',
			keep_core: (keepCore && keepCore.checked) ? '1' : '',
			skip_plugins: (noPlugins && noPlugins.checked) ? '1' : ''
		}).then(function (j) {
			if (!j.success) {
				importing = false;
				impStatus.textContent = isAuthFailure(j) ? UWP.i18n.sessionExpired : ((j.data && j.data.message) || UWP.i18n.failed);
				return;
			}
			keepNonce(j.data);
			if (j.data && j.data.secret) {
				UWP.jobSecret = j.data.secret;
			}
			driveTick();
		}).catch(function () {
			importing = false;
			impStatus.textContent = UWP.i18n.failed;
		});
	}

	// Resume an interrupted restore (e.g. after re-login) without restarting.
	function resumeImport() {
		impResult.innerHTML = '';
		importing = true;
		allowAutoDrive = true;
		transportFails = 0;
		lastPct = -1;
		lastAdvance = Date.now();
		hideResume();
		setImport(0, UWP.i18n.resuming || UWP.i18n.starting);
		driveTick();
	}

	function showResume(info) {
		var r = document.getElementById('uwp-import-resume');
		if (r) r.hidden = false;
		var el = document.getElementById('uwp-import-resume-info');
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
		var r = document.getElementById('uwp-import-resume');
		if (r) r.hidden = true;
	}

	// Drag & drop + file picker.
	if (drop) {
		['dragover', 'dragenter'].forEach(function (ev) {
			drop.addEventListener(ev, function (e) {
				e.preventDefault();
				drop.classList.add('over');
			});
		});
		['dragleave', 'drop'].forEach(function (ev) {
			drop.addEventListener(ev, function (e) {
				e.preventDefault();
				drop.classList.remove('over');
			});
		});
		drop.addEventListener('drop', function (e) {
			var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
			if (f) uploadFile(f);
		});
	}
	if (fileInput) {
		fileInput.addEventListener('change', function () {
			if (fileInput.files && fileInput.files[0]) uploadFile(fileInput.files[0]);
		});
	}

	// 1-click restore buttons (backups table + backups page).
	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.uwp-restore') : null;
		if (!btn) return;
		if (!confirm(UWP.i18n.confirm)) return;
		startImport(btn.getAttribute('data-file'));
		impBox.scrollIntoView({ behavior: 'smooth' });
	});

	if (impCancel) {
		impCancel.addEventListener('click', function () {
			importing = false;
			allowAutoDrive = false;
			UWP.jobSecret = '';
			post('uwp_import_cancel').then(function () {
				hideResume();
				impStatus.textContent = 'Cancelled.';
			}).catch(function () {
				hideResume();
				impStatus.textContent = 'Cancelled.';
			});
		});
	}

	var resumeBtn = document.getElementById('uwp-import-resume-btn');
	if (resumeBtn) {
		resumeBtn.addEventListener('click', resumeImport);
	}
	var discardBtn = document.getElementById('uwp-import-discard-btn');
	if (discardBtn) {
		discardBtn.addEventListener('click', function () {
			UWP.jobSecret = '';
			post('uwp_import_cancel').then(function () {
				hideResume();
			}).catch(function () {
				hideResume();
			});
		});
	}

	// On page load: if a job is active (e.g. still running server-side via
	// loopback chaining from another tab), show the resume banner and watch
	// it - polling takes over driving automatically if it stalls.
	post('uwp_job_status', { job: 'import' }).then(function (j) {
		if (j.success && j.data && j.data.active) {
			keepNonce(j.data);
			if (j.data.secret) {
				UWP.jobSecret = j.data.secret;
			}
			importing = true;
			transportFails = 0;
			lastPct = -1;
			lastAdvance = Date.now();
		setImport(j.data.percent || 0, j.data.message || '');
		showResume(resumeInfoText(j.data));
		pollStatus();
		} else if (j.success && j.data && j.data.done) {
			finishImport(j.data.message);
		}
	}).catch(function () { /* status check is best-effort only */ });
})();
