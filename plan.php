<?php

namespace CCTC\SourceDataVerificationModule;

use CCTC\SourceDataVerificationModule\Classes\SdvPlan;
use CCTC\SourceDataVerificationModule\Classes\SdvService;

/** @var SourceDataVerificationModule $module */
require __DIR__ . '/page_init.php';

echo '<link rel="stylesheet" href="' . h($module->getUrl('css/sdv.css')) . '">';
echo "<div class='projhdr'><i class='fas fa-list-check'></i> SDV Plan</div>";

if (!$settings->isConfigured()) {
    echo "<div class='yellow'>Source Data Verification is not configured for this project.</div>";
    return;
}
if (!$roles->hasAnyRole() && !$roles->isSuperUser) {
    echo "<div class='red'>You do not have an SDV role in this project.</div>";
    return;
}

$canEdit = $roles->canEditPlan();
$regex = $settings->flagRegex();
$ignore = $settings->ignoreTag();

echo "<p style='max-width: 900px'>The SDV plan sets which fields need Source Data Verification. By default, a field <b>requires SDV</b> when its
      action tags match <code>" . h(trim($regex, '/')) . "</code>"
    . ($ignore !== '' ? " and is <b>excluded</b> when it has <code>" . h($ignore) . "</code>" : '')
    . ". Other fields are <b>optional</b>: monitors may verify them" . ($settings->monitorsOnlyRequired() ? ' (disabled by the project settings)' : '')
    . " but they don't stop a form being marked SDV complete.</p>";
echo $canEdit
    ? "<p style='max-width: 900px'>As a monitor you can change a field's requirement below. The change applies to that field on <b>all records</b>
       and is recorded in the project Logging. Forms already marked SDV complete keep their status.</p>"
    : "<p id='cctc-sdv-plan-readonly'><i>Only monitors can change the SDV plan.</i></p>";

$sections = '';
$totalRequired = 0;
foreach ($proj->forms as $instrument => $info) {
    $fields = $plan->forInstrument($instrument);
    if (empty($fields)) continue;
    $required = count(array_filter($fields, fn($f) => $f['requirement'] === SdvPlan::REQUIRED));
    $totalRequired += $required;

    $sections .= "<details class='cctc-sdv-plan-form' style='margin: 6px 0; max-width: 1000px' " . ($required ? 'open' : '') . ">
        <summary><b>" . h(strip_tags($info['menu'])) . "</b> <small>(" . h($instrument) . ") - $required of " . count($fields) . " field(s) require SDV</small></summary>
        <table class='cctc-sdv-table' data-instrument='" . h($instrument) . "'><tr>
            <th style='width: 22%'>Variable</th><th>Label</th><th style='width: 18%'>Matching action tags</th>
            <th style='width: 10%'>Default</th><th style='width: 17%'>SDV requirement</th></tr>";
    foreach ($fields as $field => $f) {
        $control = h(ucfirst($f['requirement'])) . ($f['override'] ? ' <small>(set on plan)</small>' : '');
        if ($canEdit) {
            $control = "<select class='cctc-sdv-plan-select' data-field='" . h($field) . "' data-current='" . h($f['requirement']) . "' aria-label='SDV requirement for " . h($field) . "'>";
            foreach (SdvPlan::VALUES as $v) {
                $sel = $v === $f['requirement'] ? 'selected' : '';
                $control .= "<option value='$v' $sel>" . ucfirst($v) . ($v === $f['default'] ? ' (default)' : '') . "</option>";
            }
            $control .= "</select>";
        }
        $sections .= "<tr data-field='" . h($field) . "'><td>" . h($field) . "</td><td>" . h($f['label']) . "</td>
            <td>" . h(implode(', ', $f['tags'])) . "</td><td>" . h(ucfirst($f['default'])) . "</td><td>$control</td></tr>";
    }
    $sections .= "</table></details>";
}

if ($totalRequired === 0) {
    echo "<div class='yellow' style='max-width: 900px'>No fields currently require SDV. Add the action tag to fields in the Online Designer,
          or set fields to Required below.</div>";
}
echo "<div id='cctc-sdv-plan-message' style='display:none; max-width: 900px'></div>";
echo $sections;

// recent plan changes (module audit trail; the same changes are in the project Logging)
$history = $repo->getPlanAudit();
if (!empty($history)) {
    echo "<h6 style='margin-top: 20px'>Recent SDV plan changes</h6><table class='cctc-sdv-table' id='cctc-sdv-plan-history' style='max-width: 1000px'>
          <tr><th>When</th><th>User</th><th>Field</th><th>Change</th></tr>";
    foreach ($history as $a) {
        echo "<tr><td>" . h(SdvService::formatTs($a['ts'])) . "</td><td>" . h($a['username']) . "</td><td>" . h($a['field_name']) . "</td>
              <td>" . h(ucfirst((string)$a['old_status'])) . " → " . h(ucfirst((string)$a['new_status'])) . "</td></tr>";
    }
    echo "</table>";
}

if ($canEdit) {
    $module->initializeJavascriptModuleObject();
    $jsObj = $module->getJavascriptModuleObjectName();
    ?>
    <script>
    (function () {
        var module = <?= $jsObj ?>;
        var msg = document.getElementById('cctc-sdv-plan-message');
        function show(text, isError) {
            msg.textContent = text;
            msg.className = isError ? 'red' : 'darkgreen';
            msg.style.display = 'block';
        }
        document.querySelectorAll('select.cctc-sdv-plan-select').forEach(function (sel) {
            sel.addEventListener('change', function () {
                var field = sel.getAttribute('data-field');
                var previous = sel.getAttribute('data-current');
                if (!confirm('Change the SDV requirement of "' + field + '" to "' + sel.value + '" for this field on ALL records?')) {
                    sel.value = previous;
                    return;
                }
                sel.disabled = true;
                module.ajax('setPlan', { field: field, requirement: sel.value }).then(function (resp) {
                    sel.disabled = false;
                    if (!resp || !resp.ok) {
                        sel.value = previous;
                        show((resp && resp.error) || 'The change could not be saved.', true);
                        return;
                    }
                    sel.setAttribute('data-current', resp.field.requirement);
                    sel.value = resp.field.requirement;
                    show('SDV requirement of ' + field + ' set to ' + resp.field.requirement + '.', false);
                }).catch(function (err) {
                    sel.disabled = false;
                    sel.value = previous;
                    show('The change could not be saved: ' + err, true);
                });
            });
        });
    })();
    </script>
    <?php
}
