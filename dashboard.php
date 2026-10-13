<?php

namespace CCTC\SourceDataVerificationModule;

use CCTC\SourceDataVerificationModule\Classes\Dashboard;
use CCTC\SourceDataVerificationModule\Classes\SdvService;

/** @var SourceDataVerificationModule $module */
require __DIR__ . '/page_init.php';

echo '<link rel="stylesheet" href="' . h($module->getUrl('css/sdv.css')) . '">';
echo "<div class='projhdr'><i class='fas fa-clipboard-check'></i> SDV Dashboard</div>";

if (!$settings->isConfigured()) {
    echo "<div class='yellow'>Source Data Verification is not configured for this project. An administrator must set the monitor and data entry roles in the module configuration.</div>";
    return;
}
if (!$roles->hasAnyRole() && !$roles->isSuperUser) {
    echo "<div class='red'>You do not have an SDV role (monitor, data entry or data manager) in this project.</div>";
    return;
}

// sync: forced from the button (POST, CSRF-protected by REDCap), otherwise at most every few minutes
$forced = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['sync']);
$syncResult = $dash->syncIfDue($forced);

$in = $_GET;
$tab = $dash->tab($in);
[$size, $page, $dir] = $dash->paging($in);
$dashboardUrl = $module->getUrl('dashboard.php');
$url = fn(array $params) => $dashboardUrl . (empty($params) ? '' : '&' . http_build_query($params));
$keep = array_diff_key($in, array_flip(['prefix', 'page', 'pid', 'pg', 'redcap_csrf_token']));

echo "<p>Track Source Data Verification progress, open monitor queries and completion by form. Click a record to open the form.</p>";

if ($forced && $syncResult) {
    echo "<div class='darkgreen' style='max-width: 800px' id='cctc-sdv-sync-result'>Sync complete: {$syncResult['added']} form(s) added, {$syncResult['removed']} removed,
          {$syncResult['changedForms']} form(s) with verified data changed outside data entry.</div>";
}

// tabs
echo "<div class='cctc-sdv-tabs btn-group' role='group'>";
foreach (['forms' => 'Forms', 'queries' => 'Queries', 'summary' => 'Summary'] as $t => $label) {
    $active = $tab === $t ? 'active' : '';
    echo "<a class='btn btn-outline-primary btn-sm $active' href='" . h($url(['tab' => $t])) . "'>$label</a>";
}
echo "</div>";

echo "<form method='post' style='display:inline; margin-left: 10px' action='" . h($url($keep)) . "'>
        <button class='btn btn-defaultrc btn-sm' name='sync' value='1' id='cctc-sdv-sync' title='Find newly saved forms and changes made by data import or the API'>
        <i class='fas fa-sync'></i> Sync now</button></form>";

// ---- filter helpers ------------------------------------------------------------------------------

$select = function (string $name, array $options, string $current, string $anyLabel) {
    $html = "<select name='$name' class='x-form-text x-form-field' onchange='this.form.pg.value=0; this.form.submit()'>";
    $html .= "<option value=''>" . h($anyLabel) . "</option>";
    foreach ($options as $value => $label) {
        $sel = (string)$value === $current ? 'selected' : '';
        $html .= "<option value='" . h($value) . "' $sel>" . h($label) . "</option>";
    }
    return $html . '</select>';
};
$text = fn(string $name, string $value, string $placeholder = '') =>
    "<input type='text' name='$name' value='" . h($value) . "' placeholder='" . h($placeholder) . "' class='x-form-text x-form-field' style='width: 140px'>";
$dateInput = fn(string $name, ?string $value) =>
    "<input type='date' name='$name' value='" . h($value ? substr($value, 0, 10) : '') . "' class='x-form-text x-form-field'>";

$siteOptions = $roles->groupId === null ? $dash->groups : [];
$eventOptions = [];
if ($proj->longitudinal) foreach ($proj->eventInfo as $eid => $e) $eventOptions[$eid] = $e['name_ext'];
$formOptions = [];
foreach ($proj->forms as $f => $info) $formOptions[$f] = strip_tags($info['menu']);
$users = $repo->distinctUsers();
$userOptions = array_combine($users, $users);

