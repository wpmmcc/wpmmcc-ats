/**
 * WPTSALL Sites Page JavaScript
 */
(function($) {
    'use strict';

    var SitesPage = {
        config: window.wptsallSites || {},

        init: function() {
            this.bindEvents();
        },

        /**
         * Move admin notices to the top container
         */
        moveNoticesToTop: function() {
            var $container = $('#wptsall-notices-container');
            if (!$container.length) {
                return;
            }

            var $notices = $('div.notice, div.updated, div.error').not('.inline, .below-h2, #wptsall-notices-container div');
            if ($notices.length) {
                $notices.appendTo($container);
            }
        },

        bindEvents: function() {
            var self = this;

            // Site type change - show/hide virtual site fields
            $('#site_type').on('change', function() {
                var isVirtual = $(this).val() === 'virtual';
                $('.virtual-site-field').toggle(isVirtual);
            });

            // Add site form submit
            $('#wptsall-add-site-form').on('submit', function(e) {
                e.preventDefault();
                self.addSite($(this));
            });

            // Delete site
            $(document).on('click', '.wptsall-delete-site-btn', function() {
                var siteId = $(this).data('site-id');
                self.deleteSite(siteId, $(this));
            });

            // Edit site
            $(document).on('click', '.wptsall-edit-site-btn', function() {
                var siteId = $(this).data('site-id');
                self.editSite(siteId);
            });

            // Edit site form submit
            $('#wptsall-edit-site-form').on('submit', function(e) {
                e.preventDefault();
                self.saveSite($(this));
            });

            // Modal close buttons
            $(document).on('click', '.wptsall-modal-close', function() {
                $(this).closest('.wptsall-modal').hide();
            });

            // Close modal on outside click
            $(document).on('click', '.wptsall-modal', function(e) {
                if (e.target === this) {
                    $(this).hide();
                }
            });

            // Create language task
            $(document).on('click', '.wptsall-create-lang-task', function() {
                var textdomain = $(this).data('textdomain');
                var type = $(this).data('type');
                var lang = $(this).data('lang');
                self.createLanguageTask(textdomain, type, lang, $(this));
            });
        },

        addSite: function($form) {
            var self = this;
            var formData = {
                type: $form.find('#site_type').val(),
                name: $form.find('#site_name').val(),
                slug: $form.find('#site_slug').val(),
                target_lang: $form.find('#target_lang').val(),
                model_id: $form.find('#model_id').val()
            };

            var $btn = $form.find('[type="submit"]');
            $btn.prop('disabled', true).text(self.config.i18n.saving);

            wp.apiFetch({
                path: 'wptsall/v2/sites',
                method: 'POST',
                data: formData
            }).then(function(response) {
                $btn.prop('disabled', false).text('Add Site');
                alert(self.config.i18n.saved);
                location.href = location.href.replace(/tab=add/, 'tab=list');
            }).catch(function(error) {
                $btn.prop('disabled', false).text('Add Site');
                alert('Add failed: ' + (error.message || 'Unknown error'));
            });
        },

        deleteSite: function(siteId, $btn) {
            var self = this;

            if (!confirm(self.config.i18n.confirm_delete)) {
                return;
            }

            $btn.prop('disabled', true);

            wp.apiFetch({
                path: 'wptsall/v2/sites/' + siteId,
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

        editSite: function(siteId) {
            var self = this;

            // Fetch site data
            wp.apiFetch({
                path: 'wptsall/v2/sites/' + siteId,
                method: 'GET'
            }).then(function(site) {
                // Fill the form
                $('#edit_site_id').val(site.id);
                $('#edit_site_name').val(site.name);
                $('#edit_site_slug').val(site.slug);
                $('#edit_target_lang').val(site.target_lang);
                $('#edit_model_id').val(site.model_id || '');
                $('#edit_site_status').val(site.status);

                // Show modal
                $('#wptsall-edit-site-modal').show();
            }).catch(function(error) {
                alert('Failed to load site info: ' + (error.message || 'Unknown error'));
            });
        },

        saveSite: function($form) {
            var self = this;
            var siteId = $form.find('#edit_site_id').val();
            var formData = {
                name: $form.find('#edit_site_name').val(),
                slug: $form.find('#edit_site_slug').val(),
                target_lang: $form.find('#edit_target_lang').val(),
                model_id: $form.find('#edit_model_id').val(),
                status: $form.find('#edit_site_status').val()
            };

            var $btn = $form.find('[type="submit"]');
            $btn.prop('disabled', true).text(self.config.i18n.saving);

            wp.apiFetch({
                path: 'wptsall/v2/sites/' + siteId,
                method: 'PUT',
                data: formData
            }).then(function(response) {
                $btn.prop('disabled', false).text('Save');
                $('#wptsall-edit-site-modal').hide();
                location.reload();
            }).catch(function(error) {
                $btn.prop('disabled', false).text('Save');
                alert('Save failed: ' + (error.message || 'Unknown error'));
            });
        },

        createLanguageTask: function(textdomain, type, lang, $btn) {
            var self = this;
            var siteId = $btn.closest('.wptsall-site-languages').data('site-id') || '';

            $btn.prop('disabled', true).text('Creating...');

            wp.apiFetch({
                path: 'wptsall/v2/tasks/language',
                method: 'POST',
                data: {
                    textdomain: textdomain,
                    component_type: type,
                    target_lang: lang,
                    site_id: siteId
                }
            }).then(function(response) {
                $btn.prop('disabled', false).text('Task Created');
                $btn.removeClass('button-primary').addClass('button-secondary');
                alert(response.message || 'Translation task created, task ID: ' + response.task_id);
            }).catch(function(error) {
                $btn.prop('disabled', false).text('Create Translation Task');
                alert('Create failed: ' + (error.message || 'Unknown error'));
            });
        }
    };

    $(document).ready(function() {
        SitesPage.init();

        // Initialize virtual site field visibility
        $('#site_type').trigger('change');

        // Delay moving notices to ensure execution after WordPress common.js
        setTimeout(function() {
            SitesPage.moveNoticesToTop();
        }, 10);
    });

})(jQuery);
