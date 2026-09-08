/**
 * Templates Admin JavaScript
 *
 * @package WPTSALL\Templates
 * @since 0.5.0
 */

(function($) {
	'use strict';

	var WPTSALL_Templates = {
		/**
		 * Initialize
		 */
		init: function() {
			this.bindEvents();
			this.initCheckboxes();
		},

		/**
		 * Bind events
		 */
		bindEvents: function() {
			// Scan button
			$(document).on('click', '.wptsall-scan-btn', this.handleScan);

			// Rescan button
			$(document).on('click', '.wptsall-rescan-btn', this.handleRescan);

			// Delete button
			$(document).on('click', '.wptsall-delete-btn', this.handleDelete);

			// Export button
			$(document).on('click', '.wptsall-export-btn', this.handleExport);

			// Import button
			$(document).on('click', '.wptsall-import-btn', this.showImportModal);

			// Import form
			$(document).on('submit', '#wptsall-import-form', this.handleImport);

			// Edit entry button
			$(document).on('click', '.wptsall-edit-entry-btn', this.showEditModal);

			// Entry form
			$(document).on('submit', '#wptsall-entry-form', this.handleEntryUpdate);

			// Delete entry button
			$(document).on('click', '.wptsall-delete-entry-btn', this.handleDeleteEntry);

			// Bulk actions
			$(document).on('click', '.wptsall-bulk-apply', this.handleBulkAction);

			// Modal close
			$(document).on('click', '.wptsall-modal-close, .wptsall-modal-cancel', this.closeModal);
			$(document).on('click', '.wptsall-modal', function(e) {
				if ($(e.target).hasClass('wptsall-modal')) {
					WPTSALL_Templates.closeModal();
				}
			});

			// Checkbox select all
			$(document).on('change', '#cb-select-all-1', this.handleSelectAll);
			$(document).on('change', 'input[name="entry_ids[]"]', this.updateSelectedCount);
		},

		/**
		 * Initialize checkboxes
		 */
		initCheckboxes: function() {
			this.updateSelectedCount();
		},

		/**
		 * Handle scan
		 */
		handleScan: function(e) {
			e.preventDefault();

			var relationId = $(this).data('relation-id');
			if (!relationId) {
				alert(wptsallTemplates.strings.selectRelation || 'Please select a site relation');
				return;
			}

			var $modal = $('#wptsall-scan-modal');
			var $progress = $modal.find('.wptsall-progress-fill');
			var $text = $modal.find('.wptsall-progress-text');
			var $results = $modal.find('.wptsall-scan-results');
			var $resultsList = $modal.find('.wptsall-results-list');

			// Reset modal
			$progress.css('width', '0%');
			$text.text(wptsallTemplates.strings.scanning);
			$results.hide();
			$resultsList.empty();
			$modal.show();

			// Animate progress
			$progress.animate({ width: '50%' }, 500);

			// Make API call
			wp.apiFetch({
				path: '/wptsall/v2/templates/scan',
				method: 'POST',
				data: { relation_id: relationId }
			}).then(function(response) {
				$progress.animate({ width: '100%' }, 300, function() {
					$text.text(wptsallTemplates.strings.scanSuccess);

					// Show results
					if (response.templates && response.templates.length > 0) {
						response.templates.forEach(function(template) {
							$resultsList.append(
								'<div class="result-item">' +
								'<strong>' + template.text_domain + '</strong> (' + template.source_type + ')<br>' +
								'<small>' + template.entry_count + ' entries</small>' +
								'</div>'
							);
						});
						$results.show();
					}

					// Reload page after 2 seconds
					setTimeout(function() {
						location.reload();
					}, 2000);
				});
			}).catch(function(error) {
				$text.text(wptsallTemplates.strings.scanFailed + ': ' + (error.message || 'Unknown error'));
				$progress.css('background', '#d63638');
			});
		},

		/**
		 * Handle rescan
		 */
		handleRescan: function(e) {
			e.preventDefault();

			var templateId = $(this).data('id');
			var $btn = $(this);

			if (!confirm(wptsallTemplates.strings.confirm)) {
				return;
			}

			$btn.prop('disabled', true).text(wptsallTemplates.strings.scanning);

			wp.apiFetch({
				path: '/wptsall/v2/templates/' + templateId + '/rescan',
				method: 'POST'
			}).then(function(response) {
				alert('Rescan completed: ' + response.new + ' new, ' + response.updated + ' updated');
				location.reload();
			}).catch(function(error) {
				alert(wptsallTemplates.strings.scanFailed + ': ' + (error.message || 'Unknown error'));
				$btn.prop('disabled', false).text('Rescan');
			});
		},

		/**
		 * Handle delete
		 */
		handleDelete: function(e) {
			e.preventDefault();

			var templateId = $(this).data('id');

			if (!confirm(wptsallTemplates.strings.confirm)) {
				return;
			}

			var $btn = $(this);
			$btn.prop('disabled', true).text(wptsallTemplates.strings.deleting);

			wp.apiFetch({
				path: '/wptsall/v2/templates/' + templateId,
				method: 'DELETE'
			}).then(function() {
				$btn.closest('tr').fadeOut(function() {
					$(this).remove();
				});
			}).catch(function(error) {
				alert('Delete failed: ' + (error.message || 'Unknown error'));
				$btn.prop('disabled', false).text('Delete');
			});
		},

		/**
		 * Handle export
		 */
		handleExport: function(e) {
			e.preventDefault();

			var templateId = $(this).data('id');

			wp.apiFetch({
				path: '/wptsall/v2/templates/' + templateId + '/export',
				method: 'GET'
			}).then(function(response) {
				// Create download
				var blob = new Blob([response.content], { type: 'text/plain' });
				var url = URL.createObjectURL(blob);
				var a = document.createElement('a');
				a.href = url;
				a.download = response.filename;
				document.body.appendChild(a);
				a.click();
				document.body.removeChild(a);
				URL.revokeObjectURL(url);
			}).catch(function(error) {
				alert('Export failed: ' + (error.message || 'Unknown error'));
			});
		},

		/**
		 * Show import modal
		 */
		showImportModal: function(e) {
			e.preventDefault();

			var templateId = $(this).data('id');
			$('#wptsall-import-form').data('template-id', templateId);
			$('#wptsall-import-result').hide();
			$('#import-file').val('');
			$('#wptsall-import-modal').show();
		},

		/**
		 * Handle import
		 */
		handleImport: function(e) {
			e.preventDefault();

			var templateId = $(this).data('template-id');
			var fileInput = $('#import-file')[0];

			if (!fileInput.files.length) {
				alert('Please select a file');
				return;
			}

			var file = fileInput.files[0];
			var reader = new FileReader();

			reader.onload = function(e) {
				var content = e.target.result;

				wp.apiFetch({
					path: '/wptsall/v2/templates/' + templateId + '/import',
					method: 'POST',
					data: { content: content }
				}).then(function(response) {
					$('#wptsall-import-result').show();
					$('.wptsall-import-stats').text(
						'Imported: ' + response.imported + ', Updated: ' + response.updated
					);

					setTimeout(function() {
						location.reload();
					}, 2000);
				}).catch(function(error) {
					alert('Import failed: ' + (error.message || 'Unknown error'));
				});
			};

			reader.readAsText(file);
		},

		/**
		 * Show edit modal
		 */
		showEditModal: function(e) {
			e.preventDefault();

			var entry = $(this).data('entry');
			var $modal = $('#wptsall-entry-modal');

			// Populate form
			$('#entry-id').val(entry.id);
			$('#entry-msgctxt').val(entry.msgctxt || '');
			$('#entry-msgid').val(entry.msgid);
			$('#entry-msgstr').val(entry.msgstr || '');
			$('#entry-status').val(entry.status);
			$('#entry-note').val(entry.note || '');
			$('#entry-reference').text(entry.reference || 'N/A');

			// Handle plural forms
			if (entry.msgid_plural) {
				$('#entry-msgid-plural').val(entry.msgid_plural);
				$('#entry-msgstr-plural').val(entry.msgstr_plural || '');
				$('#entry-msgid-plural-row, #entry-msgstr-plural-row').show();
			} else {
				$('#entry-msgid-plural-row, #entry-msgstr-plural-row').hide();
			}

			$modal.show();
		},

		/**
		 * Handle entry update
		 */
		handleEntryUpdate: function(e) {
			e.preventDefault();

			var entryId = $('#entry-id').val();
			var data = {
				msgstr: $('#entry-msgstr').val(),
				status: $('#entry-status').val(),
				note: $('#entry-note').val()
			};

			// Include plural if visible
			if ($('#entry-msgstr-plural-row').is(':visible')) {
				data.msgstr_plural = $('#entry-msgstr-plural').val();
			}

			wp.apiFetch({
				path: '/wptsall/v2/template-entries/' + entryId,
				method: 'PUT',
				data: data
			}).then(function(response) {
				WPTSALL_Templates.closeModal();

				// Update row
				var $row = $('tr[data-entry-id="' + entryId + '"]');
				if (response.entry) {
					var entry = response.entry;
					$row.find('.wptsall-msgstr-text').text(entry.msgstr || '');
					$row.find('.wptsall-entry-status')
						.removeClass()
						.addClass('wptsall-entry-status wptsall-entry-status-' + entry.status)
						.text(entry.status);

					// Update edit button data
					$row.find('.wptsall-edit-entry-btn').data('entry', entry);
				}
			}).catch(function(error) {
				alert('Update failed: ' + (error.message || 'Unknown error'));
			});
		},

		/**
		 * Handle delete entry
		 */
		handleDeleteEntry: function(e) {
			e.preventDefault();

			var entryId = $(this).data('id');
			var $btn = $(this);
			var $row = $btn.closest('tr');

			if (!confirm(wptsallTemplates.strings.confirm || 'Are you sure you want to delete this entry?')) {
				return;
			}

			$btn.prop('disabled', true).text(wptsallTemplates.strings.deleting || 'Deleting...');

			wp.apiFetch({
				path: '/wptsall/v2/template-entries/' + entryId,
				method: 'DELETE'
			}).then(function(response) {
				$row.fadeOut(function() {
					$(this).remove();

					// Update entry count
					var $count = $('.wptsall-selected-count');
					var currentText = $count.text();
					if (currentText) {
						location.reload(); // Reload to update stats
					}
				});
			}).catch(function(error) {
				alert('Delete failed: ' + (error.message || 'Unknown error'));
				$btn.prop('disabled', false).text(wptsallTemplates.strings.delete || 'Delete');
			});
		},

		/**
		 * Handle bulk action
		 */
		handleBulkAction: function(e) {
			e.preventDefault();

			var action = $('#bulk-action-selector').val();
			if (!action) {
				alert('Please select an action');
				return;
			}

			var ids = [];
			$('input[name="entry_ids[]"]:checked').each(function() {
				ids.push(parseInt($(this).val()));
			});

			if (!ids.length) {
				alert('Please select at least one entry');
				return;
			}

			if (!confirm(wptsallTemplates.strings.confirm)) {
				return;
			}

			var statusMap = {
				'mark_reviewed': 'reviewed',
				'mark_skipped': 'skipped',
				'mark_pending': 'pending'
			};

			wp.apiFetch({
				path: '/wptsall/v2/template-entries/bulk-update',
				method: 'POST',
				data: {
					ids: ids,
					data: { status: statusMap[action] }
				}
			}).then(function(response) {
				alert('Updated: ' + response.updated + ' entries');
				location.reload();
			}).catch(function(error) {
				alert('Bulk update failed: ' + (error.message || 'Unknown error'));
			});
		},

		/**
		 * Handle select all
		 */
		handleSelectAll: function() {
			var checked = $(this).prop('checked');
			$('input[name="entry_ids[]"]').prop('checked', checked);
			WPTSALL_Templates.updateSelectedCount();
		},

		/**
		 * Update selected count
		 */
		updateSelectedCount: function() {
			var count = $('input[name="entry_ids[]"]:checked').length;
			$('.wptsall-selected-count').text(count > 0 ? count + ' selected' : '');
		},

		/**
		 * Close modal
		 */
		closeModal: function() {
			$('.wptsall-modal').hide();
		}
	};

	$(document).ready(function() {
		WPTSALL_Templates.init();
	});

})(jQuery);
