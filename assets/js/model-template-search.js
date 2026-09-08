jQuery(document).ready(function($) {
	$('#wptsall-template-search').on('input', function() {
		var q = $(this).val().toLowerCase();
		$('.wptsall-templates-table tbody tr').each(function() {
			var name = $(this).find('.column-name').text().toLowerCase();
			$(this).toggle(name.indexOf(q) !== -1);
		});
	});
});
