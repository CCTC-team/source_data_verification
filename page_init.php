<?php

// Shared set-up for the module's project pages. Sets $settings, $repo, $plan, $service, $proj, $roles, $dash.

namespace CCTC\SourceDataVerificationModule;

require_once __DIR__ . '/classes/Dashboard.php';

use CCTC\SourceDataVerificationModule\Classes\Dashboard;
use CCTC\SourceDataVerificationModule\Classes\Roles;

/** @var SourceDataVerificationModule $module */
$projectId = (int)$module->getProjectId();
[$settings, $repo, $plan, $service, $proj] = $module->services($projectId);
$roles = new Roles($module, $settings, $projectId);
$dash = new Dashboard($proj, $settings, $repo, $plan, $service, $roles);

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