$hidden = "<input type='hidden' name='prefix' value='" . h($module->PREFIX) . "'>
           <input type='hidden' name='page' value='dashboard'>
           <input type='hidden' name='pid' value='$projectId'>
           <input type='hidden' name='tab' value='$tab'>
           <input type='hidden' name='pg' value='$page'>";

$pager = function (int $total) use ($page, $size, $dir, $url, $keep) {
    $pages = max(1, (int)ceil($total / $size));
    $from = $total === 0 ? 0 : $page * $size + 1;
    $to = min($total, ($page + 1) * $size);
    $prev = $page > 0 ? "<a class='btn btn-defaultrc btn-xs' id='cctc-sdv-prev' href='" . h($url(array_merge($keep, ['pg' => $page - 1]))) . "'><i class='fas fa-arrow-left'></i></a>" : '';
    $next = $page + 1 < $pages ? "<a class='btn btn-defaultrc btn-xs' id='cctc-sdv-next' href='" . h($url(array_merge($keep, ['pg' => $page + 1]))) . "'><i class='fas fa-arrow-right'></i></a>" : '';
    $sizes = '';
    foreach (Dashboard::PAGE_SIZES as $s) {
        $sel = $s === $size ? 'selected' : '';
        $sizes .= "<option $sel>$s</option>";
    }
    $dirSel = fn($d) => $d === $dir ? 'selected' : '';
    return "<div class='cctc-sdv-pager'>$prev $next <span id='cctc-sdv-paging'>$from to $to of $total</span>
            <label>Page size <select name='size' onchange='this.form.pg.value=0; this.form.submit()'>$sizes</select></label>
            <label>Order <select name='dir' onchange='this.form.submit()'><option value='desc' {$dirSel('desc')}>Newest first</option><option value='asc' {$dirSel('asc')}>Oldest first</option></select></label></div>";
};

$exportLinks = function (string $exportTab) use ($module, $keep) {
    $base = $module->getUrl('export.php');
    $filtered = h($base . '&' . http_build_query(array_merge($keep, ['tab' => $exportTab])));
    $all = h($base . '&' . http_build_query(['tab' => $exportTab]));
    return "<a class='btn btn-defaultrc btn-xs' href='$filtered'><i class='fas fa-file-csv'></i> Export (filtered)</a>
            <a class='btn btn-defaultrc btn-xs' href='$all'><i class='fas fa-file-csv'></i> Export all</a>";
};

// ---- forms tab -----------------------------------------------------------------------------------

