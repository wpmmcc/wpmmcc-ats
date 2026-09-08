/**
 * Manual Translation Split-Panel Editor JavaScript
 *
 * Manages the split-panel editor UI for manual translations.
 * Communicates with manual-translations REST endpoints to load
 * source/target field data and save translated content.
 *
 * @package WPTSALL
 * @since 1.0.0
 */

(function($) {
	'use strict';

	/**
	 * Field label mapping (field key -> display label)
	 */
	var fieldLabels = {
		post_title:    'Title',
		post_content:  'Content',
		post_excerpt:  'Excerpt',
		post_date:     'Date',
		post_status:   'Status',
		post_name:     'Slug',
		_thumbnail_id: 'Featured Image'
	};

	/**
	 * Translation Editor object
	 */
	var TranslationEditor = {

		/**
		 * Localized config from PHP
		 */
		config: {},

		/**
		 * Loaded editor data (source, target, field_config, relation)
		 */
		editorData: null,

		/**
		 * Current editor mode: 'translation' (default) or 'edit'
		 */
		currentMode: 'translation',

		/**
		 * TinyMCE editor IDs that have been initialized
		 */
		tinymceEditors: {},

		/**
		 * Initialize the editor
		 */
		init: function() {
			this.config = window.wptsallTranslationEditor || {};

			this.bindEvents();
			this.loadEditorData();
		},

		/**
		 * Media frame instances keyed by field name
		 */
		mediaFrames: {},

		/**
		 * Bind UI event handlers
		 */
		bindEvents: function() {
			var self = this;

			// Save button
			$('#wptsall-save-translation').on('click', function(e) {
				e.preventDefault();
				self.saveTranslation();
			});

			// Auto-translate button
			$('#wptsall-auto-translate').on('click', function(e) {
				e.preventDefault();
				self.handleAutoTranslate();
			});

			// Back to list button
			$('#wptsall-back-to-list').on('click', function(e) {
				e.preventDefault();
				self.navigateBack();
			});

			// Media select button (delegated)
			$(document).on('click', '.wptsall-media-select-btn', function(e) {
				e.preventDefault();
				var fieldName = $(this).data('field');
				self.openMediaModal(fieldName);
			});

			// Media remove button (delegated)
			$(document).on('click', '.wptsall-media-remove-btn', function(e) {
				e.preventDefault();
				var fieldName = $(this).data('field');
				self.removeMedia(fieldName);
			});

			// Mode toggle buttons
			$(document).on('click', '.wptsall-mode-btn', function(e) {
				e.preventDefault();
				var mode = $(this).data('mode');
				self.switchMode(mode);
			});

			// Taxonomy tag remove (delegated)
			$(document).on('click', '.wptsall-tag-remove', function(e) {
				e.preventDefault();
				var termId = $(this).data('term-id');
				var $picker = $(this).closest('.wptsall-taxonomy-picker');
				var taxonomy = $picker.data('taxonomy');
				self.removeTagTerm(taxonomy, termId);
			});

			// Taxonomy tag add button (delegated)
			$(document).on('click', '.wptsall-tag-add-btn', function(e) {
				e.preventDefault();
				var taxonomy = $(this).data('taxonomy');
				self.addTagTerm(taxonomy);
			});

			// Taxonomy tag input enter key (delegated)
			$(document).on('keydown', '.wptsall-tag-input', function(e) {
				if (e.which === 13) {
					e.preventDefault();
					var $picker = $(this).closest('.wptsall-taxonomy-picker');
					var taxonomy = $picker.data('taxonomy');
					self.addTagTerm(taxonomy);
				}
			});
		},

		// -----------------------------------------------------------
		// REST API Communication
		// -----------------------------------------------------------

		/**
		 * Load editor data from REST API
		 *
		 * Fetches source post fields, existing target data, field config,
		 * and relation metadata for the editor.
		 */
		loadEditorData: function() {
			var self = this;

			this.setStatus('loading', this.config.strings.loading);

			$.ajax({
				url: this.config.restUrl + '/manual-translations/editor-data',
				method: 'GET',
				data: {
					source_post_id: this.config.sourcePostId,
					relation_id: this.config.relationId
				},
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', self.config.restNonce);
				},
				success: function(response) {
					self.editorData = response;
					self.renderSourcePanel(response);
					self.renderTargetPanel(response);
					self.initTinyMCEEditors();
					self.clearStatus();
				},
				error: function(xhr) {
					var message = self.config.strings.loadFailed;
					if (xhr.responseJSON && xhr.responseJSON.message) {
						message += ': ' + xhr.responseJSON.message;
					}
					self.showNotice('error', message);
					self.clearStatus();
				}
			});
		},

		/**
		 * Save translated content via REST API
		 *
		 * Collects all editable field values from the target panel
		 * and posts them to the manual-translations endpoint.
		 */
		saveTranslation: function() {
			var self = this;
			var translatedData = this.collectTargetValues();

			if (!translatedData) {
				return;
			}

			this.setStatus('saving', this.config.strings.saving);
			this.setSaveButtonState(true);

			// Collect taxonomy data.
			var taxonomyPayload = this.collectTaxonomyValues();

			var isEditMode = this.currentMode === 'edit';
			var targetId = this.editorData && this.editorData.target && this.editorData.target.ID ? this.editorData.target.ID : 0;

			// In Edit Mode with an existing target, use the PUT endpoint for full editing.
			if (isEditMode && targetId) {
				$.ajax({
					url: this.config.restUrl + '/manual-translations/' + targetId,
					method: 'PUT',
					contentType: 'application/json',
					data: JSON.stringify({
						relation_id: this.config.relationId,
						fields: translatedData,
						taxonomies: taxonomyPayload
					}),
					beforeSend: function(xhr) {
						xhr.setRequestHeader('X-WP-Nonce', self.config.restNonce);
					},
					success: function(response) {
						self.setStatus('saved', self.config.strings.saved);
						self.showNotice('success', self.config.strings.saved);
						self.setSaveButtonState(false);

						if (self.editorData) {
							if (!self.editorData.target) {
								self.editorData.target = {};
							}
							$.extend(self.editorData.target, translatedData);
						}
					},
					error: function(xhr) {
						var message = self.config.strings.saveFailed;
						if (xhr.responseJSON && xhr.responseJSON.message) {
							message += ': ' + xhr.responseJSON.message;
						}
						self.showNotice('error', message);
						self.setStatus('error', self.config.strings.saveFailed);
						self.setSaveButtonState(false);
					}
				});
			} else {
				// Translation Mode: use the existing POST endpoint.
				var payload = {
					source_post_id: this.config.sourcePostId,
					relation_id: this.config.relationId,
					translated_data: translatedData
				};

				// Include taxonomies if any are selected.
				if (taxonomyPayload && Object.keys(taxonomyPayload).length > 0) {
					payload.taxonomies = taxonomyPayload;
				}

				$.ajax({
					url: this.config.restUrl + '/manual-translations',
					method: 'POST',
					contentType: 'application/json',
					data: JSON.stringify(payload),
					beforeSend: function(xhr) {
						xhr.setRequestHeader('X-WP-Nonce', self.config.restNonce);
					},
					success: function(response) {
						self.setStatus('saved', self.config.strings.saved);
						self.showNotice('success', self.config.strings.saved);
						self.setSaveButtonState(false);

						// Update editorData target with saved values
						if (self.editorData) {
							if (!self.editorData.target) {
								self.editorData.target = {};
							}
							$.extend(self.editorData.target, translatedData);

							// Store target ID from response if available.
							if (response && response.target_id) {
								self.editorData.target.ID = response.target_id;
							}
						}
					},
					error: function(xhr) {
						var message = self.config.strings.saveFailed;
						if (xhr.responseJSON && xhr.responseJSON.message) {
							message += ': ' + xhr.responseJSON.message;
						}
						self.showNotice('error', message);
						self.setStatus('error', self.config.strings.saveFailed);
						self.setSaveButtonState(false);
					}
				});
			}
		},

		// -----------------------------------------------------------
		// Panel Rendering
		// -----------------------------------------------------------

		/**
		 * Render the source panel (read-only)
		 *
		 * Displays all configured fields from the source post with
		 * appropriate badges indicating the field type (translate/sync/compute).
		 *
		 * @param {Object} data Editor data from the REST API
		 */
		renderSourcePanel: function(data) {
			var $container = $('#wptsall-source-fields');
			$container.empty();

			if (!data || !data.source || !data.field_config) {
				$container.html('<p class="wptsall-no-data">No data available</p>');
				return;
			}

			var allFields = this.getOrderedFields(data.field_config);
			var source = data.source;

			for (var i = 0; i < allFields.length; i++) {
				var fieldKey = allFields[i].key;
				var fieldType = allFields[i].type;
				var label = fieldLabels[fieldKey] || fieldKey;
				var value = source[fieldKey] || '';

				var $row = this.buildSourceFieldRow(fieldKey, label, fieldType, value);
				$container.append($row);
			}
		},

		/**
		 * Render the target panel (editable form)
		 *
		 * Displays editable inputs for translate/compute fields and
		 * read-only displays for sync fields. Pre-fills values from
		 * existing target data when available.
		 *
		 * @param {Object} data Editor data from the REST API
		 */
		renderTargetPanel: function(data) {
			var $container = $('#wptsall-target-fields');
			$container.empty();

			if (!data || !data.field_config) {
				$container.html('<p class="wptsall-no-data">No data available</p>');
				return;
			}

			var allFields = this.getOrderedFields(data.field_config);
			var target = data.target || {};
			var source = data.source || {};

			for (var i = 0; i < allFields.length; i++) {
				var fieldKey = allFields[i].key;
				var fieldType = allFields[i].type;
				var label = fieldLabels[fieldKey] || fieldKey;

				var $row;

				if (fieldType === 'id_mapping') {
					if (this.isMediaField(fieldKey)) {
						// Media fields: show media picker with preview.
						$row = this.buildMediaFieldRow(fieldKey, label, fieldType, target[fieldKey] || '');
					} else {
						// Non-media ID mapping fields: show informational notice.
						$row = this.buildNonTextFieldRow(fieldKey, label, fieldType, source[fieldKey] || '');
					}
				} else if (fieldType === 'translate') {
					if (this.isNonTextField(fieldKey)) {
						// Non-text fields classified as translate (e.g. image URLs,
						// attachment IDs) cannot be manually translated as text.
						$row = this.buildNonTextFieldRow(fieldKey, label, fieldType, source[fieldKey] || '');
					} else {
						$row = this.buildTranslateFieldRow(fieldKey, label, target[fieldKey] || '');
					}
				} else if (fieldType === 'sync') {
					$row = this.buildSyncFieldRow(fieldKey, label, source[fieldKey] || '');
				} else if (fieldType === 'compute') {
					var defaultValue = target[fieldKey] || '';
					// For post_name: generate slug from target title if no existing value
					if (fieldKey === 'post_name' && !defaultValue && target.post_title) {
						defaultValue = this.slugify(target.post_title);
					}
					$row = this.buildComputeFieldRow(fieldKey, label, defaultValue);
				}

				if ($row) {
					$container.append($row);
				}
			}
		},

		// -----------------------------------------------------------
		// Field Row Builders
		// -----------------------------------------------------------

		/**
		 * Build a source panel field row (read-only display)
		 *
		 * @param {string} fieldKey  Field name (e.g. 'post_title')
		 * @param {string} label     Display label
		 * @param {string} fieldType Badge type ('translate', 'sync', 'compute')
		 * @param {string} value     Field value
		 * @return {jQuery} The field row element
		 */
		buildSourceFieldRow: function(fieldKey, label, fieldType, value) {
			var badgeClass = 'wptsall-badge-' + fieldType;
			var badgeText = this.getBadgeText(fieldType);

			var $row = $('<div>', { 'class': 'wptsall-field-row wptsall-field-row-' + fieldKey });

			var $header = $('<div>', { 'class': 'wptsall-field-header' });
			$header.append($('<span>', { 'class': 'wptsall-field-label', text: label }));
			$header.append($('<span>', { 'class': 'wptsall-field-badge ' + badgeClass, text: badgeText }));

			var $value = $('<div>', { 'class': 'wptsall-field-value' });

			if (this.isMediaField(fieldKey) && value) {
				// Media field: show image preview via REST API data
				// The source data includes _thumbnail_url from the server for previews.
				var sourceData = this.editorData && this.editorData.source ? this.editorData.source : {};
				var previewUrl = sourceData._thumbnail_url || '';

				// For _thumbnail_id, try to use the _thumbnail_url companion field.
				if (fieldKey === '_thumbnail_id' && previewUrl) {
					$value.append($('<img>', {
						'class': 'wptsall-media-preview-img',
						src: previewUrl,
						alt: 'Source media'
					}));
				} else {
					// Show the attachment ID as fallback.
					$value.append($('<div>', {
						'class': 'wptsall-field-display',
						text: 'Attachment #' + value
					}));
				}
			} else if (fieldKey === 'post_content' || fieldKey === 'post_excerpt') {
				// Use pre-formatted display for content fields
				$value.append($('<div>', {
					'class': 'wptsall-field-display wptsall-field-display-text',
					text: value
				}));
			} else {
				$value.append($('<div>', {
					'class': 'wptsall-field-display',
					text: value
				}));
			}

			$row.append($header).append($value);
			return $row;
		},

		/**
		 * Build a translate field row (editable input/textarea)
		 *
		 * @param {string} fieldKey Field name
		 * @param {string} label    Display label
		 * @param {string} value    Pre-fill value
		 * @return {jQuery} The field row element
		 */
		buildTranslateFieldRow: function(fieldKey, label, value) {
			var $row = $('<div>', { 'class': 'wptsall-field-row wptsall-field-row-' + fieldKey + ' wptsall-field-type-translate' });

			var $header = $('<div>', { 'class': 'wptsall-field-header' });
			$header.append($('<span>', { 'class': 'wptsall-field-label', text: label }));
			$header.append($('<span>', {
				'class': 'wptsall-field-badge wptsall-badge-translate',
				text: this.getBadgeText('translate')
			}));

			var $inputWrap = $('<div>', { 'class': 'wptsall-field-input' });

			if (fieldKey === 'post_content') {
				// TinyMCE placeholder: the PHP-rendered wp_editor will be moved here.
				var $tinymceSlot = $('<div>', {
					'class': 'wptsall-tinymce-slot',
					id: 'wptsall-tinymce-slot-content',
					'data-editor-id': 'wptsall_tinymce_content'
				});
				// Hidden textarea fallback for value collection.
				var $hiddenInput = $('<textarea>', {
					'class': 'wptsall-editor-field wptsall-editor-textarea large-text',
					name: 'wptsall_target_' + fieldKey,
					id: 'wptsall-target-' + fieldKey,
					rows: 10,
					'style': 'display:none;'
				}).val(value);
				$inputWrap.append($tinymceSlot).append($hiddenInput);
			} else if (fieldKey === 'post_excerpt') {
				// TinyMCE placeholder for excerpt.
				var $tinymceSlotExcerpt = $('<div>', {
					'class': 'wptsall-tinymce-slot',
					id: 'wptsall-tinymce-slot-excerpt',
					'data-editor-id': 'wptsall_tinymce_excerpt'
				});
				var $hiddenInputExcerpt = $('<textarea>', {
					'class': 'wptsall-editor-field wptsall-editor-textarea',
					name: 'wptsall_target_' + fieldKey,
					id: 'wptsall-target-' + fieldKey,
					rows: 4,
					'style': 'display:none;'
				}).val(value);
				$inputWrap.append($tinymceSlotExcerpt).append($hiddenInputExcerpt);
			} else {
				var $input = $('<input>', {
					type: 'text',
					'class': 'wptsall-editor-field regular-text widefat',
					name: 'wptsall_target_' + fieldKey,
					id: 'wptsall-target-' + fieldKey,
					value: value
				});
				$inputWrap.append($input);
			}

			$row.append($header).append($inputWrap);
			return $row;
		},

		/**
		 * Build a sync field row (read-only, shows source value)
		 *
		 * @param {string} fieldKey Field name
		 * @param {string} label    Display label
		 * @param {string} value    Source value (copied as-is)
		 * @return {jQuery} The field row element
		 */
		buildSyncFieldRow: function(fieldKey, label, value) {
			var $row = $('<div>', { 'class': 'wptsall-field-row wptsall-field-row-' + fieldKey + ' wptsall-field-type-sync' });

			var $header = $('<div>', { 'class': 'wptsall-field-header' });
			$header.append($('<span>', { 'class': 'wptsall-field-label', text: label }));
			$header.append($('<span>', {
				'class': 'wptsall-field-badge wptsall-badge-sync',
				text: this.getBadgeText('sync')
			}));

			var $inputWrap = $('<div>', { 'class': 'wptsall-field-input' });
			$inputWrap.append($('<input>', {
				type: 'text',
				'class': 'wptsall-editor-field regular-text widefat',
				name: 'wptsall_target_' + fieldKey,
				id: 'wptsall-target-' + fieldKey,
				value: value,
				readonly: true,
				disabled: true
			}));

			$row.append($header).append($inputWrap);
			return $row;
		},

		/**
		 * Build a compute field row (editable with generated default)
		 *
		 * @param {string} fieldKey     Field name
		 * @param {string} label        Display label
		 * @param {string} defaultValue Pre-computed or existing value
		 * @return {jQuery} The field row element
		 */
		buildComputeFieldRow: function(fieldKey, label, defaultValue) {
			var $row = $('<div>', { 'class': 'wptsall-field-row wptsall-field-row-' + fieldKey + ' wptsall-field-type-compute' });

			var $header = $('<div>', { 'class': 'wptsall-field-header' });
			$header.append($('<span>', { 'class': 'wptsall-field-label', text: label }));
			$header.append($('<span>', {
				'class': 'wptsall-field-badge wptsall-badge-compute',
				text: this.getBadgeText('compute')
			}));

			var $inputWrap = $('<div>', { 'class': 'wptsall-field-input' });
			$inputWrap.append($('<input>', {
				type: 'text',
				'class': 'wptsall-editor-field regular-text widefat',
				name: 'wptsall_target_' + fieldKey,
				id: 'wptsall-target-' + fieldKey,
				value: defaultValue
			}));

			$row.append($header).append($inputWrap);
			return $row;
		},

		/**
		 * Build a non-text field row (informational notice)
		 *
		 * For fields that contain non-text data (images, attachments, IDs, URLs),
		 * shows a read-only notice instead of an editable text input.
		 *
		 * @param {string} fieldKey  Field name
		 * @param {string} label     Display label
		 * @param {string} fieldType Original field type for badge display
		 * @param {string} value     Current source value (shown read-only)
		 * @return {jQuery} The field row element
		 */
		buildNonTextFieldRow: function(fieldKey, label, fieldType, value) {
			var $row = $('<div>', { 'class': 'wptsall-field-row wptsall-field-row-' + fieldKey + ' wptsall-field-nontext' });

			var $header = $('<div>', { 'class': 'wptsall-field-header' });
			$header.append($('<span>', { 'class': 'wptsall-field-label', text: label }));
			$header.append($('<span>', {
				'class': 'wptsall-field-badge wptsall-badge-' + fieldType,
				text: this.getBadgeText(fieldType)
			}));

			var $notice = $('<div>', { 'class': 'wptsall-nontext-notice' });
			$notice.append($('<span>', {
				'class': 'dashicons dashicons-info-outline wptsall-nontext-icon'
			}));
			$notice.append($('<span>', {
				'class': 'wptsall-nontext-message',
				text: (this.config.strings && this.config.strings.nonTextNotice) || 'Non-text fields do not support manual translation yet'
			}));

			// Show the current value as read-only context
			if (value) {
				var displayValue = String(value);
				if (displayValue.length > 100) {
					displayValue = displayValue.substring(0, 100) + '...';
				}
				$notice.append($('<div>', {
					'class': 'wptsall-nontext-value',
					text: displayValue
				}));
			}

			$row.append($header).append($notice);
			return $row;
		},

		/**
		 * Build a media field row (image preview + select/remove buttons)
		 *
		 * @param {string} fieldKey  Field name (e.g. '_thumbnail_id')
		 * @param {string} label     Display label
		 * @param {string} fieldType Badge type
		 * @param {string} value     Current attachment ID or empty
		 * @return {jQuery} The field row element
		 */
		buildMediaFieldRow: function(fieldKey, label, fieldType, value) {
			var $row = $('<div>', { 'class': 'wptsall-field-row wptsall-field-row-' + fieldKey + ' wptsall-field-media' });

			var $header = $('<div>', { 'class': 'wptsall-field-header' });
			$header.append($('<span>', { 'class': 'wptsall-field-label', text: label }));
			$header.append($('<span>', {
				'class': 'wptsall-field-badge wptsall-badge-' + fieldType,
				text: this.getBadgeText(fieldType)
			}));

			var strings = this.config.strings || {};

			// Media picker container
			var $picker = $('<div>', {
				'class': 'wptsall-media-picker',
				'data-field': fieldKey
			});

			// Preview area
			var $preview = $('<div>', {
				'class': 'wptsall-media-preview',
				id: 'wptsall-media-preview-' + fieldKey
			});

			if (value) {
				$preview.append($('<span>', {
					'class': 'wptsall-media-preview-loading',
					text: strings.loading || 'Loading...'
				}));
				// Load preview via AJAX
				this.loadMediaPreview(fieldKey, value);
			} else {
				$preview.append($('<span>', {
					'class': 'wptsall-media-no-image',
					text: strings.noImage || 'No image selected'
				}));
			}

			// Hidden input for the attachment ID
			var $input = $('<input>', {
				type: 'hidden',
				id: 'wptsall-target-' + fieldKey,
				name: 'wptsall_target_' + fieldKey,
				value: value || '',
				'data-field-type': 'media'
			});

			// Buttons
			var $buttons = $('<div>', { 'class': 'wptsall-media-buttons' });
			var selectText = value ? (strings.changeImage || 'Change Image') : (strings.selectImage || 'Select Image');
			$buttons.append($('<button>', {
				type: 'button',
				'class': 'button wptsall-media-select-btn',
				'data-field': fieldKey,
				text: selectText
			}));

			var $removeBtn = $('<button>', {
				type: 'button',
				'class': 'button wptsall-media-remove-btn',
				'data-field': fieldKey,
				text: strings.removeImage || 'Remove'
			});
			if (!value) {
				$removeBtn.hide();
			}
			$buttons.append($removeBtn);

			$picker.append($preview).append($input).append($buttons);
			$row.append($header).append($picker);
			return $row;
		},

		/**
		 * Load media preview image via WordPress REST API
		 *
		 * @param {string} fieldKey     Field name
		 * @param {string|number} attachmentId Attachment ID
		 */
		loadMediaPreview: function(fieldKey, attachmentId) {
			if (!attachmentId) {
				return;
			}

			var $preview = $('#wptsall-media-preview-' + fieldKey);

			$.ajax({
				url: wpApiSettings ? wpApiSettings.root + 'wp/v2/media/' + attachmentId : '/wp-json/wp/v2/media/' + attachmentId,
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', wpApiSettings ? wpApiSettings.nonce : '');
				},
				success: function(media) {
					$preview.empty();
					if (media && media.media_type === 'image' && media.source_url) {
						// Use medium size if available, otherwise source URL.
						var imgUrl = media.source_url;
						if (media.media_details && media.media_details.sizes && media.media_details.sizes.medium) {
							imgUrl = media.media_details.sizes.medium.source_url;
						}
						$preview.append($('<img>', {
							'class': 'wptsall-media-preview-img',
							src: imgUrl,
							alt: media.alt_text || ''
						}));
					} else if (media && media.source_url) {
						// Non-image attachment
						$preview.append($('<span>', { 'class': 'dashicons dashicons-media-default' }));
						$preview.append($('<span>', { text: ' ' + (media.title && media.title.rendered || 'Attachment #' + attachmentId) }));
					} else {
						$preview.append($('<span>', { text: 'Attachment #' + attachmentId }));
					}
				},
				error: function() {
					$preview.empty();
					$preview.append($('<span>', { text: 'Attachment #' + attachmentId }));
				}
			});
		},

		/**
		 * Open the WordPress media modal for a field
		 *
		 * @param {string} fieldKey Field name (e.g. '_thumbnail_id')
		 */
		openMediaModal: function(fieldKey) {
			var self = this;
			var strings = this.config.strings || {};

			// Reuse existing frame if available
			if (this.mediaFrames[fieldKey]) {
				this.mediaFrames[fieldKey].open();
				return;
			}

			// Create new media frame
			var frame = wp.media({
				title: strings.mediaFieldTitle || 'Select Media',
				button: {
					text: strings.mediaFieldButton || 'Use this media'
				},
				multiple: false,
				library: {
					type: 'image'
				}
			});

			// Handle selection
			frame.on('select', function() {
				var attachment = frame.state().get('selection').first().toJSON();
				self.setMediaField(fieldKey, attachment);
			});

			this.mediaFrames[fieldKey] = frame;
			frame.open();
		},

		/**
		 * Set a media field value after selection from media modal
		 *
		 * @param {string} fieldKey   Field name
		 * @param {Object} attachment WordPress attachment object from wp.media
		 */
		setMediaField: function(fieldKey, attachment) {
			var strings = this.config.strings || {};

			// Update hidden input value
			$('#wptsall-target-' + fieldKey).val(attachment.id);

			// Update preview
			var $preview = $('#wptsall-media-preview-' + fieldKey);
			$preview.empty();

			if (attachment.type === 'image') {
				var imgUrl = attachment.url;
				if (attachment.sizes && attachment.sizes.medium) {
					imgUrl = attachment.sizes.medium.url;
				}
				$preview.append($('<img>', {
					'class': 'wptsall-media-preview-img',
					src: imgUrl,
					alt: attachment.alt || ''
				}));
			} else {
				$preview.append($('<span>', { 'class': 'dashicons dashicons-media-default' }));
				$preview.append($('<span>', { text: ' ' + (attachment.title || attachment.filename || '') }));
			}

			// Update button labels
			var $picker = $preview.closest('.wptsall-media-picker');
			$picker.find('.wptsall-media-select-btn').text(strings.changeImage || 'Change Image');
			$picker.find('.wptsall-media-remove-btn').show();
		},

		/**
		 * Remove media from a field
		 *
		 * @param {string} fieldKey Field name
		 */
		removeMedia: function(fieldKey) {
			var strings = this.config.strings || {};

			// Clear hidden input
			$('#wptsall-target-' + fieldKey).val('');

			// Clear preview
			var $preview = $('#wptsall-media-preview-' + fieldKey);
			$preview.empty();
			$preview.append($('<span>', {
				'class': 'wptsall-media-no-image',
				text: strings.noImage || 'No image selected'
			}));

			// Update button labels
			var $picker = $preview.closest('.wptsall-media-picker');
			$picker.find('.wptsall-media-select-btn').text(strings.selectImage || 'Select Image');
			$picker.find('.wptsall-media-remove-btn').hide();
		},

		/**
		 * Check if a field key represents a media/attachment field
		 *
		 * @param {string} fieldKey The field key to check
		 * @return {boolean} True if the field is a media field
		 */
		isMediaField: function(fieldKey) {
			var mediaFields = ['_thumbnail_id'];

			if (mediaFields.indexOf(fieldKey) !== -1) {
				return true;
			}

			var mediaPatterns = [
				/^_thumbnail/,
				/_image_id$/,
				/_image$/,
				/_gallery$/,
				/_attachment_id$/,
				/^_wp_attached/
			];

			for (var i = 0; i < mediaPatterns.length; i++) {
				if (mediaPatterns[i].test(fieldKey)) {
					return true;
				}
			}

			return false;
		},

		/**
		 * Check if a field key represents a non-text field
		 *
		 * Detects fields that contain non-text data (images, attachments,
		 * file references, IDs, URLs) based on field name patterns.
		 * Only core wp_posts text columns (post_title, post_content,
		 * post_excerpt) are treated as text translate fields.
		 *
		 * @param {string} fieldKey The field key to check
		 * @return {boolean} True if the field is non-text
		 */
		isNonTextField: function(fieldKey) {
			// Known text fields that should always use text editor
			var textFields = [
				'post_title', 'post_content', 'post_excerpt',
				'_wp_attachment_image_alt',
				'_yoast_wpseo_title', '_yoast_wpseo_metadesc',
				'_yoast_wpseo_focuskw',
				'rank_math_title', 'rank_math_description',
				'rank_math_focus_keyword'
			];

			if (textFields.indexOf(fieldKey) !== -1) {
				return false;
			}

			// Patterns that indicate non-text fields
			var nonTextPatterns = [
				/_id$/,           // Attachment/post/term IDs (e.g. _thumbnail_id)
				/_ids$/,          // Multiple IDs (e.g. _product_image_gallery)
				/_image$/,        // Image fields
				/_image_id$/,     // Image ID fields
				/_file$/,         // File fields
				/_url$/,          // URL fields
				/_attachment/,    // Attachment fields
				/_gallery/,       // Gallery fields
				/^_wp_attached/,  // WP attached file
				/^_thumbnail/     // Featured image
			];

			for (var i = 0; i < nonTextPatterns.length; i++) {
				if (nonTextPatterns[i].test(fieldKey)) {
					return true;
				}
			}

			return false;
		},

		// -----------------------------------------------------------
		// Data Collection
		// -----------------------------------------------------------

		/**
		 * Collect all editable field values from the target panel
		 *
		 * Gathers values from translate, compute, and media fields.
		 * Sync fields are excluded because they are copied from source.
		 *
		 * @return {Object|null} Field key-value pairs, or null if no fields
		 */
		collectTargetValues: function() {
			var data = {};
			var fieldConfig = this.editorData && this.editorData.field_config;

			if (!fieldConfig) {
				return null;
			}

			var isEditMode = this.currentMode === 'edit';

			// Collect translate fields (skip non-text fields that have no input)
			var translateFields = fieldConfig.translate_fields || [];
			for (var i = 0; i < translateFields.length; i++) {
				var key = translateFields[i];
				if (this.isNonTextField(key)) {
					continue;
				}
				// Use TinyMCE content for post_content and post_excerpt.
				if (key === 'post_content') {
					data[key] = this.getTinyMCEContent('wptsall_tinymce_content');
				} else if (key === 'post_excerpt') {
					data[key] = this.getTinyMCEContent('wptsall_tinymce_excerpt');
				} else {
					var $field = $('#wptsall-target-' + key);
					if ($field.length) {
						data[key] = $field.val();
					}
				}
			}

			// In Edit Mode, also collect sync fields.
			if (isEditMode) {
				var syncFields = fieldConfig.sync_fields || [];
				for (var s = 0; s < syncFields.length; s++) {
					var sKey = syncFields[s];
					var $sField = $('#wptsall-target-' + sKey);
					if ($sField.length) {
						data[sKey] = $sField.val();
					}
				}
			}

			// Collect compute fields
			var computeFields = fieldConfig.compute_fields || [];
			for (var j = 0; j < computeFields.length; j++) {
				var cKey = computeFields[j];
				var $cField = $('#wptsall-target-' + cKey);
				if ($cField.length) {
					data[cKey] = $cField.val();
				}
			}

			// Collect media fields from id_mapping_fields
			var idMappingFields = fieldConfig.id_mapping_fields || [];
			for (var k = 0; k < idMappingFields.length; k++) {
				var mKey = idMappingFields[k];
				if (this.isMediaField(mKey)) {
					var $mediaField = $('#wptsall-target-' + mKey);
					if ($mediaField.length) {
						var mediaVal = $mediaField.val();
						// Include the field even if empty (allows removal).
						data[mKey] = mediaVal ? parseInt(mediaVal, 10) : '';
					}
				}
			}

			return data;
		},

		// -----------------------------------------------------------
		// Auto-Translate (optional client / external service)
		// -----------------------------------------------------------

		/**
		 * Handle auto-translate button click.
		 *
		 * Dispatches a jQuery event so the optional standalone translation
		 * client (or another integration) can fill fields. All plugin
		 * features are free; this is not license-gated.
		 */
		handleAutoTranslate: function() {
			$(document).trigger('wptsall_auto_translate', {
				sourcePostId: this.config.sourcePostId,
				relationId: this.config.relationId,
				editor: this
			});
		},

		// -----------------------------------------------------------
		// Navigation
		// -----------------------------------------------------------

		/**
		 * Navigate back to the translation list page
		 */
		navigateBack: function() {
			var backUrl = $('#wptsall-back-to-list').data('url');
			if (backUrl) {
				window.location.href = backUrl;
			} else {
				window.history.back();
			}
		},

		// -----------------------------------------------------------
		// UI Helpers
		// -----------------------------------------------------------

		/**
		 * Set the editor status message
		 *
		 * @param {string} type    Status type ('loading', 'saving', 'saved', 'error')
		 * @param {string} message Status text to display
		 */
		setStatus: function(type, message) {
			var $status = $('#wptsall-editor-status');
			$status
				.removeClass('wptsall-status-loading wptsall-status-saving wptsall-status-saved wptsall-status-error')
				.addClass('wptsall-status-' + type)
				.text(message)
				.show();
		},

		/**
		 * Clear the editor status message
		 */
		clearStatus: function() {
			$('#wptsall-editor-status')
				.removeClass('wptsall-status-loading wptsall-status-saving wptsall-status-saved wptsall-status-error')
				.text('')
				.hide();
		},

		/**
		 * Show a WordPress-style admin notice
		 *
		 * @param {string} type    Notice type ('success', 'error', 'warning', 'info')
		 * @param {string} message Notice text
		 */
		showNotice: function(type, message) {
			// Remove any existing translation editor notices
			$('.wptsall-editor-notice').remove();

			var $notice = $('<div>', {
				'class': 'notice notice-' + type + ' is-dismissible wptsall-editor-notice'
			});
			$notice.append($('<p>', { text: message }));

			// Add WordPress dismiss button
			var $dismiss = $('<button>', {
				type: 'button',
				'class': 'notice-dismiss'
			}).append($('<span>', { 'class': 'screen-reader-text', text: 'Dismiss this notice.' }));
			$notice.append($dismiss);

			// Insert notice after the page header
			var $header = $('.wrap > h1, .wrap > h2').first();
			if ($header.length) {
				$header.after($notice);
			} else {
				$('.wrap').prepend($notice);
			}

			// Bind dismiss handler
			$dismiss.on('click', function() {
				$notice.fadeOut(200, function() {
					$(this).remove();
				});
			});

			// Auto-dismiss success notices after 3 seconds
			if (type === 'success') {
				setTimeout(function() {
					$notice.fadeOut(200, function() {
						$(this).remove();
					});
				}, 3000);
			}
		},

		/**
		 * Set the save button disabled/enabled state
		 *
		 * @param {boolean} disabled Whether the button should be disabled
		 */
		setSaveButtonState: function(disabled) {
			$('#wptsall-save-translation').prop('disabled', disabled);
		},

		/**
		 * Get the badge display text for a field type
		 *
		 * @param {string} fieldType Field type ('translate', 'sync', 'compute')
		 * @return {string} Localized badge text
		 */
		getBadgeText: function(fieldType) {
			var strings = this.config.strings || {};

			switch (fieldType) {
				case 'translate':
					return strings.translate || 'Translate';
				case 'sync':
					return strings.sync || 'Sync';
				case 'id_mapping':
					return strings.idMapping || 'ID Mapping';
				case 'compute':
					return strings.compute || 'Compute';
				default:
					return fieldType;
			}
		},

		/**
		 * Get ordered list of all fields with their types
		 *
		 * Combines translate, sync, id_mapping, and compute fields into a single
		 * ordered array with type annotations. Fields appear in the
		 * order: translate -> sync -> id_mapping -> compute.
		 *
		 * @param {Object} fieldConfig The field_config from editor data
		 * @return {Array} Array of { key, type } objects
		 */
		getOrderedFields: function(fieldConfig) {
			var fields = [];
			var i;

			var translateFields = fieldConfig.translate_fields || [];
			for (i = 0; i < translateFields.length; i++) {
				fields.push({ key: translateFields[i], type: 'translate' });
			}

			var syncFields = fieldConfig.sync_fields || [];
			for (i = 0; i < syncFields.length; i++) {
				fields.push({ key: syncFields[i], type: 'sync' });
			}

			var idMappingFields = fieldConfig.id_mapping_fields || [];
			for (i = 0; i < idMappingFields.length; i++) {
				fields.push({ key: idMappingFields[i], type: 'id_mapping' });
			}

			var computeFields = fieldConfig.compute_fields || [];
			for (i = 0; i < computeFields.length; i++) {
				fields.push({ key: computeFields[i], type: 'compute' });
			}

			return fields;
		},

		// -----------------------------------------------------------
		// TinyMCE Integration
		// -----------------------------------------------------------

		/**
		 * Initialize TinyMCE editors for post_content and post_excerpt
		 *
		 * Moves the PHP-rendered wp_editor containers into the JS-created
		 * TinyMCE slot divs, then makes them visible.
		 */
		initTinyMCEEditors: function() {
			var self = this;

			// Move post_content TinyMCE into its slot.
			var $contentSlot = $('#wptsall-tinymce-slot-content');
			var $contentEditor = $('#wptsall-tinymce-content');

			if ($contentSlot.length && $contentEditor.length) {
				$contentEditor.detach().appendTo($contentSlot).show();
				this.tinymceEditors['post_content'] = 'wptsall_tinymce_content';
			}

			// Move post_excerpt TinyMCE into its slot.
			var $excerptSlot = $('#wptsall-tinymce-slot-excerpt');
			var $excerptEditor = $('#wptsall-tinymce-excerpt');

			if ($excerptSlot.length && $excerptEditor.length) {
				$excerptEditor.detach().appendTo($excerptSlot).show();
				this.tinymceEditors['post_excerpt'] = 'wptsall_tinymce_excerpt';
			}

			// Re-initialize TinyMCE instances after DOM move.
			// Use a slight delay to ensure DOM is ready.
			setTimeout(function() {
				self.reinitTinyMCE('wptsall_tinymce_content');
				self.reinitTinyMCE('wptsall_tinymce_excerpt');
			}, 100);
		},

		/**
		 * Re-initialize a TinyMCE editor after DOM manipulation.
		 *
		 * @param {string} editorId The textarea ID for the editor.
		 */
		reinitTinyMCE: function(editorId) {
			if (typeof wp === 'undefined' || typeof wp.editor === 'undefined') {
				return;
			}

			var $textarea = $('#' + editorId);
			if (!$textarea.length) {
				return;
			}

			// If TinyMCE is already initialized for this ID, remove it first.
			if (typeof tinymce !== 'undefined') {
				var existingEditor = tinymce.get(editorId);
				if (existingEditor) {
					// Editor already exists and is working, no need to reinit.
					return;
				}
			}

			// Use wp.editor.initialize if available (WordPress 4.8+).
			try {
				wp.editor.initialize(editorId, {
					tinymce: {
						wpautop: true,
						toolbar1: editorId.indexOf('excerpt') !== -1
							? 'bold,italic,bullist,numlist,link,unlink'
							: 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,wp_more,fullscreen',
						toolbar2: editorId.indexOf('excerpt') !== -1
							? ''
							: 'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help'
					},
					quicktags: true,
					mediaButtons: editorId.indexOf('excerpt') === -1
				});
			} catch (e) {
				// Silently fail — the textarea will still work as a plain editor.
			}
		},

		/**
		 * Get content from a TinyMCE editor instance.
		 *
		 * Falls back to textarea value if TinyMCE is not initialized.
		 *
		 * @param {string} editorId The TinyMCE editor textarea ID.
		 * @return {string} The editor content.
		 */
		getTinyMCEContent: function(editorId) {
			// Try TinyMCE first.
			if (typeof tinymce !== 'undefined') {
				var editor = tinymce.get(editorId);
				if (editor && !editor.isHidden()) {
					return editor.getContent();
				}
			}

			// Fall back to textarea value (HTML editor tab or no TinyMCE).
			var $textarea = $('#' + editorId);
			if ($textarea.length) {
				return $textarea.val();
			}

			return '';
		},

		// -----------------------------------------------------------
		// Mode Toggle
		// -----------------------------------------------------------

		/**
		 * Switch between Translation Mode and Edit Mode.
		 *
		 * @param {string} mode Either 'translation' or 'edit'.
		 */
		switchMode: function(mode) {
			if (mode === this.currentMode) {
				return;
			}

			this.currentMode = mode;

			// Update toggle button state.
			$('.wptsall-mode-btn').removeClass('wptsall-mode-btn-active');
			$('#wptsall-mode-' + mode).addClass('wptsall-mode-btn-active');

			// Update body class for CSS targeting.
			$('#wptsall-translation-editor')
				.removeClass('wptsall-mode-translation wptsall-mode-edit')
				.addClass('wptsall-mode-' + mode);

			if (mode === 'edit') {
				// Edit Mode: enable sync fields.
				$('.wptsall-field-type-sync .wptsall-editor-field').each(function() {
					$(this).prop('readonly', false).prop('disabled', false);
				});
			} else {
				// Translation Mode: disable sync fields.
				$('.wptsall-field-type-sync .wptsall-editor-field').each(function() {
					$(this).prop('readonly', true).prop('disabled', true);
				});
			}
		},

		// -----------------------------------------------------------
		// Taxonomy Handling
		// -----------------------------------------------------------

		/**
		 * Remove a flat taxonomy term tag.
		 *
		 * @param {string} taxonomy  Taxonomy name.
		 * @param {number} termId    Term ID to remove.
		 */
		removeTagTerm: function(taxonomy, termId) {
			var $list = $('#wptsall-tag-list-' + taxonomy);
			$list.find('.wptsall-tag-item[data-term-id="' + termId + '"]').remove();

			// Update hidden input.
			this.updateTagHiddenInput(taxonomy);
		},

		/**
		 * Add a flat taxonomy term tag.
		 *
		 * @param {string} taxonomy Taxonomy name.
		 */
		addTagTerm: function(taxonomy) {
			var $input = $('#wptsall-tag-input-' + taxonomy);
			var termName = $.trim($input.val());

			if (!termName) {
				return;
			}

			var $list = $('#wptsall-tag-list-' + taxonomy);

			// Create a new term via a placeholder ID (negative number).
			// The REST API will create the term on the server side.
			var placeholderId = -1 * (new Date().getTime() % 100000);

			var $tag = $('<span>', {
				'class': 'wptsall-tag-item wptsall-tag-new',
				'data-term-id': placeholderId,
				'data-term-name': termName
			});
			$tag.append(document.createTextNode(termName + ' '));
			$tag.append($('<button>', {
				type: 'button',
				'class': 'wptsall-tag-remove',
				'data-term-id': placeholderId,
				html: '&times;'
			}));

			$list.append($tag);
			$input.val('');

			// Update hidden input.
			this.updateTagHiddenInput(taxonomy);
		},

		/**
		 * Update the hidden input for a flat taxonomy with current term IDs/names.
		 *
		 * @param {string} taxonomy Taxonomy name.
		 */
		updateTagHiddenInput: function(taxonomy) {
			var ids = [];
			$('#wptsall-tag-list-' + taxonomy + ' .wptsall-tag-item').each(function() {
				var termId = $(this).data('term-id');
				if (termId > 0) {
					ids.push(termId);
				}
				// New terms (negative IDs) will be sent as names.
			});
			$('#wptsall-taxonomy-ids-' + taxonomy).val(ids.join(','));
		},

		/**
		 * Collect taxonomy values from all taxonomy pickers.
		 *
		 * @return {Object} Taxonomy data keyed by taxonomy name.
		 */
		collectTaxonomyValues: function() {
			var data = {};
			var taxonomyData = this.config.taxonomyData || [];

			for (var i = 0; i < taxonomyData.length; i++) {
				var tax = taxonomyData[i];
				var taxonomy = tax.name;

				if (tax.hierarchical) {
					// Collect checked checkbox IDs.
					var checkedIds = [];
					$('#wptsall-taxonomy-' + taxonomy + ' input[type="checkbox"]:checked').each(function() {
						var val = parseInt($(this).val(), 10);
						if (val > 0) {
							checkedIds.push(val);
						}
					});
					if (checkedIds.length > 0) {
						data[taxonomy] = checkedIds;
					}
				} else {
					// Collect tag term IDs and new term names.
					var termIds = [];
					var newTermNames = [];
					$('#wptsall-tag-list-' + taxonomy + ' .wptsall-tag-item').each(function() {
						var termId = $(this).data('term-id');
						if (termId > 0) {
							termIds.push(termId);
						} else {
							// New term — send the name.
							var termName = $(this).data('term-name');
							if (termName) {
								newTermNames.push(termName);
							}
						}
					});

					if (termIds.length > 0 || newTermNames.length > 0) {
						data[taxonomy] = {
							term_ids: termIds,
							new_terms: newTermNames
						};
					}
				}
			}

			return data;
		},

		// -----------------------------------------------------------
		// Utility Functions
		// -----------------------------------------------------------

		/**
		 * Generate a URL-safe slug from text
		 *
		 * Converts text to lowercase, replaces whitespace and
		 * common separators with hyphens, removes special characters,
		 * and collapses multiple hyphens.
		 *
		 * @param {string} text Input text
		 * @return {string} URL-safe slug
		 */
		slugify: function(text) {
			if (!text) {
				return '';
			}

			return text
				.toString()
				.toLowerCase()
				.replace(/\s+/g, '-')           // Replace whitespace with hyphens
				.replace(/[^\w\u4e00-\u9fff-]+/g, '') // Remove non-word chars (keep CJK + hyphens)
				.replace(/--+/g, '-')            // Collapse multiple hyphens
				.replace(/^-+/, '')              // Trim leading hyphens
				.replace(/-+$/, '');             // Trim trailing hyphens
		}
	};

	/**
	 * Initialize when document is ready
	 */
	$(document).ready(function() {
		TranslationEditor.init();
	});

})(jQuery);
