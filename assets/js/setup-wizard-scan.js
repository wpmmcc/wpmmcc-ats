(function () {
	'use strict';

	var config = window.wptsallWizardScan || {};
	document.addEventListener('DOMContentLoaded', function () {
		var btn = document.getElementById('wptsall-wizard-ajax-scan-btn');
		var spinner = document.getElementById('wptsall-wizard-scan-spinner');
		var statusSpan = document.getElementById('wptsall-wizard-scan-status');
		var container = document.getElementById('wptsall-wizard-models-container');
		if (!btn || !spinner || !statusSpan || !container || !config.ajaxUrl) {
			return;
		}

		btn.addEventListener('click', function () {
			btn.disabled = true;
			spinner.classList.add('is-active');
			statusSpan.textContent = config.scanning || '';
			var body = new URLSearchParams();
			body.set('action', 'wptsall_wizard_scan_models');
			body.set('nonce', config.nonce || '');
			fetch(config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			}).then(function (response) {
				return response.json();
			}).then(function (data) {
				btn.disabled = false;
				spinner.classList.remove('is-active');
				var models = data && data.success && data.data && data.data.models ? data.data.models : [];
				if (!models.length) {
					statusSpan.textContent = config.scanEmpty || '';
					return;
				}
				statusSpan.textContent = String(models.length) + ' ' + (config.modelsFound || '');
				container.textContent = '';
				var list = document.createElement('div');
				list.id = 'wptsall-models-list';
				list.style.columnCount = '2';
				list.style.maxWidth = '520px';
				list.style.marginBottom = '15px';
				models.forEach(function (model) {
					var label = document.createElement('label');
					label.style.display = 'block';
					var input = document.createElement('input');
					input.type = 'checkbox';
					input.name = 'models[]';
					input.value = model.id ? String(model.id) : '';
					if (model.status === 'active') {
						input.checked = true;
					}
					var name = document.createTextNode(' ' + (model.plugin_name || ''));
					var detail = document.createElement('small');
					detail.textContent = ' (' + (model.plugin_slug || '') + ', ' + (model.status || '') + ')';
					label.appendChild(input);
					label.appendChild(name);
					label.appendChild(detail);
					list.appendChild(label);
				});
				container.appendChild(list);
			}).catch(function () {
				btn.disabled = false;
				spinner.classList.remove('is-active');
				statusSpan.textContent = config.scanFailed || '';
			});
		});
	});
}());
