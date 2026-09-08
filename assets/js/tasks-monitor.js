jQuery(document).ready(function($) {
var restUrl = wptsallTasksMonitor.restUrl;
var nonce = wptsallTasksMonitor.nonce;

// Toggle add task modal
$('#wptsall-add-task-btn').on('click', function(e) {
e.preventDefault();
$('#wptsall-add-task-modal').slideToggle('fast');
});

$('#wptsall-cancel-add-task').on('click', function() {
$('#wptsall-add-task-modal').slideUp('fast');
});

// Create monitoring task
$('#wptsall-create-task-btn').on('click', function() {
var relationId = $('#add-task-relation').val();
if (!relationId) {
alert(wptsallTasksMonitor.i18n.please_select_a_site_relation);
return;
}

var $btn = $(this);
$btn.prop('disabled', true).text(wptsallTasksMonitor.i18n.creating);

$.ajax({
url: restUrl + '/tasks/monitor/start',
method: 'POST',
headers: { 'X-WP-Nonce': nonce },
contentType: 'application/json',
data: JSON.stringify({ relation_id: parseInt(relationId) }),
success: function(response) {
if (response.success) {
location.reload();
} else {
alert(response.message || wptsallTasksMonitor.i18n.creation_failed);
$btn.prop('disabled', false).text(wptsallTasksMonitor.i18n.create_monitoring_task);
}
},
error: function(xhr) {
var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : wptsallTasksMonitor.i18n.request_failed;
alert(msg);
$btn.prop('disabled', false).text(wptsallTasksMonitor.i18n.create_monitoring_task);
}
});
});

// Start/Stop monitoring
$('.wptsall-toggle-monitoring').on('click', function() {
var $btn = $(this);
var relationId = $btn.data('relation-id');
var action = $btn.data('action'); // 'start' or 'stop'

$btn.prop('disabled', true);

$.ajax({
url: restUrl + '/tasks/monitor/' + action,
method: 'POST',
headers: { 'X-WP-Nonce': nonce },
contentType: 'application/json',
data: JSON.stringify({ relation_id: parseInt(relationId) }),
success: function(response) {
if (response.success) {
location.reload();
} else {
alert(response.message || wptsallTasksMonitor.i18n.operation_failed);
$btn.prop('disabled', false);
}
},
error: function(xhr) {
var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : wptsallTasksMonitor.i18n.request_failed;
alert(msg);
$btn.prop('disabled', false);
}
});
});

// Trigger check
$('.wptsall-trigger-check').on('click', function() {
var $btn = $(this);
var taskId = $btn.data('task-id');

$btn.prop('disabled', true).text(wptsallTasksMonitor.i18n.executing);

$.ajax({
url: restUrl + '/tasks/' + taskId + '/check',
method: 'POST',
headers: { 'X-WP-Nonce': nonce },
success: function(response) {
if (response.success) {
var stats = response.stats || {};
var msg = wptsallTasksMonitor.i18n.execution_completed + '\n';
msg += wptsallTasksMonitor.i18n.checked + ': ' + (stats.total_checked || 0) + '\n';
msg += wptsallTasksMonitor.i18n.synced + ': ' + (stats.synced || 0) + '\n';
msg += wptsallTasksMonitor.i18n.error + ': ' + (stats.errors || 0);
alert(msg);
location.reload();
} else {
alert(response.error || wptsallTasksMonitor.i18n.execution_failed);
$btn.prop('disabled', false).text(wptsallTasksMonitor.i18n.execute_now);
}
},
error: function(xhr) {
var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : wptsallTasksMonitor.i18n.request_failed;
alert(msg);
$btn.prop('disabled', false).text(wptsallTasksMonitor.i18n.execute_now);
}
});
});

// Delete task
$('.wptsall-delete-task').on('click', function() {
if (!confirm(wptsallTasksMonitor.i18n.are_you_sure_you_want_to_delete_this_monitoring_ta)) {
return;
}

var $btn = $(this);
var taskId = $btn.data('task-id');

$btn.prop('disabled', true);

$.ajax({
url: restUrl + '/tasks/' + taskId,
method: 'DELETE',
headers: { 'X-WP-Nonce': nonce },
success: function(response) {
if (response.success) {
location.reload();
} else {
alert(wptsallTasksMonitor.i18n.delete_failed);
$btn.prop('disabled', false);
}
},
error: function() {
alert(wptsallTasksMonitor.i18n.request_failed);
$btn.prop('disabled', false);
}
});
});

// P2: Scan language pack
$('.wptsall-scan-langpack').on('click', function() {
var $btn = $(this);
var relationId = $btn.data('relation-id');
var originalText = $btn.text();

$btn.prop('disabled', true).text(wptsallTasksMonitor.i18n.scanning);

$.ajax({
url: restUrl + '/tasks/scan-language-pack',
method: 'POST',
headers: { 'X-WP-Nonce': nonce },
contentType: 'application/json',
data: JSON.stringify({ relation_id: parseInt(relationId), mode: 'pot' }),
success: function(response) {
if (response.success) {
var msg = wptsallTasksMonitor.i18n.language_pack_scan_completed + '\n';
msg += wptsallTasksMonitor.i18n.templates + ': ' + (response.templates ? response.templates.length : 0) + '\n';
msg += wptsallTasksMonitor.i18n.entries + ': ' + (response.total_entries || 0);
alert(msg);
} else {
alert(response.error || wptsallTasksMonitor.i18n.scan_failed);
}
$btn.prop('disabled', false).text(originalText);
},
error: function(xhr) {
var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : wptsallTasksMonitor.i18n.request_failed;
alert(msg);
$btn.prop('disabled', false).text(originalText);
}
});
});

// P3: Translate language pack
$('.wptsall-translate-langpack').on('click', function() {
var $btn = $(this);
var relationId = $btn.data('relation-id');
var originalText = $btn.text();

$btn.prop('disabled', true).text(wptsallTasksMonitor.i18n.translating);

$.ajax({
url: restUrl + '/tasks/translate-language-pack',
method: 'POST',
headers: { 'X-WP-Nonce': nonce },
contentType: 'application/json',
data: JSON.stringify({ relation_id: parseInt(relationId) }),
success: function(response) {
if (response.success) {
var msg = wptsallTasksMonitor.i18n.translation_completed + '\n';
msg += wptsallTasksMonitor.i18n.target_language + ': ' + (response.target_lang || 'auto') + '\n';
msg += wptsallTasksMonitor.i18n.translated_entries + ': ' + (response.translated || 0);
alert(msg);
} else {
alert(response.message || response.error || wptsallTasksMonitor.i18n.translation_failed);
}
$btn.prop('disabled', false).text(originalText);
},
error: function(xhr) {
var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : wptsallTasksMonitor.i18n.request_failed;
alert(msg);
$btn.prop('disabled', false).text(originalText);
}
});
});
});