if ($tab === 'forms') {
    $flt = $dash->formFilters($in);
    $total = $repo->countForms($flt, $dash->withDags);
    $rows = $dash->formRows($flt, $size, $page * $size, $dir);

    echo "<form method='get' class='blue' style='padding: 8px; max-width: 1100px'>$hidden<table class='cctc-sdv-filters'><tr>
            <td>Record</td><td>{$text('record', $flt['record'])}</td>
            <td>SDV status</td><td>{$select('status', SdvService::STATUS_LABELS, $flt['status'], 'Any')}</td>
            <td>Active queries</td><td>{$select('openq', ['yes' => 'Yes', 'no' => 'No'], $flt['open_queries'], 'Any')}</td></tr><tr>
            <td>Form</td><td>{$select('form', $formOptions, $flt['instrument'], 'All forms')}</td>";
    echo $proj->longitudinal ? "<td>Event</td><td>{$select('event', $eventOptions, $flt['event_id'], 'All events')}</td>" : "<td></td><td></td>";
    echo $siteOptions ? "<td>Site (DAG)</td><td>{$select('site', $siteOptions, $flt['group_id'], 'All sites')}</td>" : "<td></td><td></td>";
    echo "</tr><tr><td>User</td><td>{$select('user', $userOptions, $flt['user'], 'Anyone')}</td>
            <td>Activity from</td><td>{$dateInput('from', $flt['from'])}</td>
            <td>to</td><td>{$dateInput('to', $flt['to'])}</td></tr></table>
            <div class='cctc-sdv-row'><button class='btn btn-primaryrc btn-xs' type='submit' onclick='this.form.pg.value=0'>Apply filters</button>
            <a class='btn btn-link btn-xs' href='" . h($url(['tab' => 'forms'])) . "'>Reset</a> {$exportLinks('forms')}</div>
            {$pager($total)}</form>";

    if (empty($rows)) {
        echo "<p>No forms match these filters.</p>";
    } else {
        echo "<table class='cctc-sdv-table' id='cctc-sdv-forms-table' style='max-width: 1100px'><tr>
                <th>Record</th>" . ($dash->withDags ? '<th>Site</th>' : '') . ($proj->longitudinal ? '<th>Event</th>' : '') . "
                <th>Form</th><th style='width:60px'>Instance</th><th>SDV status</th><th>Required verified</th>
                <th style='width:70px'>Active queries</th><th>Last activity</th></tr>";
        foreach ($rows as $r) {
            $pct = $r['required'] ? (int)round(100 * $r['required_verified'] / $r['required']) : 0;
            $activity = $r['updated_by'] ? h($r['updated_by']) . '<br><small>' . h(SdvService::formatTs($r['updated_at'])) . '</small>' : '';
            $statusExtra = $r['status_reason'] ? "<br><small>" . h($r['status_reason']) . "</small>" : '';
            echo "<tr data-record='" . h($r['record']) . "' data-form='" . h($r['instrument']) . "'>
                <td><a href='" . h($dash->formUrl($r['record'], $r['event_id'], $r['instrument'], $r['instance'])) . "'>" . h($r['record']) . "</a></td>"
                . ($dash->withDags ? '<td>' . h($dash->siteName($r['group_id'])) . '</td>' : '')
                . ($proj->longitudinal ? '<td>' . h($dash->eventName($r['event_id'])) . '</td>' : '') . "
                <td>" . h($dash->formName($r['instrument'])) . "</td>
                <td>" . (int)$r['instance'] . "</td>
                <td><span class='cctc-sdv-status cctc-sdv-status-" . h($r['status']) . "'>" . h(SdvService::STATUS_LABELS[$r['status']] ?? $r['status']) . "</span>$statusExtra</td>
                <td>{$r['required_verified']} / {$r['required']} <span class='cctc-sdv-progress'><span style='width: $pct%'></span></span></td>
                <td>{$r['active_queries']}</td>
                <td>$activity</td></tr>";
        }
        echo "</table>";
    }
}

// ---- queries tab ---------------------------------------------------------------------------------

if ($tab === 'queries') {
    $flt = $dash->queryFilters($in);
    $total = $repo->countQueries($flt, $dash->withDags);
    $rows = $dash->queryRows($flt, $size, $page * $size, $dir);

    $responseOptions = array_combine(SdvService::RESPONSE_CODES, SdvService::RESPONSE_CODES);
    echo "<form method='get' class='blue' style='padding: 8px; max-width: 1100px'>$hidden<table class='cctc-sdv-filters'><tr>
            <td>Record</td><td>{$text('record', $flt['record'])}</td>
            <td>Query status</td><td>{$select('qstatus', SdvService::QUERY_STATUS_LABELS, $flt['status'], 'Any')}</td>
            <td>Response</td><td>{$select('response', $responseOptions, $flt['response'], 'Any')}</td></tr><tr>
            <td>Form</td><td>{$select('form', $formOptions, $flt['instrument'], 'All forms')}</td>
            <td>Field</td><td>{$text('field', $flt['field'], 'variable name')}</td>";
    echo $siteOptions ? "<td>Site (DAG)</td><td>{$select('site', $siteOptions, $flt['group_id'], 'All sites')}</td>" : "<td></td><td></td>";
    echo "</tr><tr><td>Raised by</td><td>{$select('user', $userOptions, $flt['user'], 'Anyone')}</td>
            <td>Text contains</td><td>{$text('text', $flt['text'])}</td>";
    echo $proj->longitudinal ? "<td>Event</td><td>{$select('event', $eventOptions, $flt['event_id'], 'All events')}</td>" : "<td></td><td></td>";
    echo "</tr><tr><td>Updated from</td><td>{$dateInput('from', $flt['from'])}</td><td>to</td><td>{$dateInput('to', $flt['to'])}</td></tr></table>
            <div class='cctc-sdv-row'><button class='btn btn-primaryrc btn-xs' type='submit' onclick='this.form.pg.value=0'>Apply filters</button>
            <a class='btn btn-link btn-xs' href='" . h($url(['tab' => 'queries'])) . "'>Reset</a> {$exportLinks('queries')}</div>
            {$pager($total)}</form>";

    if (empty($rows)) {
        echo "<p>No monitor queries match these filters.</p>";
    } else {
        echo "<table class='cctc-sdv-table' id='cctc-sdv-queries-table' style='max-width: 1100px'><tr>
                <th>Record</th>" . ($dash->withDags ? '<th>Site</th>' : '') . ($proj->longitudinal ? '<th>Event</th>' : '') . "
                <th>Form</th><th style='width:60px'>Instance</th><th>Field</th><th>Status</th><th>Query</th><th>Response</th><th>Raised</th></tr>";
        foreach ($rows as $r) {
            echo "<tr data-query-id='" . (int)$r['query_id'] . "'>
                <td><a href='" . h($dash->formUrl($r['record'], $r['event_id'], $r['instrument'], $r['instance'])) . "'>" . h($r['record']) . "</a></td>"
                . ($dash->withDags ? '<td>' . h($dash->siteName($r['group_id'])) . '</td>' : '')
                . ($proj->longitudinal ? '<td>' . h($dash->eventName($r['event_id'])) . '</td>' : '') . "
                <td>" . h($dash->formName($r['instrument'])) . "</td>
                <td>" . (int)$r['instance'] . "</td>
                <td>" . h($dash->fieldText($r['field_name'])) . "</td>
                <td>" . h(SdvService::QUERY_STATUS_LABELS[$r['status']] ?? $r['status']) . "</td>
                <td>" . h($r['query_text']) . "</td>
                <td>" . h($r['response']) . "</td>
                <td>" . h($r['raised_by']) . "<br><small>" . h(SdvService::formatTs($r['raised_at'])) . "</small></td></tr>";
        }
        echo "</table>";
    }
}

