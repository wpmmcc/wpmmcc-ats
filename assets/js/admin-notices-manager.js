/**
 * WPTSALL Admin Notices Manager
 *
 * Collapses third-party admin notices to keep WPTSALL pages clean.
 *
 * @package WPTSALL
 * @since 0.9.3
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Small delay to ensure all notices are rendered
        setTimeout(manageAdminNotices, 100);
    });

    function manageAdminNotices() {
        var $wrap = $('.wptsall-admin-page').first();
        if (!$wrap.length) {
            return;
        }

        var $wpbodyContent = $('#wpbody-content');
        if (!$wpbodyContent.length) {
            return;
        }

        // Collect all elements before our .wrap that look like notices
        var $thirdPartyNotices = $();
        var foundWrap = false;

        $wpbodyContent.children().each(function() {
            var $el = $(this);

            // Stop when we reach our wrap
            if ($el.hasClass('wptsall-admin-page') || $el.find('.wptsall-admin-page').length > 0) {
                foundWrap = true;
                return false; // break
            }

            // Skip screen reader text and core WP elements
            if ($el.hasClass('screen-reader-text') ||
                $el.attr('id') === 'screen-meta' ||
                $el.attr('id') === 'screen-meta-links' ||
                $el.is('form#get-shortlink')) {
                return; // continue
            }

            // Skip our own collapsed container if it exists
            if ($el.hasClass('wptsall-notices-collapsed')) {
                return; // continue
            }

            // Check if this is any kind of notice or third-party content
            var isNotice = $el.hasClass('notice') ||
                          $el.hasClass('updated') ||
                          $el.hasClass('error') ||
                          $el.hasClass('update-nag') ||
                          $el.attr('class') && $el.attr('class').indexOf('notice') !== -1;

            // Also include divs that contain plugin promotions, modals, etc.
            var isThirdParty = !$el.hasClass('wrap') &&
                              !$el.is('h1') &&
                              !$el.is('hr') &&
                              $el.is('div') &&
                              !$el.attr('id');

            // Check for common third-party notice patterns
            var hasPluginContent = $el.find('.notice, .updated, .error, button, a[href*="plugin"]').length > 0;

            if (isNotice || (isThirdParty && hasPluginContent) || $el.css('position') === 'fixed') {
                // Skip if it's a WPTSALL notice
                if ($el.hasClass('wptsall-notice') ||
                    ($el.attr('id') && $el.attr('id').indexOf('wptsall') !== -1)) {
                    return; // continue
                }
                $thirdPartyNotices = $thirdPartyNotices.add($el);
            }
        });

        if ($thirdPartyNotices.length === 0) {
            return;
        }

        // Create a collapsible container
        var $container = $('<div class="wptsall-notices-collapsed">' +
            '<div class="wptsall-notices-toggle">' +
            '<span class="wptsall-notices-count">' + $thirdPartyNotices.length + '</span> ' +
            '<span class="wptsall-notices-text">Third-party plugin notices (click to expand)</span>' +
            '<span class="wptsall-notices-arrow dashicons dashicons-arrow-down-alt2"></span>' +
            '</div>' +
            '<div class="wptsall-notices-content" style="display: none;"></div>' +
            '</div>');

        // Move notices into container
        var $noticesContent = $container.find('.wptsall-notices-content');
        $thirdPartyNotices.each(function() {
            $(this).detach().appendTo($noticesContent);
        });

        // Insert at the top of wpbody-content
        $wpbodyContent.prepend($container);

        // Toggle functionality
        $container.find('.wptsall-notices-toggle').on('click', function() {
            var $content = $container.find('.wptsall-notices-content');
            var $arrow = $container.find('.wptsall-notices-arrow');
            var $text = $container.find('.wptsall-notices-text');

            if ($content.is(':visible')) {
                $content.slideUp(200);
                $arrow.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
                $text.text('Third-party plugin notices (click to expand)');
            } else {
                $content.slideDown(200);
                $arrow.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                $text.text('Third-party plugin notices (click to collapse)');
            }
        });
    }

})(jQuery);
