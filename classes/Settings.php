<?php

namespace CCTC\SourceDataVerificationModule\Classes;

use ExternalModules\AbstractExternalModule;

/**
 * Project settings, loaded with a single call and exposed through typed getters.
 */
class Settings
{
    public const DEFAULT_FLAG_REGEX = '@SDV';

    public const TRIGGERS = ['verified', 'always', 'required', 'previously_queried', 'previously_queried_or_required', 'never'];

    private array $values;

    public function __construct(AbstractExternalModule $module, $projectId)
    {
        $this->values = $module->getProjectSettings($projectId) ?? [];
    }

    private function get(string $key)
    {
        return $this->values[$key] ?? null;
    }

    private function bool(string $key): bool
    {
        return !empty($this->get($key));
    }

    private function roleList(string $key): array
    {
        $v = $this->get($key);
        if (!is_array($v)) $v = [$v];
        return array_values(array_map('intval', array_filter($v, fn($r) => $r !== null && $r !== '')));
    }

    public function monitorRoles(): array { return $this->roleList('monitor-roles'); }
    public function dataEntryRoles(): array { return $this->roleList('data-entry-roles'); }
    public function dataManagerRoles(): array { return $this->roleList('data-manager-roles'); }

    public function isConfigured(): bool
    {
        return !empty($this->monitorRoles()) && !empty($this->dataEntryRoles());
    }

    // The regex with delimiters, ready for preg_match. Forward slashes in the configured pattern are escaped.
    public function flagRegex(): string
    {
        $raw = trim((string)$this->get('sdv-flag-regex'));
        if ($raw === '') $raw = self::DEFAULT_FLAG_REGEX;
        return '/' . str_replace('/', '\/', $raw) . '/';
    }

    public function ignoreTag(): string { return trim((string)$this->get('sdv-ignore-action-tag')); }
    public function monitorsOnlyRequired(): bool { return $this->bool('monitors-only-required-fields'); }

    public function trigger(): string
    {
        $t = (string)$this->get('trigger-reverification');
        return in_array($t, self::TRIGGERS, true) ? $t : 'verified';
    }

    public function dataManagersCanRespond(): bool { return $this->bool('allow-data-managers-to-respond'); }
    public function showInline(string $roleType): bool { return $this->bool("$roleType-show-inline"); }
    public function hideSaveButtons(): bool { return !$this->bool('do-not-hide-save-buttons'); }
    public function makeReadonly(): bool { return !$this->bool('do-not-make-readonly'); }
    public function includeFieldLabel(): bool { return $this->bool('include-field-label'); }
}
