/**
 * WPTSALL Model Basic Info Editor
 *
 * Enables editing basic model metadata on the model edit page.
 */
(function ($) {
	'use strict';

	const ModelBasicInfo = {
		config: window.wptsallModelBasicInfo || {},

		init: function () {
			if (!$('#wptsall-model-basic-info-form').length) return;

			this.bindEvents();
		},

		bindEvents: function () {
			const self = this;

			// Add/remove post types/taxonomies as explicit actions (do not render full-site checkboxes).
			$(document).on('click', '#wptsall-add-post-type-btn', function (e) {
				e.preventDefault();
				self.addTag('#wptsall-model-post-types', '#wptsall-model-add-post-type');
			});

			$(document).on('keydown', '#wptsall-model-add-post-type', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					self.addTag('#wptsall-model-post-types', '#wptsall-model-add-post-type');
				}
			});

			$(document).on('click', '#wptsall-add-taxonomy-btn', function (e) {
				e.preventDefault();
				self.addTag('#wptsall-model-taxonomies', '#wptsall-model-add-taxonomy');
			});

			$(document).on('keydown', '#wptsall-model-add-taxonomy', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					self.addTag('#wptsall-model-taxonomies', '#wptsall-model-add-taxonomy');
				}
			});

			$(document).on('click', '.wptsall-tag-remove', function (e) {
				e.preventDefault();
				const $tag = $(this).closest('.wptsall-tag');
				$tag.remove();
			});

			$(document).on('click', '#wptsall-save-model-basic-btn', function (e) {
				e.preventDefault();
				self.save();
			});
		},

		addTag: function (containerSelector, inputSelector) {
			const $container = $(containerSelector);
			const $input = $(inputSelector);
			const valueRaw = ($input.val() || '').trim();
			if (!valueRaw) return;

			// Keep stored keys in WP-safe form (matches REST sanitize_key usage).
			const value = valueRaw.toLowerCase().replace(/[^a-z0-9_-]/g, '');
			if (!value) return;

			const exists = $container.find(`.wptsall-tag[data-value="${value}"]`).length > 0;
			if (exists) {
				$input.val('');
				return;
			}

			// Remove "Not configured." placeholder if present.
			$container.find('.description').remove();

			const html = `
				<span class="wptsall-tag" data-value="${value}">
					<span class="wptsall-tag-text">${value}</span>
					<code>${value}</code>
					<button type="button" class="button-link wptsall-tag-remove" aria-label="Remove ${value}">×</button>
				</span>
			`;

			$container.append(html);
			$input.val('');
		},

		collectTags: function (containerSelector) {
			return $(containerSelector)
				.find('.wptsall-tag')
				.map(function () { return $(this).data('value'); })
				.get()
				.filter(Boolean);
		},

		save: function () {
			const self = this;
			const modelId = parseInt(self.config.modelId, 10);
			const $btn = $('#wptsall-save-model-basic-btn');
			const $spinner = $('#wptsall-model-basic-spinner');

			if (!modelId) return;

			const postTypes = self.collectTags('#wptsall-model-post-types');
			const taxonomies = self.collectTags('#wptsall-model-taxonomies');

			const payload = {
				plugin_name: $('#wptsall-model-plugin-name').val(),
				plugin_version: $('#wptsall-model-plugin-version').val(),
				text_domain: $('#wptsall-model-text-domain').val(),
				description: $('#wptsall-model-description').val(),
				post_types: postTypes,
				taxonomies: taxonomies
			};

			$btn.prop('disabled', true).text('Saving...');
			$spinner.addClass('is-active');

			wp.apiFetch({
				path: 'wptsall/v2/models/' + modelId,
				method: 'PUT',
				data: payload
			}).then(function () {
				alert('Model basic info saved.');
				window.location.reload();
			}).catch(function (err) {
				alert('Save failed: ' + (err.message || 'Unknown error'));
			}).finally(function () {
				$btn.prop('disabled', false).text('Save Basic Info');
				$spinner.removeClass('is-active');
			});
		}
	};

	$(document).ready(function () {
		ModelBasicInfo.init();
	});
})(jQuery);
