jQuery(function($) {
var restUrl = wptsallTasksSubtasks.restUrl;
var nonce = wptsallTasksSubtasks.nonce;

$(document).on('click', '.wptsall-subtask-action', function() {
var $btn = $(this);
var action = String($btn.data('action') || '');
var taskId = Number($btn.data('task-id') || 0);
var subtaskType = String($btn.data('subtask-type') || '');
var subtaskKey = String($btn.data('subtask-key') || '');
if (!taskId || !action || !subtaskKey) {
alert(wptsallTasksSubtasks.i18n.invalid_subtask_parameters);
return;
}
var note = window.prompt(wptsallTasksSubtasks.i18n.optional_note_leave_blank_to_submit, '') || '';
var actionLabel = action === 'retry'
? wptsallTasksSubtasks.i18n.retry_subtask
: wptsallTasksSubtasks.i18n.skip_subtask;
var originalText = $btn.text();
$btn.prop('disabled', true).text(actionLabel + '...');

$.ajax({
url: restUrl + '/tasks/' + taskId + '/subtasks/manual-action',
method: 'POST',
headers: { 'X-WP-Nonce': nonce },
contentType: 'application/json',
data: JSON.stringify({
action: action,
type: subtaskType,
key: subtaskKey,
note: note
}),
success: function(resp) {
if (resp && resp.success) {
window.location.reload();
return;
}
var msg = (resp && resp.message) ? resp.message : wptsallTasksSubtasks.i18n.subtask_operation_failed;
alert(msg);
$btn.prop('disabled', false).text(originalText);
},
error: function(xhr) {
var msg = (xhr.responseJSON && (xhr.responseJSON.message || (xhr.responseJSON.error && xhr.responseJSON.error.message)))
? (xhr.responseJSON.message || xhr.responseJSON.error.message)
: wptsallTasksSubtasks.i18n.request_failed;
alert(msg);
$btn.prop('disabled', false).text(originalText);
}
});
});

$(document).on('click', '.wptsall-manual-queue-action', function() {
var $btn = $(this);
var action = String($btn.data('action') || '');
var taskId = Number($btn.data('task-id') || 0);
var index = Number($btn.data('index') || 0);
if (!taskId || !action) {
alert(wptsallTasksSubtasks.i18n.invalid_manual_queue_parameters);
return;
}

if (action === 'clear_all') {
if (!window.confirm(wptsallTasksSubtasks.i18n.clear_this_task_manual_queue_continue)) {
return;
}
}

var note = window.prompt(wptsallTasksSubtasks.i18n.optional_note_leave_blank_to_submit, '') || '';
var actionTextMap = {
retry: wptsallTasksSubtasks.i18n.retry_queue_item,
dismiss: wptsallTasksSubtasks.i18n.dismiss_queue_item,
clear_all: wptsallTasksSubtasks.i18n.clear_queue
};
var originalText = $btn.text();
$btn.prop('disabled', true).text((actionTextMap[action] || action) + '...');

var payload = {
action: action,
note: note
};
if (index > 0 && action !== 'clear_all') {
payload.index = index;
}

$.ajax({
url: restUrl + '/tasks/' + taskId + '/manual-queue/resolve',
method: 'POST',
headers: { 'X-WP-Nonce': nonce },
contentType: 'application/json',
data: JSON.stringify(payload),
success: function(resp) {
if (resp && resp.success) {
window.location.reload();
return;
}
var msg = (resp && resp.message) ? resp.message : wptsallTasksSubtasks.i18n.manual_queue_operation_failed;
alert(msg);
$btn.prop('disabled', false).text(originalText);
},
error: function(xhr) {
var msg = (xhr.responseJSON && (xhr.responseJSON.message || (xhr.responseJSON.error && xhr.responseJSON.error.message)))
? (xhr.responseJSON.message || xhr.responseJSON.error.message)
: wptsallTasksSubtasks.i18n.request_failed;
alert(msg);
$btn.prop('disabled', false).text(originalText);
}
});
});

$(document).on('click', '.wptsall-job-retry-failed-subtasks', function() {
var $btn = $(this);
var jobId = String($btn.data('job-id') || '');
var businessLine = String($btn.data('business-line') || '');
if (!jobId) {
alert(wptsallTasksSubtasks.i18n.missing_job_id_cannot_execute);
return;
}
var note = window.prompt(wptsallTasksSubtasks.i18n.optional_note_leave_blank_to_submit, '') || '';
var originalText = $btn.text();
$btn.prop('disabled', true).text(wptsallTasksSubtasks.i18n.processing + '...');

$.ajax({
url: restUrl + '/tasks/jobs/' + encodeURIComponent(jobId) + '/retry-failed-subtasks',
method: 'POST',
headers: { 'X-WP-Nonce': nonce },
contentType: 'application/json',
data: JSON.stringify({
business_line: businessLine,
note: note
}),
success: function(resp) {
if (!(resp && resp.success && resp.data)) {
var fallbackMsg = wptsallTasksSubtasks.i18n.job_level_failed_subtask_retry_failed;
alert((resp && resp.message) ? resp.message : fallbackMsg);
$btn.prop('disabled', false).text(originalText);
return;
}
var data = resp.data || {};
var url = new URL(window.location.href);
url.searchParams.set('retried_failed_subtasks', String(data.updated_tasks || 0));
url.searchParams.set('retried_failed_subtasks_items', String(data.updated_subtasks || 0));
if (data.scope) {
url.searchParams.set('retried_failed_subtasks_scope', String(data.scope));
}
if (data.task_samples) {
url.searchParams.set('retried_failed_subtasks_tasks', String(data.task_samples));
}
window.location.href = url.toString();
},
error: function(xhr) {
var msg = (xhr.responseJSON && (xhr.responseJSON.message || (xhr.responseJSON.error && xhr.responseJSON.error.message)))
? (xhr.responseJSON.message || xhr.responseJSON.error.message)
: wptsallTasksSubtasks.i18n.request_failed;
alert(msg);
$btn.prop('disabled', false).text(originalText);
}
});
});
});
