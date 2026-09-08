/**
 * WPTSALL Object Editor (ISS-MOD-028)
 *
 * Handles CRUD operations on model_objects and model_object_fields
 * from the plugin template pages.
 *
 * Includes:
 * - G1: CascadingFieldSelector (inline panel replacing prompt() dialogs)
 * - G3: wptsallUrlParameterizer (auto-parameterize URL patterns)
 *
 * @since 1.0.3
 * @updated 1.0.5 G1+G3
 */
(function ($) {
    'use strict';

    // ========================================================================
    // G3: URL Auto-Parameterizer
    // ========================================================================

    /**
     * Utility to auto-parameterize URL patterns.
     *
     * Rules:
     * - Query string values that are purely numeric -> {id}
     * - Path segments that are purely numeric -> {id}
     * - /YYYY/MM/slug/ date-like patterns -> /{year}/{month}/{slug}/
     * - Already-parameterized {placeholder} segments are left untouched
     */
    var wptsallUrlParameterizer = {

        /**
         * Parameterize a URL string.
         *
         * @param {string} url The raw URL or URL pattern.
         * @return {string} The parameterized version (empty string if nothing changed).
         */
        parameterize: function (url) {
            if (!url || typeof url !== 'string') {
                return '';
            }

            var result = url;

            // Split into path and query parts.
            var qIdx = result.indexOf('?');
            var path = qIdx === -1 ? result : result.substring(0, qIdx);
            var query = qIdx === -1 ? '' : result.substring(qIdx);

            // --- Path processing ---
            // Date pattern: /YYYY/MM/slug/ (4-digit year, 2-digit month, then a non-numeric slug).
            path = path.replace(/\/(\d{4})\/(\d{2})\/([a-z0-9][a-z0-9_-]+)\//gi, function (match, y, m, s) {
                // Only replace if year looks valid (1900-2099) and month (01-12).
                var year = parseInt(y, 10);
                var month = parseInt(m, 10);
                if (year >= 1900 && year <= 2099 && month >= 1 && month <= 12) {
                    return '/{year}/{month}/{slug}/';
                }
                return match;
            });

            // Pure numeric path segments -> {id}  (but skip segments already containing {}).
            path = path.replace(/(\/)((\d+))(\/|$)/g, function (match, leadSlash, digits, _d, trailSlash) {
                return leadSlash + '{id}' + trailSlash;
            });

            // --- Query string processing ---
            if (query) {
                query = query.replace(/([?&])([^=&]+)=(\d+)(?=&|$)/g, function (match, sep, key, val) {
                    return sep + key + '={id}';
                });
            }

            var parameterized = path + query;

            // If nothing changed, return empty string (no suggestion needed).
            if (parameterized === url) {
                return '';
            }

            // If already fully parameterized (contains only existing placeholders), skip.
            return parameterized;
        },

        /**
         * Bind blur handler to URL input fields.
         * On blur, if the URL can be parameterized, offer a confirm dialog.
         *
         * @param {string} selector  jQuery selector for URL input fields.
         */
        bind: function (selector) {
            $(document).on('blur', selector, function () {
                var $input = $(this);
                var raw = $input.val();
                if (!raw) return;

                // Skip if value already contains {placeholders}.
                if (/\{[a-z_]+\}/.test(raw)) return;

                var suggestion = wptsallUrlParameterizer.parameterize(raw);
                if (suggestion && suggestion !== raw) {
                    if (confirm('Auto-parameterize URL?\n\nOriginal: ' + raw + '\nSuggested: ' + suggestion)) {
                        $input.val(suggestion);
                        $input.trigger('change');
                    }
                }
            });
        }
    };

    // Expose globally for other scripts.
    window.wptsallUrlParameterizer = wptsallUrlParameterizer;

    // ========================================================================
    // G1: Cascading Field Selector
    // ========================================================================

    /**
     * CascadingFieldSelector – inline multi-step panel for selecting fields
     * or objects via the Discovery API, replacing prompt() dialogs.
     *
     * Usage:
     *   CascadingFieldSelector.open({ mode: 'field', ... });
     *   CascadingFieldSelector.open({ mode: 'object', ... });
     */
    var CascadingFieldSelector = {

        /** Currently visible panel jQuery element. */
        $panel: null,

        /** Callback when user confirms. */
        _onConfirm: null,

        /** Current step (1-based). */
        _step: 0,

        /** Accumulated selections. */
        _selections: {},

        /** Open mode: 'field' or 'object'. */
        _mode: '',

        /** Reference model/object IDs. */
        _modelId: 0,
        _objectId: 0,
        _objectType: '',
        _objectName: '',

        /**
         * Open the selector panel beneath the trigger button.
         *
         * @param {Object} opts
         *   opts.mode        – 'field' | 'object'
         *   opts.modelId     – current model ID
         *   opts.objectId    – current object ID (field mode only)
         *   opts.objectType  – e.g. 'post_type' (field mode, optional, fetched if missing)
         *   opts.objectName  – e.g. 'post' (field mode, optional, fetched if missing)
         *   opts.$trigger    – the button that was clicked (panel appended after it)
         *   opts.onConfirm   – function(data) called with the finalized payload
         */
        open: function (opts) {
            var self = this;

            // Close any existing panel.
            self.close();

            self._mode = opts.mode || 'field';
            self._modelId = opts.modelId || 0;
            self._objectId = opts.objectId || 0;
            self._objectType = opts.objectType || '';
            self._objectName = opts.objectName || '';
            self._onConfirm = opts.onConfirm || function () {};
            self._selections = {};
            self._step = 0;

            // Create panel container.
            self.$panel = $('<div class="wptsall-cascade-panel"></div>');
            var $close = $('<button type="button" class="wptsall-cascade-close" title="Close">&times;</button>');
            $close.on('click', function () { self.close(); });
            self.$panel.append($close);

            // Insert after the trigger button.
            if (opts.$trigger && opts.$trigger.length) {
                opts.$trigger.after(self.$panel);
            } else {
                $('.wptsall-page-content').append(self.$panel);
            }

            if (self._mode === 'object') {
                self._startObjectFlow();
            } else {
                self._startFieldFlow();
            }
        },

        /** Close and remove the panel. */
        close: function () {
            if (this.$panel) {
                this.$panel.remove();
                this.$panel = null;
            }
            this._step = 0;
            this._selections = {};
        },

        // ---- Object Flow (addObject replacement) ----

        _startObjectFlow: function () {
            var self = this;
            self._step = 1;

            var $step = self._createStep('Step 1: Select Object Type');
            var types = [
                { value: 'post_type', label: 'Post Type' },
                { value: 'taxonomy', label: 'Taxonomy' },
                { value: 'option', label: 'Option' },
                { value: 'custom_table', label: 'Custom Table' }
            ];

            var $list = $('<div class="wptsall-cascade-options"></div>');
            types.forEach(function (t) {
                var $btn = $('<button type="button" class="button wptsall-cascade-option">' + t.label + '</button>');
                $btn.on('click', function () {
                    self._selections.objectType = t.value;
                    $list.find('.wptsall-cascade-option').removeClass('active');
                    $btn.addClass('active');
                    self._objectFlowStep2(t.value);
                });
                $list.append($btn);
            });

            $step.append($list);
            self.$panel.append($step);
        },

        _objectFlowStep2: function (objectType) {
            var self = this;
            self._step = 2;

            // Remove any existing step 2+.
            self.$panel.find('.wptsall-cascade-step[data-step="2"], .wptsall-cascade-step[data-step="3"]').remove();

            var $step = self._createStep('Step 2: Select Object Name');

            if (objectType === 'custom_table') {
                // Fetch tables from discovery API.
                var $loading = $('<p class="wptsall-cascade-loading"><span class="wptsall-spinner"></span> Loading tables...</p>');
                $step.append($loading);
                self.$panel.append($step);

                wp.apiFetch({ path: 'wptsall/v2/discovery/tables' }).then(function (tables) {
                    $loading.remove();
                    if (!tables || !tables.length) {
                        $step.append('<p>No custom tables found.</p>');
                        return;
                    }
                    var $list = $('<div class="wptsall-cascade-options wptsall-cascade-options-scroll"></div>');
                    tables.forEach(function (tbl) {
                        var name = typeof tbl === 'string' ? tbl : (tbl.name || tbl.table_name || '');
                        if (!name) return;
                        var $btn = $('<button type="button" class="button wptsall-cascade-option wptsall-cascade-option-sm">' + name + '</button>');
                        $btn.on('click', function () {
                            self._selections.objectName = name;
                            $list.find('.wptsall-cascade-option').removeClass('active');
                            $btn.addClass('active');
                            self._objectFlowConfirm();
                        });
                        $list.append($btn);
                    });
                    $step.append($list);
                    self._appendManualInput($step, function (val) {
                        self._selections.objectName = val;
                        self._objectFlowConfirm();
                    });
                }).catch(function () {
                    $loading.remove();
                    self._appendManualInput($step, function (val) {
                        self._selections.objectName = val;
                        self._objectFlowConfirm();
                    });
                });
            } else if (objectType === 'option') {
                self.$panel.append($step);
                self._appendManualInput($step, function (val) {
                    self._selections.objectName = val;
                    self._objectFlowConfirm();
                });
                return;
            } else {
                // For post_type/taxonomy, fetch registered types from WP REST.
                var apiPath = objectType === 'post_type' ? 'wp/v2/types' : 'wp/v2/taxonomies';
                var $loading2 = $('<p class="wptsall-cascade-loading"><span class="wptsall-spinner"></span> Loading...</p>');
                $step.append($loading2);
                self.$panel.append($step);

                wp.apiFetch({ path: apiPath }).then(function (items) {
                    $loading2.remove();
                    var $list = $('<div class="wptsall-cascade-options wptsall-cascade-options-scroll"></div>');
                    var keys = Object.keys(items);
                    keys.forEach(function (slug) {
                        var item = items[slug];
                        var label = (item.name || item.label || slug) + ' (' + slug + ')';
                        var $btn = $('<button type="button" class="button wptsall-cascade-option wptsall-cascade-option-sm"></button>');
                        $btn.text(label);
                        $btn.on('click', function () {
                            self._selections.objectName = slug;
                            $list.find('.wptsall-cascade-option').removeClass('active');
                            $btn.addClass('active');
                            self._objectFlowConfirm();
                        });
                        $list.append($btn);
                    });
                    $step.append($list);
                    self._appendManualInput($step, function (val) {
                        self._selections.objectName = val;
                        self._objectFlowConfirm();
                    });
                }).catch(function () {
                    $loading2.remove();
                    self._appendManualInput($step, function (val) {
                        self._selections.objectName = val;
                        self._objectFlowConfirm();
                    });
                });

                return; // panel already appended inside the promise path
            }

            self.$panel.append($step);
        },

        _objectFlowConfirm: function () {
            var self = this;
            self._step = 3;

            self.$panel.find('.wptsall-cascade-step[data-step="3"]').remove();

            var $step = self._createStep('Step 3: Confirm');
            var s = self._selections;

            var $summary = $('<div class="wptsall-cascade-summary">' +
                '<p><strong>Type:</strong> ' + self._esc(s.objectType) + '</p>' +
                '<p><strong>Name:</strong> ' + self._esc(s.objectName) + '</p>' +
                '</div>');
            $step.append($summary);

            var $actions = $('<div class="wptsall-cascade-actions"></div>');
            var $back = $('<button type="button" class="button">Back</button>');
            $back.on('click', function () {
                self._objectFlowStep2(s.objectType);
            });
            var $confirm = $('<button type="button" class="button button-primary">Add Object</button>');
            $confirm.on('click', function () {
                self._onConfirm({
                    object_type: s.objectType,
                    object_name: s.objectName
                });
                self.close();
            });
            $actions.append($back).append($confirm);
            $step.append($actions);
            self.$panel.append($step);
        },

        // ---- Field Flow (addField replacement) ----

        _startFieldFlow: function () {
            var self = this;

            // If we don't have objectType/objectName, fetch from API.
            if (self._objectId && (!self._objectType || !self._objectName)) {
                var $loading = $('<p class="wptsall-cascade-loading"><span class="wptsall-spinner"></span> Loading object info...</p>');
                self.$panel.append($loading);

                wp.apiFetch({
                    path: 'wptsall/v2/models/' + self._modelId + '/objects'
                }).then(function (objects) {
                    $loading.remove();
                    var obj = null;
                    if (Array.isArray(objects)) {
                        for (var i = 0; i < objects.length; i++) {
                            if (parseInt(objects[i].id, 10) === parseInt(self._objectId, 10)) {
                                obj = objects[i];
                                break;
                            }
                        }
                    }
                    if (obj) {
                        self._objectType = obj.object_type || '';
                        self._objectName = obj.object_name || '';
                    }
                    self._fieldFlowStep1();
                }).catch(function () {
                    $loading.remove();
                    self._fieldFlowStep1();
                });
            } else {
                self._fieldFlowStep1();
            }
        },

        _fieldFlowStep1: function () {
            var self = this;
            self._step = 1;

            var $step = self._createStep('Step 1: Select Field Source');

            var sources;
            if (self._objectType === 'custom_table') {
                sources = [
                    { value: 'column', label: 'Table Column', desc: 'Select from table columns' }
                ];
            } else {
                sources = [
                    { value: 'core', label: 'Core Field', desc: 'WordPress built-in fields (post_title, etc.)' },
                    { value: 'meta', label: 'Meta Field', desc: 'Custom fields / post meta' }
                ];
            }

            var $list = $('<div class="wptsall-cascade-options"></div>');
            sources.forEach(function (s) {
                var $btn = $('<button type="button" class="button wptsall-cascade-option"></button>');
                $btn.html('<strong>' + s.label + '</strong><br><small>' + s.desc + '</small>');
                $btn.on('click', function () {
                    self._selections.fieldKind = s.value;
                    $list.find('.wptsall-cascade-option').removeClass('active');
                    $btn.addClass('active');
                    self._fieldFlowStep2(s.value);
                });
                $list.append($btn);
            });

            $step.append($list);
            self.$panel.append($step);
        },

        _fieldFlowStep2: function (fieldKind) {
            var self = this;
            self._step = 2;

            self.$panel.find('.wptsall-cascade-step[data-step="2"], .wptsall-cascade-step[data-step="3"]').remove();

            var $step = self._createStep('Step 2: Select Field');
            var $loading = $('<p class="wptsall-cascade-loading"><span class="wptsall-spinner"></span> Loading fields...</p>');
            $step.append($loading);
            self.$panel.append($step);

            if (fieldKind === 'core') {
                // Show known core fields for the object type.
                $loading.remove();
                var coreFields = self._getCoreFields(self._objectType);
                self._renderFieldOptions($step, coreFields, fieldKind);
            } else if (fieldKind === 'meta') {
                // Fetch meta keys from discovery API.
                var params = {};
                if (self._objectName) {
                    params.post_type = self._objectName;
                }
                params.include_hidden = true;

                wp.apiFetch({
                    path: 'wptsall/v2/discovery/meta-keys?' + $.param(params)
                }).then(function (keys) {
                    $loading.remove();
                    var fields = [];
                    if (Array.isArray(keys)) {
                        keys.forEach(function (k) {
                            var name = typeof k === 'string' ? k : (k.meta_key || k.name || '');
                            if (name) {
                                fields.push({ name: name, count: k.count || 0 });
                            }
                        });
                    }
                    self._renderFieldOptions($step, fields, fieldKind);
                }).catch(function () {
                    $loading.remove();
                    self._appendManualInput($step, function (val) {
                        self._selections.fieldKey = val;
                        self._fieldFlowStep3(fieldKind, val);
                    });
                });
            } else if (fieldKind === 'column') {
                // Fetch table columns from discovery API.
                var tableName = self._objectName || '';
                if (!tableName) {
                    $loading.remove();
                    self._appendManualInput($step, function (val) {
                        self._selections.fieldKey = val;
                        self._fieldFlowStep3(fieldKind, val);
                    });
                    return;
                }

                wp.apiFetch({
                    path: 'wptsall/v2/discovery/columns?table=' + encodeURIComponent(tableName)
                }).then(function (columns) {
                    $loading.remove();
                    var fields = [];
                    if (Array.isArray(columns)) {
                        columns.forEach(function (col) {
                            var name = typeof col === 'string' ? col : (col.Field || col.column_name || col.name || '');
                            var type = typeof col === 'object' ? (col.Type || col.data_type || '') : '';
                            if (name) {
                                fields.push({ name: name, type: type });
                            }
                        });
                    }
                    self._renderFieldOptions($step, fields, fieldKind);
                }).catch(function () {
                    $loading.remove();
                    self._appendManualInput($step, function (val) {
                        self._selections.fieldKey = val;
                        self._fieldFlowStep3(fieldKind, val);
                    });
                });
            }
        },

        _renderFieldOptions: function ($step, fields, fieldKind) {
            var self = this;
            if (!fields || !fields.length) {
                $step.append('<p>No fields discovered.</p>');
                self._appendManualInput($step, function (val) {
                    self._selections.fieldKey = val;
                    self._fieldFlowStep3(fieldKind, val);
                });
                return;
            }

            var $filter = $('<input type="text" class="wptsall-cascade-filter" placeholder="Type to filter..." />');
            $step.append($filter);

            var $list = $('<div class="wptsall-cascade-options wptsall-cascade-options-scroll"></div>');
            fields.forEach(function (f) {
                var name = typeof f === 'string' ? f : f.name;
                var extra = '';
                if (f.type) extra = ' <code>' + self._esc(f.type) + '</code>';
                if (f.count) extra = ' <span class="wptsall-cascade-count">(' + f.count + ')</span>';

                var $btn = $('<button type="button" class="button wptsall-cascade-option wptsall-cascade-option-sm"></button>');
                $btn.html('<code>' + self._esc(name) + '</code>' + extra);
                $btn.attr('data-field-name', name);
                $btn.on('click', function () {
                    self._selections.fieldKey = name;
                    $list.find('.wptsall-cascade-option').removeClass('active');
                    $btn.addClass('active');
                    self._fieldFlowStep3(fieldKind, name);
                });
                $list.append($btn);
            });

            $step.append($list);

            // Inline filter.
            $filter.on('input', function () {
                var q = $(this).val().toLowerCase();
                $list.find('.wptsall-cascade-option').each(function () {
                    var fname = ($(this).attr('data-field-name') || '').toLowerCase();
                    $(this).toggle(fname.indexOf(q) !== -1);
                });
            });

            self._appendManualInput($step, function (val) {
                self._selections.fieldKey = val;
                self._fieldFlowStep3(fieldKind, val);
            });
        },

        _fieldFlowStep3: function (fieldKind, fieldKey) {
            var self = this;
            self._step = 3;

            self.$panel.find('.wptsall-cascade-step[data-step="3"]').remove();

            var $step = self._createStep('Step 3: Preview & Confirm');

            // Try to fetch sample values.
            var tableName = '';
            var columnName = fieldKey;

            if (fieldKind === 'meta') {
                tableName = 'postmeta';
                columnName = 'meta_value';
            } else if (fieldKind === 'column') {
                tableName = self._objectName || '';
            } else if (fieldKind === 'core') {
                tableName = 'posts';
                columnName = fieldKey;
            }

            var $summary = $('<div class="wptsall-cascade-summary">' +
                '<p><strong>Field Kind:</strong> ' + self._esc(fieldKind) + '</p>' +
                '<p><strong>Field Key:</strong> <code>' + self._esc(fieldKey) + '</code></p>' +
                '</div>');
            $step.append($summary);

            // Load sample values if table is known.
            if (tableName) {
                var $sampleBox = $('<div class="wptsall-cascade-sample"><p class="wptsall-cascade-loading"><span class="wptsall-spinner"></span> Loading sample values...</p></div>');
                $step.append($sampleBox);

                wp.apiFetch({
                    path: 'wptsall/v2/discovery/values?table=' + encodeURIComponent(tableName) + '&column=' + encodeURIComponent(columnName) + '&limit=5'
                }).then(function (values) {
                    var html = '<p><strong>Sample values:</strong></p><ul class="wptsall-cascade-sample-list">';
                    if (Array.isArray(values) && values.length) {
                        values.forEach(function (v) {
                            var text = typeof v === 'object' ? (v.value || JSON.stringify(v)) : String(v);
                            if (text.length > 80) text = text.substring(0, 80) + '...';
                            html += '<li><code>' + self._esc(text) + '</code></li>';
                        });
                    } else {
                        html += '<li><em>No sample data available</em></li>';
                    }
                    html += '</ul>';
                    $sampleBox.html(html);
                }).catch(function () {
                    $sampleBox.html('<p><em>Could not load sample values</em></p>');
                });
            }

            var $actions = $('<div class="wptsall-cascade-actions"></div>');
            var $back = $('<button type="button" class="button">Back</button>');
            $back.on('click', function () {
                self._fieldFlowStep2(self._selections.fieldKind);
            });
            var $confirm = $('<button type="button" class="button button-primary">Add Field</button>');
            $confirm.on('click', function () {
                self._onConfirm({
                    field_kind: fieldKind,
                    field_key: fieldKey,
                    source: 'manual'
                });
                self.close();
            });
            $actions.append($back).append($confirm);
            $step.append($actions);
            self.$panel.append($step);
        },

        // ---- Helpers ----

        _createStep: function (title) {
            this._step = this._step || 1;
            var $step = $('<div class="wptsall-cascade-step" data-step="' + this._step + '"></div>');
            $step.append('<h4 class="wptsall-cascade-step-title">' + title + '</h4>');
            return $step;
        },

        _appendManualInput: function ($container, onSubmit) {
            var $wrap = $('<div class="wptsall-cascade-manual"></div>');
            var $input = $('<input type="text" class="regular-text wptsall-cascade-manual-input" placeholder="Or type a name manually..." />');
            var $btn = $('<button type="button" class="button button-small">Use</button>');
            $btn.on('click', function () {
                var val = $.trim($input.val());
                if (val) onSubmit(val);
            });
            $input.on('keypress', function (e) {
                if (e.which === 13) {
                    e.preventDefault();
                    var val = $.trim($input.val());
                    if (val) onSubmit(val);
                }
            });
            $wrap.append($input).append($btn);
            $container.append($wrap);
        },

        _getCoreFields: function (objectType) {
            if (objectType === 'taxonomy') {
                return [
                    { name: 'name' },
                    { name: 'slug' },
                    { name: 'description' },
                    { name: 'parent' },
                    { name: 'count' }
                ];
            }
            // Default: post_type core fields.
            return [
                { name: 'post_title' },
                { name: 'post_content' },
                { name: 'post_excerpt' },
                { name: 'post_name' },
                { name: 'post_status' },
                { name: 'post_date' },
                { name: 'post_author' },
                { name: 'post_parent' },
                { name: 'post_mime_type' },
                { name: 'menu_order' },
                { name: 'comment_status' },
                { name: 'ping_status' },
                { name: 'guid' }
            ];
        },

        _esc: function (str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    };

    // Expose globally.
    window.CascadingFieldSelector = CascadingFieldSelector;

    // ========================================================================
    // G5: Validation Panel
    // ========================================================================

    /**
     * ValidationPanel – renders validation results (errors, warnings, pass)
     * returned from save/update API calls into the #wptsall-validation-panel
     * container injected by class-model-editor-page.php.
     */
    var ValidationPanel = {
        render: function (result) {
            var $panel = $('#wptsall-validation-panel');
            if (!$panel.length) return;

            var $status = $panel.find('.validation-status');
            var $details = $panel.find('.validation-details');
            $status.empty();
            $details.empty();

            if (result.errors && result.errors.length > 0) {
                $status.html(
                    '<div class="validation-error">' +
                    '<span class="dashicons dashicons-dismiss"></span> ' +
                    result.errors.length + ' error(s)' +
                    '</div>'
                );
                result.errors.forEach(function (err) {
                    $details.append(
                        '<div class="validation-item error">' +
                        '<strong>' + (err.field || '') + '</strong>: ' + (err.message || '') +
                        '</div>'
                    );
                });
            }

            if (result.warnings && result.warnings.length > 0) {
                $status.append(
                    '<div class="validation-warning">' +
                    '<span class="dashicons dashicons-warning"></span> ' +
                    result.warnings.length + ' warning(s)' +
                    '</div>'
                );
                result.warnings.forEach(function (warn) {
                    $details.append(
                        '<div class="validation-item warning">' +
                        '<strong>' + (warn.field || '') + '</strong>: ' + (warn.message || '') +
                        '</div>'
                    );
                });
            }

            if ((!result.errors || result.errors.length === 0) && (!result.warnings || result.warnings.length === 0)) {
                $status.html(
                    '<div class="validation-pass">' +
                    '<span class="dashicons dashicons-yes-alt"></span> All checks passed' +
                    '</div>'
                );
            }

            $panel.show();

            // Scroll panel into view.
            $('html, body').animate({
                scrollTop: $panel.offset().top - 50
            }, 300);
        }
    };

    // Expose globally for field-configurator.js and other scripts.
    window.ValidationPanel = ValidationPanel;

    // ========================================================================
    // Object Editor
    // ========================================================================

    var ObjectEditor = {
        config: window.wptsallObjectEditor || {},

        init: function () {
            var $content = $('.wptsall-page-content');
            this.modelId = $content.data('model-id');
            this.objectId = $content.data('object-id');

            if (!this.modelId) return;

            this.bindEvents();

            // G3: Bind URL parameterizer to URL pattern inputs.
            wptsallUrlParameterizer.bind('.wptsall-url-input, input[name="url_pattern"], input[name="url_signature"]');

            // G6: Check for new fields and show highlight banner.
            this.checkNewFields();
        },

        bindEvents: function () {
            var self = this;

            // Delete object button (objects list page).
            $(document).on('click', '.wptsall-delete-object-btn', function () {
                var objectId = $(this).data('object-id');
                self.deleteObject(objectId, $(this).closest('tr'));
            });

            // Add object button (objects list page).
            $('#wptsall-add-object-btn').on('click', function () {
                self.addObjectDialog($(this));
            });

            // Delete field button (object detail page).
            $(document).on('click', '.wptsall-delete-field-btn', function () {
                var fieldId = $(this).data('field-id');
                self.deleteField(fieldId, $(this).closest('tr'));
            });

            // Add field button (object detail page).
            $('#wptsall-add-field-btn').on('click', function () {
                self.addFieldDialog($(this));
            });

            // Inline edit: field_kind change.
            $(document).on('change', '.wptsall-field-kind-select', function () {
                var fieldId = $(this).data('field-id');
                self.updateField(fieldId, { field_kind: $(this).val() }, $(this));
            });

            // Inline edit: source change.
            $(document).on('change', '.wptsall-field-source-select', function () {
                var fieldId = $(this).data('field-id');
                self.updateField(fieldId, { source: $(this).val() }, $(this));
            });
        },

        // ---- Objects CRUD ----

        deleteObject: function (objectId, $row) {
            var self = this;
            if (!confirm(self.config.i18n.confirm_delete_object)) return;

            wp.apiFetch({
                path: 'wptsall/v2/models/' + self.modelId + '/objects/' + objectId,
                method: 'DELETE'
            }).then(function () {
                $row.fadeOut(function () {
                    $(this).remove();
                    self.updateObjectCount();
                });
            }).catch(function (err) {
                alert(self.config.i18n.error + ': ' + (err.message || ''));
            });
        },

        /**
         * G1: Replaced prompt()-based addObjectDialog with CascadingFieldSelector.
         */
        addObjectDialog: function ($trigger) {
            var self = this;

            CascadingFieldSelector.open({
                mode: 'object',
                modelId: self.modelId,
                $trigger: $trigger,
                onConfirm: function (data) {
                    wp.apiFetch({
                        path: 'wptsall/v2/models/' + self.modelId + '/objects',
                        method: 'POST',
                        data: data
                    }).then(function () {
                        location.reload();
                    }).catch(function (err) {
                        alert(self.config.i18n.error + ': ' + (err.message || ''));
                    });
                }
            });
        },

        updateObjectCount: function () {
            var count = $('#wptsall-objects-table tbody tr:visible').length;
            $('#wptsall-object-count').text(count);
            if (count === 0) {
                $('#wptsall-objects-table').hide();
                if (!$('#wptsall-objects-empty').length) {
                    $('#wptsall-objects-table').before('<div class="wptsall-no-models" id="wptsall-objects-empty"><p>' +
                        'No objects.' + '</p></div>');
                }
                $('#wptsall-objects-empty').show();
            }
        },

        // ---- Fields CRUD ----

        deleteField: function (fieldId, $row) {
            var self = this;
            if (!confirm(self.config.i18n.confirm_delete_field)) return;

            wp.apiFetch({
                path: 'wptsall/v2/models/' + self.modelId + '/objects/' + self.objectId + '/fields/' + fieldId,
                method: 'DELETE'
            }).then(function () {
                $row.fadeOut(function () {
                    $(this).remove();
                    self.updateFieldCount();
                });
            }).catch(function (err) {
                alert(self.config.i18n.error + ': ' + (err.message || ''));
            });
        },

        /**
         * G1: Replaced prompt()-based addFieldDialog with CascadingFieldSelector.
         */
        addFieldDialog: function ($trigger) {
            var self = this;

            CascadingFieldSelector.open({
                mode: 'field',
                modelId: self.modelId,
                objectId: self.objectId,
                $trigger: $trigger,
                onConfirm: function (fieldData) {
                    wp.apiFetch({
                        path: 'wptsall/v2/models/' + self.modelId + '/objects/' + self.objectId + '/fields',
                        method: 'POST',
                        data: fieldData
                    }).then(function () {
                        location.reload();
                    }).catch(function (err) {
                        alert(self.config.i18n.error + ': ' + (err.message || ''));
                    });
                }
            });
        },

        updateField: function (fieldId, data, $el) {
            var self = this;
            var $row = $el.closest('tr');

            $el.css('opacity', '0.5');

            wp.apiFetch({
                path: 'wptsall/v2/models/' + self.modelId + '/objects/' + self.objectId + '/fields/' + fieldId,
                method: 'PUT',
                data: data
            }).then(function () {
                $el.css('opacity', '1');
                // Brief flash to confirm save.
                $row.css('background-color', '#e6f7e6');
                setTimeout(function () {
                    $row.css('background-color', '');
                }, 800);
            }).catch(function (err) {
                $el.css('opacity', '1');
                alert(self.config.i18n.error + ': ' + (err.message || ''));
            });
        },

        updateFieldCount: function () {
            var count = $('#wptsall-fields-table tbody tr:visible').length;
            $('#wptsall-field-count').text(count);
            if (count === 0) {
                $('#wptsall-fields-table').hide();
                if (!$('#wptsall-fields-empty').length) {
                    $('#wptsall-fields-table').before('<div class="wptsall-no-models" id="wptsall-fields-empty"><p>' +
                        'No fields.' + '</p></div>');
                }
                $('#wptsall-fields-empty').show();
            }
        },

        /**
         * G6: Check for new (unconfirmed) fields and highlight them.
         *
         * Scans the fields table for rows with data-status="new" and:
         * - Adds a banner above the table with the count
         * - Click on banner scrolls to the first new field
         * - Applies flash animation on first load
         */
        checkNewFields: function () {
            var $table = $('#wptsall-fields-table');
            if (!$table.length) return;

            var $newRows = $table.find('tr[data-status="new"]');
            var newFieldCount = $newRows.length;

            if (newFieldCount === 0) return;

            // Remove existing banner if any.
            $table.prev('.wptsall-new-fields-banner').remove();

            var bannerText = newFieldCount === 1
                ? '1 new field discovered since last scan. Review and confirm below.'
                : newFieldCount + ' new fields discovered since last scan. Review and confirm below.';

            var $banner = $('<div class="wptsall-new-fields-banner">' +
                '<span class="dashicons dashicons-info-outline" style="color:#f0b849; margin-right:6px;"></span>' +
                '<strong>' + bannerText + '</strong>' +
                '</div>');

            // Click scrolls to first new field.
            $banner.css('cursor', 'pointer');
            $banner.on('click', function () {
                var $first = $newRows.first();
                if ($first.length) {
                    $('html, body').animate({
                        scrollTop: $first.offset().top - 100
                    }, 400);
                    $first.addClass('flash-highlight');
                }
            });

            $table.before($banner);

            // Flash animation on new rows.
            $newRows.addClass('flash-highlight');
        }
    };

    $(document).ready(function () {
        ObjectEditor.init();
    });

})(jQuery);
