jQuery(function($) {
var restUrl = wptsallTasksJobs.restUrl;
var nonce = wptsallTasksJobs.nonce;
var jobsBaseUrl = wptsallTasksJobs.jobsBaseUrl;
var $btn = $('#wptsall-create-job-bundle-btn');
var $result = $('#wptsall-job-create-result');
var $batchRetryBtn = $('#wptsall-jobs-batch-retry-failed-subtasks');
var $batchRetryStatus = $('#wptsall-jobs-batch-retry-status');
var initialDetailsState = wptsallTasksJobs.initialDetailsState || {};

function getErrorMessage(xhr, fallbackMsg) {
if (xhr && xhr.responseJSON) {
if (xhr.responseJSON.message) {
return xhr.responseJSON.message;
}
if (xhr.responseJSON.error && xhr.responseJSON.error.message) {
return xhr.responseJSON.error.message;
}
}
return fallbackMsg;
}

var jobTaskCache = {};

function escapeHtml(value) {
return String(value == null ? '' : value)
.replace(/&/g, '&amp;')
.replace(/</g, '&lt;')
.replace(/>/g, '&gt;')
.replace(/"/g, '&quot;')
.replace(/'/g, '&#39;');
}

function getJobDetailsRowByJobId(jobId) {
return $('.wptsall-job-details-row').filter(function() {
return String($(this).data('job-id') || '') === String(jobId || '');
}).first();
}

function getJobToggleButtonByJobId(jobId) {
return $('.wptsall-job-toggle-details').filter(function() {
return String($(this).data('job-id') || '') === String(jobId || '');
}).first();
}

function collectJobDetailFilters($panel) {
return {
status: String($panel.find('.wptsall-job-details-filter-status').val() || '').trim(),
business_line: String($panel.find('.wptsall-job-details-filter-business-line').val() || '').trim(),
task_type: String($panel.find('.wptsall-job-details-filter-task-type').val() || '').trim()
};
}

function setJobToggleButtonLabel($btn, isOpen) {
if (!$btn || $btn.length < 1) {
return;
}
$btn.text(isOpen
? wptsallTasksJobs.i18n.collapse_job_tasks
: wptsallTasksJobs.i18n.view_job_tasks
);
}

function closeAllJobDetailRows(exceptJobId) {
$('.wptsall-job-details-row:visible').each(function() {
var $row = $(this);
var rowJobId = String($row.data('job-id') || '');
if (exceptJobId && rowJobId === String(exceptJobId)) {
return;
}
$row.hide();
setJobToggleButtonLabel(getJobToggleButtonByJobId(rowJobId), false);
});
}

function applyJobDetailFilters($panel, filters) {
if (!$panel || $panel.length < 1 || !filters) {
return;
}
if (Object.prototype.hasOwnProperty.call(filters, 'status')) {
$panel.find('.wptsall-job-details-filter-status').val(String(filters.status || ''));
}
if (Object.prototype.hasOwnProperty.call(filters, 'business_line')) {
$panel.find('.wptsall-job-details-filter-business-line').val(String(filters.business_line || ''));
}
if (Object.prototype.hasOwnProperty.call(filters, 'task_type')) {
$panel.find('.wptsall-job-details-filter-task-type').val(String(filters.task_type || ''));
}
}

function updateJobDetailsStateInUrl(jobId, filters, isOpen) {
var nextUrl;
try {
nextUrl = new URL(window.location.href);
} catch (e) {
return;
}
if (isOpen && jobId) {
nextUrl.searchParams.set('details_job_id', String(jobId));
var safeFilters = filters || {};
if (safeFilters.status) {
nextUrl.searchParams.set('details_status', String(safeFilters.status));
} else {
nextUrl.searchParams.delete('details_status');
}
if (safeFilters.business_line) {
nextUrl.searchParams.set('details_business_line', String(safeFilters.business_line));
} else {
nextUrl.searchParams.delete('details_business_line');
}
if (safeFilters.task_type) {
nextUrl.searchParams.set('details_task_type', String(safeFilters.task_type));
} else {
nextUrl.searchParams.delete('details_task_type');
}
} else {
nextUrl.searchParams.delete('details_job_id');
nextUrl.searchParams.delete('details_status');
nextUrl.searchParams.delete('details_business_line');
nextUrl.searchParams.delete('details_task_type');
}
window.history.replaceState({}, '', nextUrl.toString());
}

function collectCurrentPageJobIds() {
var seen = {};
var ids = [];
$('.wptsall-job-retry-failed-subtasks-jobtab').each(function() {
var id = String($(this).data('job-id') || '').trim();
if (!id || seen[id]) {
return;
}
seen[id] = true;
ids.push(id);
});
return ids;
}

function formatSubtaskSummary(summary) {
var s = summary || {};
var parts = [];
parts.push('total=' + String(Number(s.total || 0)));
parts.push('completed=' + String(Number(s.completed || 0)));
parts.push('failed=' + String(Number(s.failed || 0)));
parts.push('skipped=' + String(Number(s.skipped || 0)));
parts.push('pending=' + String(Number(s.pending || 0)));
parts.push('processing=' + String(Number(s.processing || 0)));
var types = s.types || {};
var typePairs = [];
Object.keys(types).forEach(function(typeKey) {
typePairs.push(typeKey + ':' + String(types[typeKey]));
});
if (typePairs.length > 0) {
parts.push('types=' + typePairs.join(','));
}
return parts.join(' ; ');
}

function formatManualQueueSummary(summary) {
var s = summary || {};
var total = Number(s.total || 0);
if (total <= 0) {
return '-';
}
var parts = [];
parts.push('total=' + String(total));
parts.push('unapplied=' + String(Number(s.unapplied_fragments || 0)));
var reasons = s.reasons || {};
var reasonPairs = [];
Object.keys(reasons).forEach(function(reasonKey) {
reasonPairs.push(reasonKey + ':' + String(reasons[reasonKey]));
});
if (reasonPairs.length > 0) {
parts.push('reasons=' + reasonPairs.join(','));
}
return parts.join(' ; ');
}

function buildHistoryUrlForTask(jobId, taskItem, baseHistoryUrl) {
var fallback = new URL(jobsBaseUrl, window.location.origin);
fallback.searchParams.set('page', 'wptsall-tasks');
fallback.searchParams.set('tab', 'history');
fallback.searchParams.set('job_id', String(jobId || ''));
var urlObj = fallback;
try {
if (baseHistoryUrl) {
urlObj = new URL(String(baseHistoryUrl), window.location.origin);
}
} catch (e) {}
urlObj.searchParams.set('page', 'wptsall-tasks');
urlObj.searchParams.set('tab', 'history');
urlObj.searchParams.set('job_id', String(jobId || ''));
if (taskItem && taskItem.status) {
urlObj.searchParams.set('status', String(taskItem.status));
}
if (taskItem && taskItem.business_line) {
urlObj.searchParams.set('business_line', String(taskItem.business_line));
}
return urlObj.toString();
}

function renderJobTaskDetails($panel, jobId, data, baseHistoryUrl) {
var items = Array.isArray(data && data.items) ? data.items : [];
var metaParts = [];
metaParts.push('total=' + String(Number(data && data.total ? data.total : 0)));
metaParts.push('page=' + String(Number(data && data.page ? data.page : 1)) + '/' + String(Number(data && data.pages ? data.pages : 1)));
var summary = (data && data.summary) ? data.summary : {};
var statusSummary = summary.status || {};
var statusPairs = [];
Object.keys(statusSummary).forEach(function(k) {
statusPairs.push(k + ':' + String(statusSummary[k]));
});
if (statusPairs.length > 0) {
metaParts.push('status=' + statusPairs.join(','));
}
var subtaskSummary = summary.subtasks || {};
if (Number(subtaskSummary.total || 0) > 0) {
metaParts.push(
'subtasks='
+ String(Number(subtaskSummary.total || 0))
+ '(c:' + String(Number(subtaskSummary.completed || 0))
+ ',f:' + String(Number(subtaskSummary.failed || 0))
+ ',s:' + String(Number(subtaskSummary.skipped || 0))
+ ')'
);
}
var manualQueueSummary = summary.manual_queue || {};
if (Number(manualQueueSummary.tasks || 0) > 0) {
metaParts.push(
'manual_queue='
+ String(Number(manualQueueSummary.tasks || 0))
+ '(items:' + String(Number(manualQueueSummary.items || 0))
+ ')'
);
}
if (data && data.truncated) {
metaParts.push('truncated=true');
}
$panel.find('.wptsall-job-details-meta').text(metaParts.join(' ; '));

if (items.length === 0) {
$panel.find('.wptsall-job-details-content').html('<em>' + wptsallTasksJobs.i18n.no_tasks_under_current_filter + '</em>');
return;
}

var rows = [];
items.forEach(function(item) {
var taskId = Number(item && item.id ? item.id : 0);
var status = String(item && item.status ? item.status : '');
var businessLine = String(item && item.business_line ? item.business_line : '');
var taskType = String(item && item.task_type ? item.task_type : '');
var subtype = String(item && item.subtype ? item.subtype : '');
var objectType = String(item && item.object_type ? item.object_type : '');
var objectId = Number(item && item.object_id ? item.object_id : 0);
var retryCount = Number(item && item.retry_count ? item.retry_count : 0);
var updatedAt = String(item && item.updated_at ? item.updated_at : '');
var subtaskSummary = formatSubtaskSummary(item && item.subtasks ? item.subtasks : {});
var manualQueue = item && item.manual_queue ? item.manual_queue : {};
var manualQueueText = formatManualQueueSummary(manualQueue);
var manualQueueCell = escapeHtml(manualQueueText);
var manualQueueTotal = Number(manualQueue.total || 0);
if (manualQueueTotal > 0) {
var latestIndex = manualQueueTotal;
manualQueueCell +=
'<div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap;">' +
'<button type="button" class="button button-small wptsall-job-manual-queue-action" data-action="retry" data-task-id="' + escapeHtml(taskId) + '" data-index="' + escapeHtml(latestIndex) + '">' + wptsallTasksJobs.i18n.retry_queue_item + '</button>' +
'<button type="button" class="button button-small wptsall-job-manual-queue-action" data-action="dismiss" data-task-id="' + escapeHtml(taskId) + '" data-index="' + escapeHtml(latestIndex) + '">' + wptsallTasksJobs.i18n.dismiss_latest + '</button>' +
'<button type="button" class="button button-small wptsall-job-manual-queue-action" data-action="clear_all" data-task-id="' + escapeHtml(taskId) + '">' + wptsallTasksJobs.i18n.clear_queue + '</button>' +
'</div>';
}
var objectLabel = objectType + ':' + subtype + '#' + String(objectId);
var lastError = '';
if (item && item.last_error && item.last_error.code) {
lastError = String(item.last_error.code || '');
if (item.last_error.message) {
lastError += ' : ' + String(item.last_error.message);
}
}
var historyUrl = buildHistoryUrlForTask(jobId, item, baseHistoryUrl);
rows.push(
'<tr>' +
'<td>#' + escapeHtml(taskId) + '</td>' +
'<td>' + escapeHtml(status) + '</td>' +
'<td>' + escapeHtml(businessLine) + '</td>' +
'<td>' + escapeHtml(taskType) + '</td>' +
'<td>' + escapeHtml(subtaskSummary) + '</td>' +
'<td>' + manualQueueCell + '</td>' +
'<td>' + escapeHtml(objectLabel) + '</td>' +
'<td>' + escapeHtml(retryCount) + '</td>' +
'<td>' + escapeHtml(updatedAt) + (lastError ? ('<br><small style="color:#a00;">' + escapeHtml(lastError) + '</small>') : '') + '</td>' +
'<td><a class="button button-small" href="' + escapeHtml(historyUrl) + '">' + wptsallTasksJobs.i18n.history_filter + '</a></td>' +
'</tr>'
);
});

var html = '' +
'<table class="widefat striped" style="margin-top: 4px;">' +
'<thead>' +
'<tr>' +
'<th>' + wptsallTasksJobs.i18n.task_id + '</th>' +
'<th>' + wptsallTasksJobs.i18n.status + '</th>' +
'<th>' + wptsallTasksJobs.i18n.business_line + '</th>' +
'<th>' + wptsallTasksJobs.i18n.type + '</th>' +
'<th>' + wptsallTasksJobs.i18n.subtask_summary + '</th>' +
'<th>' + wptsallTasksJobs.i18n.manual_queue + '</th>' +
'<th>' + wptsallTasksJobs.i18n.object + '</th>' +
'<th>' + wptsallTasksJobs.i18n.retry + '</th>' +
'<th>' + wptsallTasksJobs.i18n.updated + '</th>' +
'<th>' + wptsallTasksJobs.i18n.actions + '</th>' +
'</tr>' +
'</thead>' +
'<tbody>' + rows.join('') + '</tbody>' +
'</table>';
$panel.find('.wptsall-job-details-content').html(html);
}

function loadJobTaskDetails($panel, options) {
options = options || {};
var force = !!options.force;
var jobId = String($panel.data('job-id') || '');
var historyUrl = String(options.historyUrl || '');
if (!jobId) {
return;
}
var filters = collectJobDetailFilters($panel);
var cacheKey = [
jobId,
filters.status || '',
filters.business_line || '',
filters.task_type || ''
].join('|');

if (!force && jobTaskCache[cacheKey]) {
renderJobTaskDetails($panel, jobId, jobTaskCache[cacheKey], historyUrl);
return;
}

$panel.find('.wptsall-job-details-meta').text(wptsallTasksJobs.i18n.loading);
$panel.find('.wptsall-job-details-content').html('<em>' + wptsallTasksJobs.i18n.loading_task_details + '</em>');

var requestData = {
page: 1,
per_page: 50
};
if (filters.status) {
requestData.status = filters.status;
}
if (filters.business_line) {
requestData.business_line = filters.business_line;
}
if (filters.task_type) {
requestData.task_type = filters.task_type;
}

$.ajax({
url: restUrl + '/tasks/jobs/' + encodeURIComponent(jobId) + '/tasks',
method: 'GET',
headers: { 'X-WP-Nonce': nonce },
data: requestData,
success: function(resp) {
var data = (resp && resp.items) ? resp : null;
if (!data) {
$panel.find('.wptsall-job-details-meta').text('');
$panel.find('.wptsall-job-details-content').html('<em>' + wptsallTasksJobs.i18n.detail_response_format_error + '</em>');
return;
}
jobTaskCache[cacheKey] = data;
renderJobTaskDetails($panel, jobId, data, historyUrl);
},
error: function(xhr) {
var msg = getErrorMessage(xhr, wptsallTasksJobs.i18n.request_failed);
$panel.find('.wptsall-job-details-meta').text('');
$panel.find('.wptsall-job-details-content').html('<em style="color:#a00;">' + escapeHtml(msg) + '</em>');
}
});
}

function openJobDetailsById(jobId, options) {
options = options || {};
if (!jobId) {
return;
}
var $detailsRow = getJobDetailsRowByJobId(jobId);
if ($detailsRow.length < 1) {
return;
}
closeAllJobDetailRows(jobId);
$detailsRow.show();
var $toggleBtn = getJobToggleButtonByJobId(jobId);
setJobToggleButtonLabel($toggleBtn, true);
var historyUrl = String(options.historyUrl || $toggleBtn.data('history-url') || '');
var $panel = $detailsRow.find('.wptsall-job-details-panel').first();
if (options.filters) {
applyJobDetailFilters($panel, options.filters);
}
var activeFilters = collectJobDetailFilters($panel);
if (false !== options.syncUrl) {
updateJobDetailsStateInUrl(jobId, activeFilters, true);
}
loadJobTaskDetails($panel, { force: !!options.force, historyUrl: historyUrl });
}

$btn.on('click', function() {
var relationId = parseInt($('#wptsall-job-relation-id').val(), 10) || 0;
var includeContent = $('#wptsall-job-include-content').is(':checked');
var includeLanguagePack = $('#wptsall-job-include-language-pack').is(':checked');
var preview = $('#wptsall-job-preview').is(':checked');
var allowExisting = $('#wptsall-job-allow-existing').is(':checked');
var limit = parseInt($('#wptsall-job-limit').val(), 10) || 100;
var batchSize = parseInt($('#wptsall-job-batch-size').val(), 10) || 50;
var targetLang = String($('#wptsall-job-target-lang').val() || '').trim();
var customJobId = String($('#wptsall-job-custom-id').val() || '').trim();

if (!relationId) {
alert(wptsallTasksJobs.i18n.please_select_a_site_relation);
return;
}
if (!includeContent && !includeLanguagePack) {
alert(wptsallTasksJobs.i18n.please_select_at_least_one_task_scope_content_task);
return;
}

var payload = {
relation_id: relationId,
include_content: includeContent,
include_language_pack: includeLanguagePack,
preview: preview,
allow_existing_job: allowExisting,
limit: limit,
batch_size: batchSize
};
if (targetLang) {
payload.target_lang = targetLang;
}
if (customJobId) {
payload.job_id = customJobId;
}

var originalText = $btn.text();
$btn.prop('disabled', true).text(wptsallTasksJobs.i18n.processing);
$result.text('');

$.ajax({
url: restUrl + '/tasks/jobs',
method: 'POST',
headers: { 'X-WP-Nonce': nonce },
contentType: 'application/json',
data: JSON.stringify(payload),
success: function(resp) {
var data = (resp && resp.success && resp.data) ? resp.data : null;
if (!data) {
alert(wptsallTasksJobs.i18n.failed_to_create_task_package);
$btn.prop('disabled', false).text(originalText);
return;
}
var summaryText = wptsallTasksJobs.i18n.task_package_result + ': ';
summaryText += 'job=' + (data.job_id || '-') + ', total=' + (data.total_tasks || 0);
if (data.inserted_count || data.task_samples) {
summaryText += ', inserted=' + String(data.inserted_count || 0);
}
var lines = [summaryText];
var summary = data.summary || {};
var businessLines = summary.business_lines || {};
var taskTypes = summary.task_types || {};
var warnings = Array.isArray(data.warnings) ? data.warnings : [];
var warningLines = [];

var businessLinePairs = [];
Object.keys(businessLines).forEach(function(k) {
businessLinePairs.push(k + ':' + String(businessLines[k]));
});
if (businessLinePairs.length > 0) {
lines.push('business_lines=' + businessLinePairs.join(', '));
}

var taskTypePairs = [];
Object.keys(taskTypes).forEach(function(k) {
taskTypePairs.push(k + ':' + String(taskTypes[k]));
});
if (taskTypePairs.length > 0) {
lines.push('task_types=' + taskTypePairs.join(', '));
}

if (warnings.length > 0) {
warningLines = warnings.slice(0, 5).map(function(item) {
var code = item && item.code ? String(item.code) : 'warning';
var message = item && item.message ? String(item.message) : '';
return code + (message ? (': ' + message) : '');
});
lines.push('warnings=' + warningLines.join(' | '));
}

$result.text(lines.join(' ; '));

if (data.preview) {
var previewMsg = wptsallTasksJobs.i18n.preview_completed_not_saved + '\n';
previewMsg += 'job=' + (data.job_id || '-') + '\n';
previewMsg += 'total=' + (data.total_tasks || 0);
if (warningLines && warningLines.length > 0) {
previewMsg += '\n' + 'warnings=' + warningLines.join(' | ');
}
alert(previewMsg);
$btn.prop('disabled', false).text(originalText);
return;
}

if (!data.total_tasks || Number(data.total_tasks) <= 0) {
alert(wptsallTasksJobs.i18n.task_package_created_but_task_count_is_0_please_ch);
$btn.prop('disabled', false).text(originalText);
return;
}

if (data.task_samples) {
lines.push('task_samples=' + String(data.task_samples));
$result.text(lines.join(' ; '));
}

var redirectUrl = new URL(jobsBaseUrl, window.location.origin);
redirectUrl.searchParams.set('job_id', String(data.job_id || ''));
redirectUrl.searchParams.set('job_bundle_created', '1');
redirectUrl.searchParams.set('job_bundle_total', String(data.total_tasks || 0));
if (data.job_id) {
redirectUrl.searchParams.set('job_bundle_id', String(data.job_id));
}
window.location.href = redirectUrl.toString();
},
error: function(xhr) {
var msg = getErrorMessage(xhr, wptsallTasksJobs.i18n.request_failed);
if (xhr && xhr.status === 409 && xhr.responseJSON && xhr.responseJSON.data) {
var conflictData = xhr.responseJSON.data;
if (conflictData.job_id) {
msg += '\njob_id=' + String(conflictData.job_id);
}
if (conflictData.existing_count) {
msg += '\nexisting=' + String(conflictData.existing_count);
}
}
alert(msg);
$btn.prop('disabled', false).text(originalText);
}
});
});

$(document).on('click', '.wptsall-job-toggle-details', function() {
var $toggleBtn = $(this);
var jobId = String($toggleBtn.data('job-id') || '');
if (!jobId) {
return;
}
var historyUrl = String($toggleBtn.data('history-url') || '');
var $detailsRow = getJobDetailsRowByJobId(jobId);
if ($detailsRow.length < 1) {
return;
}
var isOpen = $detailsRow.is(':visible');
if (isOpen) {
$detailsRow.hide();
setJobToggleButtonLabel($toggleBtn, false);
updateJobDetailsStateInUrl('', {}, false);
return;
}
openJobDetailsById(jobId, {
force: false,
historyUrl: historyUrl,
syncUrl: true
});
});

$batchRetryBtn.on('click', function() {
var businessLine = String($('#wptsall-jobs-batch-business-line').val() || '').trim();
var jobIds = collectCurrentPageJobIds();
if (!jobIds.length) {
alert(wptsallTasksJobs.i18n.no_processable_jobs_on_this_page);
return;
}
var note = window.prompt(wptsallTasksJobs.i18n.optional_note_applied_to_all_batch_requests, '') || '';
var confirmMsg = wptsallTasksJobs.i18n.batch_retry_failed_subtasks_for_jobs_on_this_page_;
confirmMsg += '\njob_count=' + String(jobIds.length);
confirmMsg += '\nscope=' + (businessLine ? ('business_line=' + businessLine) : 'all_business_lines');
if (!window.confirm(confirmMsg)) {
return;
}

var originalText = $batchRetryBtn.text();
$batchRetryBtn.prop('disabled', true).text(wptsallTasksJobs.i18n.batch_processing);
$batchRetryStatus.text('');

var successJobs = 0;
var failedJobs = [];
var sumUpdatedTasks = 0;
var sumUpdatedSubtasks = 0;
var currentIndex = 0;

function runNextBatchJob() {
if (currentIndex >= jobIds.length) {
var doneMsg = wptsallTasksJobs.i18n.batch_execution_completed
+ ' | success_jobs=' + String(successJobs)
+ ' | failed_jobs=' + String(failedJobs.length)
+ ' | updated_tasks=' + String(sumUpdatedTasks)
+ ' | updated_subtasks=' + String(sumUpdatedSubtasks);
$batchRetryStatus.text(doneMsg);
$batchRetryBtn.prop('disabled', false).text(originalText);
if (failedJobs.length > 0) {
alert(doneMsg + '\nfailed_job_ids=' + failedJobs.join(','));
} else {
alert(doneMsg);
}
window.location.reload();
return;
}

var jobId = jobIds[currentIndex];
$batchRetryStatus.text(
wptsallTasksJobs.i18n.batch_processing_2
+ ' (' + String(currentIndex + 1) + '/' + String(jobIds.length) + ')'
+ ' job_id=' + jobId
);
var postData = { note: note };
if (businessLine) {
postData.business_line = businessLine;
}

$.ajax({
url: restUrl + '/tasks/jobs/' + encodeURIComponent(jobId) + '/retry-failed-subtasks',
method: 'POST',
headers: { 'X-WP-Nonce': nonce },
contentType: 'application/json',
data: JSON.stringify(postData),
success: function(resp) {
if (resp && resp.success && resp.data) {
successJobs += 1;
sumUpdatedTasks += Number(resp.data.updated_tasks || 0);
sumUpdatedSubtasks += Number(resp.data.updated_subtasks || 0);
} else {
failedJobs.push(jobId);
}
},
error: function() {
failedJobs.push(jobId);
},
complete: function() {
currentIndex += 1;
runNextBatchJob();
}
});
}

runNextBatchJob();
});

$(document).on('click', '.wptsall-job-details-reload', function() {
var $panel = $(this).closest('.wptsall-job-details-panel');
var jobId = String($panel.data('job-id') || '');
var $toggleBtn = getJobToggleButtonByJobId(jobId);
var historyUrl = String($toggleBtn.data('history-url') || '');
updateJobDetailsStateInUrl(jobId, collectJobDetailFilters($panel), true);
loadJobTaskDetails($panel, { force: true, historyUrl: historyUrl });
});

$(document).on('click', '.wptsall-job-details-retry-failed', function() {
var $actionBtn = $(this);
var $panel = $actionBtn.closest('.wptsall-job-details-panel');
var jobId = String($panel.data('job-id') || '');
if (!jobId) {
alert(wptsallTasksJobs.i18n.missing_job_id_cannot_execute);
return;
}
var filters = collectJobDetailFilters($panel);
var businessLine = String(filters.business_line || '');
var taskType = String(filters.task_type || '');
var scopeLabelParts = [];
if (businessLine) {
scopeLabelParts.push('business_line=' + businessLine);
}
if (taskType) {
scopeLabelParts.push('task_type=' + taskType);
}
var scopeLabel = scopeLabelParts.length > 0 ? scopeLabelParts.join('; ') : 'all_business_lines';
var note = window.prompt(wptsallTasksJobs.i18n.optional_note_leave_blank_to_submit, '') || '';
var originalText = $actionBtn.text();
$actionBtn.prop('disabled', true).text(wptsallTasksJobs.i18n.processing);

var postData = { note: note };
if (businessLine) {
postData.business_line = businessLine;
}
if (taskType) {
postData.task_type = taskType;
}

$.ajax({
url: restUrl + '/tasks/jobs/' + encodeURIComponent(jobId) + '/retry-failed-subtasks',
method: 'POST',
headers: { 'X-WP-Nonce': nonce },
contentType: 'application/json',
data: JSON.stringify(postData),
success: function(resp) {
if (!(resp && resp.success && resp.data)) {
alert(wptsallTasksJobs.i18n.failed_subtask_retry_failed);
$actionBtn.prop('disabled', false).text(originalText);
return;
}
var data = resp.data || {};
alert(
wptsallTasksJobs.i18n.execution_completed +
'\njob_id=' + String(jobId) +
'\nscope=' + String(data.scope || scopeLabel) +
'\nupdated_tasks=' + String(data.updated_tasks || 0) +
'\nupdated_subtasks=' + String(data.updated_subtasks || 0)
);
var $toggleBtn = getJobToggleButtonByJobId(jobId);
var historyUrl = String($toggleBtn.data('history-url') || '');
loadJobTaskDetails($panel, { force: true, historyUrl: historyUrl });
$actionBtn.prop('disabled', false).text(originalText);
},
error: function(xhr) {
alert(getErrorMessage(xhr, wptsallTasksJobs.i18n.request_failed));
$actionBtn.prop('disabled', false).text(originalText);
}
});
});

$(document).on('click', '.wptsall-job-manual-queue-action', function() {
var $actionBtn = $(this);
var taskId = Number($actionBtn.data('task-id') || 0);
var action = String($actionBtn.data('action') || '');
var index = Number($actionBtn.data('index') || 0);
if (!taskId || !action) {
alert(wptsallTasksJobs.i18n.invalid_manual_queue_parameters);
return;
}
if (action === 'clear_all' && !window.confirm(wptsallTasksJobs.i18n.clear_this_task_manual_queue_continue)) {
return;
}
var note = window.prompt(wptsallTasksJobs.i18n.optional_note_leave_blank_to_submit, '') || '';
var originalText = $actionBtn.text();
$actionBtn.prop('disabled', true).text(wptsallTasksJobs.i18n.processing);

var postData = {
action: action,
note: note
};
if (index > 0 && action !== 'clear_all') {
postData.index = index;
}

$.ajax({
url: restUrl + '/tasks/' + taskId + '/manual-queue/resolve',
method: 'POST',
headers: { 'X-WP-Nonce': nonce },
contentType: 'application/json',
data: JSON.stringify(postData),
success: function(resp) {
if (!(resp && resp.success)) {
alert(wptsallTasksJobs.i18n.manual_queue_operation_failed);
$actionBtn.prop('disabled', false).text(originalText);
return;
}
var $panel = $actionBtn.closest('.wptsall-job-details-panel');
var jobId = String($panel.data('job-id') || '');
var $toggleBtn = getJobToggleButtonByJobId(jobId);
var historyUrl = String($toggleBtn.data('history-url') || '');
loadJobTaskDetails($panel, { force: true, historyUrl: historyUrl });
},
error: function(xhr) {
alert(getErrorMessage(xhr, wptsallTasksJobs.i18n.request_failed));
$actionBtn.prop('disabled', false).text(originalText);
}
});
});

$(document).on('change', '.wptsall-job-details-filter-status, .wptsall-job-details-filter-business-line, .wptsall-job-details-filter-task-type', function() {
var $panel = $(this).closest('.wptsall-job-details-panel');
var $row = $(this).closest('.wptsall-job-details-row');
if (!$row.is(':visible')) {
return;
}
var jobId = String($panel.data('job-id') || '');
var $toggleBtn = getJobToggleButtonByJobId(jobId);
var historyUrl = String($toggleBtn.data('history-url') || '');
updateJobDetailsStateInUrl(jobId, collectJobDetailFilters($panel), true);
loadJobTaskDetails($panel, { force: true, historyUrl: historyUrl });
});

if (initialDetailsState && initialDetailsState.job_id) {
var initialJobId = String(initialDetailsState.job_id || '');
if (initialJobId) {
openJobDetailsById(initialJobId, {
force: false,
historyUrl: String(getJobToggleButtonByJobId(initialJobId).data('history-url') || ''),
filters: {
status: String(initialDetailsState.status || ''),
business_line: String(initialDetailsState.business_line || ''),
task_type: String(initialDetailsState.task_type || '')
},
syncUrl: false
});
}
}

$(document).on('click', '.wptsall-job-retry-failed-subtasks-jobtab', function() {
var $actionBtn = $(this);
var jobId = String($actionBtn.data('job-id') || '');
if (!jobId) {
alert(wptsallTasksJobs.i18n.missing_job_id_cannot_execute);
return;
}
var note = window.prompt(wptsallTasksJobs.i18n.optional_note_leave_blank_to_submit, '') || '';
var originalText = $actionBtn.text();
$actionBtn.prop('disabled', true).text(wptsallTasksJobs.i18n.processing);

$.ajax({
url: restUrl + '/tasks/jobs/' + encodeURIComponent(jobId) + '/retry-failed-subtasks',
method: 'POST',
headers: { 'X-WP-Nonce': nonce },
contentType: 'application/json',
data: JSON.stringify({ note: note }),
success: function(resp) {
if (!(resp && resp.success && resp.data)) {
alert(wptsallTasksJobs.i18n.job_failedsubtaskretryfailed);
$actionBtn.prop('disabled', false).text(originalText);
return;
}
var data = resp.data || {};
var redirectUrl = new URL(jobsBaseUrl, window.location.origin);
redirectUrl.searchParams.set('job_id', String(jobId));
redirectUrl.searchParams.set('retried_failed_subtasks', String(data.updated_tasks || 0));
redirectUrl.searchParams.set('retried_failed_subtasks_items', String(data.updated_subtasks || 0));
if (data.scope) {
redirectUrl.searchParams.set('retried_failed_subtasks_scope', String(data.scope));
}
window.location.href = redirectUrl.toString();
},
error: function(xhr) {
alert(getErrorMessage(xhr, wptsallTasksJobs.i18n.request_failed));
$actionBtn.prop('disabled', false).text(originalText);
}
});
});
});
