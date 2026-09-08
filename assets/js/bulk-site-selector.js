jQuery(document).ready(function($) {
	var html = (window.wptsallBulkSiteSelector && wptsallBulkSiteSelector.selectorHtml) ? wptsallBulkSiteSelector.selectorHtml : '';
	var alertMsg = (window.wptsallBulkSiteSelector && wptsallBulkSiteSelector.alertMsg) ? wptsallBulkSiteSelector.alertMsg : '';
	var siteSelector = $('<select>').attr('name', 'wptsall_bulk_site_id').attr('id', 'wptsall-bulk-site-selector').addClass('wptsall-bulk-site-selector').html(html);
	$('select[name="action"], select[name="action2"]').each(function() {
		var clone = siteSelector.clone();
		$(this).after(clone);
	});
	$('select[name="action"], select[name="action2"]').on('change', function() {
		var action = $(this).val();
		var $selector = $(this).next('.wptsall-bulk-site-selector');
		if (action === 'wptsall_assign_site') {
			$selector.addClass('active').prop('required', true);
		} else {
			$selector.removeClass('active').prop('required', false);
		}
	});
	$(document).on('change', '.wptsall-bulk-site-selector', function() {
		var value = $(this).val();
		$('.wptsall-bulk-site-selector').val(value);
	});
	$('#posts-filter').on('submit', function(e) {
		var action = $('select[name="action"]').val();
		if (action === '-1') {
			action = $('select[name="action2"]').val();
		}
		if (action === 'wptsall_assign_site') {
			var siteId = $('#wptsall-bulk-site-selector').val() || $('.wptsall-bulk-site-selector').first().val();
			if (!siteId) {
				alert(alertMsg);
				e.preventDefault();
				return false;
			}
		}
	});
});
