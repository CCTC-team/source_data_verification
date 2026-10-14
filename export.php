<?php

namespace CCTC\SourceDataVerificationModule;

use CCTC\SourceDataVerificationModule\Classes\SdvService;

/** @var SourceDataVerificationModule $module */
require __DIR__ . '/page_init.php';

if (!$settings->isConfigured() || (!$roles->hasAnyRole() && !$roles->isSuperUser)) {
    http_response_code(403);
    exit('You do not have access to SDV data in this project.');
}

$in = $_GET;
$tab = $dash->tab($in) === 'queries' ? 'queries' : 'forms';
[, , $dir] = $dash->paging($in);

$filename = 'SDV_' . $tab . '_pid' . $projectId . '_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

// neutralise spreadsheet formulas in exported text (tab and carriage return can also start one)
$cell = fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
$row = function (array $values) use ($out, $cell) { fputcsv($out, array_map($cell, $values)); };

if ($tab === 'forms') {
    $row(['record', 'site', 'event', 'form', 'instance', 'sdv_status', 'status_reason', 'required_fields', 'required_verified',
          'fields_verified', 'active_queries', 'last_activity_by', 'last_activity_at', 'completed_by', 'completed_at']);
    foreach ($dash->formRows($dash->formFilters($in), null, 0, $dir) as $r) {
        $row([$r['record'], $dash->siteName($r['group_id']), $dash->eventName($r['event_id']), $r['instrument'], $r['instance'],
              SdvService::STATUS_LABELS[$r['status']] ?? $r['status'], $r['status_reason'], $r['required'], $r['required_verified'],
              $r['verified'], $r['active_queries'], $r['updated_by'], $r['updated_at'], $r['completed_by'], $r['completed_at']]);
    }
} else {
    $row(['query_id', 'record', 'site', 'event', 'form', 'instance', 'field', 'status', 'raised_by', 'raised_at', 'updated_at',
          'latest_query', 'latest_response', 'thread']);
    foreach ($dash->queryRows($dash->queryFilters($in), null, 0, $dir) as $r) {
        $thread = implode(' | ', array_map(fn($m) => "{$m['ts']} {$m['username']} {$m['action']}"
            . ($m['response_code'] ? ": {$m['response_code']}" : '') . ($m['text'] ? ": {$m['text']}" : ''), $r['messages']));
        $row([$r['query_id'], $r['record'], $dash->siteName($r['group_id']), $dash->eventName($r['event_id']), $r['instrument'],
              $r['instance'], $r['field_name'], $r['status'], $r['raised_by'], $r['raised_at'], $r['updated_at'],
              $r['query_text'], $r['response'], $thread]);
    }
}
fclose($out);

\REDCap::logEvent('SDV: data exported', "export = '$tab'", '', null, null, $projectId);
