/**
 * Site Relations Management JavaScript
 *
 * @package WPTSALL
 * @since 0.3.0
 * @updated 0.5.0 - Migrated to REST API
 * @updated 0.6.0 - Add many-to-many model association management
 */

(function ($) {
	'use strict';

	/**
	 * Site Relations management object
	 */
	const SiteRelations = {
		/**
		 * Current step
		 */
		currentStep: 1,

		/**
		 * Max steps
		 */
		maxSteps: 3,

		/**
		 * Form data
		 */
		formData: {
			template: '',
			source_site_id: '',
			target_sites: [],
			media_handling: 'copy'
		},

		/**
		 * REST API base URL
		 */
		restUrl: '',

		/**
		 * REST API Nonce
		 */
		restNonce: '',

		/**
		 * Initialize
		 */
		init() {
			// Initialize REST API config
			this.restUrl = wptsallSiteRelations.restUrl || '/wp-json/wptsall/v2';
			this.restNonce = wptsallSiteRelations.restNonce || wptsallSiteRelations.nonce;

			this.initSelect2();
			this.initWizard();
			this.initActions();
			this.initModals();
			this.initVirtualSites();
		},

		/**
		 * REST API request wrapper
		 */
		apiRequest(endpoint, options = {}) {
			const defaults = {
				method: 'GET',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': this.restNonce
				}
			};

			const config = { ...defaults, ...options };

			// Process URL
			let url = this.restUrl + endpoint;
			if (config.method === 'GET' && config.data) {
				const params = new URLSearchParams(config.data);
				url += '?' + params.toString();
				delete config.data;
			}

			// Process request body
			if (config.data && config.method !== 'GET') {
				config.body = JSON.stringify(config.data);
				delete config.data;
			}

			return fetch(url, config)
				.then(response => {
					if (!response.ok) {
						return response.json().then(err => {
							throw new Error(err.message || 'Request failed');
						});
					}
					return response.json();
				});
		},

		/**
		 * Initialize Select2
		 */
		initSelect2() {
			if (typeof $.fn.select2 === 'undefined') {
				return;
			}

			// Initialize all select2 elements (non-language selectors)
			$('.wptsall-select2').not('[name*="lang"], [id*="lang"]').select2({
				width: '100%',
				placeholder: function() {
					return $(this).data('placeholder') || '';
				},
				allowClear: true
			});

			// Initialize language selector (supports custom input)
			$('.wptsall-select2').filter('[name*="lang"], [id*="lang"]').select2({
				width: '100%',
				placeholder: function() {
					return $(this).data('placeholder') || '';
				},
				allowClear: true,
				tags: true,  // Allow custom input
				createTag: function(params) {
					const term = $.trim(params.term);
					if (term === '') {
						return null;
					}
					// Validate language code format (e.g. en_US, zh_CN, ja)
					if (!/^[a-z]{2,3}(_[A-Z]{2})?$/.test(term)) {
						return null;
					}
					return {
						id: term,
						text: term + ' (custom)',
						newTag: true
					};
				}
			});

			// Source site selection
			$('#source_site_id').on('change', function() {
				SiteRelations.onSourceSiteChange($(this).val());
			});

			// Target site selection
			$('#target_sites').on('change', function() {
				SiteRelations.onTargetSitesChange($(this).val());
			});
		},

		/**
		 * Initialize wizard
		 */
		initWizard() {
			const self = this;

			// Model selection
			$('input[name="template"]').on('change', function() {
				self.formData.template = $(this).val();
				self.validateStep(1);
			});

			// Next button
			$('.wptsall-wizard-next').on('click', function() {
				self.nextStep();
			});

			// Previous button
			$('.wptsall-wizard-prev').on('click', function() {
				self.prevStep();
			});

			// Form submit
			$('#wptsall-create-relation-form').on('submit', function(e) {
				e.preventDefault();
				self.submitForm();
			});
		},

		/**
		 * Initialize action buttons
		 */
		initActions() {
			const self = this;

			// Delete relation
			$(document).on('click', '.wptsall-delete-relation-btn', function(e) {
				e.preventDefault();

				if (!confirm(wptsallSiteRelations.strings.confirmDelete)) {
					return;
				}

				const relationId = $(this).data('relation-id');
				self.deleteRelation(relationId);
			});

			// v0.6.0: View models
			$(document).on('click', '.wptsall-view-models-btn', function(e) {
				e.preventDefault();
				const relationId = $(this).data('relation-id');
				self.showModelsModal(relationId);
			});

			// v0.6.0: Add model
			$(document).on('click', '.wptsall-add-models-btn', function(e) {
				e.preventDefault();
				const relationId = $(this).data('relation-id');
				self.showModelsModal(relationId, true);
			});

			// Edit relation
			$(document).on('click', '.wptsall-edit-relation-btn', function(e) {
				e.preventDefault();
				const relationId = $(this).data('relation-id');
				self.editRelation(relationId);
			});

			// Sync theme info
			$(document).on('click', '.wptsall-sync-theme-btn', function(e) {
				e.preventDefault();
				const $btn = $(this);
				const relationId = $btn.data('relation-id');
				const sourceId = $btn.data('source-id');
				const sourceLang = $btn.data('source-lang');
				const template = $btn.data('template');

				// If relation-id exists, single relation sync
				if (relationId) {
					self.syncSingleRelationTheme(relationId, $btn);
				} else if (sourceId && template) {
					// Legacy batch sync (backward compatible)
					self.syncThemeInfo(sourceId, sourceLang, template, $btn);
				}
			});

			// Add Target Site
			$(document).on('click', '.wptsall-add-target-btn', function(e) {
				e.preventDefault();
				const $btn = $(this);
				const sourceId = $btn.data('source-id');
				const sourceLang = $btn.data('source-lang');
				const template = $btn.data('template');
				self.showAddTargetsModal(sourceId, sourceLang, template);
			});

			// Pause relation
			$(document).on('click', '.wptsall-pause-relation-btn', function(e) {
				e.preventDefault();

				if (!confirm(wptsallSiteRelations.strings.confirmPause)) {
					return;
				}

				const relationId = $(this).data('relation-id');
				self.updateStatus(relationId, 'inactive');
			});

			// Activate relation
			$(document).on('click', '.wptsall-activate-relation-btn', function(e) {
				e.preventDefault();

				if (!confirm(wptsallSiteRelations.strings.confirmActivate)) {
					return;
				}

				const relationId = $(this).data('relation-id');
				self.updateStatus(relationId, 'active');
			});

			// v0.8.0: Post Type configuration
			$(document).on('click', '.wptsall-config-relation-btn', function(e) {
				e.preventDefault();
				const relationId = $(this).data('relation-id');
				self.showPostTypeConfigsModal(relationId);
			});

			// ISS-SIT-018: Copy configuration to other relations
			$(document).on('click', '.wptsall-copy-config-btn', function(e) {
				e.preventDefault();
				const relationId = $(this).data('relation-id');
				self.showCopyConfigModal(relationId);
			});
		},

		/**
		 * Next step
		 */
		nextStep() {
			if (!this.validateStep(this.currentStep)) {
				return;
			}

			if (this.currentStep < this.maxSteps) {
				this.currentStep++;
				this.renderStep(this.currentStep);
			}
		},

		/**
		 * Previous step
		 */
		prevStep() {
			if (this.currentStep > 1) {
				this.currentStep--;
				this.renderStep(this.currentStep);
			}
		},

		/**
		 * Render step
		 */
		renderStep(step) {
			$('.wptsall-wizard-step').each(function() {
				const stepNum = $(this).data('step');
				$(this).removeClass('wptsall-wizard-step-active wptsall-wizard-step-completed');

				if (stepNum < step) {
					$(this).addClass('wptsall-wizard-step-completed');
				} else if (stepNum === step) {
					$(this).addClass('wptsall-wizard-step-active');
				}
			});

			$('.wptsall-wizard-content').hide();
			$(`.wptsall-wizard-content[data-step="${step}"]`).show();

			if (step === 1) {
				$('.wptsall-wizard-prev').hide();
				$('.wptsall-wizard-next').show();
				$('.wptsall-wizard-submit').hide();
			} else if (step === this.maxSteps) {
				$('.wptsall-wizard-prev').show();
				$('.wptsall-wizard-next').hide();
				$('.wptsall-wizard-submit').show();
			} else {
				$('.wptsall-wizard-prev').show();
				$('.wptsall-wizard-next').show();
				$('.wptsall-wizard-submit').hide();
			}
		},

		/**
		 * Validate step
		 */
		validateStep(step) {
			let isValid = true;
			let errorMessage = '';

			switch (step) {
				case 1:
					if (!this.formData.template) {
						isValid = false;
						errorMessage = wptsallSiteRelations.strings.selectModel;
					}
					break;

				case 2:
					const sourceId = $('#source_site_id').val();
					if (!sourceId) {
						isValid = false;
						errorMessage = wptsallSiteRelations.strings.selectSource;
					}
					this.formData.source_site_id = sourceId;
					break;

				case 3:
					const targetSiteId = $('#target_site_id').val();
					if (!targetSiteId) {
						isValid = false;
						errorMessage = wptsallSiteRelations.strings.selectTarget;
					}
					this.formData.target_site_id = targetSiteId;
					this.formData.target_site_type = $('#target_site_type').val() || 'virtual';
					this.formData.target_lang = $('#target_lang').val();
					break;
			}

			if (!isValid && errorMessage) {
				this.showError(errorMessage);
			}

			return isValid;
		},

		/**
		 * On source site change
		 */
		onSourceSiteChange(siteId) {
			if (!siteId) {
				$('#wptsall-plugin-status-check').hide();
				return;
			}

			this.checkPluginStatus(this.formData.template, siteId);
		},

		/**
		 * On target sites change
		 */
		onTargetSitesChange(sites) {
			if (!sites || sites.length === 0) {
				$('#wptsall-relation-summary').hide();
				return;
			}

			this.renderRelationSummary();
		},

		/**
		 * Check plugin status (REST API)
		 */
		checkPluginStatus(template, siteId) {
			const $statusDiv = $('#wptsall-plugin-status-check');
			$statusDiv.html('<p>Check plugin status...</p>').show();

			this.apiRequest('/site-relations/check-plugin-status', {
				method: 'GET',
				data: { template: template, site_id: siteId }
			})
				.then(data => {
					if (data.active) {
						$statusDiv
							.removeClass('wptsall-status-error')
							.addClass('wptsall-status-success')
							.html('<p><span class="dashicons dashicons-yes-alt"></span> Plugin is active on the source site</p>');
					} else {
						$statusDiv
							.removeClass('wptsall-status-success')
							.addClass('wptsall-status-error')
							.html('<p><span class="dashicons dashicons-warning"></span> Warning: Plugin is not active on the source site</p>');
					}
				})
				.catch(() => {
					$statusDiv.html('<p>Unable to check plugin status</p>');
				});
		},

		/**
		 * Render relation summary
		 */
		renderRelationSummary() {
			const $summary = $('#wptsall-relation-summary');

			const templateName = $('input[name="template"]:checked').closest('.wptsall-model-card').find('h4').text();
			const sourceName = $('#source_site_id option:selected').text();
			const targetCount = $('#target_sites').val().length;

			const html = `
				<h4>Relation Summary</h4>
				<ul>
					<li><strong>Model: </strong>${templateName}</li>
					<li><strong>Source Site: </strong>${sourceName}</li>
					<li><strong>Target Sites: </strong>${targetCount}  site(s)</li>
				</ul>
				<p class="description">Click "Create Relation" to start syncing content.</p>
			`;

			$summary.html(html).show();
		},

		/**
		 * Submit form (REST API)
		 */
		submitForm() {
			const self = this;

			if (!this.validateStep(this.maxSteps)) {
				return;
			}

			const $form = $('#wptsall-create-relation-form');
			const $submitBtn = $('.wptsall-wizard-submit');

			$submitBtn.prop('disabled', true).text('Creating...');
			$form.addClass('wptsall-loading');

			const formData = {
				source_site_id: parseInt($form.find('#source_site_id').val()) || 1,
				source_lang: $form.find('#source_lang').val() || '',
				template: $form.find('#template').val() || '',
				target_sites: [{
					id: $form.find('#target_site_id').val() || '',
					type: $form.find('#target_site_type').val() || 'virtual',
					lang: $form.find('#target_lang').val() || ''
				}],
				media_handling: $form.find('#media_handling').val() || 'copy'
			};

			this.apiRequest('/site-relations', {
				method: 'POST',
				data: formData
			})
				.then(data => {
					$form.removeClass('wptsall-loading');
					$submitBtn.prop('disabled', false).text('Create Relation');

					const message = data.message || 'Site relation created successfully';
					alert(message);
					window.location.href = window.location.href.replace(/&tab=add/, '');
				})
				.catch(error => {
					$form.removeClass('wptsall-loading');
					$submitBtn.prop('disabled', false).text('Create Relation');
					alert('Error: ' + error.message);
				});
		},

		/**
		 * Delete relation (REST API)
		 */
		deleteRelation(relationId) {
			const $row = $(`tr[data-relation-id="${relationId}"]`);
			$row.addClass('wptsall-loading');

			this.apiRequest(`/site-relations/${relationId}`, {
				method: 'DELETE'
			})
				.then(() => {
					$row.fadeOut(300, function() {
						$(this).remove();

						if ($('.wptsall-relations-list tbody tr').length === 0) {
							location.reload();
						}
					});
				})
				.catch(error => {
					alert(error.message || 'Delete failed');
					$row.removeClass('wptsall-loading');
				});
		},

		/**
		 * Update status (REST API)
		 */
		updateStatus(relationId, status) {
			const $row = $(`tr[data-relation-id="${relationId}"]`);
			$row.addClass('wptsall-loading');

			this.apiRequest(`/site-relations/${relationId}`, {
				method: 'PUT',
				data: { status: status }
			})
				.then(() => {
					location.reload();
				})
				.catch(error => {
					alert(error.message || 'Status update failed');
					$row.removeClass('wptsall-loading');
				});
		},

		/**
		 * Edit relation (REST API)
		 */
		editRelation(relationId) {
			this.apiRequest(`/site-relations/${relationId}`, {
				method: 'GET'
			})
				.then(data => {
					this.showEditModal(data);
				})
				.catch(error => {
					alert(error.message || 'Failed to get relation details');
				});
		},

		/**
		 * Show edit modal
		 */
		showEditModal(relation) {
			const modalHtml = `
				<div class="wptsall-modal wptsall-edit-modal" id="wptsall-edit-relation-modal">
					<div class="wptsall-modal-overlay"></div>
					<div class="wptsall-modal-content">
						<div class="wptsall-modal-header">
							<h3>Edit Site Relation</h3>
							<button class="wptsall-modal-close">&times;</button>
						</div>
						<div class="wptsall-modal-body">
							<form id="wptsall-edit-relation-form">
								<input type="hidden" name="relation_id" value="${relation.id}">
								<table class="form-table">
									<tr>
										<th>Target Language</th>
										<td>
											<input type="text" name="target_lang" value="${relation.target_lang || ''}" class="regular-text">
										</td>
									</tr>
									<tr>
										<th>Default Sync Direction</th>
										<td>
											<select name="direction">
												<option value="one_way" ${(relation.direction === 'bidirectional' || relation.direction === 'both' || relation.direction === 'two_way') ? '' : 'selected'}>One Way (Source -> Target)</option>
												<option value="bidirectional" ${(relation.direction === 'bidirectional' || relation.direction === 'both' || relation.direction === 'two_way') ? 'selected' : ''}>Bidirectional</option>
											</select>
										</td>
									</tr>
									<tr>
										<th>Default Monitoring Strategy</th>
										<td>
											<span style="display:inline-block; padding:3px 0;">
												First delivery only + backfill (fixed)
											</span>
											<input type="hidden" name="sync_mode" value="new_only">
										</td>
									</tr>
									<tr>
										<th>Status</th>
										<td>
											<select name="status">
												<option value="active" ${relation.status === 'active' ? 'selected' : ''}>Active</option>
												<option value="inactive" ${relation.status === 'inactive' ? 'selected' : ''}>Inactive</option>
											</select>
										</td>
									</tr>
								</table>
							</form>
						</div>
						<div class="wptsall-modal-footer">
							<button type="button" class="button wptsall-modal-close">Cancel</button>
							<button type="button" class="button button-primary wptsall-save-relation-btn">Save</button>
						</div>
					</div>
				</div>
			`;

			$('#wptsall-edit-relation-modal').remove();
			$('body').append(modalHtml);

			$('.wptsall-save-relation-btn').on('click', () => {
				this.saveRelation();
			});
		},

		/**
		 * Save relation edit (REST API)
		 */
		saveRelation() {
			const $form = $('#wptsall-edit-relation-form');
			const $btn = $('.wptsall-save-relation-btn');
			const relationId = $form.find('[name="relation_id"]').val();

			$btn.prop('disabled', true).text('Saving...');

			this.apiRequest(`/site-relations/${relationId}`, {
				method: 'PUT',
				data: {
					target_lang: $form.find('[name="target_lang"]').val(),
					status: $form.find('[name="status"]').val(),
					direction: $form.find('[name="direction"]').val(),
					// Product decision: sync_mode is fixed to "new_only" (first delivery + backfill only).
					sync_mode: 'new_only'
				}
			})
				.then(() => {
					$('#wptsall-edit-relation-modal').remove();
					// After saving, show merged-config warnings (if any) before reloading.
					this.fetchAndShowConfigWarnings(parseInt(relationId), () => location.reload());
				})
				.catch(error => {
					alert(error.message || 'Save failed');
					$btn.prop('disabled', false).text('Save');
				});
		},

		/**
		 * Sync theme info (REST API)
		 */
		syncThemeInfo(sourceId, sourceLang, template, $btn) {
			const originalText = $btn.html();
			$btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');

			// Use batch sync endpoint
			this.apiRequest('/site-relations/sync-themes', {
				method: 'POST',
				data: { source_site_id: parseInt(sourceId) }
			})
				.then(data => {
					$btn.prop('disabled', false).html(originalText);
					alert('Theme info synced successfully');
					location.reload();
				})
				.catch(error => {
					$btn.prop('disabled', false).html(originalText);
					alert(error.message || 'Sync failed');
				});
		},

		/**
		 * Sync single relation theme info (REST API)
		 */
		syncSingleRelationTheme(relationId, $btn) {
			const originalText = $btn.html();
			$btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');

			// Use single relation sync endpoint
			this.apiRequest(`/site-relations/${relationId}/sync-theme`, {
				method: 'POST'
			})
				.then(data => {
					$btn.prop('disabled', false).html(originalText);
					this.showSuccess(data.message || 'Theme info synced successfully');
					// Optional: partial update instead of full page reload
					setTimeout(() => location.reload(), 800);
				})
				.catch(error => {
					$btn.prop('disabled', false).html(originalText);
					alert(error.message || 'Sync failed');
				});
		},

		/**
		 * Show add target site modal
		 */
		showAddTargetsModal(sourceId, sourceLang, template) {
			// Build language options from config
			const languages = window.wptsallSiteGroupsConfig?.languages || { 'en_US': 'English (United States)' };
			let languageOptions = '<option value=""></option>';
			for (const [code, name] of Object.entries(languages)) {
				languageOptions += `<option value="${code}">${name}</option>`;
			}

			const modalHtml = `
				<div class="wptsall-modal wptsall-add-targets-modal" id="wptsall-add-targets-modal">
					<div class="wptsall-modal-overlay"></div>
					<div class="wptsall-modal-content">
						<div class="wptsall-modal-header">
							<h3>Add Target Site</h3>
							<button class="wptsall-modal-close">&times;</button>
						</div>
						<div class="wptsall-modal-body">
							<form id="wptsall-add-targets-form">
								<input type="hidden" name="source_site_id" value="${sourceId}">
								<input type="hidden" name="source_lang" value="${sourceLang}">
								<input type="hidden" name="template" value="${template}">
								<table class="form-table">
									<tr>
										<th>Target Site Type</th>
										<td>
											<select name="target_type" id="add_target_type">
												<option value="virtual">Virtual Site</option>
												<option value="multisite">Multisite</option>
												<option value="external">External Site</option>
											</select>
										</td>
									</tr>
									<tr>
										<th>Target Site ID</th>
										<td>
											<input type="text" name="target_id" class="regular-text" placeholder="Enter site ID or select virtual site">
										</td>
									</tr>
									<tr>
										<th>Target Language</th>
										<td>
											<select name="target_lang" id="add_target_lang" class="wptsall-select2" data-placeholder="Select target language" style="width: 100%;">
												${languageOptions}
											</select>
										</td>
									</tr>
								</table>
							</form>
						</div>
						<div class="wptsall-modal-footer">
							<button type="button" class="button wptsall-modal-close">Cancel</button>
							<button type="button" class="button button-primary wptsall-submit-add-targets-btn">Add</button>
						</div>
					</div>
				</div>
			`;

			$('#wptsall-add-targets-modal').remove();
			$('body').append(modalHtml);

			// Initialize Select2 for language selector if available (supports custom input)
			if (typeof $.fn.select2 !== 'undefined') {
				$('#add_target_lang').select2({
					width: '100%',
					placeholder: 'Select target language or enter custom language code',
					allowClear: true,
					dropdownParent: $('#wptsall-add-targets-modal'),
					tags: true,  // Allow custom input
					createTag: function(params) {
						const term = $.trim(params.term);
						if (term === '') {
							return null;
						}
						// Validate language code format (e.g. en_US, zh_CN, ja)
						if (!/^[a-z]{2,3}(_[A-Z]{2})?$/.test(term)) {
							return null;
						}
						return {
							id: term,
							text: term + ' (custom)',
							newTag: true
						};
					}
				});
			}

			$('.wptsall-submit-add-targets-btn').on('click', () => {
				this.submitAddTargets();
			});
		},

		/**
		 * Submit add target site (REST API)
		 */
		submitAddTargets() {
			const $form = $('#wptsall-add-targets-form');
			const $btn = $('.wptsall-submit-add-targets-btn');

			const targetId = $form.find('[name="target_id"]').val();
			const targetLang = $form.find('[name="target_lang"]').val();

			if (!targetId) {
				alert('Please enter target site ID');
				return;
			}

			$btn.prop('disabled', true).text('Adding...');

			this.apiRequest('/site-relations/add-targets', {
				method: 'POST',
				data: {
					source_site_id: parseInt($form.find('[name="source_site_id"]').val()),
					source_lang: $form.find('[name="source_lang"]').val(),
					template: $form.find('[name="template"]').val(),
					new_targets: [{
						id: targetId,
						type: $form.find('[name="target_type"]').val(),
						lang: targetLang
					}]
				}
			})
				.then(() => {
					$('#wptsall-add-targets-modal').remove();
					location.reload();
				})
				.catch(error => {
					alert(error.message || 'Add failed');
					$btn.prop('disabled', false).text('Add');
				});
		},

		/**
		 * Show error message
		 */
		showError(message) {
			alert(message);
		},

		// ========================================================================
		// Modal methods
		// ========================================================================

		/**
		 * Initialize modals
		 */
		initModals() {
			const self = this;

			$(document).on('click', '.wptsall-modal-close, .wptsall-modal-overlay', function() {
				self.closeModal($(this).closest('.wptsall-modal'));
			});

			$(document).on('click', '.wptsall-modal-content', function(e) {
				e.stopPropagation();
			});

			$(document).on('keydown', function(e) {
				if (e.key === 'Escape' && $('.wptsall-modal:visible').length > 0) {
					self.closeModal($('.wptsall-modal:visible'));
				}
			});

			$(document).on('submit', '#wptsall-add-targets-form', function(e) {
				e.preventDefault();
				self.submitAddTargets();
			});
		},

		/**
		 * Open modal
		 */
		openModal($modal) {
			$modal.fadeIn(200);
			$('body').addClass('modal-open').css('overflow', 'hidden');
		},

		/**
		 * Close modal
		 */
		closeModal($modal) {
			$modal.fadeOut(200);
			$('body').removeClass('modal-open').css('overflow', '');

			$modal.find('.wptsall-modal-loading').show();
			$modal.find('.wptsall-target-sites-list').hide().empty();
			$modal.find('.wptsall-relation-details').hide().empty();
			$modal.find('.wptsall-modal-error').hide().empty();
			$modal.find('form').trigger('reset');
		},

		/**
		 * Show target sites list modal (REST API)
		 */
		showTargetSitesModal(relationId) {
			const self = this;
			const $modal = $('#wptsall-view-targets-modal');

			self.openModal($modal);

			this.apiRequest(`/site-relations/${relationId}/targets`, {
				method: 'GET'
			})
				.then(data => {
					if (data.target_sites) {
						self.renderTargetSites($modal, data.target_sites);
					} else {
						self.showModalError($modal, 'Load failed');
					}
				})
				.catch(error => {
					self.showModalError($modal, error.message || 'Network error, please retry');
				});
		},

		/**
		 * Render target sites list
		 */
		renderTargetSites($modal, sites) {
			const $list = $modal.find('.wptsall-target-sites-list');
			$list.empty();

			if (!sites || sites.length === 0) {
				$list.html('<p>No target sites</p>');
			} else {
				sites.forEach(site => {
					const typeClass = site.type === 'virtual' ? 'type-virtual' : 'type-wp';
					const typeLabel = site.type === 'virtual' ? 'Virtual Site' : 'Real Site';

					$list.append(`
						<div class="wptsall-target-site-item">
							<div class="wptsall-target-site-info">
								<div class="wptsall-target-site-name">${site.name}</div>
								<div class="wptsall-target-site-meta">
									ID: ${site.id}
									<span class="wptsall-target-site-type ${typeClass}">${typeLabel}</span>
								</div>
							</div>
						</div>
					`);
				});
			}

			$modal.find('.wptsall-modal-loading').hide();
			$list.show();
		},

		/**
		 * Show modal error
		 */
		showModalError($modal, message) {
			$modal.find('.wptsall-modal-loading').hide();
			$modal.find('.wptsall-modal-error').html(message).show();
		},

		/**
		 * Show success message
		 */
		showSuccess(message) {
			const $notice = $(`
				<div class="notice notice-success is-dismissible">
					<p>${message}</p>
				</div>
			`);

			$('.wptsall-site-relations-wrap h1').after($notice);

			setTimeout(() => {
				$notice.fadeOut(() => $notice.remove());
			}, 3000);
		},

		// ========================================================================
		// v0.6.0: Model association management methods
		// ========================================================================

		/**
		 * Show model management modal
		 */
		showModelsModal(relationId, addMode = false) {
			const self = this;
			const title = addMode ? 'Add model' : 'Associated Models';

			// Create modal
			const modalHtml = `
				<div class="wptsall-modal wptsall-models-modal" id="wptsall-models-modal">
					<div class="wptsall-modal-overlay"></div>
					<div class="wptsall-modal-content">
						<div class="wptsall-modal-header">
							<h3>${title}</h3>
							<button class="wptsall-modal-close">&times;</button>
						</div>
						<div class="wptsall-modal-body">
							<div class="wptsall-modal-loading"><span class="spinner is-active"></span> Loading...</div>
							<div class="wptsall-models-list" style="display:none;"></div>
							<div class="wptsall-available-models" style="display:none;">
								<h4>Available Models to Add</h4>
								<div class="wptsall-available-models-list"></div>
							</div>
						</div>
						<div class="wptsall-modal-footer">
							<button type="button" class="button wptsall-modal-close">Close</button>
						</div>
					</div>
				</div>
			`;

			$('#wptsall-models-modal').remove();
			$('body').append(modalHtml);

			const $modal = $('#wptsall-models-modal');
			$modal.fadeIn(200);

			// Load model list
			this.apiRequest(`/site-relations/${relationId}/models`, { method: 'GET' })
				.then(data => {
					$modal.find('.wptsall-modal-loading').hide();
					self.renderRelationModels($modal, data, relationId);
				})
				.catch(error => {
					$modal.find('.wptsall-modal-loading').html('<p class="error">' + (error.message || 'Load failed') + '</p>');
				});
		},

		/**
		 * Render relation model list
		 */
		renderRelationModels($modal, data, relationId) {
			const self = this;
			const $list = $modal.find('.wptsall-models-list');
			const models = data.models || [];

			// v0.6.0: Load language pack match status
			this.loadLanguagePackStatus($modal, relationId);

			if (models.length === 0) {
				$list.html('<p>No associated models. Click "Add Model" to add plugins for translation.</p>');
			} else {
				let html = '<table class="wp-list-table widefat fixed striped">';
				html += '<thead><tr><th>Model Name</th><th>Plugin</th><th>Language Pack</th><th>Actions</th></tr></thead><tbody>';

				models.forEach(model => {
					const langPack = model.language_pack || {};
					let langStatus = '<span class="wptsall-status wptsall-status-missing">Not Matched</span>';
					if (langPack.matched) {
						const progress = langPack.progress || 0;
						langStatus = `<span class="wptsall-status wptsall-status-active">${progress}%</span>`;
					}

					html += `<tr data-model-id="${model.id}">
						<td><strong>${model.plugin_name || model.name || '-'}</strong></td>
						<td><code>${model.plugin_slug || '-'}</code></td>
						<td>${langStatus}</td>
						<td>
							<button class="button button-small button-link-delete wptsall-remove-model-btn"
								data-relation-id="${relationId}" data-model-id="${model.id}">
								Remove
							</button>
						</td>
					</tr>`;
				});

				html += '</tbody></table>';
				$list.html(html);
			}

			$list.show();

			// Add model button
			$modal.find('.wptsall-modal-footer').prepend(`
				<button type="button" class="button button-primary wptsall-show-add-model-btn" data-relation-id="${relationId}">
					Add model
				</button>
			`);

			// Bind events
			$modal.find('.wptsall-remove-model-btn').on('click', function() {
				const modelId = $(this).data('model-id');
				self.removeModelFromRelation(relationId, modelId);
			});

			$modal.find('.wptsall-show-add-model-btn').on('click', function() {
				self.loadAvailableModels($modal, relationId);
			});
		},

		/**
		 * Load available models list
		 */
		loadAvailableModels($modal, relationId) {
			const self = this;
			const $available = $modal.find('.wptsall-available-models');

			$available.show();
			$available.find('.wptsall-available-models-list').html('<span class="spinner is-active"></span> Loading...');

			// Get all models
			wp.apiFetch({ path: 'wptsall/v2/models' })
				.then(response => {
					const models = response.models || response || [];
					const currentModels = [];

					// Get currently associated model IDs
					$modal.find('.wptsall-models-list tr[data-model-id]').each(function() {
						currentModels.push(parseInt($(this).data('model-id')));
					});

					// Filter out already associated models
					const availableModels = models.filter(m => !currentModels.includes(parseInt(m.id)));

					if (availableModels.length === 0) {
						$available.find('.wptsall-available-models-list').html('<p>No models available to add.</p>');
						return;
					}

					let html = '<div class="wptsall-model-checkboxes">';
					availableModels.forEach(model => {
						html += `<label style="display:block; margin-bottom:8px;">
							<input type="checkbox" name="add_model_ids[]" value="${model.id}">
							<strong>${model.plugin_name || model.name}</strong>
							<code style="margin-left:5px;">${model.plugin_slug || model.slug}</code>
						</label>`;
					});
					html += '</div>';
					html += `<button type="button" class="button button-primary wptsall-add-selected-models-btn" data-relation-id="${relationId}">
						Add Selected Models
					</button>`;

					$available.find('.wptsall-available-models-list').html(html);

					// Bind add events
					$available.find('.wptsall-add-selected-models-btn').on('click', function() {
						const selectedIds = [];
						$available.find('input[name="add_model_ids[]"]:checked').each(function() {
							selectedIds.push(parseInt($(this).val()));
						});

						if (selectedIds.length === 0) {
							alert('Please select at least one model');
							return;
						}

						self.addModelsToRelation(relationId, selectedIds);
					});
				})
				.catch(error => {
					$available.find('.wptsall-available-models-list').html('<p class="error">' + (error.message || 'Load failed') + '</p>');
				});
		},

		/**
		 * Add models to relation
		 */
		addModelsToRelation(relationId, modelIds) {
			const self = this;
			const $modal = $('#wptsall-models-modal');
			const $btn = $modal.find('.wptsall-add-selected-models-btn');

			$btn.prop('disabled', true).text('Adding...');

			// Get current model IDs
			const currentIds = [];
			$modal.find('.wptsall-models-list tr[data-model-id]').each(function() {
				currentIds.push(parseInt($(this).data('model-id')));
			});

			// Merge
			const allIds = [...new Set([...currentIds, ...modelIds])];

			this.apiRequest(`/site-relations/${relationId}/models`, {
				method: 'PUT',
				data: { model_ids: allIds }
			})
				.then(data => {
					self.showSuccess('Models added successfully');
					$modal.remove();
					// Update model count on page
					const $countCell = $(`tr[data-relation-id="${relationId}"]`).find('.wptsall-models-count');
					if ($countCell.length) {
						$countCell.text(data.models_count);
					}
					location.reload();
				})
				.catch(error => {
					alert('Add failed: ' + (error.message || 'Unknown error'));
					$btn.prop('disabled', false).text('Add Selected Models');
				});
		},

		/**
		 * v0.6.0: Load language pack match status
		 */
		loadLanguagePackStatus($modal, relationId) {
			const self = this;

			// Add language pack status section before model list
			const $langPackSection = $(`
				<div class="wptsall-language-pack-status">
					<h4>Language Pack Match Status</h4>
					<div class="wptsall-lang-pack-loading"><span class="spinner is-active"></span> Loading...</div>
					<div class="wptsall-lang-pack-content" style="display:none;"></div>
				</div>
			`);
			$modal.find('.wptsall-models-list').before($langPackSection);

			// Call Templates API for match status
			this.apiRequest('/templates/match', {
				method: 'GET',
				data: { relation_id: relationId }
			})
				.then(data => {
					$langPackSection.find('.wptsall-lang-pack-loading').hide();
					self.renderLanguagePackStatus($langPackSection.find('.wptsall-lang-pack-content'), data);
				})
				.catch(error => {
					$langPackSection.find('.wptsall-lang-pack-loading')
						.html('<p class="error">Failed to load language pack status: ' + (error.message || 'Unknown error') + '</p>');
				});
		},

		/**
		 * v0.6.0: Render language pack match status
		 */
		renderLanguagePackStatus($container, data) {
			const theme = data.theme || {};
			const plugins = data.plugins || [];
			const summary = data.summary || {};

			let html = '';

			// Theme language pack status
			html += '<div class="wptsall-lang-pack-section">';
			html += '<strong>Theme Language Pack: </strong>';
			if (theme.matched) {
				const progress = theme.progress || 0;
				html += `<span class="wptsall-status wptsall-status-active">Matched (${progress}%)</span>`;
				if (theme.template_name) {
					html += ` <code>${theme.template_name}</code>`;
				}
			} else {
				html += '<span class="wptsall-status wptsall-status-missing">Not Matched</span>';
				if (theme.message) {
					html += ` <span class="description">${theme.message}</span>`;
				}
			}
			html += '</div>';

			// Summary statistics
			html += '<div class="wptsall-lang-pack-summary" style="margin-top:12px; padding:10px; background:#f9f9f9; border-radius:4px;">';
			html += '<strong>Summary: </strong> ';
			html += `Total <strong>${summary.total || 0}</strong> language pack(s) needed`;
			html += ` | Matched <strong style="color:#46b450;">${summary.matched || 0}</strong>`;
			html += ` | Missing <strong style="color:#dc3232;">${summary.missing || 0}</strong>`;

			if (summary.total > 0) {
				const matchRate = Math.round(((summary.matched || 0) / summary.total) * 100);
				html += ` | Coverage <strong>${matchRate}%</strong>`;
			}
			html += '</div>';

			$container.html(html).show();
		},

		/**
		 * Remove model from relation
		 */
		removeModelFromRelation(relationId, modelId) {
			const self = this;

			if (!confirm('Are you sure you want to remove this model?')) {
				return;
			}

			this.apiRequest(`/site-relations/${relationId}/models/${modelId}`, {
				method: 'DELETE'
			})
				.then(data => {
					self.showSuccess('Model removed');
					// Refresh modal
					$('#wptsall-models-modal').remove();
					self.showModelsModal(relationId);
					// Update model count on page
					const $countCell = $(`tr[data-relation-id="${relationId}"]`).find('.wptsall-models-count');
					if ($countCell.length) {
						$countCell.text(data.models_count);
					}
				})
				.catch(error => {
					alert('Remove failed: ' + (error.message || 'Unknown error'));
				});
		},

		// ========================================================================
		// v0.8.0: Post Type configuration management methods
		// ========================================================================

		/**
		 * Show Post Type configuration modal
		 */
		showPostTypeConfigsModal(relationId) {
			const self = this;

			const modalHtml = `
				<div class="wptsall-modal wptsall-post-type-configs-modal" id="wptsall-post-type-configs-modal">
					<div class="wptsall-modal-overlay"></div>
					<div class="wptsall-modal-content" style="max-width:800px;">
						<div class="wptsall-modal-header">
							<h3>Post Type Configuration Overrides</h3>
							<button class="wptsall-modal-close">&times;</button>
						</div>
						<div class="wptsall-modal-body">
							<p class="description">Configure relation-level Post Type sync settings that override model defaults.</p>
							<div class="wptsall-modal-loading"><span class="spinner is-active"></span> Loading...</div>
							<div class="wptsall-post-type-configs-content" style="display:none;"></div>
						</div>
						<div class="wptsall-modal-footer">
							<button type="button" class="button wptsall-modal-close">Close</button>
							<button type="button" class="button button-primary wptsall-save-configs-btn" data-relation-id="${relationId}" style="display:none;">Save Configuration</button>
						</div>
					</div>
				</div>
			`;

			$('#wptsall-post-type-configs-modal').remove();
			$('body').append(modalHtml);

			const $modal = $('#wptsall-post-type-configs-modal');
			$modal.fadeIn(200);

			// Load configuration
			this.loadPostTypeConfigs($modal, relationId);

			// Bind save button events
			$modal.find('.wptsall-save-configs-btn').on('click', function() {
				self.savePostTypeConfigs(relationId);
			});
		},

		/**
		 * Load Post Type configuration
		 */
		loadPostTypeConfigs($modal, relationId) {
			const self = this;
			const $loading = $modal.find('.wptsall-modal-loading');
			const $content = $modal.find('.wptsall-post-type-configs-content');

			// Load configs and associated models in parallel
			Promise.all([
				this.apiRequest(`/site-relations/${relationId}/post-type-configs`, { method: 'GET' }),
				this.apiRequest(`/site-relations/${relationId}/models`, { method: 'GET' })
			])
				.then(([configsData, modelsData]) => {
					$loading.hide();
					self.renderPostTypeConfigs($content, relationId, configsData, modelsData);
					$content.show();
					$modal.find('.wptsall-save-configs-btn').show();
				})
				.catch(error => {
					$loading.html('<p class="error">Load failed: ' + (error.message || 'Unknown error') + '</p>');
				});
		},

		/**
		 * Render Post Type configuration form
		 */
		renderPostTypeConfigs($content, relationId, configsData, modelsData) {
			const self = this;
			const configs = configsData.configs || [];
			const stats = configsData.stats || {};
			const models = modelsData.models || [];

			// Get all available post types (from associated models)
			const availablePostTypes = new Set();
			models.forEach(model => {
				if (model.post_types) {
					model.post_types.forEach(pt => availablePostTypes.add(pt));
				}
				// Default add post
				availablePostTypes.add('post');
			});

			// Convert configs to map for lookup
			const configMap = {};
			configs.forEach(config => {
				configMap[config.post_type] = config;
			});

			let html = `
				<div class="wptsall-configs-stats" style="margin-bottom:15px; padding:10px; background:#f9f9f9; border-radius:4px;">
					<strong>Statistics: </strong>
					Total <strong>${stats.total || 0}</strong>  configurations
					| Enabled <strong style="color:#46b450;">${stats.enabled || 0}</strong>
					| Disabled <strong style="color:#dc3232;">${stats.disabled || 0}</strong>
				</div>
			`;

			html += '<div class="wptsall-configs-list">';

			const templateConfig = configMap.__templates__ || {};
			html += self.renderTemplateConfigCard(templateConfig);

			// Generate config card for each post type
			availablePostTypes.forEach(postType => {
				const config = configMap[postType] || {};
				const isConfigured = !!config.id;
				const enabled = config.enabled !== undefined ? config.enabled : 1;
				const direction = config.direction || 'one_way';
				const syncMode = config.sync_mode || 'new_only';

				html += `
					<div class="wptsall-config-card" data-post-type="${postType}" style="border:1px solid #ddd; padding:15px; margin-bottom:15px; border-radius:4px; background:${isConfigured ? '#f0fff0' : '#fff'};">
						<div class="wptsall-config-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
							<h4 style="margin:0;">
								<code>${postType}</code>
								${isConfigured ? '<span class="dashicons dashicons-yes-alt" style="color:#46b450;" title="Configured"></span>' : ''}
							</h4>
							<label style="cursor:pointer;">
								<input type="checkbox" class="wptsall-config-enabled" ${enabled ? 'checked' : ''}>
								Enable Sync
							</label>
						</div>
						<div class="wptsall-config-body" style="${!enabled ? 'opacity:0.5;' : ''}">
							<table class="form-table" style="margin:0;">
								<tr>
									<th style="width:100px; padding:5px 10px 5px 0;">Sync Direction</th>
									<td style="padding:5px 0;">
										<select class="wptsall-config-direction">
											<option value="one_way" ${direction === 'one_way' ? 'selected' : ''}>One Way (Source -> Target)</option>
											<option value="bidirectional" ${direction === 'bidirectional' ? 'selected' : ''}>Bidirectional</option>
										</select>
									</td>
								</tr>
								<tr>
									<th style="padding:5px 10px 5px 0;">Sync Mode</th>
									<td style="padding:5px 0;">
										<span style="display:inline-block; padding:3px 0;">
											First delivery only + backfill (fixed)
										</span>
										<input type="hidden" class="wptsall-config-sync-mode" value="new_only">
									</td>
								</tr>
							</table>
							<div class="wptsall-field-overrides" style="margin-top:10px;">
								<button type="button" class="button button-small wptsall-toggle-field-overrides">
									<span class="dashicons dashicons-admin-generic" style="vertical-align:middle;"></span>
									Field Override Settings
								</button>
								<div class="wptsall-field-overrides-panel" style="display:none; margin-top:10px; padding:10px; background:#f9f9f9; border-radius:4px;">
									${self.renderFieldOverridesPanel(config.field_overrides || {})}
								</div>
							</div>
						</div>
					</div>
				`;
			});

			html += '</div>';

			// Add new post type button
			html += `
				<div class="wptsall-add-post-type" style="margin-top:15px;">
					<button type="button" class="button wptsall-add-post-type-btn">
						<span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;"></span>
						Add Another Post Type
					</button>
				</div>
			`;

			$content.html(html);

			// Bind events
			$content.find('.wptsall-config-enabled').on('change', function() {
				const $card = $(this).closest('.wptsall-config-card');
				const $body = $card.find('.wptsall-config-body');
				$body.css('opacity', this.checked ? '1' : '0.5');
			});

			$content.find('.wptsall-toggle-field-overrides').on('click', function() {
				$(this).next('.wptsall-field-overrides-panel').slideToggle(200);
			});

			$content.find('.wptsall-add-post-type-btn').on('click', function() {
				self.showAddPostTypeDialog($content, relationId, availablePostTypes);
			});
		},

		renderTemplateConfigCard(config) {
			const fieldOverrides = (config && config.field_overrides) || {};
			const enabled = config.enabled !== undefined ? config.enabled : 1;
			const translatePluginI18n = !!fieldOverrides.translate_plugin_i18n;
			const translateThemeI18n = !!fieldOverrides.translate_theme_i18n;
			const translateConfigI18n = !!fieldOverrides.translate_config_i18n;
			const preflightPolicy = (fieldOverrides.preflight_policy || 'warn');
			const missingComponentBehavior = (fieldOverrides.missing_component_behavior || 'confirm_continue');
			const isConfigured = !!config.id;

			return `
				<div class="wptsall-config-card wptsall-template-config-card" data-post-type="__templates__" style="border:1px solid #ccd0d4; padding:15px; margin-bottom:15px; border-radius:4px; background:${isConfigured ? '#f6fbff' : '#fff'};">
					<div class="wptsall-config-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
						<h4 style="margin:0;">
							<code>__templates__</code>
							<small style="margin-left:6px; color:#646970;">I18n / Preflight</small>
							${isConfigured ? '<span class="dashicons dashicons-yes-alt" style="color:#46b450;" title="Configured"></span>' : ''}
						</h4>
						<label style="cursor:pointer;">
							<input type="checkbox" class="wptsall-config-enabled" ${enabled ? 'checked' : ''}>
							Enable Relation Policy
						</label>
					</div>
					<div class="wptsall-config-body" style="${!enabled ? 'opacity:0.5;' : ''}">
						<p class="description" style="margin-top:0;">
							Controls relation-level i18n lanes and missing-component preflight behavior exposed to Client worker start checks.
						</p>
						<table class="form-table" style="margin:0;">
							<tr>
								<th style="width:180px; padding:5px 10px 5px 0;">Language Pack Sources</th>
								<td style="padding:5px 0;">
									<label style="display:block; margin-bottom:4px;"><input type="checkbox" class="wptsall-template-translate-plugin-i18n" ${translatePluginI18n ? 'checked' : ''}> Plugin i18n</label>
									<label style="display:block; margin-bottom:4px;"><input type="checkbox" class="wptsall-template-translate-theme-i18n" ${translateThemeI18n ? 'checked' : ''}> Theme i18n</label>
									<label style="display:block;"><input type="checkbox" class="wptsall-template-translate-config-i18n" ${translateConfigI18n ? 'checked' : ''}> Config i18n</label>
								</td>
							</tr>
							<tr>
								<th style="padding:5px 10px 5px 0;">Preflight Policy</th>
								<td style="padding:5px 0;">
									<select class="wptsall-template-preflight-policy">
										<option value="warn" ${preflightPolicy === 'warn' ? 'selected' : ''}>Warn</option>
										<option value="block" ${preflightPolicy === 'block' ? 'selected' : ''}>Block Start</option>
									</select>
									<p class="description" style="margin:6px 0 0;">Warn allows task start with confirmation. Block prevents worker start when required components are missing.</p>
								</td>
							</tr>
							<tr>
								<th style="padding:5px 10px 5px 0;">Missing Component Behavior</th>
								<td style="padding:5px 0;">
									<select class="wptsall-template-missing-component-behavior">
										<option value="confirm_continue" ${missingComponentBehavior === 'confirm_continue' ? 'selected' : ''}>Confirm Continue</option>
										<option value="skip_unbound_fields" ${missingComponentBehavior === 'skip_unbound_fields' ? 'selected' : ''}>Skip Unbound Fields</option>
										<option value="stop_task" ${missingComponentBehavior === 'stop_task' ? 'selected' : ''}>Stop Task</option>
									</select>
								</td>
							</tr>
						</table>
					</div>
				</div>
			`;
		},

		/**
		 * Render field overrides panel
		 */
		renderFieldOverridesPanel(fieldOverrides) {
			// Predefined field types
			const fieldTypes = [
				{ key: 'post_title', label: 'Title', defaultType: 'translate' },
				{ key: 'post_content', label: 'Content', defaultType: 'translate' },
				{ key: 'post_excerpt', label: 'Excerpt', defaultType: 'translate' },
				{ key: 'post_status', label: 'Status', defaultType: 'sync' },
				{ key: 'post_date', label: 'Date', defaultType: 'sync' },
				{ key: '_thumbnail_id', label: 'Featured Image', defaultType: 'mapping' }
			];

			let html = '<table class="widefat" style="background:transparent;">';
			html += '<thead><tr><th>Field</th><th>Type</th><th>Enabled</th></tr></thead><tbody>';

			fieldTypes.forEach(field => {
				const override = fieldOverrides[field.key] || {};
				const type = override.type || field.defaultType;
				const enabled = override.enabled !== undefined ? override.enabled : true;

				html += `
					<tr data-field-key="${field.key}">
						<td><code>${field.key}</code> <small>(${field.label})</small></td>
						<td>
							<select class="wptsall-field-type" style="width:100%;">
								<option value="translate" ${type === 'translate' ? 'selected' : ''}>Translate</option>
								<option value="sync" ${type === 'sync' ? 'selected' : ''}>Sync</option>
								<option value="mapping" ${type === 'mapping' ? 'selected' : ''}>ID Mapping</option>
								<option value="compute" ${type === 'compute' ? 'selected' : ''}>Compute</option>
								<option value="ignore" ${type === 'ignore' ? 'selected' : ''}>Ignore</option>
							</select>
						</td>
						<td style="text-align:center;">
							<input type="checkbox" class="wptsall-field-enabled" ${enabled ? 'checked' : ''}>
						</td>
					</tr>
				`;
			});

			html += '</tbody></table>';
			html += '<p class="description" style="margin-top:8px;">Modifying field types will override the model default configuration.</p>';

			return html;
		},

		/**
		 * Show add Post Type dialog
		 */
		showAddPostTypeDialog($content, relationId, existingPostTypes) {
			const postType = prompt('Enter Post Type name to add (e.g. product, page, custom_post_type):');
			if (!postType) {
				return;
			}

			const sanitizedType = postType.trim().toLowerCase().replace(/[^a-z0-9_-]/g, '_');
			if (existingPostTypes.has(sanitizedType)) {
				alert('This Post Type already exists in configuration.');
				return;
			}

			// Add new config card
			const newCardHtml = `
				<div class="wptsall-config-card" data-post-type="${sanitizedType}" style="border:1px solid #ddd; padding:15px; margin-bottom:15px; border-radius:4px; background:#fff;">
					<div class="wptsall-config-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
						<h4 style="margin:0;">
							<code>${sanitizedType}</code>
							<span class="wptsall-new-badge" style="background:#0073aa; color:#fff; padding:2px 6px; border-radius:3px; font-size:11px; margin-left:5px;">New</span>
						</h4>
						<label style="cursor:pointer;">
							<input type="checkbox" class="wptsall-config-enabled" checked>
							Enable Sync
						</label>
					</div>
					<div class="wptsall-config-body">
						<table class="form-table" style="margin:0;">
							<tr>
								<th style="width:100px; padding:5px 10px 5px 0;">Sync Direction</th>
								<td style="padding:5px 0;">
									<select class="wptsall-config-direction">
										<option value="one_way" selected>One Way (Source -> Target)</option>
										<option value="bidirectional">Bidirectional</option>
									</select>
								</td>
							</tr>
								<tr>
									<th style="padding:5px 10px 5px 0;">Sync Mode</th>
									<td style="padding:5px 0;">
										<span style="display:inline-block; padding:3px 0;">
											First delivery only + backfill (fixed)
										</span>
										<input type="hidden" class="wptsall-config-sync-mode" value="new_only">
								</td>
							</tr>
						</table>
						<div class="wptsall-field-overrides" style="margin-top:10px;">
							<button type="button" class="button button-small wptsall-toggle-field-overrides">
								<span class="dashicons dashicons-admin-generic" style="vertical-align:middle;"></span>
								Field Override Settings
							</button>
							<div class="wptsall-field-overrides-panel" style="display:none; margin-top:10px; padding:10px; background:#f9f9f9; border-radius:4px;">
								${this.renderFieldOverridesPanel({})}
							</div>
						</div>
					</div>
				</div>
			`;

			$content.find('.wptsall-configs-list').append(newCardHtml);

			// Bind new card events
			const $newCard = $content.find('.wptsall-config-card').last();
			$newCard.find('.wptsall-config-enabled').on('change', function() {
				const $body = $newCard.find('.wptsall-config-body');
				$body.css('opacity', this.checked ? '1' : '0.5');
			});

			$newCard.find('.wptsall-toggle-field-overrides').on('click', function() {
				$(this).next('.wptsall-field-overrides-panel').slideToggle(200);
			});

			// Scroll to new card
			$newCard[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
		},

		/**
		 * Save Post Type configuration
		 */
		savePostTypeConfigs(relationId) {
			const self = this;
			const $modal = $('#wptsall-post-type-configs-modal');
			const $btn = $modal.find('.wptsall-save-configs-btn');
			const $content = $modal.find('.wptsall-post-type-configs-content');

			// Collect configuration data
			const configs = {};
			$content.find('.wptsall-config-card').each(function() {
				const $card = $(this);
				const postType = $card.data('post-type');

				if (postType === '__templates__') {
					configs[postType] = {
						enabled: $card.find('.wptsall-config-enabled').is(':checked') ? 1 : 0,
						field_overrides: {
							translate_plugin_i18n: $card.find('.wptsall-template-translate-plugin-i18n').is(':checked'),
							translate_theme_i18n: $card.find('.wptsall-template-translate-theme-i18n').is(':checked'),
							translate_config_i18n: $card.find('.wptsall-template-translate-config-i18n').is(':checked'),
							preflight_policy: $card.find('.wptsall-template-preflight-policy').val(),
							missing_component_behavior: $card.find('.wptsall-template-missing-component-behavior').val()
						}
					};
					return;
				}

				// Collect field overrides
				const fieldOverrides = {};
				$card.find('.wptsall-field-overrides-panel tr[data-field-key]').each(function() {
					const $row = $(this);
					const fieldKey = $row.data('field-key');
					fieldOverrides[fieldKey] = {
						type: $row.find('.wptsall-field-type').val(),
						enabled: $row.find('.wptsall-field-enabled').is(':checked')
					};
				});

				configs[postType] = {
					enabled: $card.find('.wptsall-config-enabled').is(':checked') ? 1 : 0,
					direction: $card.find('.wptsall-config-direction').val(),
					// Product decision: sync_mode is fixed to "new_only" (first delivery + backfill only).
					sync_mode: 'new_only',
					field_overrides: fieldOverrides
				};
			});

			$btn.prop('disabled', true).text('Saving...');

			this.apiRequest(`/site-relations/${relationId}/post-type-configs`, {
				method: 'POST',
				data: configs
			})
				.then(data => {
					self.showSuccess('Configuration saved successfully');
					$modal.fadeOut(200, function() {
						$(this).remove();
					});
					// Show merged-config warnings (if any) after saving overrides.
					self.fetchAndShowConfigWarnings(parseInt(relationId));
				})
				.catch(error => {
					alert('Save failed: ' + (error.message || 'Unknown error'));
					$btn.prop('disabled', false).text('Save Configuration');
				});
		},

		/**
		 * Fetch merged-config warnings for a relation and show them in a modal (ISS-TSK-068).
		 *
		 * @param {number} relationId
		 * @param {Function|null} onClose
		 */
		fetchAndShowConfigWarnings(relationId, onClose = null) {
			if (!relationId) {
				if (typeof onClose === 'function') onClose();
				return;
			}

			this.apiRequest(`/site-relations/${relationId}/config-warnings`, { method: 'GET' })
				.then(data => {
					const warnings = (data && data.warnings) ? data.warnings : [];
					if (!warnings || warnings.length === 0) {
						if (typeof onClose === 'function') onClose();
						return;
					}

					this.showConfigWarningsModal(warnings, onClose);
				})
				.catch(() => {
					// Warnings are non-blocking; ignore fetch failures.
					if (typeof onClose === 'function') onClose();
				});
		},

		/**
		 * Render a simple modal for merged-config warnings.
		 *
		 * @param {Array} items
		 * @param {Function|null} onClose
		 */
		showConfigWarningsModal(items, onClose = null) {
			const rows = (items || []).map(item => {
				const pluginName = item.plugin_name || item.plugin_slug || '';
				const objectName = item.object_name || '';
				const dataType = item.data_type || '';
				const warnings = (item.warnings || []).map(w => `<li>${this.escapeHtml(String(w))}</li>`).join('');

				return `
					<div style="margin-bottom:12px; padding:10px; border:1px solid #ddd; border-radius:6px; background:#fff;">
						<div style="font-weight:600; margin-bottom:6px;">
							${this.escapeHtml(pluginName)} · <code>${this.escapeHtml(objectName)}</code>
							<span style="color:#666;">(${this.escapeHtml(dataType)})</span>
						</div>
						<ul style="margin:0 0 0 18px;">${warnings}</ul>
					</div>
				`;
			}).join('');

			const modalHtml = `
				<div class="wptsall-modal" id="wptsall-config-warnings-modal" style="display:block;">
					<div class="wptsall-modal-overlay"></div>
					<div class="wptsall-modal-content" style="max-width:760px;">
						<div class="wptsall-modal-header">
							<h3>Configuration Conflict Notice (Reverted to Model Configuration)</h3>
							<button class="wptsall-modal-close">&times;</button>
						</div>
						<div class="wptsall-modal-body">
							<p style="margin-top:0; color:#666;">
								The following configuration conflicts/invalid overrides were detected after saving (the system has automatically reverted to model configuration to ensure consistency).
							</p>
							${rows}
						</div>
						<div class="wptsall-modal-footer">
							<button type="button" class="button button-primary wptsall-modal-close">OK</button>
						</div>
					</div>
				</div>
			`;

			$('#wptsall-config-warnings-modal').remove();
			$('body').append(modalHtml);

			const close = () => {
				$('#wptsall-config-warnings-modal').remove();
				if (typeof onClose === 'function') onClose();
			};

			const $modal = $('#wptsall-config-warnings-modal');
			$modal.on('click', '.wptsall-modal-close', close);
			$modal.on('click', '.wptsall-modal-overlay', close);
		},

		escapeHtml(str) {
			return String(str)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#039;');
		},

		// ========================================================================
		// Virtual sites management methods (REST API)
		// ========================================================================

		/**
		 * Initialize virtual sites management
		 */
		initVirtualSites() {
			const self = this;
			console.log('[WPTSALL] initVirtualSites() called');

			// Add virtual site
			$(document).on('click', '.wptsall-add-virtual-site', function(e) {
				e.preventDefault();
				self.showVirtualSiteModal();
			});

			// Edit virtual site
			$(document).on('click', '.wptsall-edit-virtual-site', function(e) {
				e.preventDefault();
				const siteId = $(this).data('site-id');
				self.showVirtualSiteModal(siteId);
			});

			// Delete virtual site
			$(document).on('click', '.wptsall-delete-virtual-site', function(e) {
				e.preventDefault();

				if (!confirm('Are you sure you want to delete this virtual site? This action cannot be undone.')) {
					return;
				}

				const siteId = $(this).data('site-id');
				self.deleteVirtualSite(siteId);
			});

			// Enable/disable virtual site (single row)
			$(document).on('click', '.wptsall-toggle-virtual-site', function(e) {
				e.preventDefault();
				const siteId = $(this).data('site-id');
				const status = $(this).data('status');
				self.toggleVirtualSiteStatus(siteId, status);
			});

			// Bulk: Select All
			$(document).on('change', '.wptsall-virtual-select-all', function() {
				const checked = !!this.checked;
				$('.wptsall-virtual-select').prop('checked', checked);
			});

			// Bulk: Apply
			$(document).on('click', '.wptsall-virtual-bulk-apply', function(e) {
				e.preventDefault();
				const action = $('.wptsall-virtual-bulk-action').val();
				const selected = $('.wptsall-virtual-select:checked').map(function() {
					return parseInt($(this).val(), 10);
				}).get().filter(Boolean);

				if (!action) {
					alert('Please select a bulk action');
					return;
				}
				if (!selected.length) {
					alert('Please select at least one virtual site');
					return;
				}

				if (action === 'delete') {
					if (!confirm(`Are you sure you want to delete the selected ${selected.length} virtual site(s)? This action cannot be undone.`)) {
						return;
					}
					self.bulkDeleteVirtualSites(selected);
					return;
				}

				let statusTo = null;
				if (action === 'activate') statusTo = 'active';
				if (action === 'deactivate') statusTo = 'inactive';

				if (!statusTo) {
					alert('Invalid bulk action');
					return;
				}

				const updates = selected.map(id => ({ id, status: statusTo }));
				self.bulkUpdateVirtualSites(updates);
			});

			// Submit virtual site form (modal)
			$(document).on('submit', '#wptsall-virtual-site-form', function(e) {
				e.preventDefault();
				self.submitVirtualSite();
			});

			// Submit virtual site form (add_virtual tab standalone form)
			$(document).on('submit', '#wptsall-create-virtual-site-form', function(e) {
				console.log('[WPTSALL] Form submit event triggered');
				e.preventDefault();
				self.submitVirtualSiteFromTab();
			});

			// Fallback: button click event
			$(document).on('click', '#wptsall-create-virtual-site-form button[type="submit"]', function(e) {
				console.log('[WPTSALL] Submit button clicked');
				e.preventDefault();
				e.stopPropagation();
				self.submitVirtualSiteFromTab();
			});

			console.log('[WPTSALL] Form handlers bound for #wptsall-create-virtual-site-form');

			// Blog sync checkbox toggle
			$(document).on('change', '#virtual-site-blog-sync', function() {
				$('.wptsall-blog-source-field').toggle(this.checked);
			});

			// v0.6.0: Permalink dropdown toggle (virtual site modal)
			$(document).on('change', '#virtual-site-permalink', function() {
				const $custom = $('#virtual-site-permalink-custom');
				if ($(this).val() === 'custom') {
					$custom.show();
				} else {
					$custom.hide().val('');
				}
			});

			// v0.6.0: Permalink dropdown toggle (add virtual site tab)
			$(document).on('change', '#site_permalink', function() {
				const $custom = $('#site_permalink_custom');
				if ($(this).val() === 'custom') {
					$custom.show();
				} else {
					$custom.hide().val('');
				}
			});

			// URL path real-time conflict detection
			let urlCheckTimeout;
			$(document).on('input', '#virtual-site-path', function() {
				clearTimeout(urlCheckTimeout);
				const $input = $(this);
				const path = $input.val();

				if (!path) {
					$('#virtual-site-path-conflict').hide();
					return;
				}

				urlCheckTimeout = setTimeout(() => {
					self.checkUrlConflict(path, $('#virtual-site-id').val());
				}, 500);
			});
		},

		/**
		 * Toggle single virtual site status (active <-> inactive).
		 */
		toggleVirtualSiteStatus(siteId, currentStatus) {
			const self = this;
			const nextStatus = (currentStatus === 'active') ? 'inactive' : 'active';

			const $row = $(`.wptsall-virtual-sites-table tr[data-site-id="${siteId}"]`);
			$row.addClass('wptsall-loading');

			this.apiRequest(`/virtual-sites/${siteId}`, {
				method: 'PUT',
				data: { status: nextStatus }
			})
				.then(data => {
					self.showSuccess(data.message || 'Virtual site status updated');
					setTimeout(() => window.location.reload(), 600);
				})
				.catch(error => {
					alert(error.message || 'Update failed');
				})
				.finally(() => {
					$row.removeClass('wptsall-loading');
				});
		},

		/**
		 * Bulk delete virtual sites.
		 */
		bulkDeleteVirtualSites(siteIds) {
			const self = this;
			this.apiRequest('/virtual-sites/bulk-delete', {
				method: 'POST',
				data: { site_ids: siteIds }
			})
				.then(data => {
					self.showSuccess(data.message || 'Bulk delete completed');
					setTimeout(() => window.location.reload(), 800);
				})
				.catch(error => {
					alert(error.message || 'Bulk delete failed');
				});
		},

		/**
		 * Bulk update virtual sites.
		 */
		bulkUpdateVirtualSites(updates) {
			const self = this;
			this.apiRequest('/virtual-sites/bulk-update', {
				method: 'POST',
				data: { updates }
			})
				.then(data => {
					self.showSuccess(data.message || 'Bulk update completed');
					setTimeout(() => window.location.reload(), 800);
				})
				.catch(error => {
					alert(error.message || 'Bulk update failed');
				});
		},

		/**
		 * Show virtual site modal
		 */
		showVirtualSiteModal(siteId = null) {
			const self = this;
			const $modal = $('#wptsall-virtual-site-modal');
			const $form = $('#wptsall-virtual-site-form');
			const $title = $modal.find('.wptsall-modal-title');

			$form.trigger('reset');
			$modal.find('.wptsall-modal-error').hide().empty();
			$('#virtual-site-path-conflict').hide();
			$('.wptsall-blog-source-field').hide();

			if (siteId) {
				$title.text('Edit Virtual Site');
				$modal.find('button[type="submit"]').text('Update');
				self.loadVirtualSite(siteId, $modal);
			} else {
				$title.text('Add Virtual Site');
				$modal.find('button[type="submit"]').text('Create');
				$('#virtual-site-id').val('');
			}

			this.openModal($modal);
		},

		/**
		 * Load virtual site data (REST API)
		 */
		loadVirtualSite(siteId, $modal) {
			const self = this;

			$modal.find('.wptsall-modal-body').addClass('wptsall-loading');

			this.apiRequest(`/virtual-sites/${siteId}`, {
				method: 'GET'
			})
				.then(site => {
					$('#virtual-site-id').val(site.id);
					$('#virtual-site-name').val(site.name);
					$('#virtual-site-subtitle').val(site.subtitle || '');
					$('#virtual-site-path').val(site.path_prefix);
					$('#virtual-site-lang').val(site.lang);
					$('#virtual-site-logo').val(site.logo_url || '');

					if (site.enable_blog_sync) {
						$('#virtual-site-blog-sync').prop('checked', true);
						$('.wptsall-blog-source-field').show();
						$('#virtual-site-blog-source').val(site.blog_source_site || '');
					}

					// v0.6.0: Permalink settings
					const permalinkValue = site.permalink_structure || '';
					const isCustom = permalinkValue && !['', 'plain', '/%year%/%monthnum%/%day%/%postname%/', '/%year%/%monthnum%/%postname%/', '/archives/%post_id%', '/%postname%/'].includes(permalinkValue);
					if (isCustom) {
						$('#virtual-site-permalink').val('custom');
						$('#virtual-site-permalink-custom').val(permalinkValue).show();
					} else {
						$('#virtual-site-permalink').val(permalinkValue);
						$('#virtual-site-permalink-custom').hide();
					}
					$('#virtual-site-category-base').val(site.category_base || '');
					$('#virtual-site-tag-base').val(site.tag_base || '');
				})
				.catch(error => {
					self.showModalError($modal, error.message || 'Failed to load virtual site');
				})
				.finally(() => {
					$modal.find('.wptsall-modal-body').removeClass('wptsall-loading');
				});
		},

		/**
		 * Submit virtual site form (REST API)
		 */
		submitVirtualSite() {
			const self = this;
			const $modal = $('#wptsall-virtual-site-modal');
			const $form = $('#wptsall-virtual-site-form');
			const $error = $modal.find('.wptsall-modal-error');
			const $submitBtn = $modal.find('button[type="submit"]');

			if (!$form[0].checkValidity()) {
				$form[0].reportValidity();
				return;
			}

			const $conflict = $('#virtual-site-path-conflict');
			if ($conflict.is(':visible') && $conflict.hasClass('wptsall-conflict-error')) {
				$error.html('URL path conflict, please modify and retry').show();
				return;
			}

			const siteId = $('#virtual-site-id').val();

			// v0.6.0: Process permalink structure
			let permalinkStructure = $('#virtual-site-permalink').val();
			if (permalinkStructure === 'custom') {
				permalinkStructure = $('#virtual-site-permalink-custom').val();
			}

			const formData = {
				name: $('#virtual-site-name').val(),
				subtitle: $('#virtual-site-subtitle').val(),
				path_prefix: $('#virtual-site-path').val(),
				lang: $('#virtual-site-lang').val(),
				logo_url: $('#virtual-site-logo').val(),
				enable_blog_sync: $('#virtual-site-blog-sync').is(':checked') ? 1 : 0,
				blog_source_site: parseInt($('#virtual-site-blog-source').val()) || 0,
				permalink_structure: permalinkStructure || '',
				category_base: $('#virtual-site-category-base').val() || '',
				tag_base: $('#virtual-site-tag-base').val() || ''
			};

			$submitBtn.prop('disabled', true);
			$submitBtn.text(siteId ? 'Updating...' : 'Creating...');
			$error.hide();

			const endpoint = siteId ? `/virtual-sites/${siteId}` : '/virtual-sites';
			const method = siteId ? 'PUT' : 'POST';

			this.apiRequest(endpoint, { method, data: formData })
				.then(data => {
					self.closeModal($modal);
					self.showSuccess(data.message || (siteId ? 'Virtual site updated successfully' : 'Virtual site created successfully'));
					setTimeout(() => window.location.reload(), 1000);
				})
				.catch(error => {
					$error.html(error.message || 'Operation failed').show();
				})
				.finally(() => {
					$submitBtn.prop('disabled', false);
					$submitBtn.text(siteId ? 'Update' : 'Create');
				});
		},

		/**
		 * Submit virtual site form (from add_virtual tab) (REST API)
		 */
		submitVirtualSiteFromTab() {
			const self = this;
			const $form = $('#wptsall-create-virtual-site-form');
			const $submitBtn = $form.find('button[type="submit"]');

			console.log('[WPTSALL] submitVirtualSiteFromTab called');

			if (!$form[0].checkValidity()) {
				$form[0].reportValidity();
				return;
			}

			// v0.6.0: Process permalink structure
			let permalinkStructure = $('#site_permalink').val();
			if (permalinkStructure === 'custom') {
				permalinkStructure = $('#site_permalink_custom').val();
			}

			const formData = {
				name: $('#site_name').val(),
				subtitle: '',
				path_prefix: $('#site_path').val(),
				lang: $('#site_language').val(),
				logo_url: '',
				enable_blog_sync: 0,
				blog_source_site: 0,
				permalink_structure: permalinkStructure || '',
				category_base: $('#site_category_base').val() || '',
				tag_base: $('#site_tag_base').val() || ''
			};

			console.log('[WPTSALL] Form data:', formData);

			$submitBtn.prop('disabled', true);
			const originalText = $submitBtn.text();
			$submitBtn.text('Creating...');

			this.apiRequest('/virtual-sites', {
				method: 'POST',
				data: formData
			})
				.then(data => {
					self.showSuccess(data.message || 'Virtual site created successfully');
					setTimeout(() => {
						const url = new URL(window.location.href);
						url.searchParams.set('tab', 'virtual_list');
						window.location.href = url.toString();
					}, 1000);
				})
				.catch(error => {
					console.log('[WPTSALL] Error:', error);
					alert(error.message || 'Create failed');
				})
				.finally(() => {
					$submitBtn.prop('disabled', false);
					$submitBtn.text(originalText);
				});
		},

		/**
		 * Delete virtual site (REST API)
		 */
		deleteVirtualSite(siteId) {
			const self = this;
			const $row = $(`.wptsall-virtual-sites-table tr[data-site-id="${siteId}"]`);

			$row.addClass('wptsall-loading');

			this.apiRequest(`/virtual-sites/${siteId}`, {
				method: 'DELETE'
			})
				.then(data => {
					$row.fadeOut(300, function() {
						$(this).remove();

						if ($('.wptsall-virtual-sites-table tbody tr').length === 0) {
							window.location.reload();
						}
					});
					self.showSuccess(data.message || 'Virtual site deleted successfully');
				})
				.catch(error => {
					alert(error.message || 'Delete failed');
					$row.removeClass('wptsall-loading');
				});
		},

		/**
		 * Check URL path conflict (REST API)
		 */
		checkUrlConflict(pathPrefix, excludeId = null) {
			const $conflict = $('#virtual-site-path-conflict');

			$conflict.removeClass('wptsall-conflict-error wptsall-conflict-success')
				.html('<span class="spinner is-active"></span> Checking...')
				.show();

			const params = { path_prefix: pathPrefix };
			if (excludeId) {
				params.exclude_id = excludeId;
			}

			this.apiRequest('/virtual-sites/check-url', {
				method: 'GET',
				data: params
			})
				.then(data => {
					if (data.has_conflict) {
						let conflictMsg = '<span class="dashicons dashicons-warning"></span> URL path conflict:';
						conflictMsg += '<ul>';
						data.conflicts.forEach(conflict => {
							conflictMsg += `<li>${conflict}</li>`;
						});
						conflictMsg += '</ul>';

						$conflict.addClass('wptsall-conflict-error')
							.html(conflictMsg)
							.show();
					} else {
						$conflict.addClass('wptsall-conflict-success')
							.html('<span class="dashicons dashicons-yes-alt"></span> URL path is available')
							.show();
					}
				})
				.catch(() => {
					$conflict.hide();
				});
		},

		// ========================================
		// ISS-SIT-018: Configuration copy feature
		// ========================================

		/**
		 * Show copy configuration modal
		 */
		showCopyConfigModal(sourceRelationId) {
			const self = this;

			const modalHtml = `
				<div class="wptsall-modal wptsall-copy-config-modal" id="wptsall-copy-config-modal">
					<div class="wptsall-modal-overlay"></div>
					<div class="wptsall-modal-content" style="max-width:500px;">
						<div class="wptsall-modal-header">
							<h3>${wptsallSiteRelations.strings.copyConfigTitle || 'Copy configuration to other relations'}</h3>
							<button class="wptsall-modal-close">&times;</button>
						</div>
						<div class="wptsall-modal-body">
							<div class="wptsall-modal-loading"><span class="spinner is-active"></span> ${wptsallSiteRelations.strings.loading || 'Loading...'}</div>
							<div class="wptsall-modal-error" style="display:none;"></div>
							<div class="wptsall-copy-config-content" style="display:none;">
								<div class="wptsall-copy-config-source" style="margin-bottom:20px;">
									<h4 style="margin:0 0 10px;">${wptsallSiteRelations.strings.sourceRelation || 'Source Relation'}</h4>
									<div class="wptsall-source-info" style="padding:10px; background:#f6f7f7; border:1px solid #dcdcde; border-radius:4px;"></div>
								</div>
								<div class="wptsall-copy-config-target">
									<h4 style="margin:0 0 10px;">${wptsallSiteRelations.strings.targetRelation || 'Target Relation'} <span class="required">*</span></h4>
									<select id="wptsall-copy-target-relation" class="regular-text" style="width:100%;">
										<option value="">${wptsallSiteRelations.strings.selectTargetRelation || 'Select target relation...'}</option>
									</select>
									<p class="description" style="margin-top:8px;">${wptsallSiteRelations.strings.copyConfigDesc || 'Select site relations to copy configuration to. The configuration will overwrite the target relation\'s existing configuration.'}</p>
								</div>
							</div>
						</div>
						<div class="wptsall-modal-footer">
							<button type="button" class="button wptsall-modal-close">${wptsallSiteRelations.strings.cancel || 'Cancel'}</button>
							<button type="button" class="button button-primary wptsall-confirm-copy-btn" data-source-id="${sourceRelationId}" style="display:none;">
								<span class="dashicons dashicons-admin-page" style="vertical-align:middle;"></span>
								${wptsallSiteRelations.strings.copyConfig || 'Copy Configuration'}
							</button>
						</div>
					</div>
				</div>
			`;

			$('#wptsall-copy-config-modal').remove();
			$('body').append(modalHtml);

			const $modal = $('#wptsall-copy-config-modal');
			$modal.fadeIn(200);

			// Load data
			this.loadCopyConfigData($modal, sourceRelationId);

			// Bind copy button events
			$modal.find('.wptsall-confirm-copy-btn').on('click', function() {
				self.executeCopyConfig(sourceRelationId);
			});
		},

		/**
		 * Load copy configuration data
		 */
		loadCopyConfigData($modal, sourceRelationId) {
			const self = this;
			const $loading = $modal.find('.wptsall-modal-loading');
			const $content = $modal.find('.wptsall-copy-config-content');
			const $error = $modal.find('.wptsall-modal-error');

			// Load source relation details, config stats, and other relations in parallel
			Promise.all([
				this.apiRequest(`/site-relations/${sourceRelationId}`, { method: 'GET' }),
				this.apiRequest(`/site-relations/${sourceRelationId}/post-type-configs/stats`, { method: 'GET' }),
				this.apiRequest('/site-relations', { method: 'GET' })
			])
				.then(([sourceData, statsData, allRelationsData]) => {
					$loading.hide();

					const source = sourceData.relation || sourceData;
					const stats = statsData.data || {};
					const allRelations = allRelationsData.relations || [];

					// Show source relation info
					const configCount = stats.total || 0;
					$modal.find('.wptsall-source-info').html(`
						<div style="display:flex; justify-content:space-between; align-items:center;">
							<div>
								<strong>#${source.id}</strong>
								<span style="margin:0 10px;">→</span>
								<code>${source.target_site_id}</code>
								<span class="lang-badge" style="margin-left:8px;">${source.target_lang || '-'}</span>
							</div>
							<div>
								<span style="color:#646970;">${wptsallSiteRelations.strings.configCount || 'configurations'}: </span>
								<strong>${configCount}</strong>
							</div>
						</div>
					`);

					// Filter and populate target relation dropdown (exclude self)
					const $select = $modal.find('#wptsall-copy-target-relation');
					const eligibleRelations = allRelations.filter(r => parseInt(r.id) !== parseInt(sourceRelationId));

					if (eligibleRelations.length === 0) {
						$select.html(`<option value="">${wptsallSiteRelations.strings.noOtherRelations || 'No other relations available'}</option>`);
					} else {
						let optionsHtml = `<option value="">${wptsallSiteRelations.strings.selectTargetRelation || 'Select target relation...'}</option>`;
						eligibleRelations.forEach(rel => {
							optionsHtml += `<option value="${rel.id}">#${rel.id} → ${rel.target_site_id} (${rel.target_lang || '-'})</option>`;
						});
						$select.html(optionsHtml);
					}

					$content.show();
					$modal.find('.wptsall-confirm-copy-btn').show();
				})
				.catch(error => {
					$loading.hide();
					$error.html(error.message || wptsallSiteRelations.strings.loadError || 'Load failed').show();
				});
		},

		/**
		 * Execute copy configuration
		 */
		executeCopyConfig(sourceRelationId) {
			const self = this;
			const $modal = $('#wptsall-copy-config-modal');
			const $btn = $modal.find('.wptsall-confirm-copy-btn');
			const $error = $modal.find('.wptsall-modal-error');
			const targetRelationId = $modal.find('#wptsall-copy-target-relation').val();

			if (!targetRelationId) {
				$error.html(wptsallSiteRelations.strings.selectTargetFirst || 'Please select a target relation first').show();
				return;
			}

			// Confirm action
			if (!confirm(wptsallSiteRelations.strings.confirmCopyConfig || 'Are you sure you want to copy the configuration? This will overwrite the existing configuration of the target relation.')) {
				return;
			}

			const originalText = $btn.html();
			$btn.prop('disabled', true).html(`<span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span>${wptsallSiteRelations.strings.copying || 'Copying...'}`);
			$error.hide();

			this.apiRequest(`/site-relations/${sourceRelationId}/post-type-configs/copy`, {
				method: 'POST',
				data: {
					target_relation_id: parseInt(targetRelationId)
				}
			})
				.then(response => {
					const data = response.data || {};
					const copiedCount = data.copied_count || 0;

					// Show success message
					self.showSuccess(
						(wptsallSiteRelations.strings.copySuccess || 'Configuration copied successfully! Copied %d configuration(s)').replace('%d', copiedCount)
					);

					// Close modal
					$modal.fadeOut(200, function() {
						$(this).remove();
					});
				})
				.catch(error => {
					$error.html(error.message || wptsallSiteRelations.strings.copyFailed || 'Copy failed').show();
					$btn.prop('disabled', false).html(originalText);
				});
		},

		/**
		 * Show success notification
		 */
		showSuccess(message) {
			const $notice = $(`
				<div class="notice notice-success is-dismissible" style="position:fixed;top:50px;right:20px;z-index:999999;">
					<p>${message}</p>
				</div>
			`);

			$('body').append($notice);

			setTimeout(() => {
				$notice.fadeOut(() => $notice.remove());
			}, 3000);
		}
	};

	/**
	 * Initialize when document is ready
	 */
	$(document).ready(function() {
		console.log('[WPTSALL] site-relations.js loaded (REST API version)');
		console.log('[WPTSALL] .wptsall-sites-page count:', $('.wptsall-sites-page').length);
		console.log('[WPTSALL] .wptsall-add-site count:', $('.wptsall-add-site').length);

		if ($('.wptsall-sites-page').length > 0 || $('.wptsall-add-site').length > 0) {
			console.log('[WPTSALL] Initializing SiteRelations...');
			SiteRelations.init();
			console.log('[WPTSALL] SiteRelations initialized');
		} else {
			console.log('[WPTSALL] Not on sites page, skipping initialization');
		}
	});

})(jQuery);
