(function () {
	'use strict';
	if (window.jpManageBound) return;
	window.jpManageBound = true;
	var previousFocus = null;
	var previewObjectUrl = null;
	var previewRequestId = 0;
	var resultsCache = Object.create(null);
	var resultsCacheTtl = 20000;

	function closest(element, selector) {
		return element && element.closest ? element.closest(selector) : null;
	}

	function resultsCacheKey(form, page) {
		function value(name) {
			var field = form ? form.querySelector('[name="' + name + '"]') : null;
			return field ? field.value : '';
		}
		return [value('view') || 'active', value('driveFolder'), value('q'), value('filter'), value('urgency'), String(page || '1')].join('|');
	}

	function rememberCurrentResults(form) {
		var results = document.getElementById('jp-payment-results');
		if (!form || !results || results.classList.contains('is-loading')) return;
		var currentPage = '1';
		try { currentPage = new URL(window.location.href).searchParams.get('p') || '1'; } catch (error) {}
		resultsCache[resultsCacheKey(form, currentPage)] = {html: results.innerHTML, savedAt: Date.now()};
	}

	function updateEmbeddedHistory(form, page) {
		var query = form.querySelector('[name="q"]');
		var filter = form.querySelector('[name="filter"]');
		var urgency = form.querySelector('[name="urgency"]');
		var view = form.querySelector('[name="view"]');
		var driveFolder = form.querySelector('[name="driveFolder"]');
		var historyUrl = new URL(form.action, window.location.href);
		if (query && query.value) historyUrl.searchParams.set('q', query.value); else historyUrl.searchParams.delete('q');
		if (filter && filter.value) historyUrl.searchParams.set('filter', filter.value); else historyUrl.searchParams.delete('filter');
		if (urgency && urgency.value) historyUrl.searchParams.set('urgency', urgency.value); else historyUrl.searchParams.delete('urgency');
		if (view && view.value && view.value !== 'active') historyUrl.searchParams.set('view', view.value); else historyUrl.searchParams.delete('view');
		if (view && view.value === 'drive' && driveFolder && driveFolder.value) historyUrl.searchParams.set('driveFolder', driveFolder.value); else historyUrl.searchParams.delete('driveFolder');
		if (page && String(page) !== '1') historyUrl.searchParams.set('p', page); else historyUrl.searchParams.delete('p');
		historyUrl.hash = 'journalPayment';
		window.history.replaceState({}, '', historyUrl.toString());
	}

	function loadEmbeddedResults(form, page, forceRefresh) {
		var results = document.getElementById('jp-payment-results');
		var fetchUrl = form.getAttribute('data-fetch-url');
		if (!results || !fetchUrl || !window.fetch || !window.URL) return false;

		if (form.jpSearchController && form.jpSearchController.abort) form.jpSearchController.abort();
		form.jpSearchController = window.AbortController ? new AbortController() : null;
		var requestToken = {};
		form.jpSearchRequest = requestToken;
		var requestUrl = new URL(fetchUrl, window.location.href);
		var query = form.querySelector('[name="q"]');
		var filter = form.querySelector('[name="filter"]');
		var urgency = form.querySelector('[name="urgency"]');
		var view = form.querySelector('[name="view"]');
		var driveFolder = form.querySelector('[name="driveFolder"]');
		var cacheKey = resultsCacheKey(form, page || '1');
		var cached = resultsCache[cacheKey];
		if (!forceRefresh && cached && Date.now() - cached.savedAt < resultsCacheTtl) {
			results.innerHTML = cached.html;
			results.classList.remove('is-loading');
			results.removeAttribute('aria-busy');
			updateEmbeddedHistory(form, page || '1');
			return true;
		}
		requestUrl.searchParams.set('q', query ? query.value : '');
		requestUrl.searchParams.set('filter', filter ? filter.value : '');
		requestUrl.searchParams.set('urgency', urgency ? urgency.value : '');
		requestUrl.searchParams.set('view', view ? view.value : 'active');
		requestUrl.searchParams.set('driveFolder', driveFolder ? driveFolder.value : '');
		requestUrl.searchParams.set('p', page || '1');
		requestUrl.searchParams.set('resultsOnly', '1');

		results.classList.add('is-loading');
		results.setAttribute('aria-busy', 'true');
		var options = {
			credentials: 'same-origin',
			headers: {'X-Requested-With': 'XMLHttpRequest'}
		};
		if (form.jpSearchController) options.signal = form.jpSearchController.signal;

		fetch(requestUrl.toString(), options)
			.then(function (response) {
				if (!response.ok) throw new Error('HTTP ' + response.status);
				return response.json();
			})
			.then(function (message) {
				if (form.jpSearchRequest !== requestToken) return;
				if (!message || message.status !== true || typeof message.content !== 'string') throw new Error('Respons tidak valid');
				var parsed = new DOMParser().parseFromString(message.content, 'text/html');
				var nextResults = parsed.getElementById('jp-payment-results');
				if (!nextResults) throw new Error('Area hasil tidak ditemukan');
				results.innerHTML = nextResults.innerHTML;
				var nextDriveManager = results.querySelector('.jp-drive-manager[data-drive-folder]');
				if (driveFolder && nextDriveManager) driveFolder.value = nextDriveManager.getAttribute('data-drive-folder') || '';
				var savedResult = {html: results.innerHTML, savedAt: Date.now()};
				resultsCache[cacheKey] = savedResult;
				resultsCache[resultsCacheKey(form, page || '1')] = savedResult;
				updateEmbeddedHistory(form, page || '1');
			})
			.catch(function (error) {
				if (form.jpSearchRequest !== requestToken || (error && error.name === 'AbortError')) return;
				results.innerHTML = '<div class="jp-alert jp-alert-error" role="alert"><strong>Hasil belum dapat dimuat.</strong> Silakan coba kembali.</div>';
			})
			.then(function () {
				if (form.jpSearchRequest !== requestToken) return;
				results.classList.remove('is-loading');
				results.removeAttribute('aria-busy');
			});
		return true;
	}

	function modalParts() {
		var modal = document.getElementById('jp-document-preview-modal');
		if (!modal) return null;
		return {
			modal: modal,
			frame: modal.querySelector('iframe'),
			title: document.getElementById('jp-preview-title'),
			newTab: modal.querySelector('.jp-preview-newtab'),
			download: modal.querySelector('.jp-preview-download'),
			loading: modal.querySelector('.jp-modal-loading')
		};
	}

	function openModal(button) {
		var parts = modalParts();
		var url = button.getAttribute('data-preview-url') || button.getAttribute('href');
		if (!parts || !url) return;
		// Escape transformed/narrow OJS tab containers so the overlay always uses
		// the full browser viewport and remains perfectly centered.
		if (parts.modal.parentNode !== document.body) document.body.appendChild(parts.modal);
		var ojsPage = document.querySelector('.pkp_structure_page');
		var ojsPageWidth = ojsPage ? Math.round(ojsPage.getBoundingClientRect().width) : 0;
		if (ojsPageWidth > 0) {
			parts.modal.style.setProperty('--jp-ojs-preview-width', ojsPageWidth + 'px');
		} else {
			parts.modal.style.removeProperty('--jp-ojs-preview-width');
		}
		previousFocus = button;
		var documentName = button.getAttribute('data-preview-name') || button.getAttribute('title') || 'Dokumen';
		parts.title.textContent = documentName;
		parts.frame.setAttribute('title', 'Pratinjau ' + documentName);
		parts.newTab.href = url;
		if (parts.download) {
			try {
				var parsedDownloadUrl = new URL(url, window.location.href);
				parsedDownloadUrl.searchParams.set('download', '1');
				parts.download.href = parsedDownloadUrl.href;
			} catch (ignoreDownload) { parts.download.href = url; }
		}
		parts.loading.textContent = 'Memuat ' + documentName + '…';
		parts.loading.hidden = false;
		parts.frame.onload = null;
		parts.frame.src = 'about:blank';
		if (previewObjectUrl) {
			URL.revokeObjectURL(previewObjectUrl);
			previewObjectUrl = null;
		}
		var requestId = ++previewRequestId;
		var previewUrl = url;
		try {
			var parsedPreviewUrl = new URL(url, window.location.href);
			parsedPreviewUrl.searchParams.set('jpPreview', 'fit');
			previewUrl = parsedPreviewUrl.href;
		} catch (ignore) {}
		parts.modal.hidden = false;
		parts.modal.style.display = 'grid';
		parts.modal.setAttribute('aria-hidden', 'false');
		document.body.classList.add('jp-modal-open');
		// Validate the same-origin response before giving it to the browser PDF
		// viewer. This prevents a hidden PHP/HTML error from appearing as a blank
		// iframe and also avoids stray buffered output corrupting the PDF header.
		fetch(previewUrl, {credentials: 'same-origin', headers: {'Accept': 'application/pdf'}})
			.then(function (response) {
				return response.arrayBuffer().then(function (buffer) {
					return {response: response, buffer: buffer};
				});
			})
			.then(function (result) {
				if (requestId !== previewRequestId) return;
				var bytes = new Uint8Array(result.buffer);
				var isPdf = bytes.length >= 5 && bytes[0] === 37 && bytes[1] === 80 && bytes[2] === 68 && bytes[3] === 70 && bytes[4] === 45;
				if (!result.response.ok || !isPdf) {
					var detail = '';
					try {
						detail = new TextDecoder('utf-8').decode(bytes).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 240);
					} catch (decodeError) {}
					throw new Error(detail || ('Server mengembalikan status ' + result.response.status + ', bukan PDF yang valid.'));
				}
				previewObjectUrl = URL.createObjectURL(new Blob([result.buffer], {type: 'application/pdf'}));
				parts.frame.onload = function () {
					if (requestId === previewRequestId) parts.loading.hidden = true;
				};
				parts.frame.src = previewObjectUrl + '#view=Fit&toolbar=1';
			})
			.catch(function (error) {
				if (requestId !== previewRequestId) return;
				parts.loading.textContent = 'Pratinjau gagal dimuat. ' + (error && error.message ? error.message : 'Silakan periksa log error server.');
				parts.loading.hidden = false;
			});
		var closeButton = parts.modal.querySelector('.jp-modal-close');
		if (closeButton) closeButton.focus();
	}

	function closeModal() {
		var parts = modalParts();
		if (!parts) return;
		parts.modal.hidden = true;
		parts.modal.style.display = 'none';
		parts.modal.setAttribute('aria-hidden', 'true');
		previewRequestId++;
		parts.frame.src = 'about:blank';
		if (previewObjectUrl) {
			URL.revokeObjectURL(previewObjectUrl);
			previewObjectUrl = null;
		}
		document.body.classList.remove('jp-modal-open');
		if (previousFocus) previousFocus.focus();
	}

	function toggleRowDetail(row) {
		var detailId = row ? row.getAttribute('data-detail-id') : '';
		var detail = detailId ? document.getElementById(detailId) : null;
		if (!detail) return;
		var willOpen = detail.hidden;
		document.querySelectorAll('.jp-list-detail:not([hidden])').forEach(function (openDetail) {
			openDetail.hidden = true;
		});
		document.querySelectorAll('.jp-list-row[aria-expanded="true"]').forEach(function (openRow) {
			openRow.setAttribute('aria-expanded', 'false');
		});
		if (willOpen) {
			detail.hidden = false;
			row.setAttribute('aria-expanded', 'true');
		}
	}

	document.addEventListener('click', function (event) {
		var driveRefresh = closest(event.target, '.jp-page.jp-embedded #jp-payment-results [data-drive-refresh]');
		if (driveRefresh) {
			var refreshForm = document.querySelector('.jp-page.jp-embedded .jp-filter[data-fetch-url]');
			var refreshView = refreshForm ? refreshForm.querySelector('[name="view"]') : null;
			if (refreshForm && refreshView) {
				event.preventDefault();
				refreshView.value = 'drive';
				loadEmbeddedResults(refreshForm, '1', true);
				return;
			}
		}

		var viewLink = closest(event.target, '.jp-page.jp-embedded .jp-manage-tabs a[data-payment-view]');
		if (viewLink) {
			var viewForm = document.querySelector('.jp-page.jp-embedded .jp-filter[data-fetch-url]');
			var viewInput = viewForm ? viewForm.querySelector('[name="view"]') : null;
			var statusFilter = viewForm ? viewForm.querySelector('[name="filter"]') : null;
			var urgencyFilter = viewForm ? viewForm.querySelector('[name="urgency"]') : null;
			if (viewForm && viewInput) {
				event.preventDefault();
				rememberCurrentResults(viewForm);
				viewInput.value = viewLink.getAttribute('data-payment-view') || 'active';
				viewForm.hidden = viewInput.value === 'drive' || viewInput.value === 'finance' || viewInput.value === 'production' || viewInput.value === 'audit';
				if (statusFilter) statusFilter.value = '';
				if (urgencyFilter) urgencyFilter.value = '';
				if (statusFilter) Array.prototype.forEach.call(statusFilter.querySelectorAll('option[data-payment-scope]'), function (option) {
					var unavailable = option.getAttribute('data-payment-scope') !== viewInput.value;
					option.disabled = unavailable;
					option.hidden = unavailable;
				});
				document.querySelectorAll('.jp-manage-tabs a').forEach(function (tab) { tab.classList.toggle('is-active', tab === viewLink); });
				loadEmbeddedResults(viewForm, '1');
				return;
			}
		}

		var driveFolderLink = closest(event.target, '.jp-page.jp-embedded #jp-payment-results a[data-drive-folder-id]');
		if (driveFolderLink) {
			var driveForm = document.querySelector('.jp-page.jp-embedded .jp-filter[data-fetch-url]');
			var driveInput = driveForm ? driveForm.querySelector('[name="driveFolder"]') : null;
			var driveView = driveForm ? driveForm.querySelector('[name="view"]') : null;
			if (driveForm && driveInput && driveView) {
				event.preventDefault();
				driveInput.value = driveFolderLink.getAttribute('data-drive-folder-id') || '';
				driveView.value = 'drive';
				loadEmbeddedResults(driveForm, '1');
				return;
			}
		}

		var pageLink = closest(event.target, '.jp-page.jp-embedded #jp-payment-results .jp-pagination a');
		if (pageLink) {
			var searchForm = document.querySelector('.jp-page.jp-embedded .jp-filter[data-fetch-url]');
			if (searchForm) {
				event.preventDefault();
				var pageUrl = new URL(pageLink.href, window.location.href);
				loadEmbeddedResults(searchForm, pageUrl.searchParams.get('p') || '1');
				return;
			}
		}

		var emailSend = closest(event.target, '[data-email-send]');
		if (emailSend) {
			var documentName = emailSend.getAttribute('data-email-send') || 'dokumen';
			var recipient = emailSend.getAttribute('data-email-recipient') || 'email penulis';
			if (!window.confirm('Kirim ' + documentName + ' ke ' + recipient + '?')) {
				event.preventDefault();
				return;
			}
		}

		var listRow = closest(event.target, '.jp-list-row[data-detail-id]');
		if (listRow && !closest(event.target, 'a, button, input, select, textarea, label, form')) {
			event.preventDefault();
			toggleRowDetail(listRow);
			return;
		}

		var detailClose = closest(event.target, '.jp-detail-close');
		if (detailClose) {
			event.preventDefault();
			var closeId = detailClose.getAttribute('data-detail-id');
			var closeDetail = closeId ? document.getElementById(closeId) : null;
			var relatedRow = closeId ? document.querySelector('.jp-list-row[data-detail-id="' + closeId + '"]') : null;
			if (closeDetail) closeDetail.hidden = true;
			if (relatedRow) {
				relatedRow.setAttribute('aria-expanded', 'false');
				relatedRow.focus();
			}
			return;
		}

		var editToggle = closest(event.target, '.jp-edit-toggle');
		if (editToggle) {
			event.preventDefault();
			var editId = editToggle.getAttribute('data-edit-id');
			var editForm = editId ? document.getElementById(editId) : null;
			if (!editForm) return;
			editForm.hidden = !editForm.hidden;
			editToggle.setAttribute('aria-expanded', editForm.hidden ? 'false' : 'true');
			var editLabel = editForm.hidden ? 'Edit data pembayaran' : 'Tutup formulir edit';
			editToggle.setAttribute('aria-label', editLabel);
			editToggle.setAttribute('title', editLabel);
			editToggle.setAttribute('data-tooltip', editLabel);
			if (!editForm.hidden) {
				var firstInput = editForm.querySelector('input:not([type="hidden"])');
				if (firstInput) firstInput.focus();
			}
			return;
		}

		var editCancel = closest(event.target, '.jp-edit-cancel');
		if (editCancel) {
			event.preventDefault();
			var cancelId = editCancel.getAttribute('data-edit-id');
			var cancelForm = cancelId ? document.getElementById(cancelId) : null;
			var cancelToggle = cancelId ? document.querySelector('.jp-edit-toggle[data-edit-id="' + cancelId + '"]') : null;
			if (cancelForm) cancelForm.hidden = true;
			if (cancelToggle) {
				cancelToggle.setAttribute('aria-expanded', 'false');
				cancelToggle.setAttribute('aria-label', 'Edit data pembayaran');
				cancelToggle.setAttribute('title', 'Edit data pembayaran');
				cancelToggle.setAttribute('data-tooltip', 'Edit data pembayaran');
				cancelToggle.focus();
			}
			return;
		}

		var preview = closest(event.target, '.jp-document-preview');
		if (preview) {
			event.preventDefault();
			openModal(preview);
			return;
		}
		if (closest(event.target, '.jp-modal-close')) {
			event.preventDefault();
			closeModal();
			return;
		}
		var parts = modalParts();
		if (parts && event.target === parts.modal) closeModal();

	});

	document.addEventListener('keydown', function (event) {
		var row = closest(event.target, '.jp-list-row[data-detail-id]');
		if (!row || event.target !== row || (event.key !== 'Enter' && event.key !== ' ')) return;
		event.preventDefault();
		toggleRowDetail(row);
	});

	document.addEventListener('submit', function (event) {
		var proofDecision = event.submitter && event.submitter.getAttribute ? event.submitter.getAttribute('data-proof-decision') : '';
		if (proofDecision === 'approved' && !window.confirm('Setujui galley ini untuk diterbitkan? Keputusan akan dicatat bersama waktu dan identitas Anda.')) {
			event.preventDefault();
			return;
		}
		if (proofDecision === 'corrections_requested' && !window.confirm('Ajukan catatan koreksi kepada editor? Versi galley ini akan ditutup sampai editor mengunggah versi baru.')) {
			event.preventDefault();
			return;
		}
		var searchForm = closest(event.target, '.jp-page.jp-embedded .jp-filter[data-fetch-url]');
		if (searchForm && loadEmbeddedResults(searchForm, '1')) {
			event.preventDefault();
			return;
		}
		var currentView = document.querySelector('.jp-page .jp-filter [name="view"]');
		if (currentView && !event.target.querySelector('[name="returnView"]')) {
			var returnView = document.createElement('input');
			returnView.type = 'hidden';
			returnView.name = 'returnView';
			returnView.value = currentView.value || 'active';
			event.target.appendChild(returnView);
		}
		var driveTrashForm = closest(event.target, 'form.jp-drive-trash-form');
		if (driveTrashForm) {
			var driveName = driveTrashForm.getAttribute('data-drive-file-name') || 'file ini';
			if (!window.confirm('Pindahkan "' + driveName + '" ke Sampah Google Drive? File masih dapat dipulihkan dari Drive.')) event.preventDefault();
			return;
		}
		var deleteForm = closest(event.target, 'form[data-delete-record="true"]');
		if (!deleteForm) return;
		var articleId = deleteForm.getAttribute('data-article-id') || '';
		if (!window.confirm('Hapus pembayaran ID Artikel ' + articleId + '? Data dan bukti pembayaran akan dihapus permanen.')) event.preventDefault();
	});

	document.addEventListener('keydown', function (event) {
		var parts = modalParts();
		if (event.key === 'Escape' && parts && !parts.modal.hidden) closeModal();
	});
})();
