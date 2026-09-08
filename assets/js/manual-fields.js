/**
 * WPTSALL Manual Fields Management
 */
(function ($) {
    'use strict';

    const ManualFields = {
        config: window.wptsallManualFields || {},
        tables: [],
        fields: [],

        init: function () {
            if (!$('#wptsall-manual-fields-list').length) return;

            this.bindEvents();
            this.loadInitialData();
        },

        bindEvents: function () {
            const self = this;

            // Add new field row
            $('#wptsall-add-manual-field-btn').on('click', function () {
                self.addFieldRow();
            });

            // Save manual fields
            $('#wptsall-save-manual-fields-btn').on('click', function () {
                self.saveFields();
            });

            // Table selection change -> load columns
            $(document).on('change', '.field-table', function () {
                const $row = $(this).closest('.wptsall-manual-field-item');
                self.loadColumns($(this).val(), $row);
            });

            // Remove field row
            $(document).on('click', '.wptsall-remove-manual-field', function () {
                if (confirm('Are you sure you want to delete this manual field definition?')) {
                    $(this).closest('.wptsall-manual-field-item').remove();
                    self.toggleSaveButton();
                }
            });

            // Test configuration
            $(document).on('click', '.wptsall-test-manual-field', function () {
                self.testConfig($(this).closest('.wptsall-manual-field-item'));
            });

            // Any change -> show save button
            $(document).on('change input', '.wptsall-manual-field-item input, .wptsall-manual-field-item select', function () {
                self.toggleSaveButton();
            });
        },

        loadInitialData: function () {
            const self = this;
            const $list = $('#wptsall-manual-fields-list');

            Promise.all([
                wp.apiFetch({ path: 'wptsall/v2/discovery/tables' }),
                wp.apiFetch({ path: 'wptsall/v2/models/' + self.config.modelId + '/manual-fields' })
            ]).then(([tables, response]) => {
                self.tables = tables;
                self.fields = response.fields || [];

                $list.find('.wptsall-loading-fields').remove();

                if (self.fields.length > 0) {
                    self.fields.forEach(field => self.addFieldRow(field));
                } else {
                    $list.append('<p class="wptsall-no-manual-fields">No manual fields defined yet.</p>');
                }
            }).catch(err => {
                $list.html('<p class="error">Load failed: ' + err.message + '</p>');
            });
        },

        loadColumns: function (table, $row, selectedField = '', selectedIdMap = '') {
            if (!table) return;

            const $fieldSelect = $row.find('.field-name');
            const $idSelect = $row.find('.field-id-map-select');
            const rowId = $row.attr('id');
            const dataListId = 'dl-' + rowId;

            $fieldSelect.prop('disabled', true).val('Loading...');
            $idSelect.prop('disabled', true).html('<option>Loading...</option>');

            // 1. Load column names (standard for all tables)
            wp.apiFetch({ path: 'wptsall/v2/discovery/columns?table=' + table })
                .then(columns => {
                    const idOptions = columns.map(c => `<option value="${c.field}" ${selectedIdMap === c.field ? 'selected' : ''}>${c.field} (${c.type})</option>`).join('');
                    const placeholder = '<option value="">-- Select field --</option>';

                    $idSelect.prop('disabled', false).html(placeholder + idOptions);

                    // Auto-prefill for standard WP tables
                    if (!selectedIdMap) {
                        if (table.includes('postmeta')) $idSelect.val('post_id');
                        else if (table.includes('termmeta')) $idSelect.val('term_id');
                        else if (table.includes('usermeta')) $idSelect.val('user_id');
                        else if (table.includes('posts')) $idSelect.val('ID');
                    }

                    // 2. Load distinct values for Target Field suggestion (if it's a meta table)
                    // We use "meta_key" column for meta tables, or just column names otherwise
                    let suggestColumn = '';
                    if (table.includes('meta')) suggestColumn = 'meta_key';

                    if (suggestColumn) {
                        return wp.apiFetch({ path: `wptsall/v2/discovery/values?table=${table}&column=${suggestColumn}` });
                    }
                    return columns.map(c => c.field);
                })
                .then(suggestions => {
                    $fieldSelect.prop('disabled', false).val(selectedField);

                    // Create/Update datalist
                    let $dl = $(`#${dataListId}`);
                    if (!$dl.length) {
                        $dl = $(`<datalist id="${dataListId}"></datalist>`).insertAfter($fieldSelect);
                    }
                    $dl.empty().append(suggestions.map(s => `<option value="${s}">`).join(''));
                    $fieldSelect.attr('list', dataListId);
                })
                .catch(err => {
                    $fieldSelect.prop('disabled', false).val('');
                    console.error('Discovery failed:', err);
                });
        },

        addFieldRow: function (data = {}) {
            const self = this;
            const $list = $('#wptsall-manual-fields-list');
            $list.find('.wptsall-no-manual-fields').remove();

            const rowId = 'mf-' + Date.now() + Math.floor(Math.random() * 1000);

            const html = `
                <div class="wptsall-manual-field-item" id="${rowId}" data-validated="false">
                    <div class="wptsall-manual-field-row">
                        <div class="wptsall-manual-field-col">
                            <label>Table</label>
                            <select class="field-table">
                                <option value="">-- Select table --</option>
                                ${self.tables.map(t => `<option value="${t}" ${data.table_name === t ? 'selected' : ''}>${t}</option>`).join('')}
                            </select>
                        </div>
                        <div class="wptsall-manual-field-col">
                            <label>Target Field</label>
                            <input type="text" class="field-name" value="${data.field_name || ''}" placeholder="Select or enter field name" ${!data.table_name ? 'disabled' : ''}>
                        </div>
                        <div class="wptsall-manual-field-col">
                            <label>Relationship Field</label>
                            <select class="field-id-map-select field-id-map" ${!data.table_name ? 'disabled' : ''}>
                                <option value="">-- Select field --</option>
                                ${data.associated_id_map ? `<option value="${data.associated_id_map}" selected>${data.associated_id_map}</option>` : ''}
                            </select>
                        </div>
                    </div>
                    <div class="wptsall-manual-field-row" style="margin-top:10px;">
                        <div class="wptsall-manual-field-col" style="flex: 2;">
                            <label>Description</label>
                            <input type="text" class="field-desc" value="${data.description || ''}" placeholder="Field description">
                        </div>
                        <div class="wptsall-manual-field-actions">
                            <button type="button" class="button wptsall-test-manual-field" title="Test and verify this config">🧪 Verify Connection</button>
                            <button type="button" class="button button-link-delete wptsall-remove-manual-field" title="Delete">Delete</button>
                        </div>
                    </div>
                    <div class="wptsall-manual-field-test-result"></div>
                </div>
            `;

            $list.append(html);
            const $newRow = $('#' + rowId);

            // If we have data, load the full column lists
            if (data.table_name) {
                this.loadColumns(data.table_name, $newRow, data.field_name, data.associated_id_map);
                if (data.field_name) $newRow.attr('data-validated', 'true');
            }
        },

        toggleSaveButton: function () {
            $('#wptsall-save-manual-fields-btn').show();
        },

        saveFields: function () {
            const self = this;
            const $btn = $('#wptsall-save-manual-fields-btn');
            const $spinner = $('#wptsall-manual-fields-spinner');

            const fields = [];
            let allValidated = true;

            $('.wptsall-manual-field-item').each(function () {
                const $item = $(this);
                const isValidated = $item.attr('data-validated') === 'true';

                const field = {
                    table_name: $item.find('.field-table').val(),
                    field_name: $item.find('.field-name').val(),
                    associated_id_map: $item.find('.field-id-map').val(),
                    description: $item.find('.field-desc').val().trim()
                };

                if (!field.table_name || !field.field_name || !field.associated_id_map) {
                    return; // Skip incomplete
                }

                if (!isValidated) {
                    allValidated = false;
                    $item.find('.wptsall-manual-field-test-result').addClass('error').text('Please click "Verify Connection" first to ensure configuration is correct.').show();
                }

                fields.push(field);
            });

            if (!allValidated) {
                if (!confirm('Some fields have not been verified. Force saving may cause issues. Are you sure you want to continue?')) {
                    return;
                }
            }

            // Check for duplicates in the list itself
            const uniqueCheck = new Set();
            for (const f of fields) {
                const key = `${f.table_name}.${f.field_name}`;
                if (uniqueCheck.has(key)) {
                    alert(`Duplicate field: ${key}`);
                    return;
                }
                uniqueCheck.add(key);
            }

            $btn.prop('disabled', true).text('Saving...');
            $spinner.addClass('is-active');

            wp.apiFetch({
                path: 'wptsall/v2/models/' + self.config.modelId + '/manual-fields',
                method: 'POST',
                data: { meta_fields: fields }
            }).then(() => {
                $btn.prop('disabled', false).text('Save Manual Fields').hide();
                $spinner.removeClass('is-active');
                alert('Manual field definitions saved.');
            }).catch(err => {
                $btn.prop('disabled', false).text('Save Manual Fields');
                $spinner.removeClass('is-active');
                alert('Save failed: ' + err.message);
            });
        },

        testConfig: function ($item) {
            const self = this;
            const $result = $item.find('.wptsall-manual-field-test-result');

            const config = {
                table: $item.find('.field-table').val(),
                field: $item.find('.field-name').val(),
                associated_id_map: $item.find('.field-id-map').val()
            };

            if (!config.table || !config.field || !config.associated_id_map) {
                alert('Please select a table, target field, and relationship field first.');
                return;
            }

            const sampleId = prompt('Enter a sample object ID (e.g. post ID) to verify connection:', '');
            if (!sampleId) return;

            $result.removeClass('error success').text('Verifying connection...').show();

            wp.apiFetch({
                path: 'wptsall/v2/discovery/test-config',
                method: 'POST',
                data: { config: config, sample_id: parseInt(sampleId) }
            }).then(response => {
                if (typeof response.value === 'string' && response.value.startsWith('[Error]')) {
                    $result.addClass('error').text(response.value);
                    $item.attr('data-validated', 'false');
                } else if (response.value === '[No Data Found]') {
                    $result.addClass('error').text('Connection successful, but no data found for this ID. Please check the relationship field or ID.');
                    $item.attr('data-validated', 'false');
                } else {
                    $result.addClass('success').text('Connection successful! Value retrieved: ' + (typeof response.value === 'object' ? JSON.stringify(response.value) : response.value));
                    $item.attr('data-validated', 'true');
                }
            }).catch(err => {
                $result.addClass('error').text('Verification failed: ' + err.message);
                $item.attr('data-validated', 'false');
            });
        }
    };

    $(document).ready(function () {
        ManualFields.init();
    });

})(jQuery);
