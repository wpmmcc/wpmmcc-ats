/**
 * WPTSALL Quick Edit / Bulk Edit Language Selector (P5-9)
 *
 * On post list screens (edit.php) for managed post types, injects a
 * "Language" select into the Quick Edit and Bulk Edit panels. The select
 * uses `wptsallQuickEdit` data from wp_localize_script. The selection
 * is saved as `_wptsall_language` post meta via the standard edit/save
 * flow (the post meta is preserved by the Quick Edit save handler).
 *
 * @package WPTSALL
 * @since 1.4.0
 */

(function($) {
    'use strict';

    var cfg = window.wptsallQuickEdit || {};
    var langs = cfg.langs || window.wptsallQuickEditLangs || [];
    if (!langs || !langs.length) {
        // Still allow empty language list (None option only).
        langs = [];
    }

    $(document).ready(function() {
        injectQuickEditField();
        injectBulkEditField();
    });

    function buildSelect(selectedValue) {
        var $select = $('<select>')
            .attr('name', '_wptsall_language')
            .addClass('wptsall-quickedit-lang');
        $select.append($('<option>').val('').text('— None —'));
        (langs || []).forEach(function(lang) {
            var code = lang.code || lang;
            var name = lang.name || code;
            var $opt = $('<option>')
                .val(code)
                .text(code + ' — ' + name)
                .prop('selected', code === selectedValue);
            $select.append($opt);
        });
        return $select;
    }

    function injectQuickEditField() {
        // WP renders the Quick Edit row dynamically into the row's edit slot.
        // The form contains the existing fields; we add ours as a new field.
        $(document).on('click', 'button.editinline', function() {
            var $row = $(this).closest('tr');
            var postId = $row.attr('id') ? $row.attr('id').replace('post-', '') : '';
            var currentLang = $row.data('wptsall-lang') || '';
            // wait for WP to inject the edit row
            setTimeout(function() {
                var $editRow = $('#edit-' + postId);
                if (!$editRow.length || $editRow.find('.wptsall-quickedit-lang').length) {
                    return;
                }
                var $td = $editRow.find('td:has(.inline-edit-group)').first();
                if (!$td.length) {
                    $td = $editRow.find('td').last();
                }
                var $field = $('<fieldset class="inline-edit-col-right wptsall-quickedit-wrap">')
                    .append($('<div class="inline-edit-group">')
                        .append($('<label>').text('WPTSALL Language'))
                        .append(buildSelect(currentLang))
                    );
                $td.append($field);
            }, 50);
        });
    }

    function injectBulkEditField() {
        var $bulkRow = $('#bulk-edit');
        if (!$bulkRow.length || $bulkRow.find('.wptsall-quickedit-lang').length) {
            return;
        }
        var $field = $('<fieldset class="inline-edit-col-right wptsall-quickedit-wrap">')
            .append($('<div class="inline-edit-group">')
                .append($('<label>').text('WPTSALL Language (bulk)'))
                .append(buildSelect(''))
                .append($('<p class="description">').text('Select a language to assign to all selected posts, or leave as — None — to skip.'))
            );
        $bulkRow.find('.inline-edit-col-right').first().after($field);
    }
})(jQuery);
