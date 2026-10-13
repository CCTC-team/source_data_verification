<?php

namespace CCTC\SourceDataVerificationModule;

use ExternalModules\AbstractExternalModule;
use Throwable;

require_once __DIR__ . '/classes/SdvException.php';
require_once __DIR__ . '/classes/TransactionConflictException.php';
require_once __DIR__ . '/classes/Settings.php';
require_once __DIR__ . '/classes/Roles.php';
require_once __DIR__ . '/classes/FormContext.php';
require_once __DIR__ . '/classes/ValueHasher.php';
require_once __DIR__ . '/classes/Repository.php';
require_once __DIR__ . '/classes/SdvPlan.php';
require_once __DIR__ . '/classes/SdvService.php';

use CCTC\SourceDataVerificationModule\Classes\FormContext;
use CCTC\SourceDataVerificationModule\Classes\Repository;
use CCTC\SourceDataVerificationModule\Classes\Roles;
use CCTC\SourceDataVerificationModule\Classes\SdvException;
use CCTC\SourceDataVerificationModule\Classes\SdvPlan;
use CCTC\SourceDataVerificationModule\Classes\SdvService;
use CCTC\SourceDataVerificationModule\Classes\Settings;

class SourceDataVerificationModule extends AbstractExternalModule
{
    /*
        See README.md for how this module works.
        All SDV state lives in the sdv_* tables (sql/schema.sql); no REDCap core files are modified.
     */

    private const MONITOR_ACTIONS = [
        'verifyField', 'unverifyField', 'verifyAllRequired', 'completeForm', 'markNotRequired', 'reopenForm',
        'raiseQuery', 'reraiseQuery', 'acceptQuery', 'closeQuery',
    ];
    private const QUERY_ACTIONS = ['respondQuery', 'reraiseQuery', 'acceptQuery', 'closeQuery'];

    // ---- wiring ---------------------------------------------------------------------------------

    public function project($projectId): \Project
    {
        global $Proj;
        if (isset($Proj) && (int)$Proj->project_id === (int)$projectId) return $Proj;
        return new \Project($projectId);
    }

    /** @return array{0:Settings,1:Repository,2:SdvPlan,3:SdvService,4:\Project} */
    public function services($projectId): array
    {
        $proj = $this->project($projectId);
        $settings = new Settings($this, $projectId);
        $repo = new Repository((int)$projectId);
        $plan = new SdvPlan($proj, $settings, $repo);
        return [$settings, $repo, $plan, new SdvService($proj, $settings, $repo, $plan), $proj];
    }

    // Throws unless the user may see this form instance (form rights and DAG)
    public function assertAccess(Roles $roles, Repository $repo, FormContext $ctx): void
    {
        if (!$roles->canViewForm($ctx->instrument)) {
            throw new SdvException('You do not have access to this form.');
        }
        if ($roles->groupId !== null && $repo->recordGroupId($ctx->record) !== $roles->groupId) {
            throw new SdvException('You do not have access to this record.');
        }
    }

    // The SDV Dashboard and SDV Plan pages are for users with an SDV role (the framework default is design rights only)
    public function redcap_module_link_check_display($project_id, $link)
    {
        if (empty($project_id)) return null;
        try {
            $settings = new Settings($this, $project_id);
            $roles = new Roles($this, $settings, $project_id);
            return ($roles->isSuperUser || ($settings->isConfigured() && $roles->hasAnyRole())) ? $link : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ---- system enable --------------------------------------------------------------------------

    public function redcap_module_system_enable($version): void
    {
        $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $statement) {
            if (trim($statement) !== '') $this->query($statement, []);
        }
        $this->log('SDV tables checked/created', ['version' => $version]);
    }

    // ---- data entry form ------------------------------------------------------------------------

    public function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance)
    {
        if (empty($project_id) || $record === null || $record === '') return;
        try {
            $this->renderForm($project_id, $record, $instrument, $event_id, $repeat_instance);
        } catch (Throwable $e) {
            $this->log('Error in redcap_data_entry_form', [
                'error' => $e->getMessage(), 'record' => $record, 'instrument' => $instrument, 'event_id' => $event_id,
            ]);
            echo "<div class='red' style='margin: 10px 0; max-width: 800px;'><strong>Source Data Verification:</strong>
                  the SDV panel could not be loaded. Please contact your administrator if this persists.</div>";
        }
    }

