/**
 * WPTSALL Model Editor JavaScript
 */
(function($) {
    'use strict';

    var ModelEditor = {
        config: window.wptsallModelEditor || {},
        currentRule: null,

        init: function() {
            this.moveNoticesToTop();
            this.bindEvents();
            this.initTabs();
        },

        /**
         * Move admin notices to the top container
         * Prevents notices from splitting the header and content
         */
        moveNoticesToTop: function() {
            var $container = $('#wptsall-notices-container');
            if (!$container.length) {
                return;
            }

            // Find all notices (exclude inline type and those already in the container)
            var $notices = $('div.notice, div.updated, div.error').not('.inline, .below-h2, #wptsall-notices-container div');

            if ($notices.length) {
                // Move notices to the dedicated container
                $notices.appendTo($container);
            }
        },

        bindEvents: function() {
            var self = this;

            // Scan plugin modal
            $('#wptsall-scan-plugin-btn').on('click', function(e) {
                e.preventDefault();
                $('#wptsall-scan-modal').show();
            });

            // Close modal
            $('.wptsall-modal-close').on('click', function() {
                $(this).closest('.wptsall-modal').hide();
            });

            // Click outside modal to close
            $(window).on('click', function(e) {
                if ($(e.target).hasClass('wptsall-modal')) {
                    $(e.target).hide();
                }
            });

            // Preview scan
            $('#scan-preview-btn').on('click', function() {
                self.previewScan();
            });

            // Save scan
            $('#wptsall-scan-form').on('submit', function(e) {
                e.preventDefault();
                self.saveScan();
            });

            // Rescan model
            $('.wptsall-rescan-btn').on('click', function() {
                var modelId = $(this).data('model-id');
                self.rescanModel(modelId, $(this));
            });

            // Delete model
            $('.wptsall-delete-model-btn').on('click', function() {
                var modelId = $(this).data('model-id');
                self.deleteModel(modelId, $(this));
            });

            // Import modal
            $('#wptsall-import-btn').on('click', function(e) {
                e.preventDefault();
                $('#wptsall-import-modal').show();
            });

            // Export modal
            $('#wptsall-export-btn').on('click', function(e) {
                e.preventDefault();
                $('#wptsall-export-modal').show();
            });

            // Import form
            $('#wptsall-import-form').on('submit', function(e) {
                e.preventDefault();
                self.importModels();
            });

            // Export form
            $('#wptsall-export-form').on('submit', function(e) {
                e.preventDefault();
                self.exportModels();
            });

            // Scan all plugins button
            $('#wptsall-scan-all-btn').on('click', function(e) {
                e.preventDefault();
                self.scanAllPlugins();
            });

            // Export select all
            $('#export-select-all').on('change', function() {
                $('input[name="export_models[]"]').prop('checked', $(this).is(':checked'));
            });

            // Import file preview
            $('#import-file').on('change', function() {
                self.previewImportFile(this.files[0]);
            });

            // Custom plugin input toggle
            $('#scan-plugin').on('change', function() {
                if ($(this).val() === '__custom__') {
                    $('#scan-plugin-custom-row').show();
                    $('#scan-plugin-custom').focus();
                } else {
                    $('#scan-plugin-custom-row').hide();
                    $('#scan-plugin-custom').val('');
                }
            });

            // Show available plugins list
            $('#show-available-plugins').on('click', function(e) {
                e.preventDefault();
                $('#available-plugins-list').toggle();
            });

            // Toggle rule card
            $(document).on('click', '.wptsall-rule-header', function(e) {
                if (!$(e.target).closest('.wptsall-rule-actions').length) {
                    var $card = $(this).closest('.wptsall-rule-card');
                    $card.toggleClass('expanded');
                    $card.find('.wptsall-rule-body').slideToggle(200);
                }
            });

            // Edit rule
            $(document).on('click', '.wptsall-edit-rule-btn', function(e) {
                e.stopPropagation();
                var $card = $(this).closest('.wptsall-rule-card');
                var ruleId = $card.data('rule-id');
                self.editRule(ruleId);
            });

            // Delete rule
            $(document).on('click', '.wptsall-delete-rule-btn', function(e) {
                e.stopPropagation();
                var $card = $(this).closest('.wptsall-rule-card');
                var ruleId = $card.data('rule-id');
                self.deleteRule(ruleId, $card);
            });

            // Export rule
            $(document).on('click', '.wptsall-export-rule-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.exportRule($(this));
            });

            // Add rule
            $('.wptsall-add-rule-btn').on('click', function() {
                var accessLevel = $(this).data('access-level');
                self.addRule(accessLevel);
            });

            // Save rule form
            $('#wptsall-rule-form').on('submit', function(e) {
                e.preventDefault();
                self.saveRule();
            });

            // Access level change
            $('#rule-access-level').on('change', function() {
                self.toggleHooksSection($(this).val());
            });

            // Fields tabs
            $(document).on('click', '.wptsall-fields-tab', function() {
                var tab = $(this).data('fields-tab');
                $('.wptsall-fields-tab').removeClass('active');
                $(this).addClass('active');
                $('.wptsall-fields-content').hide();
                $('.wptsall-fields-content[data-fields-tab="' + tab + '"]').show();
            });

            // Add field
            $(document).on('click', '.wptsall-add-field-btn', function() {
                var fieldType = $(this).data('field-type');
                self.addField(fieldType);
            });

            // Remove field
            $(document).on('click', '.wptsall-field-remove', function() {
                $(this).closest('.wptsall-field-item').remove();
            });

            // Add hook
            $('#add-hook-btn').on('click', function() {
                self.addHook();
            });

            // Remove hook
            $(document).on('click', '.wptsall-hook-remove', function() {
                $(this).closest('.wptsall-hook-item').remove();
            });
        },

        initTabs: function() {
            var self = this;

            // Tab navigation - only for Model Editor page (uses data-tab attribute)
            $('.wptsall-tabs .nav-tab[data-tab]').on('click', function(e) {
                e.preventDefault();
                var tab = $(this).data('tab');

                // Skip if tab is undefined
                if (!tab) {
                    return;
                }

                $('.wptsall-tabs .nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');

                $('.wptsall-tab-content').hide();
                $('#tab-' + tab.replace('_', '-')).show();
            });
        },

        getSelectedPluginSlug: function() {
            var selectVal = $('#scan-plugin').val();
            if (selectVal === '__custom__') {
                return $('#scan-plugin-custom').val().trim();
            }
            return selectVal;
        },

        /**
         * Show admin notice
         * @param {string} message - Notice message
         * @param {string} type - Notice type: 'success', 'warning', 'error', 'info'
         */
        showNotice: function(message, type) {
            type = type || 'info';
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button></div>');

            // Add to notices container or wrap
            var $container = $('#wptsall-notices-container');
            if (!$container.length) {
                $container = $('.wrap').first();
            }

            $container.prepend($notice);

            // Handle dismiss
            $notice.find('.notice-dismiss').on('click', function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            });

            // Auto dismiss after 10 seconds for warnings
            if (type === 'warning') {
                setTimeout(function() {
                    $notice.fadeOut(function() {
                        $(this).remove();
                    });
                }, 10000);
            }
        },

        previewScan: function() {
            var self = this;
            var pluginSlug = this.getSelectedPluginSlug();

            if (!pluginSlug) {
                alert('Please select or enter a plugin');
                return;
            }

            var $btn = $('#scan-preview-btn');
            $btn.prop('disabled', true).text(self.config.i18n.scanning);

            wp.apiFetch({
                path: 'wptsall/v2/models/scan',
                method: 'POST',
                data: {
                    plugin_slug: pluginSlug,
                    save: false
                }
            }).then(function(response) {
                $btn.prop('disabled', false).text('Preview Scan Results');

                // Show warning if plugin is not active
                if (response.warning) {
                    self.showNotice(response.warning, 'warning');
                }

                $('#scan-preview').show();
                $('#scan-preview-content').text(JSON.stringify(response.result, null, 2));
                $('#scan-save-btn').prop('disabled', false);
            }).catch(function(error) {
                $btn.prop('disabled', false).text('Preview Scan Results');
                alert('Scan failed: ' + (error.message || 'Unknown error'));
            });
        },

        saveScan: function() {
            var self = this;
            var pluginSlug = this.getSelectedPluginSlug();

            if (!pluginSlug) {
                return;
            }

            var $btn = $('#scan-save-btn');
            $btn.prop('disabled', true).text(self.config.i18n.saving);

            wp.apiFetch({
                path: 'wptsall/v2/models/scan',
                method: 'POST',
                data: {
                    plugin_slug: pluginSlug,
                    save: true
                }
            }).then(function(response) {
                alert(self.config.i18n.saved);
                location.reload();
            }).catch(function(error) {
                $btn.prop('disabled', false).text('Save Model');
                alert('Save failed: ' + (error.message || 'Unknown error'));
            });
        },

        rescanModel: function(modelId, $btn) {
            var self = this;
            var pluginSlug = $btn.data('plugin-slug');

            // If we have a modelId, it's a rescan (re-init)
            if (modelId) {
                // Confirm before reinitializing (this will remove all manual edits)
                if (!confirm('Warning: Rescan will completely reset the model, deleting all existing rules and manual edits.\n\nAre you sure you want to continue?')) {
                    return;
                }

                $btn.prop('disabled', true).text(self.config.i18n.scanning);

                wp.apiFetch({
                    path: 'wptsall/v2/models/' + modelId + '/rescan',
                    method: 'POST'
                }).then(function(response) {
                    $btn.prop('disabled', false).text('Rescan');
                    var msg = 'Model reinitialized!\nTotal ' + response.rule_count + ' rule(s)';
                    alert(msg);
                    location.reload();
                }).catch(function(error) {
                    $btn.prop('disabled', false).text('Rescan');
                    alert('Scan failed: ' + (error.message || 'Unknown error'));
                });
            } else if (pluginSlug) {
                // No modelId but have slug -> Create new model from mapping
                $btn.prop('disabled', true).text(self.config.i18n.scanning);

                wp.apiFetch({
                    path: 'wptsall/v2/models/scan',
                    method: 'POST',
                    data: {
                        plugin_slug: pluginSlug,
                        save: true
                    }
                }).then(function(response) {
                    $btn.prop('disabled', false).text('Scan');
                    var msg = 'Scan successful!\nModel created with ' + response.rule_count + ' rule(s)';
                    alert(msg);
                    location.reload();
                }).catch(function(error) {
                    $btn.prop('disabled', false).text('Scan');
                    alert('Scan failed: ' + (error.message || 'Unknown error'));
                });
            }
        },

        deleteModel: function(modelId, $btn) {
            var self = this;

            if (!confirm(self.config.i18n.confirm_delete)) {
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

        addRule: function(accessLevel) {
            var self = this;

            self.currentRule = null;
            $('#rule-modal-title').text('Add URL Rule');
            $('#rule-form-id').val('');
            $('#wptsall-rule-form')[0].reset();
            $('#rule-access-level').val(accessLevel);

            // Clear fields
            $('#fields-translate, #fields-sync, #fields-exclude, #admin-hooks').empty();

            // Toggle hooks section
            self.toggleHooksSection(accessLevel);

            $('#wptsall-rule-modal').show();
        },

        editRule: function(ruleId) {
            var self = this;

            wp.apiFetch({
                path: 'wptsall/v2/rules/' + ruleId
            }).then(function(rule) {
                self.currentRule = rule;
                $('#rule-modal-title').text('Edit URL Rule');
                $('#rule-form-id').val(rule.id);

                // Fill form
                $('#rule-rule-id').val(rule.rule_id).prop('readonly', true);
                $('#rule-label').val(rule.label);
                $('#rule-url-pattern').val(rule.url_pattern);
                $('#rule-access-level').val(rule.access_level);
                $('#rule-object-type').val(rule.object_type);
                $('#rule-object-subtype').val(rule.object_subtype);
                $('#rule-template-file').val(rule.template_file);
                $('#rule-template-domain').val(rule.template_domain);

                // Fill fields
                self.fillFields('translate', rule.fields_translate || []);
                self.fillFields('sync', rule.fields_sync || []);
                self.fillFields('exclude', rule.fields_exclude || []);

                // Fill hooks
                self.fillHooks(rule.admin_hooks || []);

                // Toggle hooks section
                self.toggleHooksSection(rule.access_level);

                $('#wptsall-rule-modal').show();
            }).catch(function(error) {
                alert('Failed to load rule: ' + (error.message || 'Unknown error'));
            });
        },

        deleteRule: function(ruleId, $card) {
            var self = this;

            if (!confirm(self.config.i18n.confirm_delete_rule)) {
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

        saveRule: function() {
            var self = this;
            var $form = $('#wptsall-rule-form');
            var ruleId = $('#rule-form-id').val();
            var modelId = $form.find('[name="model_id"]').val();

            // Collect data
            var data = {
                rule_id: $('#rule-rule-id').val(),
                label: $('#rule-label').val(),
                url_pattern: $('#rule-url-pattern').val(),
                access_level: $('#rule-access-level').val(),
                object_type: $('#rule-object-type').val(),
                object_subtype: $('#rule-object-subtype').val(),
                template_file: $('#rule-template-file').val(),
                template_domain: $('#rule-template-domain').val(),
                fields_translate: self.collectFields('translate'),
                fields_sync: self.collectFields('sync'),
                fields_exclude: self.collectFields('exclude'),
                admin_hooks: self.collectHooks()
            };

            var $btn = $form.find('[type="submit"]');
            $btn.prop('disabled', true).text(self.config.i18n.saving);

            var path, method;
            if (ruleId) {
                path = 'wptsall/v2/rules/' + ruleId;
                method = 'PUT';
            } else {
                path = 'wptsall/v2/models/' + modelId + '/rules';
                method = 'POST';
            }

            wp.apiFetch({
                path: path,
                method: method,
                data: data
            }).then(function(response) {
                $btn.prop('disabled', false).text('Save Rule');
                $('#wptsall-rule-modal').hide();
                location.reload();
            }).catch(function(error) {
                $btn.prop('disabled', false).text('Save Rule');
                alert('Save failed: ' + (error.message || 'Unknown error'));
            });
        },

        toggleHooksSection: function(accessLevel) {
            if (accessLevel === 'admin') {
                $('#hooks-section').show();
                $('#fields-section').hide();
            } else {
                $('#hooks-section').hide();
                $('#fields-section').show();
            }
        },

        addField: function(fieldType) {
            var html = '<div class="wptsall-field-item">' +
                '<select name="field_table">' +
                '<option value="posts">posts</option>' +
                '<option value="postmeta">postmeta</option>' +
                '<option value="terms">terms</option>' +
                '<option value="termmeta">termmeta</option>' +
                '<option value="term_taxonomy">term_taxonomy</option>' +
                '</select>' +
                '<input type="text" name="field_name" placeholder="Field name">' +
                '<span class="wptsall-field-remove">&times;</span>' +
                '</div>';

            $('#fields-' + fieldType).append(html);
        },

        fillFields: function(fieldType, fields) {
            var $container = $('#fields-' + fieldType);
            $container.empty();

            // Ensure fields is an array
            if (!fields || !Array.isArray(fields)) {
                return;
            }

            fields.forEach(function(field) {
                var table = field.table || 'postmeta';
                var name = field.field || field;

                var html = '<div class="wptsall-field-item">' +
                    '<select name="field_table">' +
                    '<option value="posts"' + (table === 'posts' ? ' selected' : '') + '>posts</option>' +
                    '<option value="postmeta"' + (table === 'postmeta' ? ' selected' : '') + '>postmeta</option>' +
                    '<option value="terms"' + (table === 'terms' ? ' selected' : '') + '>terms</option>' +
                    '<option value="termmeta"' + (table === 'termmeta' ? ' selected' : '') + '>termmeta</option>' +
                    '<option value="term_taxonomy"' + (table === 'term_taxonomy' ? ' selected' : '') + '>term_taxonomy</option>' +
                    '</select>' +
                    '<input type="text" name="field_name" value="' + name + '">' +
                    '<span class="wptsall-field-remove">&times;</span>' +
                    '</div>';

                $container.append(html);
            });
        },

        collectFields: function(fieldType) {
            var fields = [];
            $('#fields-' + fieldType + ' .wptsall-field-item').each(function() {
                var table = $(this).find('[name="field_table"]').val();
                var name = $(this).find('[name="field_name"]').val().trim();
                if (name) {
                    fields.push({
                        table: table,
                        field: name
                    });
                }
            });
            return fields;
        },

        addHook: function() {
            var html = '<div class="wptsall-hook-item">' +
                '<input type="text" name="hook_name" placeholder="Hook name (e.g. restrict_manage_posts)">' +
                '<input type="text" name="hook_action" placeholder="Action (e.g. add_virtual_site_filter)">' +
                '<span class="wptsall-hook-remove">&times;</span>' +
                '</div>';

            $('#admin-hooks').append(html);
        },

        fillHooks: function(hooks) {
            var $container = $('#admin-hooks');
            $container.empty();

            // Ensure hooks is an array
            if (!hooks || !Array.isArray(hooks)) {
                return;
            }

            hooks.forEach(function(hook) {
                var html = '<div class="wptsall-hook-item">' +
                    '<input type="text" name="hook_name" value="' + (hook.hook_name || '') + '">' +
                    '<input type="text" name="hook_action" value="' + (hook.action || '') + '">' +
                    '<span class="wptsall-hook-remove">&times;</span>' +
                    '</div>';

                $container.append(html);
            });
        },

        collectHooks: function() {
            var hooks = [];
            $('#admin-hooks .wptsall-hook-item').each(function() {
                var name = $(this).find('[name="hook_name"]').val().trim();
                var action = $(this).find('[name="hook_action"]').val().trim();
                if (name) {
                    hooks.push({
                        hook_name: name,
                        action: action
                    });
                }
            });
            return hooks;
        },

        // Import/Export functions
        previewImportFile: function(file) {
            if (!file) return;

            var reader = new FileReader();
            reader.onload = function(e) {
                try {
                    var data = JSON.parse(e.target.result);
                    var preview = '';

                    if (Array.isArray(data)) {
                        preview = 'Will import ' + data.length + ' model(s):\n';
                        data.forEach(function(model) {
                            preview += '- ' + (model.plugin_name || model.slug) + '\n';
                        });
                    } else if (data.slug) {
                        preview = 'Will import 1 model:\n- ' + (data.plugin_name || data.slug);
                    } else {
                        preview = 'Invalid model configuration file';
                    }

                    $('#import-preview-content').text(preview);
                    $('#import-preview').show();
                } catch (err) {
                    $('#import-preview-content').text('File parse error: ' + err.message);
                    $('#import-preview').show();
                }
            };
            reader.readAsText(file);
        },

        importModels: function() {
            var self = this;
            var fileInput = document.getElementById('import-file');
            var file = fileInput.files[0];

            if (!file) {
                alert('Please select a file');
                return;
            }

            var reader = new FileReader();
            reader.onload = function(e) {
                try {
                    var data = JSON.parse(e.target.result);
                    var overwrite = $('#import-overwrite').is(':checked');

                    wp.apiFetch({
                        path: 'wptsall/v2/models/import',
                        method: 'POST',
                        data: {
                            models: Array.isArray(data) ? data : [data],
                            overwrite: overwrite
                        }
                    }).then(function(response) {
                        alert('Import successful! Imported ' + response.imported + ' model(s)');
                        location.reload();
                    }).catch(function(error) {
                        alert('Import failed: ' + (error.message || 'Unknown error'));
                    });
                } catch (err) {
                    alert('File parse error: ' + err.message);
                }
            };
            reader.readAsText(file);
        },

        exportModels: function() {
            var selected = [];

            $('input[name="export_models[]"]:checked').each(function() {
                selected.push($(this).val());
            });

            if (selected.length === 0) {
                alert('Please select at least one model');
                return;
            }

            var includeRules = $('#export-include-rules').is(':checked');

            wp.apiFetch({
                path: 'wptsall/v2/models/export',
                method: 'POST',
                data: {
                    model_ids: selected,
                    include_rules: includeRules
                }
            }).then(function(response) {
                ModelEditor.downloadJsonFile(
                    response.data,
                    'wptsall-models-' + new Date().toISOString().slice(0, 10) + '.json'
                );

                $('#wptsall-export-modal').hide();
            }).catch(function(error) {
                alert('Export failed: ' + (error.message || 'Unknown error'));
            });
        },

        exportRule: function($button) {
            var ruleId = parseInt($button.data('rule-id'), 10);
            if (!ruleId) {
                return;
            }

            var self = this;
            var originalText = $button.text();
            $button.prop('disabled', true).text(self.config.i18n.saving || 'Saving...');

            wp.apiFetch({
                path: 'wptsall/v2/rules/' + ruleId + '/export',
                method: 'GET'
            }).then(function(response) {
                var payload = response && response.data ? response.data : response;
                var fileName = self.buildRuleExportFileName($button, payload, ruleId) + '.json';
                self.downloadJsonFile(payload, fileName);
                self.showNotice(
                    self.config.i18n.export_rule_success || 'Rule exported successfully!',
                    'success'
                );
            }).catch(function(error) {
                self.showNotice(
                    (self.config.i18n.export_failed || 'Export failed') + ': ' + (error.message || 'Unknown error'),
                    'error'
                );
            }).finally(function() {
                $button.prop('disabled', false).text(originalText);
            });
        },

        buildRuleExportFileName: function($button, payload, ruleId) {
            var rule = payload && payload.rule ? payload.rule : {};
            var templateRef = payload && payload.template_ref ? payload.template_ref : {};
            var parts = [
                'wptsall-rule',
                templateRef.plugin_slug || '',
                rule.data_type || '',
                $button.data('object-name') || rule.object_name || '',
                $button.data('rule-name') || rule.name || '',
                String(ruleId || '')
            ];

            return parts
                .map(this.sanitizeFileNamePart)
                .filter(Boolean)
                .join('-');
        },

        sanitizeFileNamePart: function(value) {
            return String(value || '')
                .trim()
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        },

        downloadJsonFile: function(payload, fileName) {
            var blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = fileName;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        },

        /**
         * Scan all plugins and update mappings
         */
        scanAllPlugins: function() {
            var self = this;

            if (!confirm(self.config.i18n.confirm_scan_all)) {
                return;
            }

            var $btn = $('#wptsall-scan-all-btn');
            var originalText = $btn.text();
            $btn.addClass('disabled').text(self.config.i18n.scanning);

            wp.apiFetch({
                path: 'wptsall/v2/plugins/scan-all',
                method: 'POST'
            }).then(function(response) {
                $btn.removeClass('disabled').text(originalText);

                var msg = self.config.i18n.scan_all_success
                    .replace('%1$d', response.total_scanned)
                    .replace('%2$d', response.content_plugins);
                alert(msg);
                location.reload();
            }).catch(function(error) {
                $btn.removeClass('disabled').text(originalText);
                alert('Scan failed: ' + (error.message || 'Unknown error'));
            });
        }
    };

    /**
     * Import Conflict Dialog
     *
     * Shows a conflict comparison dialog when importing a model or rule
     * that already exists on the site. Provides options to overwrite,
     * create a duplicate, or skip the import.
     *
     * @since 1.4.0
     */
    var ImportConflictDialog = {
        $overlay: null,
        onResolveCallback: null,

        /**
         * Initialize the dialog overlay (lazy creation)
         */
        ensureOverlay: function() {
            if (this.$overlay) {
                return;
            }

            var html = '<div class="wptsall-import-overlay" id="wptsall-import-conflict-overlay">' +
                '<div class="wptsall-import-conflict-dialog">' +
                    '<div class="dialog-header">' +
                        '<h2 id="wptsall-conflict-dialog-title"></h2>' +
                        '<button type="button" class="dialog-close" aria-label="Close">&times;</button>' +
                    '</div>' +
                    '<div class="dialog-body" id="wptsall-conflict-dialog-body"></div>' +
                    '<div class="conflict-actions" id="wptsall-conflict-dialog-actions"></div>' +
                '</div>' +
            '</div>';

            $('body').append(html);
            this.$overlay = $('#wptsall-import-conflict-overlay');

            var self = this;

            // Close on overlay click
            this.$overlay.on('click', function(e) {
                if ($(e.target).is(self.$overlay)) {
                    self.dismiss();
                }
            });

            // Close button
            this.$overlay.find('.dialog-close').on('click', function() {
                self.dismiss();
            });

            // Escape key
            $(document).on('keydown.wptsallConflict', function(e) {
                if (e.key === 'Escape' && self.$overlay && self.$overlay.is(':visible')) {
                    self.dismiss();
                }
            });
        },

        /**
         * Show the conflict dialog
         *
         * @param {object}   conflictData - REST API conflict response
         * @param {string}   importType   - 'model' or 'rule'
         * @param {function} onResolve    - callback(action: 'overwrite'|'skip'|'duplicate')
         */
        show: function(conflictData, importType, onResolve) {
            this.ensureOverlay();
            this.onResolveCallback = onResolve;

            var isModel = importType === 'model';

            // Set title
            var title = isModel
                ? wptsallModelEditor.i18n.import_conflict_model || 'Import Conflict - Model Already Exists'
                : wptsallModelEditor.i18n.import_conflict_rule || 'Import Conflict - Rule Already Exists';
            $('#wptsall-conflict-dialog-title').text(title);

            // Build body content
            var bodyHtml = this.buildConflictInfo(conflictData, isModel);
            bodyHtml += this.buildDiffTable(conflictData, isModel);
            $('#wptsall-conflict-dialog-body').html(bodyHtml);

            // Build action buttons
            var actionsHtml = this.buildActionButtons();
            $('#wptsall-conflict-dialog-actions').html(actionsHtml);

            // Bind action button events
            var self = this;
            $('#wptsall-conflict-dialog-actions').off('click.conflict').on('click.conflict', '.button', function(e) {
                e.preventDefault();
                var action = $(this).data('action');
                if (action) {
                    self.resolve(action);
                }
            });

            // Show overlay
            this.$overlay.show();
        },

        /**
         * Build conflict info section
         *
         * @param {object}  data    - conflict data
         * @param {boolean} isModel - true for model, false for rule
         * @return {string} HTML
         */
        buildConflictInfo: function(data, isModel) {
            var html = '<div class="conflict-info">';

            if (isModel) {
                html += '<p><strong>' + (wptsallModelEditor.i18n.plugin_slug || 'Plugin Slug') + ':</strong> ' +
                    this.escapeHtml(data.plugin_slug || '') + '</p>';
                html += '<p><strong>' + (wptsallModelEditor.i18n.existing_id || 'Existing Model ID') + ':</strong> ' +
                    this.escapeHtml(String(data.existing_id || '')) + '</p>';
            } else {
                html += '<p><strong>' + (wptsallModelEditor.i18n.data_type || 'Data Type') + ':</strong> ' +
                    this.escapeHtml(data.data_type || '') + '</p>';
                html += '<p><strong>' + (wptsallModelEditor.i18n.object_name || 'Object Name') + ':</strong> ' +
                    this.escapeHtml(data.object_name || '') + '</p>';
                html += '<p><strong>' + (wptsallModelEditor.i18n.existing_id || 'Existing Rule ID') + ':</strong> ' +
                    this.escapeHtml(String(data.existing_id || '')) + '</p>';
            }

            html += '<p style="margin-top:8px; color:#996800;">' +
                (wptsallModelEditor.i18n.conflict_description || 'A matching item already exists. Choose how to proceed:') +
                '</p>';
            html += '</div>';

            return html;
        },

        /**
         * Build diff comparison table
         *
         * @param {object}  data    - conflict data with differences
         * @param {boolean} isModel - true for model, false for rule
         * @return {string} HTML
         */
        buildDiffTable: function(data, isModel) {
            var differences = data.differences || {};
            var html = '<table class="conflict-diff-table">';

            html += '<thead><tr>' +
                '<th>' + (wptsallModelEditor.i18n.field || 'Field') + '</th>' +
                '<th>' + (wptsallModelEditor.i18n.existing_value || 'Existing') + '</th>' +
                '<th>' + (wptsallModelEditor.i18n.imported_value || 'Imported') + '</th>' +
                '</tr></thead>';
            html += '<tbody>';

            if (isModel) {
                // Model diff: objects and fields
                var objects = differences.objects || {};
                html += '<tr>' +
                    '<td><strong>' + (wptsallModelEditor.i18n.objects_count || 'Objects') + '</strong></td>' +
                    '<td>' + (objects.existing || 0) + '</td>' +
                    '<td>' + (objects['import'] || 0) + '</td>' +
                    '</tr>';

                var fields = differences.fields || {};
                var fieldKeys = Object.keys(fields);
                if (fieldKeys.length > 0) {
                    for (var i = 0; i < fieldKeys.length; i++) {
                        var key = fieldKeys[i];
                        var diff = fields[key];
                        var statusClass = '';
                        var statusLabel = '';

                        if (diff.status === 'new') {
                            statusClass = 'diff-added';
                            statusLabel = ' [NEW]';
                        } else if (diff.status === 'removed') {
                            statusClass = 'diff-removed';
                            statusLabel = ' [REMOVED]';
                        } else {
                            statusClass = 'diff-changed';
                            statusLabel = ' [CHANGED]';
                        }

                        html += '<tr class="' + statusClass + '">' +
                            '<td>' + this.escapeHtml(key) + statusLabel + '</td>' +
                            '<td>' + (diff.existing_fields || 0) + ' fields</td>' +
                            '<td>' + (diff.import_fields || 0) + ' fields</td>' +
                            '</tr>';
                    }
                } else {
                    html += '<tr><td colspan="3" style="text-align:center; color:#646970;">' +
                        (wptsallModelEditor.i18n.no_field_differences || 'No field-level differences detected') +
                        '</td></tr>';
                }
            } else {
                // Rule diff: field counts and changes
                var fieldCount = differences.field_count || {};
                html += '<tr>' +
                    '<td><strong>' + (wptsallModelEditor.i18n.total_fields || 'Total Fields') + '</strong></td>' +
                    '<td>' + (fieldCount.existing || 0) + '</td>' +
                    '<td>' + (fieldCount['import'] || 0) + '</td>' +
                    '</tr>';

                var added = differences.added || [];
                if (added.length > 0) {
                    html += '<tr class="diff-added">' +
                        '<td><strong>' + (wptsallModelEditor.i18n.added_fields || 'Added Fields') + '</strong></td>' +
                        '<td>-</td>' +
                        '<td>' + this.escapeHtml(added.join(', ')) + '</td>' +
                        '</tr>';
                }

                var removed = differences.removed || [];
                if (removed.length > 0) {
                    html += '<tr class="diff-removed">' +
                        '<td><strong>' + (wptsallModelEditor.i18n.removed_fields || 'Removed Fields') + '</strong></td>' +
                        '<td>' + this.escapeHtml(removed.join(', ')) + '</td>' +
                        '<td>-</td>' +
                        '</tr>';
                }

                var typeChanges = differences.type_changes || {};
                var changeKeys = Object.keys(typeChanges);
                for (var j = 0; j < changeKeys.length; j++) {
                    var changeKey = changeKeys[j];
                    var change = typeChanges[changeKey];
                    html += '<tr class="diff-changed">' +
                        '<td>' + this.escapeHtml(changeKey) + '</td>' +
                        '<td>' + this.escapeHtml(change.existing || '') + '</td>' +
                        '<td>' + this.escapeHtml(change['import'] || '') + '</td>' +
                        '</tr>';
                }

                if (added.length === 0 && removed.length === 0 && changeKeys.length === 0) {
                    html += '<tr><td colspan="3" style="text-align:center; color:#646970;">' +
                        (wptsallModelEditor.i18n.no_field_differences || 'No field-level differences detected') +
                        '</td></tr>';
                }
            }

            html += '</tbody></table>';
            return html;
        },

        /**
         * Build action buttons
         *
         * @return {string} HTML
         */
        buildActionButtons: function() {
            return '<button type="button" class="button" data-action="skip">' +
                    (wptsallModelEditor.i18n.skip_import || 'Skip') +
                '</button>' +
                '<button type="button" class="button" data-action="duplicate">' +
                    (wptsallModelEditor.i18n.create_duplicate || 'Create Duplicate') +
                '</button>' +
                '<button type="button" class="button button-primary" data-action="overwrite">' +
                    (wptsallModelEditor.i18n.overwrite_existing || 'Overwrite Existing') +
                '</button>';
        },

        /**
         * Resolve conflict with chosen action
         *
         * @param {string} action - 'overwrite', 'skip', or 'duplicate'
         */
        resolve: function(action) {
            this.hide();
            if (typeof this.onResolveCallback === 'function') {
                this.onResolveCallback(action);
            }
        },

        /**
         * Dismiss without action (close button / escape / overlay click)
         */
        dismiss: function() {
            this.hide();
            // Dismissing is treated as skip
            if (typeof this.onResolveCallback === 'function') {
                this.onResolveCallback('skip');
            }
        },

        /**
         * Hide the overlay
         */
        hide: function() {
            if (this.$overlay) {
                this.$overlay.hide();
            }
        },

        /**
         * Escape HTML special characters
         *
         * @param {string} str
         * @return {string}
         */
        escapeHtml: function(str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    };

    /**
     * V3 Import Flow Handler
     *
     * Handles the two-step import process for v3.0 model and rule JSON files.
     * Step 1: Check for conflicts (action=check)
     * Step 2: Execute import with chosen action (overwrite/skip/duplicate)
     *
     * @since 1.4.0
     */
    var ImportFlowHandler = {

        /**
         * Import a model from a v3.0 JSON file
         *
         * @param {File} file - JSON file from file input
         */
        importModelV3: function(file) {
            if (!file) {
                return;
            }

            var self = this;
            var reader = new FileReader();

            reader.onload = function(e) {
                var jsonStr;
                try {
                    // Validate JSON
                    JSON.parse(e.target.result);
                    jsonStr = e.target.result;
                } catch (err) {
                    ModelEditor.showNotice(
                        (wptsallModelEditor.i18n.invalid_json || 'Invalid JSON file') + ': ' + err.message,
                        'error'
                    );
                    return;
                }

                self.executeModelImport(jsonStr, 'check');
            };

            reader.readAsText(file);
        },

        /**
         * Execute model import (step 1 or step 2)
         *
         * @param {string} jsonStr - JSON string
         * @param {string} action  - 'check', 'overwrite', 'skip', 'duplicate'
         */
        executeModelImport: function(jsonStr, action) {
            var self = this;

            wp.apiFetch({
                path: 'wptsall/v2/models/import-v3',
                method: 'POST',
                data: {
                    json: jsonStr,
                    action: action
                }
            }).then(function(response) {
                if (action === 'check') {
                    if (response.conflict) {
                        // Has conflict, show dialog
                        ImportConflictDialog.show(response, 'model', function(chosenAction) {
                            if (chosenAction === 'skip') {
                                ModelEditor.showNotice(
                                    wptsallModelEditor.i18n.import_skipped || 'Import skipped.',
                                    'info'
                                );
                            } else {
                                self.executeModelImport(jsonStr, chosenAction);
                            }
                        });
                    } else {
                        // No conflict, auto-execute overwrite
                        self.executeModelImport(jsonStr, 'overwrite');
                    }
                } else {
                    // Step 2 completed
                    self.showImportResult(response, 'model');
                }
            }).catch(function(error) {
                ModelEditor.showNotice(
                    (wptsallModelEditor.i18n.import_failed || 'Import failed') + ': ' + (error.message || 'Unknown error'),
                    'error'
                );
            });
        },

        /**
         * Import a rule from a v3.0 JSON file
         *
         * @param {File} file    - JSON file from file input
         * @param {int}  modelId - Target model ID
         */
        importRuleV3: function(file, modelId) {
            if (!file || !modelId) {
                return;
            }

            var self = this;
            var reader = new FileReader();

            reader.onload = function(e) {
                var jsonStr;
                try {
                    JSON.parse(e.target.result);
                    jsonStr = e.target.result;
                } catch (err) {
                    ModelEditor.showNotice(
                        (wptsallModelEditor.i18n.invalid_json || 'Invalid JSON file') + ': ' + err.message,
                        'error'
                    );
                    return;
                }

                self.executeRuleImport(jsonStr, modelId, 'check');
            };

            reader.readAsText(file);
        },

        /**
         * Execute rule import (step 1 or step 2)
         *
         * @param {string} jsonStr - JSON string
         * @param {int}    modelId - Target model ID
         * @param {string} action  - 'check', 'overwrite', 'skip', 'duplicate'
         */
        executeRuleImport: function(jsonStr, modelId, action) {
            var self = this;

            wp.apiFetch({
                path: 'wptsall/v2/rules/import-v3',
                method: 'POST',
                data: {
                    json: jsonStr,
                    model_id: modelId,
                    action: action
                }
            }).then(function(response) {
                if (action === 'check') {
                    if (response.conflict) {
                        ImportConflictDialog.show(response, 'rule', function(chosenAction) {
                            if (chosenAction === 'skip') {
                                ModelEditor.showNotice(
                                    wptsallModelEditor.i18n.import_skipped || 'Import skipped.',
                                    'info'
                                );
                            } else {
                                self.executeRuleImport(jsonStr, modelId, chosenAction);
                            }
                        });
                    } else {
                        // No conflict, auto-execute overwrite
                        self.executeRuleImport(jsonStr, modelId, 'overwrite');
                    }
                } else {
                    self.showImportResult(response, 'rule');
                }
            }).catch(function(error) {
                ModelEditor.showNotice(
                    (wptsallModelEditor.i18n.import_failed || 'Import failed') + ': ' + (error.message || 'Unknown error'),
                    'error'
                );
            });
        },

        /**
         * Show import result notice and reload if successful
         *
         * @param {object} response   - REST API response
         * @param {string} importType - 'model' or 'rule'
         */
        showImportResult: function(response, importType) {
            if (response.action === 'skipped') {
                ModelEditor.showNotice(response.message || 'Import skipped.', 'info');
                return;
            }

            if (response.success) {
                var msg = importType === 'model'
                    ? (wptsallModelEditor.i18n.model_imported || 'Model imported successfully!') +
                      ' (ID: ' + response.model_id + ')'
                    : (wptsallModelEditor.i18n.rule_imported || 'Rule imported successfully!') +
                      ' (ID: ' + response.rule_id + ')';

                ModelEditor.showNotice(msg, 'success');

                // Reload after a short delay to show the notice
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                ModelEditor.showNotice(
                    (wptsallModelEditor.i18n.import_failed || 'Import failed'),
                    'error'
                );
            }
        }
    };

    $(document).ready(function() {
        ModelEditor.init();

        // Delay moving notices to ensure execution after WordPress common.js
        // WordPress common.js also moves notices on document.ready
        setTimeout(function() {
            ModelEditor.moveNoticesToTop();
        }, 10);

        // H3: Import Model V3 button
        $('#wptsall-import-model-v3-btn').on('click', function(e) {
            e.preventDefault();
            $('#wptsall-import-model-v3-file').trigger('click');
        });

        $('#wptsall-import-model-v3-file').on('change', function() {
            if (this.files && this.files[0]) {
                ImportFlowHandler.importModelV3(this.files[0]);
                // Reset file input so the same file can be imported again
                $(this).val('');
            }
        });

        // H3: Import Rule V3 button
        $('#wptsall-import-rule-v3-btn').on('click', function(e) {
            e.preventDefault();
            $('#wptsall-import-rule-v3-file').trigger('click');
        });

        $('#wptsall-import-rule-v3-file').on('change', function() {
            if (this.files && this.files[0]) {
                var modelId = $(this).data('model-id');
                ImportFlowHandler.importRuleV3(this.files[0], modelId);
                $(this).val('');
            }
        });
    });

})(jQuery);
