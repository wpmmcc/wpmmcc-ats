/**
 * Field Rules (Hot-plug) tab: validate / save / delete field-rules documents
 * via the wptsall/v2 REST endpoints.
 *
 * Previously rendered as an inline <script> block in the Model editor page
 * (WordPress.org review feedback: use wp_enqueue_script for JS). Data is
 * provided by wp_localize_script() below (window.wptsallFieldRules).
 */
(function () {
	'use strict';
	var cfg = window.wptsallFieldRules || {};
	var restBase = String(cfg.restUrl || '');
	var nonce = String(cfg.nonce || '');
	var out = document.getElementById('wptsall-fr-result');
	if (!out || !restBase) {
		return;
	}
	function headers() {
		return { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce };
	}
	function slug() { return (document.getElementById('wptsall-fr-slug').value || '').trim(); }
	function raw() { return (document.getElementById('wptsall-fr-json').value || '') || ''; }
	async function show(res) {
		var text = await res.text();
		try { out.textContent = JSON.stringify(JSON.parse(text), null, 2); }
		catch (e) { out.textContent = text; }
	}
	document.getElementById('wptsall-fr-validate').addEventListener('click', async function () {
		var res = await fetch(restBase + '/validate', {
			method: 'POST', headers: headers(),
			body: JSON.stringify({ raw: raw() })
		});
		await show(res);
	});
	document.getElementById('wptsall-fr-save').addEventListener('click', async function () {
		var s = slug();
		if (!s) { out.textContent = 'plugin slug required'; return; }
		var res = await fetch(restBase + '/' + encodeURIComponent(s), {
			method: 'PUT', headers: headers(),
			body: JSON.stringify({ raw: raw() })
		});
		await show(res);
		if (res.ok) { window.location.reload(); }
	});
	document.getElementById('wptsall-fr-delete').addEventListener('click', async function () {
		var s = slug();
		if (!s) { out.textContent = 'plugin slug required'; return; }
		var res = await fetch(restBase + '/' + encodeURIComponent(s), {
			method: 'DELETE', headers: headers()
		});
		await show(res);
		if (res.ok) { window.location.reload(); }
	});
})();
