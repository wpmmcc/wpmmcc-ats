/**
 * WPTSALL Field Configurator
 *
 * Advanced field configuration UI for translation rules.
 * Supports drag-and-drop, field type categorization, and detailed configuration.
 *
 * G2: Available fields are now loaded from model_object_fields table via
 *     GET /wptsall/v2/rules/{id}/available-fields, replacing the old
 *     manualFields-only approach.
 *
 * @since 0.5.0
 * @updated 1.0.5 G2: rule-based available fields
 */

(function ($) {
    'use strict';

    /**
     * Field Configurator Class
     */
    class FieldConfigurator {
        constructor(containerId, options = {}) {
            this.container = $(`#${containerId}`);
            // PHP passes rule_id='new' for the add-rule form; treat non-numeric
            // ids as "no rule yet" so the loader uses the model/object path
            // instead of hitting /rules/new/available-fields (404).
            this.options = $.extend({
                restUrl: wptsallFieldConfig.restUrl,
                nonce: wptsallFieldConfig.nonce,
                ruleId: Number(wptsallFieldConfig.ruleId) || 0,
                relationId: wptsallFieldConfig.relationId || 0,
                modelId: wptsallFieldConfig.modelId,
                objectName: wptsallFieldConfig.objectName,
                dataType: wptsallFieldConfig.dataType,
                objectChoices: wptsallFieldConfig.objectChoices || {},
                objectPlaceholder: wptsallFieldConfig.objectPlaceholder || '-- Please select --',
                noObjectsLabel: wptsallFieldConfig.noObjectsLabel || 'No model object is available for the selected data type',
                manualFields: wptsallFieldConfig.manualFields || []
            }, options);
            this.options.ruleId = Number(this.options.ruleId) || 0;

            this.fieldTypes = {
                // WPML four-state labels (+ advanced id_mapping / compute).
                translate: { label: 'Translate', icon: '🌐', color: '#2271b1', hint: 'Send for translation' },
                sync_direct: { label: 'Copy', icon: '📋', color: '#50575e', hint: 'Always copy from source (WPML Copy)' },
                copy_once: { label: 'Copy once', icon: '1️⃣', color: '#996800', hint: 'Copy only when target is empty (WPML Copy once)' },
                sync_mapped: { label: 'ID Mapping', icon: '🔗', color: '#00a32a', hint: 'Rewrite IDs to target objects' },
                compute: { label: 'Compute', icon: '⚙️', color: '#8c8f94', hint: 'Derived / regenerated fields' }
            };
            // Fields not placed in any category = Don't translate (WPML Ignore)

            this.fieldConfig = {
                translate: [],
                sync_direct: [],
                copy_once: [],
                sync_mapped: [],
                compute: []
            };

            // G2: All known fields from model_object_fields (populated by loadRuleAvailableFields).
            this.templateFields = [];
            this.templateFieldNameSet = new Set();
            this.dependencyState = {
                summary: null,
                dependencies: []
            };

            this.init();
        }

        /**
         * Initialize the configurator
         */
        init() {
            // Load existing configuration BEFORE render (PHP pre-fills the textarea)
            this.loadExistingConfiguration();
            this.render();
            this.bindEvents();
            this.syncObjectNameSelect();

            // G2: Load available fields from model_object_fields via the new endpoint.
            this.loadRuleAvailableFields();
            this.loadDependencyGraph();
            this.loadEffectiveConfigPreview();

            // Render loaded fields to UI
            this.renderLoadedFields();
        }

        /**
         * G2: Load available fields from /rules/{id}/available-fields endpoint.
         *
         * Falls back to legacy manualFields + detect-fields if the endpoint
         * is unavailable or if ruleId is missing.
         */
        loadRuleAvailableFields() {
            const self = this;
            const ruleId = this.options.ruleId;

            if (!ruleId) {
                // No rule context: try template-constrained model/object fields first.
                this.loadFieldsForModelObject();
                return;
            }

            $('#wptsall-fc-available-fields').html(
                '<p class="wptsall-fc-loading">Loading template fields...</p>'
            );

            $.ajax({
                url: `${this.options.restUrl}rules/${ruleId}/available-fields`,
                method: 'GET',
                headers: { 'X-WP-Nonce': this.options.nonce },
                success: function (response) {
                    const fields = Array.isArray(response) ? response : [];
                    self.templateFields = fields;
                    self.templateFieldNameSet = new Set(
                        fields.map(f => String(f.field_key || '').trim()).filter(Boolean)
                    );

                    // Convert to the format renderAvailableFields expects.
                    const mapped = fields.map(f => ({
                        name: f.field_key,
                        type: f.field_kind || 'unknown',
                        source: f.source || '',
                        dataType: f.data_type || '',
                        status: f.status || '',
                        id: f.id
                    }));

                    // Merge with any remaining manual fields (backward compat).
                    const merged = self.templateFieldNameSet.size > 0
                        ? mapped
                        : self.mergeManualFields(mapped);
                    self.renderAvailableFields(merged);
                    self.showInlineNotice(`Loaded ${fields.length} template field(s) for this rule.`, 'info');
                },
                error: function () {
                    // Fallback: try legacy detect-fields or show manualFields.
                    if (self.options.manualFields && self.options.manualFields.length > 0) {
                        self.renderAvailableFields(self.mergeManualFields([]));
                    } else {
                        $('#wptsall-fc-available-fields').html(
                            '<p class="wptsall-fc-error">Could not load template fields. ' +
                            'Click "Auto-detect Fields" to try detection.</p>'
                        );
                    }
                    self.templateFieldNameSet = new Set();
                    self.showInlineNotice('Template fields could not be loaded. You can still detect fields, but save-time checks will be stricter.', 'warning');
                }
            });
        }

        /**
         * Load available fields by model_id + object_name + data_type.
         *
         * Used when rule_id does not exist yet (new rule form). This keeps
         * field selection constrained to plugin template fields.
         */
        loadFieldsForModelObject() {
            const modelId = Number(this.options.modelId || 0);
            const dataType = String(this.options.dataType || '').trim();
            const objectName = String(this.options.objectName || '').trim();

            if (!modelId || !dataType || !objectName) {
                this.templateFieldNameSet = new Set();
                // Show an actionable hint instead of leaving the loading/detecting
                // placeholder spinning forever while nothing is being fetched.
                $('#wptsall-fc-available-fields').html(
                    '<p class="wptsall-fc-empty">Select the rule target object to load its template fields.</p>'
                );
                if (this.options.manualFields && this.options.manualFields.length > 0) {
                    this.renderAvailableFields(this.mergeManualFields([]));
                }
                return;
            }

            $('#wptsall-fc-available-fields').html(
                '<p class="wptsall-fc-loading">Loading template fields...</p>'
            );

            $.ajax({
                url: `${this.options.restUrl}fields`,
                method: 'GET',
                headers: { 'X-WP-Nonce': this.options.nonce },
                data: {
                    model_id: modelId,
                    data_type: dataType,
                    object_name: objectName
                },
                success: (response) => {
                    const mapped = this.mapFieldBucketsToList(response);
                    this.templateFieldNameSet = new Set(
                        mapped.map(f => String(f.name || '').trim()).filter(Boolean)
                    );
                    const merged = this.templateFieldNameSet.size > 0
                        ? mapped
                        : this.mergeManualFields(mapped);
                    this.renderAvailableFields(merged);
                    this.showInlineNotice(`Loaded ${this.templateFieldNameSet.size} template field(s) for ${objectName}.`, 'info');
                },
                error: () => {
                    this.templateFieldNameSet = new Set();
                    if (this.options.manualFields && this.options.manualFields.length > 0) {
                        this.renderAvailableFields(this.mergeManualFields([]));
                    } else {
                        $('#wptsall-fc-available-fields').html(
                            '<p class="wptsall-fc-error">Could not load template fields. Click "Auto-detect Fields" to try detection.</p>'
                        );
                    }
                    this.showInlineNotice('Template field list is unavailable. Add fields cautiously and validate before save.', 'warning');
                }
            });
        }

        /**
         * Load dependency graph for current rule/object context.
         */
        loadDependencyGraph() {
            const $summary = $('#wptsall-fc-dependency-summary');
            const $list = $('#wptsall-fc-dependency-list');
            if (!$summary.length || !$list.length) {
                return;
            }

            const ruleId = Number(this.options.ruleId || 0);
            const modelId = Number(
                $('form#wptsall-rule-edit-form').find('input[name="model_id"]').val() ||
                this.options.modelId ||
                0
            );
            const dataType = String($('#rule-data-type').val() || this.options.dataType || '').trim();
            const objectName = String($('#rule-object-name').val() || this.options.objectName || '').trim();

            const requestData = {};
            if (ruleId > 0) {
                requestData.rule_id = ruleId;
            } else {
                if (!modelId || !dataType || !objectName) {
                    $summary.html('<span class="is-muted">Select rule binding to inspect dependencies.</span>');
                    $list.html('');
                    return;
                }
                requestData.model_id = modelId;
                requestData.data_type = dataType;
                requestData.object_name = objectName;
            }

            $summary.html('<span class="is-loading">Loading dependencies...</span>');
            $list.html('');

            $.ajax({
                url: `${this.options.restUrl}rules/dependencies`,
                method: 'GET',
                headers: { 'X-WP-Nonce': this.options.nonce },
                data: requestData,
                success: (response) => {
                    this.renderDependencyGraph(response || {});
                },
                error: (xhr) => {
                    let msg = 'Could not load dependency graph.';
                    if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    $summary.html(`<span class="is-error">${this._escapeHtml(msg)}</span>`);
                    $list.html('');
                }
            });
        }

        /**
         * Load effective rule preview (base + relation merged + conflict sources).
         */
        loadEffectiveConfigPreview() {
            const $summary = $('#wptsall-fc-effective-summary');
            const $json = $('#wptsall-fc-effective-json');
            if (!$summary.length || !$json.length) {
                return;
            }

            const ruleId = Number(this.options.ruleId || 0);
            if (!ruleId) {
                $summary.html('<span class="is-muted">Save rule first to preview effective config.</span>');
                $json.text('');
                return;
            }

            const relationId = Number(
                $('#wptsall-fc-effective-relation-id').val() ||
                this.options.relationId ||
                0
            );

            const requestData = {};
            if (relationId > 0) {
                requestData.relation_id = relationId;
            }

            $summary.html('<span class="is-loading">Loading effective config...</span>');
            $json.text('');

            $.ajax({
                url: `${this.options.restUrl}rules/${ruleId}/effective-config`,
                method: 'GET',
                headers: { 'X-WP-Nonce': this.options.nonce },
                data: requestData,
                success: (response) => {
                    this.renderEffectiveConfigPreview(response || {});
                },
                error: (xhr) => {
                    let msg = 'Could not load effective config.';
                    if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    $summary.html(`<span class="is-error">${this._escapeHtml(msg)}</span>`);
                    $json.text('');
                }
            });
        }

        /**
         * Render effective rule preview panel.
         *
         * @param {Object} response API response.
         */
        renderEffectiveConfigPreview(response) {
            const $summary = $('#wptsall-fc-effective-summary');
            const $json = $('#wptsall-fc-effective-json');

            const warnings = Array.isArray(response.warnings) ? response.warnings : [];
            const diffs = Array.isArray(response.field_differences) ? response.field_differences : [];
            const hasRelation = !!(response.relation_id || 0);
            const overrideSource = response.override_source || {};
            const relationLabel = hasRelation ? `relation ${response.relation_id}` : 'base only';
            const overrideCount = Number(overrideSource.field_overrides_count || 0);

            $summary.html(
                `<span class="wptsall-fc-dep-pill is-total">${this._escapeHtml(relationLabel)}</span>` +
                `<span class="wptsall-fc-dep-pill ${warnings.length ? 'is-unresolved' : 'is-resolved'}">warnings ${warnings.length}</span>` +
                `<span class="wptsall-fc-dep-pill ${diffs.length ? 'is-external' : 'is-resolved'}">field diffs ${diffs.length}</span>` +
                `<span class="wptsall-fc-dep-pill is-total">overrides ${overrideCount}</span>`
            );

            const preview = {
                rule_binding: response.rule_binding || {},
                relation_id: response.relation_id || null,
                warnings,
                field_differences: diffs,
                base: response.base || {},
                effective: response.effective || {},
                override_source: overrideSource
            };

            $json.text(JSON.stringify(preview, null, 2));
        }

        /**
         * Render dependency graph summary + list.
         *
         * @param {Object} response
         */
        renderDependencyGraph(response) {
            const $summary = $('#wptsall-fc-dependency-summary');
            const $list = $('#wptsall-fc-dependency-list');
            const summary = response && response.summary ? response.summary : {};
            const deps = Array.isArray(response && response.dependencies) ? response.dependencies : [];

            const total = Number(summary.total || deps.length || 0);
            const resolved = Number(summary.resolved || 0);
            const unresolved = Number(summary.unresolved || 0);
            const external = Number(summary.external || 0);
            const invalid = Number(summary.invalid || 0);

            $summary.html(
                `<span class="wptsall-fc-dep-pill is-total">total ${total}</span>` +
                `<span class="wptsall-fc-dep-pill is-resolved">resolved ${resolved}</span>` +
                `<span class="wptsall-fc-dep-pill is-unresolved">unresolved ${unresolved}</span>` +
                `<span class="wptsall-fc-dep-pill is-external">external ${external}</span>` +
                `<span class="wptsall-fc-dep-pill is-invalid">invalid ${invalid}</span>`
            );

            if (!deps.length) {
                $list.html('<p class="wptsall-fc-dep-empty">No dependency fields for this object.</p>');
                return;
            }

            const rows = deps.map((dep) => {
                const status = String(dep.status || 'unknown').trim().toLowerCase();
                const fieldKey = this._escapeHtml(dep.source_field_key || '');
                const refType = this._escapeHtml(dep.reference_type || '');
                const refTarget = this._escapeHtml(dep.reference_target || '');
                const targetObjectType = this._escapeHtml(dep.target_object_type || '');
                const targetObjectName = this._escapeHtml(dep.target_object_name || '');
                const targetLabel = targetObjectName
                    ? `${targetObjectType}:${targetObjectName}`
                    : (refTarget || '?');
                const statusClass = `is-${status}`;
                const note = this._escapeHtml(dep.resolution_note || '');
                let actionHtml = '';
                if (dep.target_rule_edit_url) {
                    actionHtml = `<a href="${this._escapeHtml(dep.target_rule_edit_url)}" class="button-link">Open target rule</a>`;
                }

                return `
                    <div class="wptsall-fc-dep-item">
                        <div class="wptsall-fc-dep-main">
                            <strong>${fieldKey}</strong>
                            <span class="wptsall-fc-dep-arrow">→</span>
                            <span class="wptsall-fc-dep-target">${targetLabel}</span>
                            <span class="wptsall-fc-dep-status ${statusClass}">${status}</span>
                        </div>
                        <div class="wptsall-fc-dep-meta">
                            <span>${refType || 'unknown'} / ${refTarget || '-'}</span>
                            ${note ? `<span class="wptsall-fc-dep-note">${note}</span>` : ''}
                            ${actionHtml}
                        </div>
                    </div>
                `;
            });

            $list.html(rows.join(''));
        }

        /**
         * Escape HTML for safe inline rendering.
         *
         * @param {*} value
         * @returns {string}
         * @private
         */
        _escapeHtml(value) {
            const str = String(value ?? '');
            return str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        /**
         * Convert /fields response buckets to sidebar field list.
         *
         * @param {Object} response API response with main/meta maps.
         * @return {Array<Object>}
         */
        mapFieldBucketsToList(response) {
            const mapped = [];
            if (!response || typeof response !== 'object') {
                return mapped;
            }

            const pushBucket = (bucketName, fallbackType) => {
                const bucket = response[bucketName];
                if (!bucket || typeof bucket !== 'object') {
                    return;
                }
                Object.keys(bucket).forEach((fieldName) => {
                    const info = bucket[fieldName] || {};
                    mapped.push({
                        name: fieldName,
                        type: fallbackType,
                        source: info.source || 'template',
                        translatable: !!info.translatable,
                        description: info.label ? String(info.label) : ''
                    });
                });
            };

            pushBucket('main', 'core');
            pushBucket('meta', 'meta');
            return mapped;
        }

        /**
         * Merge manual fields into detected fields
         */
        mergeManualFields(detectedFields) {
            const merged = [...detectedFields];
            this.options.manualFields.forEach(mf => {
                const fieldName = mf.field_name || mf.name;
                const tableName = mf.table_name || 'manual';
                const idMap = mf.associated_id_map || 'post_id';

                if (fieldName && !merged.find(f => f.name === fieldName)) {
                    merged.push({
                        name: fieldName,
                        type: 'manual',
                        source: tableName,
                        description: `${mf.description || 'Manually defined field'} (Table: ${tableName}, Link: ${idMap})`
                    });
                }
            });
            return merged;
        }

        /**
         * Render already-loaded fields to the UI
         */
        renderLoadedFields() {
            // Render translate fields
            this.fieldConfig.translate.forEach(field => {
                const fieldName = typeof field === 'string' ? field : field.name;
                const config = typeof field === 'object' ? field : { name: fieldName, source: 'core' };
                this.renderFieldInCategory('translate', fieldName, config);
            });

            // Render sync_direct fields
            this.fieldConfig.sync_direct.forEach(field => {
                const fieldName = typeof field === 'string' ? field : field.name;
                const config = typeof field === 'object' ? field : { name: fieldName, source: 'core' };
                this.renderFieldInCategory('sync_direct', fieldName, config);
            });

            // Render copy_once fields (WPML Copy once)
            this.fieldConfig.copy_once.forEach(field => {
                const fieldName = typeof field === 'string' ? field : field.name;
                const config = typeof field === 'object' ? field : { name: fieldName, source: 'core' };
                this.renderFieldInCategory('copy_once', fieldName, config);
            });

            // Render sync_mapped fields
            this.fieldConfig.sync_mapped.forEach(field => {
                const fieldName = typeof field === 'string' ? field : field.name;
                const config = typeof field === 'object' ? field : { name: fieldName };
                this.renderFieldInCategory('sync_mapped', fieldName, config);
            });

            // Render compute fields
            this.fieldConfig.compute.forEach(field => {
                const fieldName = typeof field === 'string' ? field : field.name;
                const config = typeof field === 'object' ? field : { name: fieldName };
                this.renderFieldInCategory('compute', fieldName, config);
            });

            // Update counts
            this.updateCounts();
        }

        /**
         * Load existing configuration from PHP-rendered textarea
         * This must be called BEFORE render() since render() will replace the container
         */
        loadExistingConfiguration() {
            // Check if PHP has pre-filled configuration in the textarea
            const existingTextarea = $('#wptsall-fc-json-output');
            if (existingTextarea.length && existingTextarea.val()) {
                try {
                    const config = JSON.parse(existingTextarea.val());
                    this.parseExistingConfig(config);
                } catch (e) {
                    console.warn('Failed to parse existing field configuration:', e);
                }
            }
        }

        /**
         * Parse existing configuration into fieldConfig
         * Handles v0.8.0 field_capabilities format and legacy formats
         */
        parseExistingConfig(config) {
            const coreFields = ['post_title', 'post_content', 'post_excerpt', 'post_name'];

            // v0.8.0 format: field_capabilities object
            if (config.field_capabilities && typeof config.field_capabilities === 'object') {
                for (const [fieldName, fieldConfig] of Object.entries(config.field_capabilities)) {
                    const type = fieldConfig.type || 'sync';
                    const source = coreFields.includes(fieldName) ? 'core' : 'meta';

                    switch (type) {
                        case 'translate':
                            {
                                const entry = { name: fieldName, source: source };
                                // G4: Restore content_format if previously saved.
                                if (fieldConfig.content_format) {
                                    entry.content_format = fieldConfig.content_format;
                                    entry._content_format_user_set = true;
                                }
                                this.fieldConfig.translate.push(entry);
                            }
                            break;
                        case 'sync':
                            {
                                const entry = { name: fieldName, source: source };
                                // v3: Restore value_format if saved.
                                if (fieldConfig.value_format) {
                                    entry.value_format = fieldConfig.value_format;
                                }
                                this.fieldConfig.sync_direct.push(entry);
                            }
                            break;
                        case 'copy_once':
                        case 'copy-once':
                            {
                                const entry = { name: fieldName, source: source };
                                this.fieldConfig.copy_once.push(entry);
                            }
                            break;
                        case 'mapping':
                        case 'id_mapping':
                            {
                                const entry = { name: fieldName, type: 'id' };
                                // v3: Restore reference info if saved.
                                if (fieldConfig.reference_type) {
                                    entry.reference_type = fieldConfig.reference_type;
                                }
                                if (fieldConfig.reference_target) {
                                    entry.reference_target = fieldConfig.reference_target;
                                }
                                this.fieldConfig.sync_mapped.push(entry);
                            }
                            break;
                        case 'compute':
                            this.fieldConfig.compute.push(fieldName);
                            break;
                    }
                }
                console.log('Loaded field_capabilities configuration:', this.fieldConfig);
                return;
            }

            // Legacy format: translate_fields
            if (config.translate_fields) {
                const translateFields = config.translate_fields;

                if (typeof translateFields === 'object' && !Array.isArray(translateFields)) {
                    if (translateFields.core_fields) {
                        translateFields.core_fields.forEach(field => {
                            const fieldObj = typeof field === 'string'
                                ? { name: field, source: 'core' }
                                : field;
                            this.fieldConfig.translate.push(fieldObj);
                        });
                    }
                    if (translateFields.meta_fields) {
                        translateFields.meta_fields.forEach(field => {
                            const fieldObj = typeof field === 'string'
                                ? { name: field, source: 'meta' }
                                : field;
                            this.fieldConfig.translate.push(fieldObj);
                        });
                    }
                } else if (Array.isArray(translateFields)) {
                    translateFields.forEach(field => {
                        const source = coreFields.includes(field) ? 'core' : 'meta';
                        this.fieldConfig.translate.push({ name: field, source: source });
                    });
                }
            }

            // Legacy format: sync_fields
            if (config.sync_fields) {
                const syncFields = config.sync_fields;

                if (typeof syncFields === 'object' && !Array.isArray(syncFields)) {
                    if (syncFields.direct_sync) {
                        syncFields.direct_sync.forEach(field => {
                            const fieldObj = typeof field === 'string'
                                ? { name: field, source: 'core' }
                                : field;
                            this.fieldConfig.sync_direct.push(fieldObj);
                        });
                    }
                    if (syncFields.mapped_sync) {
                        syncFields.mapped_sync.forEach(field => {
                            this.fieldConfig.sync_mapped.push(field);
                        });
                    }
                } else if (Array.isArray(syncFields)) {
                    syncFields.forEach(field => {
                        this.fieldConfig.sync_direct.push({ name: field, source: 'core' });
                    });
                }
            }

            // Legacy format: field_mappings
            if (config.field_mappings && Array.isArray(config.field_mappings)) {
                this.fieldConfig.sync_mapped = config.field_mappings;
            }

            // Legacy format: compute_fields
            if (config.compute_fields && Array.isArray(config.compute_fields)) {
                this.fieldConfig.compute = config.compute_fields;
            }

            console.log('Loaded existing field configuration:', this.fieldConfig);
        }

        /**
         * Render the configurator UI
         */
        render() {
            const html = `
                <div class="wptsall-field-configurator">
                    <div class="wptsall-fc-header">
                        <h3>Field Configuration</h3>
                        <div class="wptsall-fc-actions">
                            <button type="button" class="button" id="wptsall-fc-detect-fields">
                                🔍 Auto-detect Fields
                            </button>
                            <button type="button" class="button" id="wptsall-fc-import">
                                📥 Import Config
                            </button>
                            <button type="button" class="button" id="wptsall-fc-export">
                                📤 Export Config
                            </button>
                        </div>
                    </div>

                    <div id="wptsall-fc-inline-notice" class="wptsall-fc-inline-notice" style="display:none;"></div>

                    <div class="wptsall-fc-content">
                        <div class="wptsall-fc-sidebar">
                            <div class="wptsall-fc-field-pool">
                                <h4>Available Fields</h4>
                                <div class="wptsall-fc-search">
                                    <input type="text" id="wptsall-fc-search-input" placeholder="Search fields..." />
                                </div>
                                <div id="wptsall-fc-available-fields" class="wptsall-fc-fields-list">
                                    <p class="wptsall-fc-loading">Loading fields...</p>
                                </div>
                                <button type="button" class="button button-small" id="wptsall-fc-add-custom">
                                    + Add Custom Field
                                </button>
                            </div>
                            <div class="wptsall-fc-dependency-panel" id="wptsall-fc-dependency-panel">
                                <h4>Dependency Chain</h4>
                                <div id="wptsall-fc-dependency-summary" class="wptsall-fc-dependency-summary">
                                    <span class="is-loading">Loading dependencies...</span>
                                </div>
                                <div id="wptsall-fc-dependency-list" class="wptsall-fc-dependency-list"></div>
                            </div>
                            <div class="wptsall-fc-effective-panel" id="wptsall-fc-effective-panel">
                                <h4>Effective Rule Preview</h4>
                                <div class="wptsall-fc-effective-controls">
                                    <label for="wptsall-fc-effective-relation-id">Relation ID (optional)</label>
                                    <input type="number" id="wptsall-fc-effective-relation-id" min="0" step="1" value="${Number(this.options.relationId || 0)}" />
                                    <button type="button" class="button button-small" id="wptsall-fc-load-effective">Load</button>
                                </div>
                                <div id="wptsall-fc-effective-summary" class="wptsall-fc-dependency-summary">
                                    <span class="is-loading">Loading effective config...</span>
                                </div>
                                <pre id="wptsall-fc-effective-json" class="wptsall-fc-effective-json"></pre>
                            </div>
                        </div>

                        <div class="wptsall-fc-main">
                            ${Object.keys(this.fieldTypes).map(type => this.renderFieldCategory(type)).join('')}
                        </div>
                    </div>
                </div>
            `;
            // Note: The hidden textarea #wptsall-fc-json-output is rendered by PHP outside this container

            this.container.html(html);
        }

        /**
         * Render a field category container
         */
        renderFieldCategory(type) {
            const config = this.fieldTypes[type];
            return `
                <div class="wptsall-fc-category" data-type="${type}">
                    <div class="wptsall-fc-category-header" style="border-left: 4px solid ${config.color}">
                        <span class="wptsall-fc-category-icon">${config.icon}</span>
                        <h4>${config.label}</h4>
                        <span class="wptsall-fc-count">0</span>
                    </div>
                    ${config.hint ? `<p class="description wptsall-fc-category-hint" style="margin:4px 8px 0;">${config.hint}</p>` : ''}
                    <div class="wptsall-fc-category-body wptsall-fc-droppable" data-category="${type}">
                        <p class="wptsall-fc-empty-message">Drag fields here or click to add</p>
                    </div>
                </div>
            `;
        }

        /**
         * Bind events
         */
        bindEvents() {
            const self = this;

            // Detect fields
            $('#wptsall-fc-detect-fields').on('click', function () {
                self.detectFields();
            });

            // Import/Export
            $('#wptsall-fc-import').on('click', function () {
                self.importConfiguration();
            });

            $('#wptsall-fc-export').on('click', function () {
                self.exportConfiguration();
            });

            // Search
            $('#wptsall-fc-search-input').on('input', function () {
                self.filterAvailableFields($(this).val());
            });

            // Add custom field
            $('#wptsall-fc-add-custom').on('click', function () {
                self.addCustomField();
            });

            $('#rule-data-type').on('change', function () {
                self.syncObjectNameSelect();
                self.options.dataType = $('#rule-data-type').val() || self.options.dataType;
                self.options.objectName = $('#rule-object-name').val() || '';
                self.loadFieldsForModelObject();
                self.loadDependencyGraph();
                self.loadEffectiveConfigPreview();
            });

            // Keep available fields aligned with selected rule target object.
            $('#rule-object-name').on('change', function () {
                self.options.dataType = $('#rule-data-type').val() || self.options.dataType;
                self.options.objectName = $('#rule-object-name').val() || '';
                self.loadFieldsForModelObject();
                self.loadDependencyGraph();
                self.loadEffectiveConfigPreview();
            });

            $('#wptsall-fc-load-effective').on('click', function () {
                self.options.relationId = Number($('#wptsall-fc-effective-relation-id').val() || 0);
                self.loadEffectiveConfigPreview();
            });

            $('#wptsall-fc-effective-relation-id').on('change', function () {
                self.options.relationId = Number($(this).val() || 0);
                self.loadEffectiveConfigPreview();
            });

            // Make categories droppable
            $('.wptsall-fc-droppable').droppable({
                accept: '.wptsall-fc-field-item',
                hoverClass: 'wptsall-fc-drop-hover',
                drop: function (event, ui) {
                    const fieldName = ui.draggable.data('field');
                    const category = $(this).data('category');
                    self.addFieldToCategory(fieldName, category);
                }
            });

            // Form submission - serialize configuration.
            // G5: Intercept the rule edit form to do AJAX save and show validation panel.
            // Namespace + off() first: importConfiguration() re-renders and re-binds,
            // and accumulating submit handlers double-saves the rule (two POSTs).
            $('form#wptsall-rule-edit-form')
                .off('submit.wptsallFcSave')
                .on('submit.wptsallFcSave', function (e) {
                    e.preventDefault();
                    self.serializeConfiguration();
                    self._ajaxSaveRule($(this));
                });

            // For any other forms, just serialize.
            $('form').not('#wptsall-rule-edit-form')
                .off('submit.wptsallFcSerialize')
                .on('submit.wptsallFcSerialize', function () {
                    self.serializeConfiguration();
                });
        }

        normalizeRuleDataType(dataType) {
            const normalized = String(dataType || '').trim();
            if (normalized === 'taxonomy') {
                return 'term';
            }
            return normalized;
        }

        getObjectChoicesForDataType(dataType) {
            const normalized = this.normalizeRuleDataType(dataType);
            const choices = this.options.objectChoices || {};
            return Array.isArray(choices[normalized]) ? choices[normalized] : [];
        }

        syncObjectNameSelect() {
            const $select = $('#rule-object-name');
            if (!$select.length) {
                return;
            }

            const dataType = this.normalizeRuleDataType($('#rule-data-type').val() || this.options.dataType);
            const currentValue = String($select.val() || this.options.objectName || '').trim();
            const choices = this.getObjectChoicesForDataType(dataType);

            let html = `<option value="">${this._escapeHtml(this.options.objectPlaceholder)}</option>`;
            if (choices.length > 0) {
                choices.forEach(choice => {
                    const value = String(choice.value || '').trim();
                    const label = String(choice.label || value).trim();
                    const objectType = String(choice.object_type || '').trim();
                    html += `<option value="${this._escapeHtml(value)}" data-rule-data-type="${this._escapeHtml(dataType)}" data-object-type="${this._escapeHtml(objectType)}">${this._escapeHtml(label)}</option>`;
                });
            } else {
                html += `<option value="" disabled>${this._escapeHtml(this.options.noObjectsLabel)}</option>`;
            }

            $select.html(html);

            if (currentValue && choices.some(choice => String(choice.value || '').trim() === currentValue)) {
                $select.val(currentValue);
            } else {
                $select.val('');
            }

            this.options.dataType = dataType;
            this.options.objectName = $select.val() || '';
        }

        /**
         * Detect available fields from object.
         *
         * G2: First tries /rules/{id}/available-fields (model_object_fields),
         * then falls back to detect-fields for runtime detection.
         */
        detectFields() {
            const self = this;
            const ruleId = this.options.ruleId;

            $('#wptsall-fc-available-fields').html('<p class="wptsall-fc-loading">Detecting fields...</p>');

            if (ruleId) {
                // G2 primary path: load from model_object_fields via rule.
                $.ajax({
                    url: `${this.options.restUrl}rules/${ruleId}/available-fields`,
                    method: 'GET',
                    headers: { 'X-WP-Nonce': this.options.nonce },
                    success: function (response) {
                        const fields = Array.isArray(response) ? response : [];
                        self.templateFields = fields;
                        self.templateFieldNameSet = new Set(
                            fields.map(f => String(f.field_key || '').trim()).filter(Boolean)
                        );

                        const mapped = fields.map(f => ({
                            name: f.field_key,
                            type: f.field_kind || 'unknown',
                            source: f.source || '',
                            dataType: f.data_type || '',
                            status: f.status || '',
                            id: f.id
                        }));

                        const merged = self.templateFieldNameSet.size > 0
                            ? mapped
                            : self.mergeManualFields(mapped);
                        self.renderAvailableFields(merged);
                        self.showInlineNotice(`Detected ${fields.length} template field(s).`, 'success');
                    },
                    error: function () {
                        // Fallback: use legacy detect-fields.
                        self.templateFieldNameSet = new Set();
                        self._detectFieldsLegacy();
                    }
                });
            } else {
                self.loadFieldsForModelObject();
            }
        }

        /**
         * Legacy field detection via POST /models/detect-fields.
         * @private
         */
        _detectFieldsLegacy() {
            const self = this;
            const dataType = this.options.dataType || 'post';
            const objectName = this.options.objectName || 'post';
            this.templateFieldNameSet = new Set();

            $.ajax({
                url: `${this.options.restUrl}models/detect-fields`,
                method: 'POST',
                headers: {
                    'X-WP-Nonce': this.options.nonce
                },
                data: {
                    data_type: dataType,
                    object_name: objectName
                },
                success: function (response) {
                    // The endpoint may return the field list as a JSON object with
                    // numeric keys (PHP mixed-key array) — normalize before use.
                    let fields = response ? response.fields : null;
                    if (fields && !Array.isArray(fields) && typeof fields === 'object') {
                        fields = Object.values(fields);
                    }
                    if (Array.isArray(fields)) {
                        const allFields = self.mergeManualFields(fields);
                        self.renderAvailableFields(allFields);
                        self.showInlineNotice('Using runtime-detected fields (template-constrained list unavailable).', 'warning');
                    } else {
                        $('#wptsall-fc-available-fields').html(
                            '<p class="wptsall-fc-error">No fields were detected for this object.</p>'
                        );
                        self.showInlineNotice('Field detection returned no fields. Verify the data type and object, then retry.', 'warning');
                    }
                },
                error: function () {
                    $('#wptsall-fc-available-fields').html('<p class="wptsall-fc-error">Field detection failed</p>');
                    self.showInlineNotice('Field detection failed. Please verify model/object settings and retry.', 'error');
                }
            });
        }

        /**
         * Build a set of field names that are already configured in any category.
         * @return {Set<string>}
         * @private
         */
        _getConfiguredFieldNames() {
            const names = new Set();
            Object.keys(this.fieldConfig).forEach(cat => {
                this.fieldConfig[cat].forEach(f => {
                    const n = typeof f === 'string' ? f : (f.name || f.field || '');
                    if (n) names.add(n);
                });
            });
            return names;
        }

        /**
         * Determine the category label for a configured field name.
         * @param {string} fieldName
         * @return {string} e.g. 'translate', 'sync_direct', 'sync_mapped', 'compute', or ''
         * @private
         */
        _getFieldCategory(fieldName) {
            for (const cat of Object.keys(this.fieldConfig)) {
                const found = this.fieldConfig[cat].find(f => {
                    const n = typeof f === 'string' ? f : (f.name || f.field || '');
                    return n === fieldName;
                });
                if (found) return cat;
            }
            return '';
        }

        /**
         * Render available fields in sidebar.
         *
         * G2: Shows configured status badges (translate/sync/id_mapping)
         * and highlights unconfigured fields with "Needs classification".
         */
        renderAvailableFields(fields) {
            const self = this;
            const configured = this._getConfiguredFieldNames();
            // Defensive: never render a non-array payload (mixed-key JSON objects).
            const list = Array.isArray(fields) ? fields : [];

            const html = list.map(field => {
                const fieldName = field.name;
                const isConfigured = configured.has(fieldName);
                const category = isConfigured ? this._getFieldCategory(fieldName) : '';
                const categoryLabel = category ? (this.fieldTypes[category]
                    ? this.fieldTypes[category].label
                    : category) : '';

                let badge = '';
                let extraClass = '';

                if (isConfigured && categoryLabel) {
                    const color = this.fieldTypes[category] ? this.fieldTypes[category].color : '#50575e';
                    badge = `<span class="wptsall-fc-field-badge" style="background:${color};color:#fff;">${categoryLabel}</span>`;
                    extraClass = ' wptsall-fc-field-configured';
                } else {
                    badge = '<span class="wptsall-fc-field-badge wptsall-fc-field-unclassified">Needs classification</span>';
                    extraClass = ' wptsall-fc-field-unconfigured';
                }

                const typeLabel = field.type || 'unknown';
                const sourceLabel = field.source
                    ? `<span class="wptsall-fc-field-source">${field.source}</span>`
                    : '';
                const descHtml = field.description
                    ? `<span class="wptsall-fc-field-desc">${field.description}</span>`
                    : '';

                return `
                    <div class="wptsall-fc-field-item${extraClass}" data-field="${fieldName}" draggable="true">
                        <span class="wptsall-fc-field-name">${fieldName}</span>
                        <span class="wptsall-fc-field-type">${typeLabel}</span>
                        ${sourceLabel}
                        ${badge}
                        ${descHtml}
                    </div>
                `;
            }).join('');

            $('#wptsall-fc-available-fields').html(html);

            // Make fields draggable
            $('.wptsall-fc-field-item').draggable({
                helper: 'clone',
                revert: 'invalid',
                cursor: 'move'
            });

            // Click to add field
            $('.wptsall-fc-field-item').on('click', function () {
                const fieldName = $(this).data('field');
                // Show category selection dialog
                // For simplicity, add to translate by default
                self.addFieldToCategory(fieldName, 'translate');
            });
        }

        /**
         * Filter available fields
         */
        filterAvailableFields(query) {
            const lowerQuery = query.toLowerCase();
            $('.wptsall-fc-field-item').each(function () {
                const fieldName = $(this).data('field').toLowerCase();
                if (fieldName.includes(lowerQuery)) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        }

        /**
         * Add field to category
         */
        addFieldToCategory(fieldName, category, config = {}) {
            if (this.templateFieldNameSet && this.templateFieldNameSet.size > 0 && !this.templateFieldNameSet.has(fieldName)) {
                this.showInlineNotice(
                    `Field "${fieldName}" is not declared in the plugin template. Add it in template fields first, then configure this rule.`,
                    'error'
                );
                return;
            }

            const existingCategory = this._getFieldCategory(fieldName);
            if (existingCategory) {
                if (existingCategory === category) {
                    alert('This field already exists in this category');
                } else {
                    this.showInlineNotice(
                        `Field "${fieldName}" is already configured as ${this.fieldTypes[existingCategory]?.label || existingCategory}. Remove it first before changing category.`,
                        'warning'
                    );
                }
                return;
            }

            this.clearInlineNotice();

            // Default config based on category
            const defaultConfig = this.getDefaultFieldConfig(category);
            const fieldConfig = $.extend({}, defaultConfig, config, { field: fieldName, name: fieldName });

            // Add to data model
            this.fieldConfig[category].push(fieldConfig);

            // Render field in category
            this.renderFieldInCategory(category, fieldName, fieldConfig);

            // Update count
            this.updateCategoryCount(category);

            // Remove empty message
            $(`.wptsall-fc-droppable[data-category="${category}"] .wptsall-fc-empty-message`).remove();

            // G2: Update the sidebar badge for this field.
            this._refreshFieldBadge(fieldName);
        }

        /**
         * Get default field configuration based on category
         */
        getDefaultFieldConfig(category) {
            switch (category) {
                case 'translate':
                    return {
                        type: 'text',
                        source: 'post',
                        required: false
                    };
                case 'sync_direct':
                    return {
                        source: 'post'
                    };
                case 'copy_once':
                    return {
                        source: 'post'
                    };
                case 'sync_mapped':
                    return {
                        mapping_type: 'term_mapping',
                        source: 'taxonomy',
                        create_if_missing: false,
                        auto_translate: false
                    };
                case 'compute':
                    return {
                        method: 'slug',
                        source_field: ''
                    };
                default:
                    return {};
            }
        }

        /**
         * Render field in category
         */
        renderFieldInCategory(category, fieldName, config) {
            // G4: Build content_format dropdown HTML for translate fields.
            let contentFormatHtml = '';
            if (category === 'translate') {
                contentFormatHtml = this._renderContentFormatSelector(fieldName, config);
            }

            // v3: Build reference info badge for id_mapping (sync_mapped) fields.
            let refInfoHtml = '';
            if (category === 'sync_mapped' && config.reference_type) {
                const target = config.reference_target || '?';
                refInfoHtml = `<span class="wptsall-fc-ref-badge" title="Reference: ${config.reference_type} → ${target}">` +
                    `${config.reference_type} → ${target}</span>`;
            }

            // v3: Build value_format badge for sync fields.
            let valueFormatHtml = '';
            if (category === 'sync_direct' && config.value_format) {
                valueFormatHtml = `<span class="wptsall-fc-value-format-badge" title="Value format: ${config.value_format}">${config.value_format}</span>`;
            }

            const html = `
                <div class="wptsall-fc-configured-field" data-field="${fieldName}">
                    <div class="wptsall-fc-field-header">
                        <span class="wptsall-fc-drag-handle">⋮⋮</span>
                        <strong>${fieldName}</strong>
                        ${contentFormatHtml}
                        ${refInfoHtml}
                        ${valueFormatHtml}
                        <button type="button" class="wptsall-fc-config-btn" title="Configure">⚙️</button>
                        <button type="button" class="wptsall-fc-remove-btn" title="Delete">×</button>
                    </div>
                    <div class="wptsall-fc-field-config" style="display:none;">
                        ${this.renderFieldConfig(category, config)}
                    </div>
                </div>
            `;

            $(`.wptsall-fc-droppable[data-category="${category}"]`).append(html);

            // Bind actions
            this.bindFieldActions(category, fieldName);
        }

        /**
         * Render field configuration form
         */
        renderFieldConfig(category, config) {
            switch (category) {
                case 'translate':
                    return `
                        <label>Type:
                            <select class="wptsall-fc-input" data-key="type">
                                <option value="text" ${config.type === 'text' ? 'selected' : ''}>Plain Text</option>
                                <option value="html" ${config.type === 'html' ? 'selected' : ''}>HTML</option>
                                <option value="preserve_html" ${config.type === 'preserve_html' ? 'selected' : ''}>Preserve HTML Tags</option>
                            </select>
                        </label>
                        <label>Source:
                            <select class="wptsall-fc-input" data-key="source">
                                <option value="post" ${config.source === 'post' ? 'selected' : ''}>Post Field</option>
                                <option value="meta" ${config.source === 'meta' ? 'selected' : ''}>Meta Field</option>
                            </select>
                        </label>
                        <label>
                            <input type="checkbox" class="wptsall-fc-input" data-key="required" ${config.required ? 'checked' : ''}>
                            Required
                        </label>
                    `;
                case 'sync_mapped':
                    return `
                        <label>Mapping Type:
                            <select class="wptsall-fc-input" data-key="mapping_type">
                                <option value="term_mapping" ${config.mapping_type === 'term_mapping' ? 'selected' : ''}>Term/Tag</option>
                                <option value="media_mapping" ${config.mapping_type === 'media_mapping' ? 'selected' : ''}>Media File</option>
                                <option value="post_mapping" ${config.mapping_type === 'post_mapping' ? 'selected' : ''}>Post Reference</option>
                            </select>
                        </label>
                        <label>
                            <input type="checkbox" class="wptsall-fc-input" data-key="create_if_missing" ${config.create_if_missing ? 'checked' : ''}>
                            Auto-create Missing Items
                        </label>
                        <label>
                            <input type="checkbox" class="wptsall-fc-input" data-key="auto_translate" ${config.auto_translate ? 'checked' : ''}>
                            Auto-translate
                        </label>
                    `;
                case 'compute':
                    return `
                        <label>Compute Method:
                            <select class="wptsall-fc-input" data-key="method">
                                <option value="slug" ${config.method === 'slug' ? 'selected' : ''}>Generate Slug from Title</option>
                                <option value="copy_from" ${config.method === 'copy_from' ? 'selected' : ''}>Copy from Another Field</option>
                            </select>
                        </label>
                    `;
                default:
                    return '<p>No configuration needed</p>';
            }
        }

        /**
         * G4: Render inline content_format selector for translate fields.
         *
         * Shows a <select> dropdown with content format options.
         * If the current value matches the auto-detected value from the classifier,
         * an "auto" badge is shown. It disappears when the user manually changes it.
         *
         * @param {string} fieldName
         * @param {Object} config  Field configuration object.
         * @return {string} HTML string.
         * @private
         */
        _renderContentFormatSelector(fieldName, config) {
            const formats = [
                { value: 'plain_text', label: 'Plain Text' },
                { value: 'rich_html', label: 'Rich HTML' },
                { value: 'serialized_php', label: 'Serialized PHP' },
                { value: 'json_structured', label: 'JSON Structured' },
                { value: 'media_ref', label: 'Media Reference' },
                { value: 'slug', label: 'Slug' },
                { value: 'code', label: 'Code Snippet' }
            ];

            // Determine current value: check config.content_format, fallback to 'plain_text'.
            const currentVal = config.content_format || 'plain_text';

            // Check if this is the auto-detected value (not yet user-overridden).
            const isAuto = !config._content_format_user_set;

            const options = formats.map(f =>
                `<option value="${f.value}" ${f.value === currentVal ? 'selected' : ''}>${f.label}</option>`
            ).join('');

            const autoBadge = isAuto
                ? '<span class="wptsall-fc-auto-badge" title="Auto-detected by classifier">auto</span>'
                : '';

            return `<span class="wptsall-fc-content-format-wrap">
                <select class="wptsall-fc-content-format" data-field="${fieldName}" title="Content Format">
                    ${options}
                </select>
                ${autoBadge}
            </span>`;
        }

        /**
         * Bind field actions (config, remove)
         */
        bindFieldActions(category, fieldName) {
            const self = this;
            const fieldElement = $(`.wptsall-fc-droppable[data-category="${category}"] [data-field="${fieldName}"]`);

            // Config button
            fieldElement.find('.wptsall-fc-config-btn').on('click', function () {
                fieldElement.find('.wptsall-fc-field-config').slideToggle();
            });

            // Remove button — with deletion impact check for id_mapping fields.
            fieldElement.find('.wptsall-fc-remove-btn').on('click', function () {
                const ruleId = self.options.ruleId;
                // Check for relationship impact before deleting.
                if (ruleId && (category === 'sync_mapped' || category === 'id_mapping')) {
                    $.ajax({
                        url: `${self.options.restUrl}rules/${ruleId}/field-deletion-impact?field_key=${encodeURIComponent(fieldName)}`,
                        method: 'GET',
                        headers: { 'X-WP-Nonce': self.options.nonce },
                        success: function (result) {
                            let msg = 'Are you sure you want to delete this field?';
                            if (result.warnings && result.warnings.length > 0) {
                                msg += '\n\nWarning:\n' + result.warnings.join('\n');
                            }
                            if (confirm(msg)) {
                                self.removeFieldFromCategory(fieldName, category);
                            }
                        },
                        error: function () {
                            if (confirm('Are you sure you want to delete this field?')) {
                                self.removeFieldFromCategory(fieldName, category);
                            }
                        }
                    });
                } else {
                    if (confirm('Are you sure you want to delete this field?')) {
                        self.removeFieldFromCategory(fieldName, category);
                    }
                }
            });

            // Save config on change
            fieldElement.find('.wptsall-fc-input').on('change', function () {
                const key = $(this).data('key');
                const value = $(this).is(':checkbox') ? $(this).is(':checked') : $(this).val();
                self.updateFieldConfig(category, fieldName, key, value);
            });

            // G4: content_format change (translate fields only).
            fieldElement.find('.wptsall-fc-content-format').on('change', function () {
                const val = $(this).val();
                self.updateFieldConfig(category, fieldName, 'content_format', val);
                // Mark as user-set so it's no longer "auto".
                self.updateFieldConfig(category, fieldName, '_content_format_user_set', true);
                // Remove the auto badge.
                $(this).siblings('.wptsall-fc-auto-badge').remove();
            });
        }

        /**
         * Remove field from category
         */
        removeFieldFromCategory(fieldName, category) {
            // Remove from data model
            this.fieldConfig[category] = this.fieldConfig[category].filter((f) => {
                const name = typeof f === 'string' ? f : (f.field || f.name || '');
                return name !== fieldName;
            });

            // Remove from DOM
            $(`.wptsall-fc-droppable[data-category="${category}"] [data-field="${fieldName}"]`).remove();

            // Update count
            this.updateCategoryCount(category);

            // Show empty message if no fields
            if (this.fieldConfig[category].length === 0) {
                $(`.wptsall-fc-droppable[data-category="${category}"]`).html('<p class="wptsall-fc-empty-message">Drag fields here or click to add</p>');
            }

            // G2: Update sidebar badge back to "Needs classification".
            this._refreshFieldBadge(fieldName);
        }

        /**
         * G2: Refresh a single field's badge in the sidebar after add/remove.
         * @param {string} fieldName
         * @private
         */
        _refreshFieldBadge(fieldName) {
            const $item = $(`.wptsall-fc-field-item[data-field="${fieldName}"]`);
            if (!$item.length) return;

            const category = this._getFieldCategory(fieldName);
            const $badge = $item.find('.wptsall-fc-field-badge');

            if (category && this.fieldTypes[category]) {
                const ft = this.fieldTypes[category];
                $badge.attr('style', `background:${ft.color};color:#fff;`)
                    .removeClass('wptsall-fc-field-unclassified')
                    .text(ft.label);
                $item.removeClass('wptsall-fc-field-unconfigured').addClass('wptsall-fc-field-configured');
            } else {
                $badge.attr('style', '')
                    .addClass('wptsall-fc-field-unclassified')
                    .text('Needs classification');
                $item.removeClass('wptsall-fc-field-configured').addClass('wptsall-fc-field-unconfigured');
            }
        }

        /**
         * Show inline notice in configurator panel.
         *
         * @param {string} message
         * @param {'info'|'warning'|'error'|'success'} type
         */
        showInlineNotice(message, type = 'info') {
            const $notice = $('#wptsall-fc-inline-notice');
            if (!$notice.length) {
                return;
            }
            const safeType = ['info', 'warning', 'error', 'success'].includes(type) ? type : 'info';
            $notice
                .removeClass('is-info is-warning is-error is-success')
                .addClass(`is-${safeType}`)
                .text(message || '')
                .show();
        }

        /**
         * Clear inline notice.
         */
        clearInlineNotice() {
            const $notice = $('#wptsall-fc-inline-notice');
            if ($notice.length) {
                $notice.hide().text('').removeClass('is-info is-warning is-error is-success');
            }
        }

        /**
         * Return field keys configured in capabilities but absent in template field set.
         *
         * @param {Object} fieldCapabilities
         * @return {Array<string>}
         */
        collectConfiguredFieldsOutsideTemplate(fieldCapabilities) {
            if (!this.templateFieldNameSet || this.templateFieldNameSet.size === 0) {
                return [];
            }
            if (!fieldCapabilities || typeof fieldCapabilities !== 'object') {
                return [];
            }
            return Object.keys(fieldCapabilities).filter((fieldName) => {
                const n = String(fieldName || '').trim();
                return n && !this.templateFieldNameSet.has(n);
            });
        }

        /**
         * Update field configuration
         */
        updateFieldConfig(category, fieldName, key, value) {
            const field = this.fieldConfig[category].find((f) => {
                const name = typeof f === 'string' ? f : (f.field || f.name || '');
                return name === fieldName;
            });
            if (field) {
                field[key] = value;
            }
        }

        /**
         * Update category count badge
         */
        updateCategoryCount(category) {
            const count = this.fieldConfig[category].length;
            $(`.wptsall-fc-category[data-type="${category}"] .wptsall-fc-count`).text(count);
        }

        /**
         * Update all category counts
         */
        updateCounts() {
            Object.keys(this.fieldConfig).forEach(category => {
                this.updateCategoryCount(category);
            });
        }

        /**
         * Add custom field
         */
        addCustomField() {
            if (this.templateFieldNameSet && this.templateFieldNameSet.size > 0) {
                this.showInlineNotice(
                    'Custom field entry is disabled in template-constrained mode. Please add the field in the plugin template first.',
                    'warning'
                );
                return;
            }
            const fieldName = prompt('Enter field name (e.g. custom_field):');
            if (fieldName) {
                // Show in available fields
                const html = `
                    <div class="wptsall-fc-field-item wptsall-fc-field-unconfigured" data-field="${fieldName}" draggable="true">
                        <span class="wptsall-fc-field-name">${fieldName}</span>
                        <span class="wptsall-fc-field-type">custom</span>
                        <span class="wptsall-fc-field-badge wptsall-fc-field-unclassified">Needs classification</span>
                    </div>
                `;
                $('#wptsall-fc-available-fields').prepend(html);

                // Make draggable
                $(`.wptsall-fc-field-item[data-field="${fieldName}"]`).draggable({
                    helper: 'clone',
                    revert: 'invalid',
                    cursor: 'move'
                });
            }
        }

        /**
         * Load configuration from server
         */
        loadConfiguration() {
            // Configuration is loaded via loadRuleAvailableFields (G2) and loadExistingConfiguration.
        }

        /**
         * Serialize configuration to JSON (v0.8.0 field_capabilities format)
         */
        serializeConfiguration() {
            const fieldCapabilities = {};

            // Translate fields (G4: include content_format when set)
            this.fieldConfig.translate.forEach(field => {
                const fieldName = typeof field === 'string' ? field : field.name;
                const cap = {
                    type: 'translate',
                    direction: 'one_way',
                    enabled: true
                };
                // G4: Persist content_format if explicitly set.
                if (typeof field === 'object' && field.content_format) {
                    cap.content_format = field.content_format;
                }
                fieldCapabilities[fieldName] = cap;
            });

            // Sync fields (v3: include value_format when set) — WPML "Copy"
            this.fieldConfig.sync_direct.forEach(field => {
                const fieldName = typeof field === 'string' ? field : field.name;
                const cap = {
                    type: 'sync',
                    direction: 'one_way',
                    enabled: true
                };
                if (typeof field === 'object' && field.value_format) {
                    cap.value_format = field.value_format;
                }
                fieldCapabilities[fieldName] = cap;
            });

            // Copy once (WPML Copy once)
            this.fieldConfig.copy_once.forEach(field => {
                const fieldName = typeof field === 'string' ? field : field.name;
                fieldCapabilities[fieldName] = {
                    type: 'copy_once',
                    direction: 'one_way',
                    enabled: true
                };
            });

            // ID mapping fields (v3: include reference_type and reference_target)
            this.fieldConfig.sync_mapped.forEach(field => {
                const fieldName = typeof field === 'string' ? field : field.name;
                const cap = {
                    type: 'id_mapping',
                    direction: 'one_way',
                    enabled: true
                };
                if (typeof field === 'object' && field.reference_type) {
                    cap.reference_type = field.reference_type;
                }
                if (typeof field === 'object' && field.reference_target) {
                    cap.reference_target = field.reference_target;
                }
                fieldCapabilities[fieldName] = cap;
            });

            // Compute fields
            this.fieldConfig.compute.forEach(field => {
                const fieldName = typeof field === 'string' ? field : field.name;
                fieldCapabilities[fieldName] = {
                    type: 'compute',
                    enabled: true
                };
            });

            const output = {
                field_capabilities: fieldCapabilities
            };

            // Write to hidden textarea
            $('#wptsall-fc-json-output').val(JSON.stringify(output));
        }

        /**
         * Import configuration from JSON
         */
        importConfiguration() {
            const json = prompt('Paste JSON configuration:');
            if (json) {
                try {
                    const config = JSON.parse(json);
                    // Clear current configuration
                    Object.keys(this.fieldConfig).forEach(category => {
                        this.fieldConfig[category] = [];
                    });

                    // Load new configuration
                    this.parseExistingConfig(config);

                    this.render();
                    this.bindEvents();
                    this.renderLoadedFields();

                    // Reload sidebar
                    this.loadRuleAvailableFields();

                    alert('Configuration imported successfully');
                } catch (e) {
                    alert('Invalid JSON format');
                }
            }
        }

        /**
         * Export configuration to JSON
         */
        exportConfiguration() {
            this.serializeConfiguration();
            const json = $('#wptsall-fc-json-output').val();

            // Copy to clipboard or download
            const blob = new Blob([json], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `field-config-${this.options.objectName}.json`;
            a.click();
        }

        /**
         * G5: AJAX-save the rule edit form and display validation results.
         *
         * Collects form data, POSTs to the REST API endpoint for creating
         * or updating a rule, then renders validation feedback.
         *
         * @param {jQuery} $form The form element.
         * @private
         */
        _ajaxSaveRule($form) {
            const self = this;
            const $submitBtn = $form.find('[type="submit"]');
            const originalText = $submitBtn.text();
            $submitBtn.prop('disabled', true).text('Saving...');

            const modelId = $form.find('input[name="model_id"]').val();
            const ruleId = $form.find('input[name="rule_id"]').val();
            const isNew = !ruleId;

            // Build the field_configuration from the hidden textarea.
            let fieldCapabilities = {};
            try {
                const fc = JSON.parse($('#wptsall-fc-json-output').val() || '{}');
                fieldCapabilities = fc.field_capabilities || {};
            } catch (e) {
                // ignore parse errors
            }

            const data = {
                name: $form.find('[name="name"]').val() || '',
                url_pattern: $form.find('[name="url_pattern"]').val() || '',
                url_type: $form.find('[name="url_type"]').val() || 'single',
                example_url: $form.find('[name="example_url"]').val() || '',
                data_type: $form.find('[name="data_type"]').val() || 'post',
                object_name: $form.find('[name="object_name"]').val() || '',
                field_capabilities: fieldCapabilities,
                backend_edit: $form.find('[name="backend_edit"]').val() || '',
                backend_list: $form.find('[name="backend_list"]').val() || '',
                priority: parseInt($form.find('[name="priority"]').val(), 10) || 10,
                is_active: $form.find('[name="is_active"]').is(':checked') ? 1 : 0,
                note: $form.find('[name="note"]').val() || ''
            };

            const outsideTemplate = self.collectConfiguredFieldsOutsideTemplate(fieldCapabilities);
            if (outsideTemplate.length > 0) {
                $submitBtn.prop('disabled', false).text(originalText);
                self.showInlineNotice(
                    `Cannot save: ${outsideTemplate.length} field(s) are not in plugin template: ${outsideTemplate.join(', ')}`,
                    'error'
                );
                return;
            }
            self.clearInlineNotice();

            const restPath = isNew ? `models/${modelId}/rules` : `rules/${ruleId}`;
            const method = isNew ? 'POST' : 'PUT';

            $.ajax({
                url: self.options.restUrl + restPath,
                method: method,
                headers: { 'X-WP-Nonce': self.options.nonce },
                contentType: 'application/json',
                data: JSON.stringify(data),
                success: function (response) {
                    $submitBtn.prop('disabled', false).text(originalText);

                    // G5: Show validation panel if response includes validation data.
                    if (response && response.validation && typeof window.ValidationPanel !== 'undefined') {
                        window.ValidationPanel.render(response.validation);
                    } else if (typeof window.ValidationPanel !== 'undefined') {
                        // No validation data means success with no issues.
                        window.ValidationPanel.render({ errors: [], warnings: [] });
                    }

                    // Show success notice.
                    const $notice = $('<div class="notice notice-success is-dismissible"><p>Rule saved successfully.</p></div>');
                    $form.before($notice);
                    setTimeout(function () { $notice.fadeOut(); }, 4000);

                    // If this was a new rule, update the hidden rule_id so subsequent saves do PUT.
                    if (isNew && response && response.id) {
                        $form.find('input[name="rule_id"]').val(response.id);
                        self.options.ruleId = response.id;
                        self.loadRuleAvailableFields();
                    }
                    self.options.dataType = data.data_type;
                    self.options.objectName = data.object_name;
                    self.loadDependencyGraph();
                },
                error: function (xhr) {
                    $submitBtn.prop('disabled', false).text(originalText);

                    let errorMsg = 'Save failed';
                    if (xhr.responseJSON) {
                        if (xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        // G5: Some error responses may also include validation data.
                        if (xhr.responseJSON.validation && typeof window.ValidationPanel !== 'undefined') {
                            window.ValidationPanel.render(xhr.responseJSON.validation);
                        }
                        const removed = xhr.responseJSON.data && Array.isArray(xhr.responseJSON.data.removed_fields)
                            ? xhr.responseJSON.data.removed_fields
                            : [];
                        if (removed.length > 0) {
                            errorMsg += ` (Fields not in template: ${removed.join(', ')})`;
                        }
                    }

                    const $notice = $('<div class="notice notice-error is-dismissible"><p>' + errorMsg + '</p></div>');
                    $form.before($notice);
                    self.showInlineNotice(errorMsg, 'error');
                }
            });
        }
    }

    // Initialize when document is ready
    $(document).ready(function () {
        if ($('#wptsall-field-configurator-container').length) {
            window.wptsallFieldConfigurator = new FieldConfigurator('wptsall-field-configurator-container');
        }
    });

})(jQuery);
