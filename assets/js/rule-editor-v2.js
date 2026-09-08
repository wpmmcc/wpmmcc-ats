/**
 * WPTSALL Rule Editor V2 - JavaScript
 *
 * Uses the new wptsall/v2 API
 * Supports form validation and error highlighting
 */
(function($) {
    'use strict';

    var RuleEditorV2 = {
        config: window.wptsallRuleEditorV2 || {},
        currentRule: null,
        currentModelId: null,
        options: null,

        init: function() {
            var self = this;

            // Load options (URL types, data types)
            this.loadOptions().then(function() {
                self.bindEvents();
                self.initPage();
            });
        },

        /**
         * Load options configuration
         */
        loadOptions: function() {
            var self = this;
            return wp.apiFetch({
                path: 'wptsall/v2/options'
            }).then(function(options) {
                self.options = options;
                return options;
            }).catch(function(error) {
                console.error('Failed to load options:', error);
                self.options = {
                    url_types: {},
                    data_types: {}
                };
            });
        },

        bindEvents: function() {
            var self = this;

            // Model list - scan all plugins
            $('#wptsall-scan-all-btn').on('click', function(e) {
                e.preventDefault();
                self.scanAllPlugins();
            });

            // Model detail - add rule button
            $(document).on('click', '.wptsall-add-rule-btn', function(e) {
                e.preventDefault();
                var urlType = $(this).data('url-type') || 'single';
                self.openRuleModal(null, urlType);
            });

            // Edit rule
            $(document).on('click', '.wptsall-edit-rule-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var ruleId = $(this).closest('.wptsall-rule-card').data('rule-id');
                self.editRule(ruleId);
            });

            // Delete rule
            $(document).on('click', '.wptsall-delete-rule-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var $card = $(this).closest('.wptsall-rule-card');
                var ruleId = $card.data('rule-id');
                self.deleteRule(ruleId, $card);
            });

            // Toggle rule status
            $(document).on('click', '.wptsall-toggle-rule-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var $card = $(this).closest('.wptsall-rule-card');
                var ruleId = $card.data('rule-id');
                self.toggleRule(ruleId, $card);
            });

            // Expand/collapse rule card
            $(document).on('click', '.wptsall-rule-header', function(e) {
                if (!$(e.target).closest('.wptsall-rule-actions').length) {
                    var $card = $(this).closest('.wptsall-rule-card');
                    $card.toggleClass('expanded');
                    $card.find('.wptsall-rule-body').slideToggle(200);
                }
            });

            // Close modal
            $(document).on('click', '.wptsall-modal-close', function() {
                $(this).closest('.wptsall-modal').hide();
                self.clearValidationErrors();
            });

            // Close modal on outside click
            $(window).on('click', function(e) {
                if ($(e.target).hasClass('wptsall-modal')) {
                    $(e.target).hide();
                    self.clearValidationErrors();
                }
            });

            // Save rule
            $('#wptsall-rule-form-v2').on('submit', function(e) {
                e.preventDefault();
                self.saveRule();
            });

            // Real-time validation
            $('#wptsall-rule-form-v2').on('change', 'input, select, textarea', function() {
                var field = $(this).attr('name');
                if (field) {
                    self.validateField(field);
                }
            });

            // Validate URL pattern on blur
            $('#rule-url-pattern').on('blur', function() {
                self.validateField('url_pattern');
            });

            // Update available fields when data type changes
            $('#rule-data-type, #rule-object-name').on('change', function() {
                self.loadAvailableFields();
            });

            // Add field button
            $(document).on('click', '.wptsall-add-field-btn', function() {
                var fieldType = $(this).data('field-type');
                self.addFieldRow(fieldType);
            });

            // Delete field
            $(document).on('click', '.wptsall-field-remove', function() {
                $(this).closest('.wptsall-field-row').remove();
            });

            // Update placeholder hint when URL type changes
            $('#rule-url-type').on('change', function() {
                self.updateUrlPatternHint();
            });

            // Delete model
            $(document).on('click', '.wptsall-delete-model-btn', function(e) {
                e.preventDefault();
                var modelId = $(this).data('model-id');
                self.deleteModel(modelId, $(this));
            });
        },

        initPage: function() {
            // Get current model ID
            this.currentModelId = this.config.modelId || null;

            // Initialize option dropdowns
            this.populateUrlTypeSelect();
            this.populateDataTypeSelect();
        },

        /**
         * Populate URL type dropdown
         */
        populateUrlTypeSelect: function() {
            var $select = $('#rule-url-type');
            if (!$select.length || !this.options) return;

            $select.empty();
            $.each(this.options.url_types, function(key, label) {
                $select.append($('<option>', {
                    value: key,
                    text: label
                }));
            });
        },

        /**
         * Populate data type dropdown
         */
        populateDataTypeSelect: function() {
            var $select = $('#rule-data-type');
            if (!$select.length || !this.options) return;

            $select.empty();
            $.each(this.options.data_types, function(key, label) {
                $select.append($('<option>', {
                    value: key,
                    text: label
                }));
            });
        },

        /**
         * Update URL pattern hint
         */
        updateUrlPatternHint: function() {
            var urlType = $('#rule-url-type').val();
            var $hint = $('#url-pattern-hint');

            var hints = {
                'single': 'Example: /course/{slug}/ or /product/{id}/',
                'archive': 'Example: /courses/ or /products/',
                'taxonomy': 'Example: /course-category/{slug}/',
                'author': 'Example: /instructor/{name}/',
                'home': 'Example: /',
                'search': 'Example: /search/'
            };

            $hint.text(hints[urlType] || 'Enter URL pattern');
        },

        /**
         * Scan all plugins
         */
        scanAllPlugins: function() {
            var self = this;

            if (!confirm('Are you sure you want to rescan all plugins? This will update existing model data.')) {
                return;
            }

            var $btn = $('#wptsall-scan-all-btn');
            var originalText = $btn.text();
            $btn.prop('disabled', true).text('Scanning...');

            wp.apiFetch({
                path: 'wptsall/v2/models/scan-all',
                method: 'POST'
            }).then(function(response) {
                $btn.prop('disabled', false).text(originalText);

                var msg = 'Scan complete!\n' +
                    'Created: ' + response.models_created + ' model(s)\n' +
                    'Updated: ' + response.models_updated + ' model(s)\n' +
                    'Total rules: ' + response.total_rules;

                alert(msg);
                location.reload();
            }).catch(function(error) {
                $btn.prop('disabled', false).text(originalText);
                alert('Scan failed: ' + (error.message || 'Unknown error'));
            });
        },

        /**
         * Open rule edit modal
         */
        openRuleModal: function(rule, defaultUrlType) {
            var self = this;

            this.currentRule = rule;
            this.clearValidationErrors();

            // Reset form
            $('#wptsall-rule-form-v2')[0].reset();
            $('#rule-form-id').val('');

            if (rule) {
                // Edit mode
                $('#rule-modal-title').text('Edit Translation Rule');
                $('#rule-form-id').val(rule.id);

                // Fill form
                $('#rule-name').val(rule.name || '');
                $('#rule-url-pattern').val(rule.url_pattern || '');
                $('#rule-url-type').val(rule.url_type || 'single');
                $('#rule-example-url').val(rule.example_url || '');
                $('#rule-data-type').val(rule.data_type || 'post');
                $('#rule-object-name').val(rule.object_name || '');
                $('#rule-requires-login').prop('checked', rule.requires_login == 1);
                $('#rule-backend-edit').val(rule.backend_edit || '');
                $('#rule-backend-list').val(rule.backend_list || '');
                $('#rule-priority').val(rule.priority || 10);
                $('#rule-note').val(rule.note || '');

                // Fill fields (v0.8.0: convert from field_capabilities)
                var fieldsFromCaps = this.convertCapabilitiesToFields(rule.field_capabilities || {});
                this.fillFields('translate', fieldsFromCaps.translate);
                this.fillFields('sync', fieldsFromCaps.sync);
            } else {
                // Create mode
                $('#rule-modal-title').text('Add Translation Rule');
                $('#rule-url-type').val(defaultUrlType || 'single');
                $('#rule-data-type').val('post');
                $('#rule-priority').val(10);

                // Clear field lists
                $('#fields-translate, #fields-sync').empty();
            }

            this.updateUrlPatternHint();
            this.loadAvailableFields();

            $('#wptsall-rule-modal-v2').show();
        },

        /**
         * Edit rule
         */
        editRule: function(ruleId) {
            var self = this;

            wp.apiFetch({
                path: 'wptsall/v2/rules/' + ruleId
            }).then(function(rule) {
                self.openRuleModal(rule);
            }).catch(function(error) {
                alert('Failed to load rule: ' + (error.message || 'Unknown error'));
            });
        },

        /**
         * Save rule
         */
        saveRule: function() {
            var self = this;
            var ruleId = $('#rule-form-id').val();

            // Collect form data
            var data = {
                name: $('#rule-name').val(),
                url_pattern: $('#rule-url-pattern').val(),
                url_type: $('#rule-url-type').val(),
                example_url: $('#rule-example-url').val(),
                data_type: $('#rule-data-type').val(),
                object_name: $('#rule-object-name').val(),
                requires_login: $('#rule-requires-login').is(':checked') ? 1 : 0,
                backend_edit: $('#rule-backend-edit').val(),
                backend_list: $('#rule-backend-list').val(),
                priority: parseInt($('#rule-priority').val()) || 10,
                note: $('#rule-note').val(),
                // v0.8.0: use field_capabilities
                field_capabilities: this.convertFieldsToCapabilities(
                    this.collectFields('translate'),
                    this.collectFields('sync')
                )
            };

            // Validate first
            this.clearValidationErrors();

            var $btn = $('#wptsall-rule-form-v2 [type="submit"]');
            var originalText = $btn.text();
            $btn.prop('disabled', true).text('Validating...');

            // Call validation API
            wp.apiFetch({
                path: 'wptsall/v2/rules/validate',
                method: 'POST',
                data: $.extend({}, data, { model_id: this.currentModelId })
            }).then(function(validation) {
                if (!validation.valid) {
                    $btn.prop('disabled', false).text(originalText);
                    self.showValidationErrors(validation.errors);
                    return;
                }

                // Validation passed, save
                $btn.text('Saving...');

                var path, method;
                if (ruleId) {
                    path = 'wptsall/v2/rules/' + ruleId;
                    method = 'PUT';
                } else {
                    path = 'wptsall/v2/models/' + self.currentModelId + '/rules';
                    method = 'POST';
                }

                return wp.apiFetch({
                    path: path,
                    method: method,
                    data: data
                });
            }).then(function(response) {
                if (response && response.success) {
                    $btn.prop('disabled', false).text(originalText);
                    $('#wptsall-rule-modal-v2').hide();
                    location.reload();
                }
            }).catch(function(error) {
                $btn.prop('disabled', false).text(originalText);

                // Check for validation errors
                if (error.data && error.data.errors) {
                    self.showValidationErrors(error.data.errors);
                } else {
                    alert('Save failed: ' + (error.message || 'Unknown error'));
                }
            });
        },

        /**
         * Delete rule
         */
        deleteRule: function(ruleId, $card) {
            if (!confirm('Are you sure you want to delete this rule?')) {
                return;
            }

            wp.apiFetch({
                path: 'wptsall/v2/rules/' + ruleId,
                method: 'DELETE'
            }).then(function(response) {
                $card.fadeOut(function() {
                    $(this).remove();
                });
            }).catch(function(error) {
                alert('Delete failed: ' + (error.message || 'Unknown error'));
            });
        },

        /**
         * Toggle rule status
         */
        toggleRule: function(ruleId, $card) {
            wp.apiFetch({
                path: 'wptsall/v2/rules/' + ruleId + '/toggle',
                method: 'POST'
            }).then(function(response) {
                var $btn = $card.find('.wptsall-toggle-rule-btn');
                if (response.is_active) {
                    $card.removeClass('inactive');
                    $btn.text('Disable').attr('title', 'Click to disable');
                } else {
                    $card.addClass('inactive');
                    $btn.text('Enable').attr('title', 'Click to enable');
                }
            }).catch(function(error) {
                alert('Operation failed: ' + (error.message || 'Unknown error'));
            });
        },

        /**
         * Delete model
         */
        deleteModel: function(modelId, $btn) {
            if (!confirm('Are you sure you want to delete this model and all its rules? This action cannot be undone.')) {
                return;
            }

            $btn.prop('disabled', true);

            wp.apiFetch({
                path: 'wptsall/v2/models/' + modelId,
                method: 'DELETE'
            }).then(function(response) {
                $btn.closest('tr').fadeOut(function() {
                    $(this).remove();
                });
            }).catch(function(error) {
                $btn.prop('disabled', false);
                alert('Delete failed: ' + (error.message || 'Unknown error'));
            });
        },

        /**
         * Show validation errors
         */
        showValidationErrors: function(errors) {
            var self = this;

            $.each(errors, function(field, fieldErrors) {
                var $field = $('[name="' + field + '"], #rule-' + field.replace(/_/g, '-'));

                if ($field.length) {
                    $field.addClass('wptsall-field-error');

                    // Add error messages
                    var $wrapper = $field.closest('.wptsall-form-field');
                    if ($wrapper.length) {
                        $wrapper.find('.wptsall-field-error-msg').remove();

                        $.each(fieldErrors, function(i, error) {
                            var cls = error.type === 'warning' ? 'wptsall-field-warning-msg' : 'wptsall-field-error-msg';
                            $wrapper.append('<span class="' + cls + '">' + error.message + '</span>');
                        });
                    }
                }
            });

            // Scroll to first error
            var $firstError = $('.wptsall-field-error').first();
            if ($firstError.length) {
                $firstError.focus();
            }
        },

        /**
         * Clear validation errors
         */
        clearValidationErrors: function() {
            $('.wptsall-field-error').removeClass('wptsall-field-error');
            $('.wptsall-field-error-msg, .wptsall-field-warning-msg').remove();
        },

        /**
         * Validate single field
         */
        validateField: function(fieldName) {
            var $field = $('[name="' + fieldName + '"], #rule-' + fieldName.replace(/_/g, '-'));
            var $wrapper = $field.closest('.wptsall-form-field');

            // Clear errors for this field
            $field.removeClass('wptsall-field-error');
            $wrapper.find('.wptsall-field-error-msg, .wptsall-field-warning-msg').remove();
        },

        /**
         * Load available fields
         */
        loadAvailableFields: function() {
            var self = this;
            var dataType = $('#rule-data-type').val();
            var objectName = $('#rule-object-name').val();

            if (!dataType || !objectName) {
                return;
            }

            wp.apiFetch({
                path: 'wptsall/v2/fields',
                data: {
                    data_type: dataType,
                    object_name: objectName
                }
            }).then(function(fields) {
                self.updateFieldSuggestions(fields);
            }).catch(function(error) {
                console.error('Failed to load fields:', error);
            });
        },

        /**
         * Update field suggestions
         */
        updateFieldSuggestions: function(fields) {
            var suggestions = [];

            // Main fields
            $.each(fields.main, function(key, info) {
                suggestions.push(key);
            });

            // Meta fields
            $.each(fields.meta, function(key, info) {
                suggestions.push(key);
            });

            // Update datalist
            var $datalist = $('#field-suggestions');
            if (!$datalist.length) {
                $datalist = $('<datalist id="field-suggestions"></datalist>').appendTo('body');
            }

            $datalist.empty();
            $.each(suggestions, function(i, field) {
                $datalist.append($('<option>', { value: field }));
            });
        },

        /**
         * Fill fields list
         */
        fillFields: function(fieldType, fields) {
            var $container = $('#fields-' + fieldType);
            $container.empty();

            if (!fields || !Array.isArray(fields)) {
                return;
            }

            var self = this;
            $.each(fields, function(i, field) {
                self.addFieldRow(fieldType, field);
            });
        },

        /**
         * Add field row
         */
        addFieldRow: function(fieldType, value) {
            var $container = $('#fields-' + fieldType);

            var html = '<div class="wptsall-field-row">' +
                '<input type="text" name="fields_' + fieldType + '[]" ' +
                'value="' + (value || '') + '" ' +
                'list="field-suggestions" ' +
                'placeholder="Enter field name">' +
                '<button type="button" class="wptsall-field-remove" title="Delete">&times;</button>' +
                '</div>';

            $container.append(html);
        },

        /**
         * Collect fields list
         */
        collectFields: function(fieldType) {
            var fields = [];
            $('[name="fields_' + fieldType + '[]"]').each(function() {
                var val = $(this).val().trim();
                if (val) {
                    fields.push(val);
                }
            });
            return fields;
        },

        /**
         * Convert field_capabilities to UI field arrays
         * @since 0.8.0
         */
        convertCapabilitiesToFields: function(capabilities) {
            var result = {
                translate: [],
                sync: [],
                mapping: [],
                compute: []
            };

            if (!capabilities || typeof capabilities !== 'object') {
                return result;
            }

            for (var fieldName in capabilities) {
                if (!capabilities.hasOwnProperty(fieldName)) {
                    continue;
                }

                var config = capabilities[fieldName];
                var type = config.type || 'sync';

                if (result.hasOwnProperty(type)) {
                    result[type].push(fieldName);
                }
            }

            return result;
        },

        /**
         * Convert UI field arrays to field_capabilities
         * @since 0.8.0
         */
        convertFieldsToCapabilities: function(translateFields, syncFields) {
            var capabilities = {};

            // Translate fields
            if (translateFields && Array.isArray(translateFields)) {
                translateFields.forEach(function(field) {
                    capabilities[field] = {
                        type: 'translate',
                        direction: 'one_way',
                        enabled: true
                    };
                });
            }

            // Sync fields
            if (syncFields && Array.isArray(syncFields)) {
                syncFields.forEach(function(field) {
                    capabilities[field] = {
                        type: 'sync',
                        direction: 'one_way',
                        enabled: true
                    };
                });
            }

            return capabilities;
        }
    };

    $(document).ready(function() {
        RuleEditorV2.init();
    });

})(jQuery);