    private function renderForm($projectId, $record, $instrument, $eventId, $instance): void
    {
        $settings = new Settings($this, $projectId);
        $roles = new Roles($this, $settings, $projectId);
        if (!$settings->isConfigured()) {
            if ($roles->isSuperUser) {
                echo "<div class='yellow' style='margin: 10px 0; max-width: 800px;'>Source Data Verification is enabled but not configured.
                      Set the monitor and data entry roles in the module configuration.</div>";
            }
            return;
        }
        if (!$roles->hasAnyRole() && !$roles->isSuperUser) return;

        [, $repo, $plan, $service, $proj] = $this->services($projectId);
        if (empty($plan->sdvFieldNames($instrument))) return;

        $ctx = FormContext::fromInput($proj, $record, $eventId, $instrument, $instance);
        [$loaded, $notices] = $service->checkForm($ctx, $roles->username, 'form opened');
        $needsRow = $loaded['exists'] && $loaded['form'] === null && !empty($plan->requiredFields($instrument));
        if ($needsRow) {
            $repo->ensureFormRow($ctx, SdvService::now());
        }
        if ($needsRow) {
            $loaded = $service->load($ctx);
        }

        $state = $service->buildState($ctx, $roles, $loaded, $notices);

        $restricted = ($roles->isMonitor || $roles->isDataManager) && !$roles->isDataEntry;
        $ui = [
            'hideSave' => $restricted && $settings->hideSaveButtons(),
            'readonly' => $restricted && $settings->makeReadonly(),
            'formFields' => array_values(array_filter(array_keys($proj->forms[$instrument]['fields']),
                fn($f) => $f !== "{$instrument}_complete")),
        ];

        $this->initializeJavascriptModuleObject();
        $json = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
        echo '<link rel="stylesheet" href="' . htmlspecialchars($this->getUrl('css/sdv.css'), ENT_QUOTES) . '">';
        echo '<script src="' . htmlspecialchars($this->getUrl('js/sdv-form.js'), ENT_QUOTES) . '"></script>';
        echo '<div id="cctc-sdv-panel" class="cctc-sdv-panel"></div>';
        echo '<script>CctcSdvForm.init(' . $this->getJavascriptModuleObjectName() . ', '
            . json_encode($state, $json) . ', ' . json_encode($ui, $json) . ');</script>';
    }

    // ---- save hook ------------------------------------------------------------------------------