// ---- summary tab ---------------------------------------------------------------------------------

if ($tab === 'summary') {
    $summary = $dash->summary();
    $statuses = SdvService::STATUS_LABELS;
    if (empty($summary)) {
        echo "<p>No forms requiring SDV have been saved yet.</p>";
    }
    ksort($summary);
    foreach ($summary as $site => $byInstrument) {
        if ($dash->withDags) {
            echo "<h6 style='margin-top: 15px'>" . h($site === '' ? 'Records not assigned to a site' : 'Site: ' . $dash->siteName($site)) . "</h6>";
        }
        echo "<table class='cctc-sdv-table cctc-sdv-summary-table' style='max-width: 1100px'><tr><th>Form</th>";
        foreach ($statuses as $label) echo "<th>" . h($label) . "</th>";
        echo "<th>Total</th><th>Done</th></tr>";
        $totals = array_fill_keys(array_keys($statuses), 0);
        foreach ($proj->forms as $instrument => $info) {
            if (!isset($byInstrument[$instrument])) continue;
            $counts = $byInstrument[$instrument];
            $total = array_sum($counts);
            $done = ($counts['complete'] ?? 0) + ($counts['not_required'] ?? 0);
            $pct = $total ? (int)round(100 * $done / $total) : 0;
            echo "<tr><td>" . h($dash->formName($instrument)) . "</td>";
            foreach (array_keys($statuses) as $s) {
                $n = $counts[$s] ?? 0;
                $totals[$s] += $n;
                $link = $url(['tab' => 'forms', 'form' => $instrument, 'status' => $s] + ($dash->withDags && $site !== '' ? ['site' => $site] : []));
                echo "<td>" . ($n ? "<a href='" . h($link) . "'>$n</a>" : '0') . "</td>";
            }
            echo "<td>$total</td><td>$pct% <span class='cctc-sdv-progress'><span style='width: $pct%'></span></span></td></tr>";
        }
        $grand = array_sum($totals);
        $grandDone = $totals['complete'] + $totals['not_required'];
        $grandPct = $grand ? (int)round(100 * $grandDone / $grand) : 0;
        echo "<tr style='font-weight: bold'><td>All forms</td>";
        foreach ($totals as $n) echo "<td>$n</td>";
        echo "<td>$grand</td><td>$grandPct%</td></tr></table>";
    }
}
