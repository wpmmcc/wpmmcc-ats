/**
 * Custom Model Wizard
 *
 * Handles the five-step wizard for creating custom models.
 *
 * @package WPTSALL
 * @since 0.7.1
 */

(function($) {
	'use strict';

	/**
	 * Custom Model Wizard Class
	 */
	class CustomModelWizard {
		constructor(config) {
			this.config = config;
			this.restUrl = config.restUrl || '/wp-json/wptsall/v2';
			this.restNonce = config.restNonce || '';
			this.currentStep = 1;
			this.totalSteps = 5;

			// Wizard state
			this.state = {
				selectedPlugin: null,
				pluginData: null,
				urlPattern: '',
				urlType: 'frontend',
				extractedParams: [],
				linkChains: [],
				translateFields: [],
				syncFields: [],
				fieldMappings: [],
				computeFields: [],
				dataType: 'post',
				objectName: ''
			};

			// Cache DOM elements
			this.$wizard = $('.wptsall-wizard');
			this.$stepIndicators = this.$wizard.find('.wptsall-wizard-step');
			this.$stepContents = this.$wizard.find('.wptsall-wizard-step-content');
			this.$prevBtn = this.$wizard.find('.wptsall-wizard-prev');
			this.$nextBtn = this.$wizard.find('.wptsall-wizard-next');
			this.$saveBtn = this.$wizard.find('.wptsall-wizard-save');

			this.init();
		}

		/**
		 * Initialize the wizard
		 */
		init() {
			this.bindEvents();
			this.loadUnregisteredPlugins();
			this.updateStepIndicators();
		}

		/**
		 * Bind event handlers
		 */
		bindEvents() {
			// Navigation buttons
			this.$prevBtn.on('click', () => this.prevStep());
			this.$nextBtn.on('click', () => this.nextStep());
			this.$saveBtn.on('click', () => this.saveModel());

			// Plugin selection
			this.$wizard.on('change', '#wptsall-plugin-select', (e) => {
				this.onPluginSelect($(e.target).val());
			});

			// URL pattern input
			this.$wizard.on('input', '#wptsall-url-pattern', (e) => {
				this.onUrlPatternChange($(e.target).val());
			});

			// URL type selection
			this.$wizard.on('change', '#wptsall-url-type', (e) => {
				this.state.urlType = $(e.target).val();
			});

			// Add chain button
			this.$wizard.on('click', '.wptsall-add-chain-btn', () => {
				this.addLinkChain();
			});

			// Remove chain button
			this.$wizard.on('click', '.wptsall-remove-chain-btn', (e) => {
				const index = $(e.target).closest('.wptsall-link-chain-item').data('index');
				this.removeLinkChain(index);
			});

			// Chain node changes
			this.$wizard.on('change', '.chain-source-type', (e) => {
				this.onChainSourceTypeChange($(e.target));
			});

			this.$wizard.on('change', '.chain-target-table', (e) => {
				this.onChainTargetTableChange($(e.target));
			});

			// Test chain button
			this.$wizard.on('click', '.wptsall-test-chain-btn', () => {
				this.testLinkChain();
			});

			// Field checkboxes
			this.$wizard.on('change', '.wptsall-field-checkbox', (e) => {
				this.onFieldChange($(e.target));
			});
		}

		/**
		 * Navigate to previous step
		 */
		prevStep() {
			if (this.currentStep > 1) {
				this.goToStep(this.currentStep - 1);
			}
		}

		/**
		 * Navigate to next step
		 */
		async nextStep() {
			if (await this.validateCurrentStep()) {
				if (this.currentStep < this.totalSteps) {
					this.goToStep(this.currentStep + 1);
				}
			}
		}

		/**
		 * Go to specific step
		 */
		goToStep(step) {
			this.currentStep = step;
			this.updateStepIndicators();
			this.updateStepContent();
			this.updateNavigationButtons();
		}

		/**
		 * Update step indicators
		 */
		updateStepIndicators() {
			this.$stepIndicators.each((i, el) => {
				const $step = $(el);
				const stepNum = i + 1;

				$step.removeClass('active completed');

				if (stepNum === this.currentStep) {
					$step.addClass('active');
				} else if (stepNum < this.currentStep) {
					$step.addClass('completed');
				}
			});
		}

		/**
		 * Update step content visibility
		 */
		updateStepContent() {
			this.$stepContents.removeClass('active');
			this.$stepContents.filter(`[data-step="${this.currentStep}"]`).addClass('active');

			// Trigger step-specific setup
			switch (this.currentStep) {
				case 3:
					this.setupLinkChainStep();
					break;
				case 4:
					this.setupFieldSelectionStep();
					break;
				case 5:
					this.setupValidationStep();
					break;
			}
		}

		/**
		 * Update navigation button states
		 */
		updateNavigationButtons() {
			this.$prevBtn.prop('disabled', this.currentStep === 1);
			this.$nextBtn.toggle(this.currentStep < this.totalSteps);
			this.$saveBtn.toggle(this.currentStep === this.totalSteps);
		}

		/**
		 * Validate current step
		 */
		async validateCurrentStep() {
			switch (this.currentStep) {
				case 1:
					return this.validatePluginSelection();
				case 2:
					return this.validateUrlPattern();
				case 3:
					return this.validateLinkChains();
				case 4:
					return this.validateFieldSelection();
				default:
					return true;
			}
		}

		/**
		 * Validate plugin selection (Step 1)
		 */
		validatePluginSelection() {
			if (!this.state.selectedPlugin) {
				this.showError(wptsallWizard.i18n.selectPlugin || 'Please select a plugin');
				return false;
			}
			return true;
		}

		/**
		 * Validate URL pattern (Step 2)
		 */
		validateUrlPattern() {
			if (!this.state.urlPattern) {
				this.showError(wptsallWizard.i18n.enterUrlPattern || 'Please enter a URL pattern');
				return false;
			}

			// Basic pattern validation
			if (!this.state.urlPattern.startsWith('/')) {
				this.showError(wptsallWizard.i18n.urlPatternSlash || 'URL pattern must start with /');
				return false;
			}

			return true;
		}

		/**
		 * Validate link chains (Step 3)
		 */
		async validateLinkChains() {
			// Link chains are optional for simple models
			if (this.state.linkChains.length === 0) {
				return true;
			}

			// Validate chain configuration
			for (const chain of this.state.linkChains) {
				if (!chain.target_table || !chain.target_match_column || !chain.target_id_column) {
					this.showError(wptsallWizard.i18n.incompleteChain || 'Please complete all chain configurations');
					return false;
				}
			}

			return true;
		}

		/**
		 * Validate field selection (Step 4)
		 */
		validateFieldSelection() {
			const totalFields = this.state.translateFields.length +
				this.state.syncFields.length +
				this.state.fieldMappings.length +
				this.state.computeFields.length;

			if (totalFields === 0) {
				this.showError(wptsallWizard.i18n.selectFields || 'Please select at least one field');
				return false;
			}

			return true;
		}

		/**
		 * Load unregistered plugins
		 */
		async loadUnregisteredPlugins() {
			this.setLoading(true);

			try {
				const response = await this.apiRequest('/custom-models/unregistered-plugins');

				if (response.success) {
					this.renderPluginSelect(response.plugins);
				} else {
					this.showError(response.message || 'Failed to load plugins');
				}
			} catch (error) {
				this.showError('Error loading plugins: ' + error.message);
			} finally {
				this.setLoading(false);
			}
		}

		/**
		 * Render plugin select dropdown
		 */
		renderPluginSelect(plugins) {
			const $select = $('#wptsall-plugin-select');
			$select.empty();
			$select.append('<option value="">' + (wptsallWizard.i18n.selectPlugin || 'Select a plugin...') + '</option>');

			for (const [slug, data] of Object.entries(plugins)) {
				$select.append(`<option value="${slug}">${data.name} (${slug})</option>`);
			}

			// Store plugins for later reference
			this.availablePlugins = plugins;
		}

		/**
		 * Handle plugin selection
		 */
		async onPluginSelect(pluginSlug) {
			if (!pluginSlug) {
				this.state.selectedPlugin = null;
				this.state.pluginData = null;
				$('.wptsall-plugin-data-check').hide();
				return;
			}

			this.state.selectedPlugin = pluginSlug;
			this.state.pluginData = this.availablePlugins[pluginSlug];

			// Check if plugin has data
			await this.checkPluginData(pluginSlug);
		}

		/**
		 * Check if plugin has data
		 */
		async checkPluginData(pluginSlug) {
			this.setLoading(true);

			try {
				const response = await this.apiRequest('/custom-models/check-plugin-data', {
					method: 'POST',
					data: { plugin_slug: pluginSlug }
				});

				this.renderPluginDataCheck(response);
			} catch (error) {
				this.showError('Error checking plugin data: ' + error.message);
			} finally {
				this.setLoading(false);
			}
		}

		/**
		 * Render plugin data check result
		 */
		renderPluginDataCheck(data) {
			const $container = $('.wptsall-plugin-data-check');

			let html = '';
			let statusClass = data.has_data ? 'has-data' : 'no-data';
			let icon = data.has_data ? '&#10003;' : '&#10007;';
			let message = data.has_data
				? (wptsallWizard.i18n.dataFound || 'Found {count} related data items').replace('{count}', data.data_count)
				: (wptsallWizard.i18n.noDataFound || 'No related data found');

			html += `<span class="check-icon">${icon}</span>${message}`;

			if (data.has_data) {
				html += '<div class="data-details">';

				if (data.post_types && data.post_types.length > 0) {
					html += '<div><strong>' + (wptsallWizard.i18n.postTypes || 'Post Types') + ':</strong> ';
					html += data.post_types.map(pt => `${pt.label} (${pt.count})`).join(', ');
					html += '</div>';

					// Auto-set object_name from first post type
					if (data.post_types.length > 0) {
						this.state.objectName = data.post_types[0].name;
						this.state.dataType = 'post';
					}
				}

				if (data.meta_keys && data.meta_keys.length > 0) {
					html += '<div><strong>' + (wptsallWizard.i18n.metaKeys || 'Meta Keys') + ':</strong> ';
					html += data.meta_keys.slice(0, 10).join(', ');
					if (data.meta_keys.length > 10) {
						html += ` ... +${data.meta_keys.length - 10}`;
					}
					html += '</div>';
				}

				if (data.tables && data.tables.length > 0) {
					html += '<div><strong>' + (wptsallWizard.i18n.customTables || 'Custom Tables') + ':</strong> ';
					html += data.tables.map(t => `${t.name} (${t.row_count})`).join(', ');
					html += '</div>';
				}

				html += '</div>';
			}

			$container.html(html).removeClass('has-data no-data').addClass(statusClass).show();
		}

		/**
		 * Handle URL pattern change
		 */
		onUrlPatternChange(pattern) {
			this.state.urlPattern = pattern;
			this.extractUrlParams(pattern);
			this.renderUrlPatternPreview();
		}

		/**
		 * Extract parameters from URL pattern
		 */
		extractUrlParams(pattern) {
			const paramRegex = /\{(\w+)\}/g;
			const params = [];
			let match;

			while ((match = paramRegex.exec(pattern)) !== null) {
				params.push({
					name: match[1],
					type: 'string'
				});
			}

			this.state.extractedParams = params;
		}

		/**
		 * Render URL pattern preview
		 */
		renderUrlPatternPreview() {
			const $preview = $('.wptsall-url-pattern-preview');
			const siteUrl = wptsallWizard.siteUrl || '';

			let html = `
				<div class="preview-label">${wptsallWizard.i18n.preview || 'Preview'}:</div>
				<div class="preview-url">${siteUrl}${this.state.urlPattern}</div>
			`;

			if (this.state.extractedParams.length > 0) {
				html += '<div class="wptsall-extracted-params">';
				html += '<div class="preview-label">' + (wptsallWizard.i18n.extractedParams || 'Extracted Parameters') + ':</div>';

				for (const param of this.state.extractedParams) {
					html += `
						<span class="param-item">
							<span class="param-name">{${param.name}}</span>
							<span class="param-type">${param.type}</span>
						</span>
					`;
				}

				html += '</div>';
			}

			$preview.html(html).show();
		}

		/**
		 * Setup link chain step
		 */
		async setupLinkChainStep() {
			// Load database tables if not loaded
			if (!this.databaseTables) {
				await this.loadDatabaseTables();
			}

			this.renderLinkChainBuilder();
		}

		/**
		 * Load database tables
		 */
		async loadDatabaseTables() {
			try {
				const response = await this.apiRequest('/discovery/tables');
				if (response.success) {
					this.databaseTables = response.tables;
				}
			} catch (error) {
				console.error('Error loading tables:', error);
			}
		}

		/**
		 * Render link chain builder
		 */
		renderLinkChainBuilder() {
			const $builder = $('.wptsall-link-chain-builder');

			let html = '<div class="wptsall-link-chains">';

			// Render existing chains
			if (this.state.linkChains.length > 0) {
				this.state.linkChains.forEach((chain, index) => {
					html += this.renderLinkChainItem(chain, index);
				});
			}

			html += '</div>';

			// Add chain button
			html += `
				<button type="button" class="wptsall-add-chain-btn">
					<span class="dashicons dashicons-plus-alt2"></span>
					${wptsallWizard.i18n.addChainNode || 'Add Link Node'}
				</button>
			`;

			// Test chain button
			if (this.state.linkChains.length > 0) {
				html += `
					<div class="wptsall-chain-test" style="margin-top: 20px;">
						<input type="text" class="wptsall-test-value" placeholder="${wptsallWizard.i18n.testValue || 'Enter test value...'}" style="width: 200px; margin-right: 10px;">
						<button type="button" class="button wptsall-test-chain-btn">${wptsallWizard.i18n.testChain || 'Test Chain'}</button>
					</div>
					<div class="wptsall-chain-test-result" style="margin-top: 10px;"></div>
				`;
			}

			$builder.html(html);
		}

		/**
		 * Render a single link chain item
		 */
		renderLinkChainItem(chain, index) {
			const tables = this.databaseTables || [];
			const tableOptions = tables.map(t =>
				`<option value="${t.display_name}" ${chain.target_table === t.display_name ? 'selected' : ''}>${t.display_name}</option>`
			).join('');

			const sourceTypeOptions = `
				<option value="url_param" ${chain.source_type === 'url_param' ? 'selected' : ''}>${wptsallWizard.i18n.urlParam || 'URL Parameter'}</option>
				<option value="table_column" ${chain.source_type === 'table_column' ? 'selected' : ''}>${wptsallWizard.i18n.tableColumn || 'Table Column'}</option>
			`;

			// Source param options from extracted params
			const paramOptions = this.state.extractedParams.map(p =>
				`<option value="${p.name}" ${chain.source_param === p.name ? 'selected' : ''}>{${p.name}}</option>`
			).join('');

			return `
				<div class="wptsall-link-chain-item" data-index="${index}">
					<div class="wptsall-link-chain">
						<div class="wptsall-chain-node source">
							<div class="wptsall-chain-node-title">${wptsallWizard.i18n.source || 'Source'}</div>
							<select class="chain-source-type">
								${sourceTypeOptions}
							</select>
							<div class="source-type-fields">
								${chain.source_type === 'url_param' ? `
									<select class="chain-source-param">
										<option value="">${wptsallWizard.i18n.selectParam || 'Select parameter'}</option>
										${paramOptions}
									</select>
								` : `
									<select class="chain-source-table">
										<option value="">${wptsallWizard.i18n.selectTable || 'Select table'}</option>
										${tableOptions}
									</select>
									<input type="text" class="chain-source-column" placeholder="${wptsallWizard.i18n.columnName || 'Column name'}" value="${chain.source_column || ''}">
								`}
							</div>
						</div>

						<span class="wptsall-chain-arrow">&rarr;</span>

						<div class="wptsall-chain-node target">
							<div class="wptsall-chain-node-title">${wptsallWizard.i18n.target || 'Target'}</div>
							<select class="chain-target-table">
								<option value="">${wptsallWizard.i18n.selectTable || 'Select table'}</option>
								${tableOptions}
							</select>
							<div class="target-columns">
								<input type="text" class="chain-target-match" placeholder="${wptsallWizard.i18n.matchColumn || 'Match column'}" value="${chain.target_match_column || ''}">
								<input type="text" class="chain-target-id" placeholder="${wptsallWizard.i18n.idColumn || 'ID column'}" value="${chain.target_id_column || ''}">
							</div>
						</div>

						<button type="button" class="button wptsall-remove-chain-btn" title="${wptsallWizard.i18n.remove || 'Remove'}">
							<span class="dashicons dashicons-no-alt"></span>
						</button>
					</div>
				</div>
			`;
		}

		/**
		 * Add a new link chain
		 */
		addLinkChain() {
			this.state.linkChains.push({
				source_type: 'url_param',
				source_param: this.state.extractedParams.length > 0 ? this.state.extractedParams[0].name : '',
				source_table: '',
				source_column: '',
				target_table: '',
				target_match_column: '',
				target_id_column: ''
			});

			this.renderLinkChainBuilder();
		}

		/**
		 * Remove a link chain
		 */
		removeLinkChain(index) {
			this.state.linkChains.splice(index, 1);
			this.renderLinkChainBuilder();
		}

		/**
		 * Handle chain source type change
		 */
		onChainSourceTypeChange($select) {
			const $item = $select.closest('.wptsall-link-chain-item');
			const index = $item.data('index');
			const sourceType = $select.val();

			this.state.linkChains[index].source_type = sourceType;
			this.renderLinkChainBuilder();
		}

		/**
		 * Handle target table change
		 */
		async onChainTargetTableChange($select) {
			const tableName = $select.val();

			if (!tableName) return;

			// Load columns for the selected table
			try {
				const response = await this.apiRequest(`/discovery/columns?table=${tableName}`);
				if (response.success) {
					// Could auto-suggest common column names
					console.log('Table columns:', response.columns);
				}
			} catch (error) {
				console.error('Error loading columns:', error);
			}
		}

		/**
		 * Test link chain
		 */
		async testLinkChain() {
			const testValue = this.$wizard.find('.wptsall-test-value').val();
			if (!testValue) {
				this.showError(wptsallWizard.i18n.enterTestValue || 'Please enter a test value');
				return;
			}

			// Collect current chain configuration
			this.collectLinkChainState();

			try {
				const response = await this.apiRequest('/custom-models/test-link-chain', {
					method: 'POST',
					data: {
						chain_config: this.state.linkChains,
						sample_value: testValue
					}
				});

				this.renderChainTestResult(response);
			} catch (error) {
				this.showError('Error testing chain: ' + error.message);
			}
		}

		/**
		 * Collect link chain state from DOM
		 */
		collectLinkChainState() {
			this.state.linkChains = [];

			this.$wizard.find('.wptsall-link-chain-item').each((index, el) => {
				const $item = $(el);
				const sourceType = $item.find('.chain-source-type').val();

				const chain = {
					source_type: sourceType,
					target_table: $item.find('.chain-target-table').val(),
					target_match_column: $item.find('.chain-target-match').val(),
					target_id_column: $item.find('.chain-target-id').val()
				};

				if (sourceType === 'url_param') {
					chain.source_param = $item.find('.chain-source-param').val();
				} else {
					chain.source_table = $item.find('.chain-source-table').val();
					chain.source_column = $item.find('.chain-source-column').val();
				}

				this.state.linkChains.push(chain);
			});
		}

		/**
		 * Render chain test result
		 */
		renderChainTestResult(response) {
			const $result = this.$wizard.find('.wptsall-chain-test-result');

			if (response.success && response.test_result && response.test_result.success) {
				$result.html(`
					<div class="wptsall-validation-result success">
						<span class="result-icon">&#10003;</span>
						${wptsallWizard.i18n.chainTestSuccess || 'Chain test successful!'}
						(${response.test_result.matched_rows} ${wptsallWizard.i18n.matchedRows || 'rows matched'})
					</div>
				`);
			} else {
				const error = response.test_result?.error || response.errors?.join(', ') || 'Unknown error';
				$result.html(`
					<div class="wptsall-validation-result error">
						<span class="result-icon">&#10007;</span>
						${wptsallWizard.i18n.chainTestFailed || 'Chain test failed'}: ${error}
					</div>
				`);
			}
		}

		/**
		 * Setup field selection step
		 */
		async setupFieldSelectionStep() {
			// Load available fields based on data type and object name
			await this.loadAvailableFields();
			this.renderFieldSelection();
		}

		/**
		 * Load available fields
		 */
		async loadAvailableFields() {
			if (!this.state.dataType || !this.state.objectName) {
				return;
			}

			try {
				const response = await this.apiRequest('/models/detect-fields', {
					method: 'POST',
					data: {
						data_type: this.state.dataType,
						object_name: this.state.objectName
					}
				});

				if (response.success) {
					this.availableFields = response.fields;
				}
			} catch (error) {
				console.error('Error loading fields:', error);
			}
		}

		/**
		 * Render field selection UI
		 */
		renderFieldSelection() {
			const $container = $('.wptsall-field-selection');
			const fields = this.availableFields || [];

			// Group fields by source
			const coreFields = fields.filter(f => f.source === 'post' || f.source === 'term');
			const metaFields = fields.filter(f => f.source === 'meta');
			const taxonomyFields = fields.filter(f => f.source === 'taxonomy');

			let html = '<div class="wptsall-field-groups">';

			// Translate fields
			html += this.renderFieldGroup(
				wptsallWizard.i18n.translateFields || 'Translate Fields',
				'dashicons-translation',
				'translate',
				coreFields.concat(metaFields)
			);

			// Sync fields
			html += this.renderFieldGroup(
				wptsallWizard.i18n.syncFields || 'Sync Fields',
				'dashicons-update',
				'sync',
				coreFields
			);

			// Field mappings
			html += this.renderFieldGroup(
				wptsallWizard.i18n.fieldMappings || 'ID Mappings',
				'dashicons-admin-links',
				'mapping',
				metaFields.filter(f => f.name.includes('_id'))
			);

			// Compute fields
			html += this.renderFieldGroup(
				wptsallWizard.i18n.computeFields || 'Compute Fields',
				'dashicons-calculator',
				'compute',
				coreFields.filter(f => ['post_name', 'guid', 'comment_count'].includes(f.name))
			);

			html += '</div>';

			$container.html(html);
		}

		/**
		 * Render a field group
		 */
		renderFieldGroup(title, icon, type, fields) {
			let html = `
				<div class="wptsall-field-group">
					<div class="wptsall-field-group-title">
						<span class="dashicons ${icon}"></span>
						${title}
					</div>
					<div class="wptsall-field-list">
			`;

			if (fields.length === 0) {
				html += `<div class="no-fields">${wptsallWizard.i18n.noFields || 'No fields available'}</div>`;
			} else {
				for (const field of fields) {
					const checked = this.isFieldSelected(field.name, type) ? 'checked' : '';
					html += `
						<div class="wptsall-field-item">
							<input type="checkbox" class="wptsall-field-checkbox"
								data-field="${field.name}"
								data-type="${type}"
								id="field-${type}-${field.name}"
								${checked}>
							<label for="field-${type}-${field.name}">${field.name}</label>
							<span class="field-type">${field.type}</span>
						</div>
					`;
				}
			}

			html += '</div></div>';
			return html;
		}

		/**
		 * Check if field is selected
		 */
		isFieldSelected(fieldName, type) {
			switch (type) {
				case 'translate':
					return this.state.translateFields.includes(fieldName);
				case 'sync':
					return this.state.syncFields.includes(fieldName);
				case 'mapping':
					return this.state.fieldMappings.includes(fieldName);
				case 'compute':
					return this.state.computeFields.includes(fieldName);
				default:
					return false;
			}
		}

		/**
		 * Handle field checkbox change
		 */
		onFieldChange($checkbox) {
			const fieldName = $checkbox.data('field');
			const type = $checkbox.data('type');
			const isChecked = $checkbox.is(':checked');

			let stateArray;
			switch (type) {
				case 'translate':
					stateArray = this.state.translateFields;
					break;
				case 'sync':
					stateArray = this.state.syncFields;
					break;
				case 'mapping':
					stateArray = this.state.fieldMappings;
					break;
				case 'compute':
					stateArray = this.state.computeFields;
					break;
			}

			if (isChecked) {
				if (!stateArray.includes(fieldName)) {
					stateArray.push(fieldName);
				}
			} else {
				const index = stateArray.indexOf(fieldName);
				if (index > -1) {
					stateArray.splice(index, 1);
				}
			}
		}

		/**
		 * Setup validation step
		 */
		setupValidationStep() {
			this.collectLinkChainState();
			this.renderValidationSummary();
		}

		/**
		 * Render validation summary
		 */
		renderValidationSummary() {
			const $container = $('.wptsall-validation-summary');
			const pluginData = this.state.pluginData || {};

			let html = `
				<div class="wptsall-summary-section">
					<h4>${wptsallWizard.i18n.pluginInfo || 'Plugin Information'}</h4>
					<div class="wptsall-summary-item">
						<span class="label">${wptsallWizard.i18n.plugin || 'Plugin'}:</span>
						<span class="value">${pluginData.name || this.state.selectedPlugin}</span>
					</div>
					<div class="wptsall-summary-item">
						<span class="label">${wptsallWizard.i18n.urlPattern || 'URL Pattern'}:</span>
						<span class="value">${this.state.urlPattern}</span>
					</div>
					<div class="wptsall-summary-item">
						<span class="label">${wptsallWizard.i18n.urlType || 'URL Type'}:</span>
						<span class="value">${this.state.urlType}</span>
					</div>
				</div>

				<div class="wptsall-summary-section">
					<h4>${wptsallWizard.i18n.linkChains || 'Link Chains'}</h4>
					<div class="wptsall-summary-item">
						<span class="label">${wptsallWizard.i18n.chainCount || 'Chain Nodes'}:</span>
						<span class="value">${this.state.linkChains.length}</span>
					</div>
				</div>

				<div class="wptsall-summary-section">
					<h4>${wptsallWizard.i18n.fields || 'Fields'}</h4>
					<div class="wptsall-summary-item">
						<span class="label">${wptsallWizard.i18n.translateFields || 'Translate'}:</span>
						<span class="value">${this.state.translateFields.length}</span>
					</div>
					<div class="wptsall-summary-item">
						<span class="label">${wptsallWizard.i18n.syncFields || 'Sync'}:</span>
						<span class="value">${this.state.syncFields.length}</span>
					</div>
					<div class="wptsall-summary-item">
						<span class="label">${wptsallWizard.i18n.fieldMappings || 'ID Mappings'}:</span>
						<span class="value">${this.state.fieldMappings.length}</span>
					</div>
					<div class="wptsall-summary-item">
						<span class="label">${wptsallWizard.i18n.computeFields || 'Compute'}:</span>
						<span class="value">${this.state.computeFields.length}</span>
					</div>
				</div>
			`;

			$container.html(html);
		}

		/**
		 * Save the model
		 */
		async saveModel() {
			this.setLoading(true);
			this.$saveBtn.prop('disabled', true);

			// Prepare model data (v0.8.0: use field_capabilities)
			const modelData = {
				plugin_slug: this.state.selectedPlugin,
				plugin_name: this.state.pluginData?.name || this.state.selectedPlugin,
				plugin_version: this.state.pluginData?.version || '',
				text_domain: this.state.pluginData?.text_domain || this.state.selectedPlugin,
				url_pattern: this.state.urlPattern,
				url_type: this.state.urlType,
				data_type: this.state.dataType,
				object_name: this.state.objectName,
				field_capabilities: this.buildFieldCapabilities(
					this.state.translateFields,
					this.state.syncFields,
					this.state.fieldMappings,
					this.state.computeFields
				),
				link_chains: this.state.linkChains
			};

			try {
				const response = await this.apiRequest('/custom-models', {
					method: 'POST',
					data: modelData
				});

				if (response.success) {
					this.showSuccess(wptsallWizard.i18n.modelCreated || 'Custom model created successfully!');

					// Redirect to model editor after short delay
					setTimeout(() => {
						if (response.model_id) {
							window.location.href = wptsallWizard.editUrl.replace('{id}', response.model_id);
						} else {
							window.location.href = wptsallWizard.listUrl;
						}
					}, 1500);
				} else {
					this.showError(response.message || 'Failed to create model');
					this.$saveBtn.prop('disabled', false);
				}
			} catch (error) {
				this.showError('Error creating model: ' + error.message);
				this.$saveBtn.prop('disabled', false);
			} finally {
				this.setLoading(false);
			}
		}

		/**
		 * Format fields for save (legacy, kept for compatibility)
		 */
		formatFieldsForSave(fields) {
			return fields.map(field => ({
				field: field,
				type: 'text'
			}));
		}

		/**
		 * Build field_capabilities object (v0.8.0)
		 */
		buildFieldCapabilities(translateFields, syncFields, mappingFields, computeFields) {
			const capabilities = {};

			// Translate fields
			(translateFields || []).forEach(field => {
				capabilities[field] = {
					type: 'translate',
					direction: 'one_way',
					enabled: true
				};
			});

			// Sync fields
			(syncFields || []).forEach(field => {
				capabilities[field] = {
					type: 'sync',
					direction: 'one_way',
					enabled: true
				};
			});

			// Mapping fields
			(mappingFields || []).forEach(field => {
				capabilities[field] = {
					type: 'mapping',
					direction: 'one_way',
					enabled: true
				};
			});

			// Compute fields
			(computeFields || []).forEach(field => {
				capabilities[field] = {
					type: 'compute',
					enabled: true
				};
			});

			return capabilities;
		}

		/**
		 * Make API request
		 */
		async apiRequest(endpoint, options = {}) {
			const url = this.restUrl + endpoint;
			const config = {
				method: options.method || 'GET',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': this.restNonce
				}
			};

			if (options.data) {
				config.body = JSON.stringify(options.data);
			}

			const response = await fetch(url, config);
			return response.json();
		}

		/**
		 * Set loading state
		 */
		setLoading(loading) {
			this.$wizard.toggleClass('loading', loading);
		}

		/**
		 * Show error message
		 */
		showError(message) {
			// Use WordPress notices if available
			const $notice = $(`
				<div class="notice notice-error is-dismissible">
					<p>${message}</p>
					<button type="button" class="notice-dismiss"></button>
				</div>
			`);

			$('.wptsall-wizard-notices').html($notice);

			$notice.find('.notice-dismiss').on('click', function() {
				$notice.fadeOut(function() { $(this).remove(); });
			});

			// Auto-dismiss after 5 seconds
			setTimeout(() => $notice.fadeOut(function() { $(this).remove(); }), 5000);
		}

		/**
		 * Show success message
		 */
		showSuccess(message) {
			const $notice = $(`
				<div class="notice notice-success is-dismissible">
					<p>${message}</p>
					<button type="button" class="notice-dismiss"></button>
				</div>
			`);

			$('.wptsall-wizard-notices').html($notice);

			$notice.find('.notice-dismiss').on('click', function() {
				$notice.fadeOut(function() { $(this).remove(); });
			});
		}
	}

	// Initialize on document ready
	$(document).ready(function() {
		if ($('.wptsall-wizard').length > 0 && typeof wptsallWizard !== 'undefined') {
			window.wptsallCustomModelWizard = new CustomModelWizard(wptsallWizard);
		}
	});

})(jQuery);
