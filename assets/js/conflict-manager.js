/**
 * WPTSALL Conflict Manager
 *
 * Conflict management page frontend interaction
 *
 * @package WPTSALL
 * @since 0.9.0
 */

(function($) {
    'use strict';

    const ConflictManager = {
        // State
        currentPage: 1,
        perPage: 20,
        statusFilter: '',
        currentConflict: null,

        // Config
        restUrl: wptsallConflicts.restUrl || '/wp-json/wptsall/v2',
        restNonce: wptsallConflicts.restNonce || '',
        i18n: wptsallConflicts.i18n || {},
        strategies: wptsallConflicts.strategies || {},

        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.loadStats();
            this.loadConflicts();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            const self = this;

            // Filter change
            $('#conflict-status-filter').on('change', function() {
                self.statusFilter = $(this).val();
                self.currentPage = 1;
                self.loadConflicts();
            });

            // Refresh button
            $('#btn-refresh').on('click', function() {
                self.loadStats();
                self.loadConflicts();
            });

            // Auto resolve button
            $('#btn-auto-resolve').on('click', function() {
                self.autoResolveAll();
            });

            // Modal close
            $('.wptsall-modal-close, .wptsall-modal-cancel, .wptsall-modal-overlay').on('click', function() {
                self.closeModal();
            });

            // Strategy change - show/hide manual select column
            $('input[name="resolve_strategy"]').on('change', function() {
                const isManual = $(this).val() === 'manual';
                $('.manual-select-col').toggle(isManual);
                $('input[name="manual_field_select"]').prop('disabled', !isManual);
            });

            // Confirm resolve
            $('#btn-confirm-resolve').on('click', function() {
                self.resolveConflict();
            });

            // Pagination clicks (delegated)
            $(document).on('click', '.wptsall-conflict-pagination a', function(e) {
                e.preventDefault();
                const page = $(this).data('page');
                if (page) {
                    self.currentPage = parseInt(page, 10);
                    self.loadConflicts();
                }
            });

            // Resolve button clicks (delegated)
            $(document).on('click', '.btn-resolve-conflict', function() {
                const conflictId = $(this).data('id');
                self.openResolveModal(conflictId);
            });

            // View detail clicks (delegated)
            $(document).on('click', '.btn-view-conflict', function() {
                const conflictId = $(this).data('id');
                self.openResolveModal(conflictId);
            });
        },

        /**
         * Make API request
         */
        apiRequest: function(endpoint, options) {
            options = options || {};
            const url = this.restUrl + endpoint;

            const config = {
                url: url,
                method: options.method || 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': this.restNonce
                },
                dataType: 'json'
            };

            if (options.data) {
                if (config.method === 'GET') {
                    config.url += '?' + $.param(options.data);
                } else {
                    config.data = JSON.stringify(options.data);
                }
            }

            return $.ajax(config);
        },

        /**
         * Load conflict statistics
         */
        loadStats: function() {
            const self = this;
            const $container = $('#conflict-stats');

            $container.html('<div class="stats-loading">' + this.i18n.loading + '</div>');

            this.apiRequest('/conflicts/stats')
                .done(function(response) {
                    if (response.success && response.stats) {
                        self.renderStats(response.stats);
                    }
                })
                .fail(function() {
                    $container.html('<div class="stats-error">' + self.i18n.error + '</div>');
                });
        },

        /**
         * Render statistics
         */
        renderStats: function(stats) {
            const pending = stats.pending || 0;
            const resolved = stats.resolved || 0;
            const total = stats.total || (pending + resolved);

            const html = `
                <div class="stat-card stat-total">
                    <div class="stat-number">${total}</div>
                    <div class="stat-label">${this.escapeHtml('Total Conflicts')}</div>
                </div>
                <div class="stat-card stat-pending">
                    <div class="stat-number">${pending}</div>
                    <div class="stat-label">${this.escapeHtml('Pending')}</div>
                </div>
                <div class="stat-card stat-resolved">
                    <div class="stat-number">${resolved}</div>
                    <div class="stat-label">${this.escapeHtml('Resolved')}</div>
                </div>
            `;

            $('#conflict-stats').html(html);
        },

        /**
         * Load conflicts list
         */
        loadConflicts: function() {
            const self = this;
            const $container = $('#conflict-list');

            $container.html('<div class="list-loading">' + this.i18n.loading + '</div>');

            const params = {
                page: this.currentPage,
                per_page: this.perPage
            };

            if (this.statusFilter) {
                params.status = this.statusFilter;
            }

            this.apiRequest('/conflicts', { data: params })
                .done(function(response) {
                    if (response.items) {
                        self.renderConflicts(response.items);
                        self.renderPagination(response.total, response.pages, response.page);
                    } else {
                        $container.html('<div class="no-conflicts">' + self.i18n.no_conflicts + '</div>');
                    }
                })
                .fail(function() {
                    $container.html('<div class="list-error">' + self.i18n.error + '</div>');
                });
        },

        /**
         * Render conflicts table
         */
        renderConflicts: function(conflicts) {
            if (!conflicts || conflicts.length === 0) {
                $('#conflict-list').html('<div class="no-conflicts">' + this.i18n.no_conflicts + '</div>');
                return;
            }

            let html = `
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th class="column-id">ID</th>
                            <th class="column-type">${this.escapeHtml('Object Type')}</th>
                            <th class="column-conflict-type">${this.escapeHtml('Conflict Type')}</th>
                            <th class="column-source">${this.escapeHtml('Source Site')}</th>
                            <th class="column-target">${this.escapeHtml('Target Site')}</th>
                            <th class="column-status">${this.escapeHtml('Status')}</th>
                            <th class="column-detected">${this.escapeHtml('Detection Time')}</th>
                            <th class="column-actions">${this.escapeHtml('Actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            conflicts.forEach(function(conflict) {
                const statusClass = conflict.status === 'pending' ? 'status-pending' : 'status-resolved';
                const statusText = conflict.status === 'pending' ? 'Pending' : 'Resolved';
                const detectedAt = conflict.detected_at ? new Date(conflict.detected_at).toLocaleString() : '-';

                html += `
                    <tr data-id="${conflict.id}">
                        <td class="column-id">${conflict.id}</td>
                        <td class="column-type">
                            <span class="object-type">${this.escapeHtml(conflict.object_type || '-')}</span>
                            <span class="object-subtype">${this.escapeHtml(conflict.subtype || '')}</span>
                        </td>
                        <td class="column-conflict-type">${this.escapeHtml(conflict.type || '-')}</td>
                        <td class="column-source">
                            <span class="site-id">Site ${conflict.source_blog_id}</span>
                            <span class="object-id">#${conflict.source_id}</span>
                        </td>
                        <td class="column-target">
                            <span class="site-id">Site ${conflict.target_blog_id}</span>
                            <span class="object-id">#${conflict.target_id}</span>
                        </td>
                        <td class="column-status">
                            <span class="conflict-status ${statusClass}">${this.escapeHtml(statusText)}</span>
                        </td>
                        <td class="column-detected">${this.escapeHtml(detectedAt)}</td>
                        <td class="column-actions">
                            ${conflict.status === 'pending' ?
                                `<button type="button" class="button button-small btn-resolve-conflict" data-id="${conflict.id}">
                                    ${this.escapeHtml('Resolve')}
                                </button>` :
                                `<button type="button" class="button button-small btn-view-conflict" data-id="${conflict.id}">
                                    ${this.escapeHtml('View')}
                                </button>`
                            }
                        </td>
                    </tr>
                `;
            }.bind(this));

            html += '</tbody></table>';

            $('#conflict-list').html(html);
        },

        /**
         * Render pagination
         */
        renderPagination: function(total, pages, currentPage) {
            if (pages <= 1) {
                $('#conflict-pagination').html('');
                return;
            }

            let html = '<div class="tablenav-pages">';
            html += `<span class="displaying-num">${total} items</span>`;
            html += '<span class="pagination-links">';

            // First page
            if (currentPage > 1) {
                html += `<a class="first-page button" href="#" data-page="1">&laquo;</a>`;
                html += `<a class="prev-page button" href="#" data-page="${currentPage - 1}">&lsaquo;</a>`;
            } else {
                html += '<span class="tablenav-pages-navspan button disabled">&laquo;</span>';
                html += '<span class="tablenav-pages-navspan button disabled">&lsaquo;</span>';
            }

            html += `<span class="paging-input">${currentPage} / ${pages}</span>`;

            // Last page
            if (currentPage < pages) {
                html += `<a class="next-page button" href="#" data-page="${currentPage + 1}">&rsaquo;</a>`;
                html += `<a class="last-page button" href="#" data-page="${pages}">&raquo;</a>`;
            } else {
                html += '<span class="tablenav-pages-navspan button disabled">&rsaquo;</span>';
                html += '<span class="tablenav-pages-navspan button disabled">&raquo;</span>';
            }

            html += '</span></div>';

            $('#conflict-pagination').html(html);
        },

        /**
         * Open resolve modal
         */
        openResolveModal: function(conflictId) {
            const self = this;

            // Load conflict details
            this.apiRequest('/conflicts/' + conflictId)
                .done(function(conflict) {
                    self.currentConflict = conflict;
                    self.populateModal(conflict);
                    $('#conflict-resolve-modal').show();
                })
                .fail(function() {
                    alert(self.i18n.error);
                });
        },

        /**
         * Populate modal with conflict data
         */
        populateModal: function(conflict) {
            $('#modal-object-type').text(conflict.object_type + ' / ' + conflict.subtype);
            $('#modal-conflict-type').text(conflict.type || '-');
            $('#modal-detected-at').text(conflict.detected_at ? new Date(conflict.detected_at).toLocaleString() : '-');

            // Populate comparison table
            const conflictData = conflict.conflict_data || {};
            const sourceData = conflictData.source_data || {};
            const targetData = conflictData.target_data || {};
            const fields = conflictData.conflicting_fields || Object.keys({...sourceData, ...targetData});

            let tbody = '';

            if (fields.length > 0) {
                fields.forEach(function(field) {
                    const sourceValue = this.formatValue(sourceData[field]);
                    const targetValue = this.formatValue(targetData[field]);
                    const isDifferent = sourceValue !== targetValue;

                    tbody += `
                        <tr class="${isDifferent ? 'field-different' : ''}">
                            <td class="field-name">${this.escapeHtml(field)}</td>
                            <td class="source-value">${this.escapeHtml(sourceValue)}</td>
                            <td class="target-value">${this.escapeHtml(targetValue)}</td>
                            <td class="manual-select-col" style="display:none;">
                                <select name="manual_field_select" data-field="${this.escapeHtml(field)}" disabled>
                                    <option value="source">${this.escapeHtml('Source Site')}</option>
                                    <option value="target">${this.escapeHtml('Target Site')}</option>
                                </select>
                            </td>
                        </tr>
                    `;
                }.bind(this));
            } else {
                tbody = `<tr><td colspan="4">${this.escapeHtml('No conflicting field data')}</td></tr>`;
            }

            $('#modal-comparison-body').html(tbody);

            // Reset strategy selection
            $('input[name="resolve_strategy"][value="source_wins"]').prop('checked', true);
            $('.manual-select-col').hide();

            // Disable resolve button if already resolved
            if (conflict.status === 'resolved') {
                $('#btn-confirm-resolve').prop('disabled', true).text('Resolved');
            } else {
                $('#btn-confirm-resolve').prop('disabled', false).text(this.escapeHtml('Confirm Resolve'));
            }
        },

        /**
         * Format value for display
         */
        formatValue: function(value) {
            if (value === undefined || value === null) {
                return '(Empty)';
            }
            if (typeof value === 'object') {
                return JSON.stringify(value);
            }
            return String(value);
        },

        /**
         * Close modal
         */
        closeModal: function() {
            $('#conflict-resolve-modal').hide();
            this.currentConflict = null;
        },

        /**
         * Resolve current conflict
         */
        resolveConflict: function() {
            if (!this.currentConflict) {
                return;
            }

            const self = this;
            const conflictId = this.currentConflict.id;
            const strategy = $('input[name="resolve_strategy"]:checked').val();

            const data = {
                strategy: strategy
            };

            // If manual, collect field selections
            if (strategy === 'manual') {
                const manualResolution = {};
                $('select[name="manual_field_select"]').each(function() {
                    const field = $(this).data('field');
                    const choice = $(this).val();
                    manualResolution[field] = choice;
                });
                data.manual_resolution = manualResolution;
            }

            const $btn = $('#btn-confirm-resolve');
            $btn.prop('disabled', true).text(this.i18n.resolving);

            this.apiRequest('/conflicts/' + conflictId + '/resolve', {
                method: 'POST',
                data: data
            })
            .done(function(response) {
                if (response.success) {
                    self.closeModal();
                    self.loadStats();
                    self.loadConflicts();
                    self.showNotice('success', self.i18n.resolved);
                } else {
                    alert(self.i18n.error);
                }
            })
            .fail(function() {
                alert(self.i18n.error);
            })
            .always(function() {
                $btn.prop('disabled', false).text(self.escapeHtml('ConfirmResolve'));
            });
        },

        /**
         * Auto resolve all pending conflicts
         */
        autoResolveAll: function() {
            if (!confirm(this.i18n.confirm_auto_resolve)) {
                return;
            }

            const self = this;
            const $btn = $('#btn-auto-resolve');

            $btn.prop('disabled', true).text(this.i18n.resolving);

            this.apiRequest('/conflicts/auto-resolve', {
                method: 'POST',
                data: { limit: 100 }
            })
            .done(function(response) {
                if (response.success) {
                    self.loadStats();
                    self.loadConflicts();
                    self.showNotice('success', `${self.i18n.resolved}: ${response.resolved} conflicts`);
                }
            })
            .fail(function() {
                alert(self.i18n.error);
            })
            .always(function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span> ' + self.escapeHtml('Auto Resolve All'));
            });
        },

        /**
         * Show admin notice
         */
        showNotice: function(type, message) {
            const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
            const $notice = $(`
                <div class="notice ${noticeClass} is-dismissible">
                    <p>${this.escapeHtml(message)}</p>
                    <button type="button" class="notice-dismiss"></button>
                </div>
            `);

            $('.wptsall-conflict-page h1').after($notice);

            // Auto dismiss after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);

            // Manual dismiss
            $notice.find('.notice-dismiss').on('click', function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            });
        },

        /**
         * Escape HTML
         */
        escapeHtml: function(text) {
            if (text === null || text === undefined) {
                return '';
            }
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        // Only init if we're on the conflict page
        if ($('.wptsall-conflict-page').length > 0) {
            ConflictManager.init();
        }
    });

})(jQuery);
