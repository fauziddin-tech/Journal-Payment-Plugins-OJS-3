(function () {
	'use strict';
	var page = document.querySelector('.jp-page');
	if (page && page.getAttribute('data-theme-mode') === 'auto') applyThemeAccent(page);

	function rgbParts(value) {
		if (!value || value === 'transparent') return null;
		var probe = document.createElement('span');
		probe.style.color = value;
		probe.style.display = 'none';
		document.body.appendChild(probe);
		var normalized = window.getComputedStyle(probe).color;
		probe.parentNode.removeChild(probe);
		var match = normalized.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?/);
		if (!match || (match[4] !== undefined && Number(match[4]) === 0)) return null;
		return [Number(match[1]), Number(match[2]), Number(match[3])];
	}

	function usefulAccent(value) {
		var rgb = rgbParts(value);
		if (!rgb) return null;
		var max = Math.max.apply(Math, rgb);
		var min = Math.min.apply(Math, rgb);
		var luminance = (rgb[0] * 299 + rgb[1] * 587 + rgb[2] * 114) / 1000;
		if (luminance < 25 || luminance > 235 || max - min < 18) return null;
		return 'rgb(' + rgb.join(',') + ')';
	}

	function applyThemeAccent(target) {
		var rootStyle = window.getComputedStyle(document.documentElement);
		var variables = ['--primary', '--primary-color', '--color-primary', '--brand-primary', '--accent', '--accent-color', '--theme-color'];
		var accent = null;
		for (var i = 0; i < variables.length && !accent; i++) accent = usefulAccent(rootStyle.getPropertyValue(variables[i]).trim());
		var meta = document.querySelector('meta[name="theme-color"]');
		if (!accent && meta) accent = usefulAccent(meta.getAttribute('content'));

		var backgrounds = ['.pkp_structure_head', '.pkp_head_wrapper', '.pkp_site_name_wrapper', '.pkp_navigation_primary_wrapper'];
		for (var b = 0; b < backgrounds.length && !accent; b++) {
			var backgroundSource = document.querySelector(backgrounds[b]);
			if (backgroundSource) accent = usefulAccent(window.getComputedStyle(backgroundSource).backgroundColor);
		}
		var links = ['.pkp_navigation_primary a', '.pkp_navigation_user a', '.pkp_site_name a'];
		for (var l = 0; l < links.length && !accent; l++) {
			var linkSource = document.querySelector(links[l]);
			if (linkSource) accent = usefulAccent(window.getComputedStyle(linkSource).color);
		}
		if (accent) target.style.setProperty('--jp-accent', accent);
	}

	var form = document.getElementById('jp-payment-form');
	if (!form) return;

	var articleId = document.getElementById('jp-article-id');
	var lookupButton = document.getElementById('jp-lookup-button');
	var lookupStatus = document.getElementById('jp-lookup-status');
	var phone = document.getElementById('jp-phone');
	var phoneHelp = document.getElementById('jp-phone-help');
	var submitButton = document.getElementById('jp-submit-button');
	var duplicateAlert = document.getElementById('jp-duplicate-alert');
	var duplicateMessage = document.getElementById('jp-duplicate-message');
	var duplicateStatusLink = document.getElementById('jp-duplicate-status-link');
	var lastLookup = '';
	var isDuplicate = false;

	function setStatus(message, state) {
		lookupStatus.textContent = message;
		lookupStatus.className = 'jp-field-help' + (state ? ' is-' + state : '');
	}

	function fill(id, value, badgeName) {
		var input = document.getElementById(id);
		if (input && value) input.value = value;
		var badge = form.querySelector('[data-badge="' + badgeName + '"]');
		if (badge) badge.textContent = value ? '✓ dari OJS' : '';
	}

	function clearSubmissionData() {
		['jp-name', 'jp-email', 'jp-title'].forEach(function (id) {
			var input = document.getElementById(id);
			if (input) input.value = '';
		});
		form.querySelectorAll('.jp-ojs-badge').forEach(function (badge) {
			badge.textContent = '';
		});
	}

	function setDuplicate(existing, id) {
		isDuplicate = !!(existing && existing.exists);
		if (!duplicateAlert) return;
		duplicateAlert.hidden = !isDuplicate;
		if (submitButton) submitButton.disabled = isDuplicate;
		if (!isDuplicate) return;
		var status = existing.statusLabel || 'Sudah Dikirim';
		duplicateMessage.textContent = 'ID artikel ' + id + ' sudah memiliki data pembayaran dengan status “' + status + '”. Pengiriman baru diblokir agar data tidak ganda.';
		var statusUrl = form.getAttribute('data-status-url');
		if (duplicateStatusLink && statusUrl) {
			duplicateStatusLink.href = statusUrl + (statusUrl.indexOf('?') === -1 ? '?' : '&') + 'articleId=' + encodeURIComponent(id);
		}
	}

	function lookup() {
		var id = articleId.value.trim();
		if (!/^\d+$/.test(id)) {
			setStatus('ID artikel harus berupa angka.', 'error');
			return;
		}
		if (id === lastLookup) return;

		var csrf = form.querySelector('input[name="csrfToken"]');
		var body = new URLSearchParams();
		body.append('articleId', id);
		if (csrf) body.append('csrfToken', csrf.value);
		lookupButton.disabled = true;
		setStatus('Mencari data artikel di OJS…', 'loading');

		fetch(form.getAttribute('data-lookup-url'), {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: body.toString()
		}).then(function (response) {
			return response.json();
		}).then(function (json) {
			if (!json.success) throw new Error(json.message || 'Artikel tidak ditemukan.');
			lastLookup = id;
			fill('jp-name', json.data.name, 'name');
			fill('jp-email', json.data.email, 'email');
			fill('jp-title', json.data.title, 'title');
			setDuplicate(json.data.existingPayment, id);
			if (isDuplicate) {
				setStatus('Artikel ditemukan, tetapi pembayaran sudah pernah dibuat.', 'error');
				duplicateAlert.scrollIntoView({behavior: 'smooth', block: 'nearest'});
			} else {
				setStatus('Artikel ditemukan. Data penulis dan judul sudah diisi dari OJS.', 'success');
			}
		}).catch(function (error) {
			lastLookup = '';
			setDuplicate(null, id);
			setStatus(error.message || 'Data artikel belum dapat diambil.', 'error');
		}).finally(function () {
			lookupButton.disabled = false;
		});
	}

	function normalizePhone(value) {
		var digits = String(value || '').replace(/\D/g, '');
		if (digits.indexOf('0062') === 0) digits = digits.slice(2);
		if (digits.indexOf('0') === 0) digits = '62' + digits.slice(1);
		else if (digits.indexOf('8') === 0) digits = '62' + digits;
		return /^628\d{7,12}$/.test(digits) ? digits : '';
	}

	lookupButton.addEventListener('click', lookup);
	articleId.addEventListener('input', function () {
		lastLookup = '';
		setDuplicate(null, articleId.value.trim());
		clearSubmissionData();
		setStatus(articleId.value.trim() ? 'Klik tombol Cari untuk mengambil data artikel dari OJS.' : 'Masukkan ID artikel, lalu klik tombol Cari.', '');
	});
	articleId.addEventListener('keydown', function (event) {
		if (event.key === 'Enter') {
			event.preventDefault();
			setStatus('Klik tombol Cari untuk mengambil data artikel dari OJS.', '');
		}
	});
	phone.addEventListener('blur', function () {
		var normalized = normalizePhone(phone.value);
		if (normalized) {
			phone.value = normalized;
			phoneHelp.textContent = 'Format WhatsApp siap digunakan: ' + normalized;
			phoneHelp.className = 'jp-field-help is-success';
		} else if (phone.value.trim()) {
			phoneHelp.textContent = 'Nomor tidak valid. Gunakan 08…, 628…, atau +628….';
			phoneHelp.className = 'jp-field-help is-error';
		}
	});
	form.addEventListener('submit', function (event) {
		if (isDuplicate) {
			event.preventDefault();
			setStatus('Pembayaran untuk ID artikel ini sudah pernah dibuat.', 'error');
			return;
		}
		var normalized = normalizePhone(phone.value);
		if (!normalized) {
			event.preventDefault();
			phone.focus();
			phoneHelp.textContent = 'Nomor tidak valid. Gunakan 08…, 628…, atau +628….';
			phoneHelp.className = 'jp-field-help is-error';
			return;
		}
		phone.value = normalized;
	});
})();
