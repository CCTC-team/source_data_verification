<?php

namespace CCTC\SourceDataVerificationModule\Classes;

use ExternalModules\AbstractExternalModule;

/**
 * The current user's SDV role, DAG and form rights in a project. Rights are read once per request.
 */
class Roles
{
    public readonly string $username;
    public readonly bool $isSuperUser;
    public readonly bool $isMonitor;
    public readonly bool $isDataEntry;
    public readonly bool $isDataManager;
    public readonly ?int $groupId;
    private array $formRights;

    public function __construct(AbstractExternalModule $module, Settings $settings, $projectId)
    {
        $user = $module->getUser();
        $this->username = $user->getUsername();
        $this->isSuperUser = $user->isSuperUser();

        $rights = $user->getRights($projectId) ?? [];
        $roleId = isset($rights['role_id']) && $rights['role_id'] !== '' ? (int)$rights['role_id'] : null;

        $this->isMonitor = $roleId !== null && in_array($roleId, $settings->monitorRoles(), true);
        $this->isDataEntry = $roleId !== null && in_array($roleId, $settings->dataEntryRoles(), true);
        $this->isDataManager = $roleId !== null && in_array($roleId, $settings->dataManagerRoles(), true);
        $this->groupId = isset($rights['group_id']) && $rights['group_id'] !== '' ? (int)$rights['group_id'] : null;
        // getRights() returns the raw privileges row, where form rights are a "[form,1][form2,0]" string
        $this->formRights = $rights['forms'] ?? \UserRights::convertFormRightsToArray($rights['data_entry'] ?? '');
    }

    public function hasAnyRole(): bool
    {
        return $this->isMonitor || $this->isDataEntry || $this->isDataManager;
    }

    public function canViewForm(string $instrument): bool
    {
        return $this->isSuperUser || (int)($this->formRights[$instrument] ?? 0) > 0;
    }

    public function canRespond(Settings $settings): bool
    {
        return $this->isDataEntry || ($this->isDataManager && $settings->dataManagersCanRespond());
    }

    public function canEditPlan(): bool
    {
        return $this->isMonitor || $this->isSuperUser;
    }

    // The role whose inline-display setting applies, matching the precedence used for the UI
    public function inlineRoleType(): ?string
    {
        if ($this->isMonitor) return 'monitor';
        if ($this->isDataEntry) return 'data-entry';
        if ($this->isDataManager) return 'data-manager';
        return null;
    }
}
