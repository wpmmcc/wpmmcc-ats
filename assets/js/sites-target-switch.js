jQuery(document).ready(function($) {
	$('#target_site_type').on('change', function() {
		var type = $(this).val();
		if (type === 'virtual') {
			$('#target_virtual_row').show();
			$('#target_wp_row').hide();
			$('#target_virtual_site').prop('required', true);
			$('#target_wp_site').prop('required', false);
		} else {
			$('#target_virtual_row').hide();
			$('#target_wp_row').show();
			$('#target_virtual_site').prop('required', false);
			$('#target_wp_site').prop('required', true);
		}
	});

	$('#target_virtual_site').on('change', function() {
		var $selected = $(this).find('option:selected');
		$('#target_site_id').val($selected.val());
		$('#target_lang').val($selected.data('lang'));
	});

	$('#target_wp_site').on('change', function() {
		var $selected = $(this).find('option:selected');
		$('#target_site_id').val($selected.val());
		$('#target_lang').val($selected.data('lang'));
	});
});