    public function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance): void
    {
        try {
            $settings = new Settings($this, $project_id);
            if (!$settings->isConfigured()) return;
            [, , $plan, $service, $proj] = $this->services($project_id);
            if (empty($plan->sdvFieldNames($instrument))) return;

            $ctx = FormContext::fromInput($proj, $record, $event_id, $instrument, $repeat_instance);
            $user = defined('USERID') ? USERID : '[survey respondent]';
            $service->onSave($ctx, $user);
        } catch (Throwable $e) {
            // never block the user's save
            $this->log('Error in redcap_save_record', [
                'error' => $e->getMessage(), 'record' => $record, 'instrument' => $instrument, 'event_id' => $event_id,
            ]);
        }
    }

    // ---- ajax -----------------------------------------------------------------------------------

    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
                                       $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        try {
            if (empty($project_id)) throw new SdvException('No project.');
            $payload = is_array($payload) ? $payload : [];
            [$settings, $repo, , $service, $proj] = $this->services($project_id);
            if (!$settings->isConfigured()) throw new SdvException('Source Data Verification is not configured for this project.');
            $roles = new Roles($this, $settings, $project_id);
            if (!$roles->hasAnyRole() && !$roles->isSuperUser) throw new SdvException('You do not have an SDV role in this project.');
            $user = $roles->username;

            if (in_array($action, self::MONITOR_ACTIONS, true) && !$roles->isMonitor) {
                throw new SdvException('Only monitors can do this.');
            }

            if ($action === 'setPlan') {
                if (!$roles->canEditPlan()) throw new SdvException('Only monitors can change which fields require SDV.');
                $info = $service->setPlan((string)($payload['field'] ?? ''), (string)($payload['requirement'] ?? ''), $user);
                $result = ['ok' => true, 'field' => ['requirement' => $info['requirement'], 'override' => $info['override'], 'default' => $info['default']]];
                if (!empty($payload['ctx'])) {
                    $ctx = $this->ctxFromPayload($proj, $payload['ctx']);
                    $this->assertAccess($roles, $repo, $ctx);
                    // the plan changed, so build the state with a fresh plan
                    [, , , $fresh] = $this->services($project_id);
                    $result['state'] = $fresh->buildState($ctx, $roles, $fresh->load($ctx));
                    $result['message'] = "SDV requirement of {$payload['field']} set to {$info['requirement']} on all records.";
                }
                return $result;
            }

            if ($action === 'setPlanForm') {
                if (!$roles->canEditPlan()) throw new SdvException('Only monitors can change which fields require SDV.');
                $infos = $service->setPlanForInstrument((string)($payload['instrument'] ?? ''), (string)($payload['requirement'] ?? ''), $user);
                return ['ok' => true, 'fields' => array_map(fn($i) => [
                    'requirement' => $i['requirement'], 'override' => $i['override'], 'default' => $i['default'],
                ], $infos)];
            }

            if (in_array($action, self::QUERY_ACTIONS, true)) {
                [, $ctx] = $service->queryContext((int)($payload['queryId'] ?? 0));
            } else {
                $ctx = $this->ctxFromPayload($proj, $payload['ctx'] ?? []);
            }
            $this->assertAccess($roles, $repo, $ctx);

            $message = null;
            switch ($action) {
                case 'state':
                    break;
                case 'history':
                    return ['ok' => true, 'history' => $service->history($ctx)];
                case 'verifyField':
                    $service->verifyField($ctx, (string)($payload['field'] ?? ''), $user);
                    break;
                case 'unverifyField':
                    $service->unverifyField($ctx, (string)($payload['field'] ?? ''), $user);
                    break;
                case 'verifyAllRequired':
                    $n = $service->verifyAllRequired($ctx, $user);
                    $message = $n === 0 ? 'No further fields could be verified.' : "$n field" . ($n === 1 ? '' : 's') . ' verified.';
                    break;
                case 'completeForm':
                    $service->completeForm($ctx, $user);
                    $message = 'Form marked SDV complete.';
                    break;
                case 'markNotRequired':
                    $service->markNotRequired($ctx, $payload['reason'] ?? null, $user);
                    $message = 'Form marked SDV not required.';
                    break;
                case 'reopenForm':
                    $service->reopenForm($ctx, $payload['reason'] ?? null, $user);
                    $message = 'Form reopened for SDV.';
                    break;
                case 'raiseQuery':
                    $service->raiseQuery($ctx, (string)($payload['field'] ?? ''), $payload['text'] ?? null, $user);
                    $message = 'Query raised.';
                    break;
                case 'respondQuery':
                    if (!$roles->canRespond($settings, $ctx->instrument)) throw new SdvException('You are not permitted to respond to monitor queries.');
                    $service->respondQuery($ctx, (int)$payload['queryId'], (string)($payload['code'] ?? ''), $payload['comment'] ?? null, $user);
                    $message = 'Response sent.';
                    break;
                case 'reraiseQuery':
                    $service->reraiseQuery($ctx, (int)$payload['queryId'], $payload['text'] ?? null, $user);
                    $message = 'Query re-raised.';
                    break;
                case 'acceptQuery':
                    $service->acceptQuery($ctx, (int)$payload['queryId'], !empty($payload['verify']), $user);
                    $message = 'Response accepted and query closed.';
                    break;
                case 'closeQuery':
                    $service->closeQuery($ctx, (int)$payload['queryId'], $payload['reason'] ?? null, $user);
                    $message = 'Query closed.';
                    break;
                default:
                    throw new SdvException('Unknown action.');
            }

            [$loaded, $notices] = $action === 'state' ? $service->checkForm($ctx, $user, 'form opened') : [$service->load($ctx), []];
            return ['ok' => true, 'message' => $message, 'state' => $service->buildState($ctx, $roles, $loaded, $notices)];

        } catch (SdvException|\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            $this->log('Error in redcap_module_ajax', ['action' => $action, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'An unexpected error occurred and has been logged. Please refresh and try again.'];
        }
    }

    private function ctxFromPayload(\Project $proj, $c): FormContext
    {
        $c = is_array($c) ? $c : [];
        return FormContext::fromInput($proj, $c['record'] ?? '', $c['eventId'] ?? 0, $c['instrument'] ?? '', $c['instance'] ?? 1);
    }

    // ---- configuration --------------------------------------------------------------------------

    public function validateSettings($settings): ?string
    {
        $regex = trim((string)($settings['sdv-flag-regex'] ?? ''));
        if ($regex !== '' && @preg_match('/' . str_replace('/', '\/', $regex) . '/', '') === false) {
            return 'The SDV action tag regex is not valid. Do not include the leading and trailing slashes.';
        }

        $ignore = trim((string)($settings['sdv-ignore-action-tag'] ?? ''));
        if ($ignore !== '' && !preg_match('/^@[A-Za-z0-9_-]+$/', $ignore)) {
            return 'The SDV ignore action tag must start with @ and contain only letters, numbers, - and _ (e.g. @SDV-IGNORE).';
        }

        if (array_key_exists('trigger-reverification', $settings)
            && !in_array($settings['trigger-reverification'], Settings::TRIGGERS, true)) {
            return 'Choose when a completed form needs re-verification.';
        }

        $roleSets = [];
        foreach (['monitor-roles' => 'monitor', 'data-entry-roles' => 'data entry', 'data-manager-roles' => 'data manager'] as $key => $label) {
            $roles = array_filter((array)($settings[$key] ?? []), fn($r) => $r !== null && $r !== '');
            foreach ($roles as $r) {
                if (isset($roleSets[$r]) && $roleSets[$r] !== $label) {
                    return "A user role cannot be both a {$roleSets[$r]} role and a $label role.";
                }
                $roleSets[$r] = $label;
            }
        }
        return null;
    }

    public function redcap_module_save_configuration($project_id): void
    {
        $this->auditConfigurationChange($project_id);
    }

    // Logs one entry per changed setting to the module's View Logs page (same behaviour as Monitoring QR)
    private function auditConfigurationChange($project_id): void
    {
        $config   = $this->getConfig();
        $isSystem = empty($project_id);
        $scope    = $isSystem ? 'system' : 'project';
        $keys     = $this->collectSettingKeys($config[$scope . '-settings'] ?? []);
        if (empty($keys)) return;

        $snapshotKey = 'audit-snapshot-' . $scope;
        $read  = fn($k)     => $isSystem ? $this->getSystemSetting($k)    : $this->getProjectSetting($k);
        $write = fn($k, $v) => $isSystem ? $this->setSystemSetting($k, $v) : $this->setProjectSetting($k, $v);

        $new = [];
        foreach ($keys as $k) $new[$k] = $this->normaliseSetting($read($k));

        $rawOld = $read($snapshotKey);
        $old = is_string($rawOld) ? json_decode($rawOld, true) : null;
        if (!is_array($old)) $old = [];

        $changed = false;
        foreach ($keys as $k) {
            $before = $this->normaliseSetting($old[$k] ?? null);
            $after  = $new[$k];
            if ($before !== $after) {
                $changed = true;
                $this->log("Configuration changed ($scope)", [
                    'project_id' => $project_id,
                    'setting'    => $k,
                    'old_value'  => $before === '' ? '(empty)' : $before,
                    'new_value'  => $after  === '' ? '(empty)' : $after,
                ]);
            }
        }
        if ($changed) $write($snapshotKey, json_encode($new));
    }

    private function collectSettingKeys(array $settings): array
    {
        $keys = [];
        foreach ($settings as $s) {
            if (!isset($s['key'])) continue;
            if (($s['type'] ?? '') === 'descriptive') continue;
            $keys[] = $s['key'];
        }
        return $keys;
    }

    private function normaliseSetting($v): string
    {
        if ($v === null || $v === false) return '';
        if ($v === true) return '1';
        if (is_array($v)) {
            foreach ($v as $entry) {
                $hasValue = is_array($entry) ? !empty($entry) : trim((string) ($entry ?? '')) !== '';
                if ($hasValue) return json_encode($v);
            }
            return '';
        }
        return trim((string) $v);
    }
}
