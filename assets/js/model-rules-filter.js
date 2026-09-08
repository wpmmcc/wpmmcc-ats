jQuery(document).ready(function($) {
	$('#wptsall-filter-btn').on('click', function() {
		var plugin = $('#wptsall-plugin-filter').val();
		var url = (window.wptsallModelRulesFilter && wptsallModelRulesFilter.rulesUrl) ? wptsallModelRulesFilter.rulesUrl : '';
		if (plugin) {
			url += (url.indexOf('?') === -1 ? '?' : '&') + 'plugin=' + encodeURIComponent(plugin);
		}
		window.location.href = url;
	});
});
