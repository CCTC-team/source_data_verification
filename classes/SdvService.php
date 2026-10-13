<?php

namespace CCTC\SourceDataVerificationModule\Classes;

/**
 * SDV business rules. Every state-changing action takes a row lock on the form instance, writes the
 * module tables and the module audit table in one transaction, then records a REDCap log event.
 */
class SdvService
{
    public const PENDING = 'pending';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETE = 'complete';
    public const NOT_REQUIRED = 'not_required';
    public const REVERIFY = 'reverify';

    public const STATUS_LABELS = [
        self::PENDING => 'SDV pending',
        self::IN_PROGRESS => 'SDV in progress',
        self::COMPLETE => 'SDV complete',
        self::NOT_REQUIRED => 'SDV not required',
        self::REVERIFY => 'Re-verification required',
    ];

    public const QUERY_OPEN = 'open';
    public const QUERY_RESPONDED = 'responded';
    public const QUERY_CLOSED = 'closed';

    public const QUERY_STATUS_LABELS = [
        self::QUERY_OPEN => 'Open - awaiting site response',
        self::QUERY_RESPONDED => 'Responded - awaiting monitor review',
        self::QUERY_CLOSED => 'Closed',
    ];

    public const RESPONSE_CODES = [
        'Value updated as per source',
        'Value correct as per source',
        'Value correct, error in source updated',
        'Missing data not done',
    ];

    private const MAX_TEXT = 5000;

    public function __construct(
        private readonly \Project $proj,
        private readonly Settings $settings,
        private readonly Repository $repo,
        private readonly SdvPlan $plan
    ) {}

    public static function now(): string
    {
        return defined('NOW') ? NOW : date('Y-m-d H:i:s');
    }

    // ---- reading --------------------------------------------------------------------------------

    /**
     * Everything about one form instance, read with four indexed queries.
     * With $forUpdate the form row is locked before anything else is read. Under REPEATABLE READ the
     * transaction's snapshot is taken at its first plain read, so reading first would return state from
     * before a concurrent action that committed while this one waited for the lock.
     */
    public function load(FormContext $ctx, bool $forUpdate = false): array
    {
        $form = $forUpdate ? $this->repo->getFormRow($ctx, true) : null;

        $sdvFields = $this->plan->sdvFieldNames($ctx->instrument);
        $completeField = "{$ctx->instrument}_complete";
        $values = $this->repo->readValues($ctx, [...$sdvFields, $completeField]);
        $exists = !empty($values);

        return [
            'form' => $forUpdate ? $form : $this->repo->getFormRow($ctx),
            'fieldRows' => $this->repo->getFieldRows($ctx),
            'queries' => $this->repo->getQueries($ctx),
            'values' => $values,
            'hashes' => ValueHasher::hashAll($sdvFields, $values),
            'exists' => $exists,
            'plan' => $this->plan->forInstrument($ctx->instrument),
        ];
    }

    /**
     * Loads a form instance for display or after a save: clears SDV state left over from data that has since
     * been deleted, then runs change detection.
     * @return array{0: array, 1: string[]} [loaded state, notices]
     */
    public function checkForm(FormContext $ctx, string $user, string $source): array
    {
        $loaded = $this->load($ctx);
        $notices = [];
        if ($this->isStale($ctx, $loaded) && $this->clearStaleState($ctx, $user, $source)) {
            $notices[] = 'The SDV status of this form was reset because its data was deleted after SDV started.';
            $loaded = $this->load($ctx);
        }
        $changes = $this->detectChanges($ctx, $loaded, $user, $source);
        if (!empty($changes)) $loaded = $this->load($ctx);
        return [$loaded, [...$notices, ...$changes]];
    }

    private static function activeQueryByField(array $queries): array
    {
        $out = [];
        foreach ($queries as $q) {
            if ($q['status'] !== self::QUERY_CLOSED) $out[$q['field_name']] = $q;
        }
        return $out;
    }

    // ---- deleted form data ----------------------------------------------------------------------

    private static function hasActivity(array $loaded): bool
    {
        $form = $loaded['form'];
        return ($form !== null && !($form['status'] === self::PENDING && $form['updated_at'] === null))
            || !empty($loaded['fieldRows'])
            || !empty(self::activeQueryByField($loaded['queries']));
    }

    /**
     * True when the SDV state belongs to form data that no longer exists, or to data deleted after the state
     * was created (so a re-created record or form does not inherit the old verification).
     */
    private function isStale(FormContext $ctx, array $loaded): bool
    {
        if (!self::hasActivity($loaded)) return false;
        if (!$loaded['exists']) return true;
        if ($loaded['form'] === null) return false;
        // log times have one-second resolution, so a deletion in the same second as the row's creation counts
        $deleted = $this->repo->lastDeletionTs($ctx);
        return $deleted !== null && $deleted >= (int)date('YmdHis', strtotime($loaded['form']['created_at']));
    }

    /**
     * Removes the form's SDV status and verified fields and closes its active queries, keeping the query
     * threads and the audit trail. Re-checked under the form row lock. Returns false if nothing was stale.
     */
    public function clearStaleState(FormContext $ctx, string $user, string $source): bool
    {
        $cleared = $this->repo->transaction(function () use ($ctx, $user, $source) {
            $loaded = $this->load($ctx, true);
            if (!$this->isStale($ctx, $loaded)) return null;
            $ts = self::now();
            $closed = [];
            foreach (self::activeQueryByField($loaded['queries']) as $q) {
                $this->repo->setQueryStatus((int)$q['query_id'], self::QUERY_CLOSED, $ts);
                $this->repo->insertMessage((int)$q['query_id'], 'close', null, 'Closed: the form data was deleted', $user, $ts);
                $this->repo->audit($user, $ts, 'query_closed', $ctx, $q['field_name'], $q['status'], self::QUERY_CLOSED, ['query_id' => (int)$q['query_id']]);
                $closed[] = $q['field_name'];
            }
            $old = $loaded['form']['status'] ?? null;
            $this->repo->deleteFormState($ctx);
            $detail = ['source' => $source, 'verified_fields' => array_keys($loaded['fieldRows']), 'closed_queries' => $closed];
            $this->repo->audit($user, $ts, 'form_state_cleared', $ctx, null, $old, null, $detail);
            return ['old' => $old] + $detail;
        });
        if ($cleared === null) return false;

        $this->logEvent('SDV: status cleared after form data was deleted', $ctx, [
            'previous status' => $cleared['old'] ?? '',
            'verified fields' => implode(', ', $cleared['verified_fields']),
            'closed queries' => implode(', ', $cleared['closed_queries']),
            'source' => $source,
        ]);
        return true;
    }

    // ---- change detection -----------------------------------------------------------------------

    /**
     * Compares current values with the hashes stored at verification (and with the snapshot taken when
     * the form was marked complete). A verified field whose value changed always loses its tick; a
     * complete form drops to 'reverify' when the configured trigger matches one of the changed fields.
     * Runs on save and whenever the form is opened, so imports and API writes are caught too.
     * $loaded is unlocked state, used only to skip the transaction when nothing changed; the changes are
     * worked out again under the form row lock before anything is written.
     * @return string[] notices describing what changed
     */
    public function detectChanges(FormContext $ctx, array $loaded, string $user, string $source): array
    {
        if (!$this->pendingChanges($loaded)['any']) return [];

        $applied = $this->repo->transaction(function () use ($ctx, $user, $source) {
            $locked = $this->load($ctx, true);
            $change = $this->pendingChanges($locked);
            $ts = self::now();
            $marked = [];
            foreach ($change['changedVerified'] as $field) {
                if ($this->repo->markFieldChanged($ctx, $field, $locked['fieldRows'][$field]['value_hash'])) {
                    $marked[] = $field;
                    $this->repo->audit($user, $ts, 'field_changed', $ctx, $field, 'verified', 'changed', ['source' => $source]);
                }
            }
            if ($change['reverify']) {
                $this->repo->updateForm($ctx, ['status' => self::REVERIFY, 'status_reason' => $change['reason'], 'updated_by' => $user, 'updated_at' => $ts]);
                $this->repo->audit($user, $ts, 'form_reverify', $ctx, null, self::COMPLETE, self::REVERIFY,
                    ['changed_fields' => $change['candidates'], 'trigger' => $this->settings->trigger(), 'source' => $source]);
            }
            return ['marked' => $marked] + $change;
        });

        $notices = [];
        if (!empty($applied['marked'])) {
            $notices[] = 'Value changed since verification: ' . implode(', ', $applied['marked']);
            $this->logEvent('SDV: verified field value changed', $ctx, ['fields' => implode(', ', $applied['marked']), 'source' => $source]);
        }
        if ($applied['reverify']) {
            $notices[] = $applied['reason'] . '. The form needs re-verification.';
            $this->logEvent('SDV: form requires re-verification', $ctx, ['fields' => implode(', ', $applied['candidates'])]);
        }
        return $notices;
    }

    /**
     * Works out which verified fields have changed value and whether a complete form must drop to 'reverify'.
     * @return array{any:bool, changedVerified:string[], candidates:string[], reverify:bool, reason:?string}
     */
    private function pendingChanges(array $loaded): array
    {
        $none = ['any' => false, 'changedVerified' => [], 'candidates' => [], 'reverify' => false, 'reason' => null];
        if (!$loaded['exists'] || (empty($loaded['fieldRows']) && ($loaded['form']['status'] ?? null) !== self::COMPLETE)) {
            return $none;
        }

        $changedVerified = [];
        foreach ($loaded['fieldRows'] as $field => $row) {
            if ($row['status'] === 'verified' && isset($loaded['hashes'][$field]) && $row['value_hash'] !== $loaded['hashes'][$field]) {
                $changedVerified[] = $field;
            }
        }

        $candidates = $changedVerified;
        $form = $loaded['form'];
        if ($form && $form['status'] === self::COMPLETE && !empty($form['snapshot'])) {
            $snapshot = json_decode($form['snapshot'], true) ?: [];
            $blank = ValueHasher::hash([]);
            foreach ($loaded['hashes'] as $field => $hash) {
                if (($snapshot[$field] ?? $blank) !== $hash) $candidates[] = $field;
            }
            $candidates = array_values(array_unique($candidates));
        }

        $reverify = $form && $form['status'] === self::COMPLETE
            && $this->triggerFires($candidates, $changedVerified, $loaded);

        return [
            'any' => !empty($changedVerified) || $reverify,
            'changedVerified' => $changedVerified,
            'candidates' => $candidates,
            'reverify' => $reverify,
            'reason' => $reverify ? 'Data changed after SDV: ' . implode(', ', $candidates) : null,
        ];
    }

    private function triggerFires(array $candidates, array $changedVerified, array $loaded): bool
    {
        if (empty($candidates)) return false;
        $required = fn($f) => ($loaded['plan'][$f]['requirement'] ?? null) === SdvPlan::REQUIRED;
        $queried = array_flip(array_column($loaded['queries'], 'field_name'));
        $wasQueried = fn($f) => isset($queried[$f]);

        return match ($this->settings->trigger()) {
            'never' => false,
            'always' => true,
            'verified' => !empty($changedVerified),
            'required' => !empty(array_filter($candidates, $required)),
            'previously_queried' => !empty(array_filter($candidates, $wasQueried)),
            'previously_queried_or_required' => !empty(array_filter($candidates, fn($f) => $required($f) || $wasQueried($f))),
        };
    }

    /**
     * Save hook entry point: creates the pending row for instruments that need SDV, then checks for changes.
     */
    public function onSave(FormContext $ctx, string $user): void
    {
        [$loaded] = $this->checkForm($ctx, $user, 'data entry save');
        if ($loaded['exists'] && $loaded['form'] === null && !empty($this->plan->requiredFields($ctx->instrument))) {
            $this->repo->ensureFormRow($ctx, self::now());
        }
    }

    // ---- actions --------------------------------------------------------------------------------

    /**
     * Runs $fn inside a transaction holding a row lock on the form instance, with freshly loaded state.
     */
    private function locked(FormContext $ctx, callable $fn)
    {
        return $this->repo->transaction(function () use ($ctx, $fn) {
            $this->repo->ensureFormRow($ctx, self::now());
            $loaded = $this->load($ctx, true);
            if (!$loaded['exists']) {
                throw new SdvException('Save the form before carrying out SDV.');
            }
            return $fn($loaded);
        });
    }

    private static function requireOpenForm(array $loaded): void
    {
        $status = $loaded['form']['status'] ?? self::PENDING;
        if ($status === self::COMPLETE || $status === self::NOT_REQUIRED) {
            throw new SdvException('This form is marked "' . self::STATUS_LABELS[$status] . '". Reopen it before making changes.');
        }
    }

    private function requireSdvField(array $loaded, string $field): array
    {
        $info = $loaded['plan'][$field] ?? null;
        if ($info === null) throw new SdvException("Field '$field' is not part of this form's SDV.");
        if ($info['requirement'] === SdvPlan::EXCLUDED) throw new SdvException("Field '$field' is excluded from SDV.");
        if ($this->settings->monitorsOnlyRequired() && $info['requirement'] !== SdvPlan::REQUIRED) {
            throw new SdvException("Field '$field' does not require SDV. This project only allows SDV of required fields.");
        }
        return $info;
    }

    private static function cleanText(?string $text, bool $required, string $what): ?string
    {
        $text = trim((string)$text);
        if ($text === '') {
            if ($required) throw new SdvException("Please enter $what.");
            return null;
        }
        if (mb_strlen($text) > self::MAX_TEXT) throw new SdvException(ucfirst($what) . ' is too long (maximum ' . self::MAX_TEXT . ' characters).');
        return $text;
    }

    // pending <-> in_progress depending on whether anything has happened on the form; other statuses are left alone
    private function refreshOpenStatus(FormContext $ctx, string $user, string $ts): void
    {
        $form = $this->repo->getFormRow($ctx);
        $status = $form['status'] ?? self::PENDING;
        if ($status !== self::PENDING && $status !== self::IN_PROGRESS) {
            $this->repo->updateForm($ctx, ['updated_by' => $user, 'updated_at' => $ts]);
            return;
        }
        $active = !empty($this->repo->getFieldRows($ctx)) || !empty($this->repo->getQueries($ctx));
        $new = $active ? self::IN_PROGRESS : self::PENDING;
        $this->repo->updateForm($ctx, ['status' => $new, 'updated_by' => $user, 'updated_at' => $ts]);
        if ($new !== $status) {
            $this->repo->audit($user, $ts, 'form_status', $ctx, null, $status, $new, null);
        }
    }

    private function verifyOne(FormContext $ctx, array $loaded, string $field, string $user, string $ts): bool
    {
        $current = $loaded['hashes'][$field];
        $row = $loaded['fieldRows'][$field] ?? null;
        if ($row && $row['status'] === 'verified' && $row['value_hash'] === $current) return false;
        $this->repo->upsertVerifiedField($ctx, $field, $current, $user, $ts);
        $this->repo->audit($user, $ts, 'field_verified', $ctx, $field, $row['status'] ?? null, 'verified', null);
        return true;
    }

    public function verifyField(FormContext $ctx, string $field, string $user): void
    {
        $this->locked($ctx, function (array $loaded) use ($ctx, $field, $user) {
            self::requireOpenForm($loaded);
            $this->requireSdvField($loaded, $field);
            if (isset(self::activeQueryByField($loaded['queries'])[$field])) {
                throw new SdvException("Field '$field' has an open query. Resolve the query before verifying.");
            }
            $ts = self::now();
            $this->verifyOne($ctx, $loaded, $field, $user, $ts);
            $this->refreshOpenStatus($ctx, $user, $ts);
        });
        $this->logEvent('SDV: field verified', $ctx, ['field' => $field]);
    }

    public function unverifyField(FormContext $ctx, string $field, string $user): void
    {
        $this->locked($ctx, function (array $loaded) use ($ctx, $field, $user) {
            self::requireOpenForm($loaded);
            $row = $loaded['fieldRows'][$field] ?? null;
            if ($row === null) throw new SdvException("Field '$field' is not verified.");
            $ts = self::now();
            $this->repo->deleteField($ctx, $field);
            $this->repo->audit($user, $ts, 'field_unverified', $ctx, $field, $row['status'], null, null);
            $this->refreshOpenStatus($ctx, $user, $ts);
        });
        $this->logEvent('SDV: field verification removed', $ctx, ['field' => $field]);
    }

    public function verifyAllRequired(FormContext $ctx, string $user): int
    {
        $verified = $this->locked($ctx, function (array $loaded) use ($ctx, $user) {
            self::requireOpenForm($loaded);
            $ts = self::now();
            $active = self::activeQueryByField($loaded['queries']);
            $done = [];
            foreach ($loaded['plan'] as $field => $info) {
                if ($info['requirement'] !== SdvPlan::REQUIRED || isset($active[$field])) continue;
                if ($this->verifyOne($ctx, $loaded, $field, $user, $ts)) $done[] = $field;
            }
            $this->refreshOpenStatus($ctx, $user, $ts);
            return $done;
        });
        if (!empty($verified)) $this->logEvent('SDV: fields verified', $ctx, ['fields' => implode(', ', $verified)]);
        return count($verified);
    }

    public function completeForm(FormContext $ctx, string $user): void
    {
        $this->locked($ctx, function (array $loaded) use ($ctx, $user) {
            self::requireOpenForm($loaded);
            $missing = [];
            foreach ($loaded['plan'] as $field => $info) {
                if ($info['requirement'] !== SdvPlan::REQUIRED) continue;
                $row = $loaded['fieldRows'][$field] ?? null;
                if (!$row || $row['status'] !== 'verified' || $row['value_hash'] !== $loaded['hashes'][$field]) $missing[] = $field;
            }
            if (!empty($missing)) {
                throw new SdvException('All fields requiring SDV must be verified first. Not verified: ' . implode(', ', $missing));
            }
            $active = self::activeQueryByField($loaded['queries']);
            if (!empty($active)) {
                throw new SdvException('Close all queries before marking the form SDV complete. Open queries on: ' . implode(', ', array_keys($active)));
            }
            $ts = self::now();
            $old = $loaded['form']['status'] ?? self::PENDING;
            $this->repo->updateForm($ctx, [
                'status' => self::COMPLETE, 'status_reason' => null, 'snapshot' => json_encode($loaded['hashes']),
                'updated_by' => $user, 'updated_at' => $ts, 'completed_by' => $user, 'completed_at' => $ts,
            ]);
            $this->repo->audit($user, $ts, 'form_complete', $ctx, null, $old, self::COMPLETE, null);
        });
        $this->logEvent('SDV: form marked SDV complete', $ctx, []);
    }

    public function markNotRequired(FormContext $ctx, ?string $reason, string $user): void
    {
        $reason = self::cleanText($reason, true, 'a reason');
        $this->locked($ctx, function (array $loaded) use ($ctx, $reason, $user) {
            self::requireOpenForm($loaded);
            $ts = self::now();
            foreach (self::activeQueryByField($loaded['queries']) as $q) {
                $this->repo->setQueryStatus((int)$q['query_id'], self::QUERY_CLOSED, $ts);
                $this->repo->insertMessage((int)$q['query_id'], 'close', null, 'Closed: form marked SDV not required', $user, $ts);
                $this->repo->audit($user, $ts, 'query_closed', $ctx, $q['field_name'], $q['status'], self::QUERY_CLOSED, ['query_id' => (int)$q['query_id']]);
            }
            $old = $loaded['form']['status'] ?? self::PENDING;
            $this->repo->updateForm($ctx, ['status' => self::NOT_REQUIRED, 'status_reason' => $reason, 'updated_by' => $user, 'updated_at' => $ts]);
            $this->repo->audit($user, $ts, 'form_not_required', $ctx, null, $old, self::NOT_REQUIRED, ['reason' => $reason]);
        });
        $this->logEvent('SDV: form marked SDV not required', $ctx, ['reason' => $reason]);
    }

    public function reopenForm(FormContext $ctx, ?string $reason, string $user): void
    {
        $reason = self::cleanText($reason, true, 'a reason');
        $this->locked($ctx, function (array $loaded) use ($ctx, $reason, $user) {
            $old = $loaded['form']['status'] ?? self::PENDING;
            if ($old !== self::COMPLETE && $old !== self::NOT_REQUIRED) {
                throw new SdvException('Only forms marked SDV complete or SDV not required can be reopened.');
            }
            $ts = self::now();
            $new = !empty($loaded['fieldRows']) || !empty($loaded['queries']) ? self::IN_PROGRESS : self::PENDING;
            $this->repo->updateForm($ctx, [
                'status' => $new, 'status_reason' => $reason, 'snapshot' => null, 'completed_by' => null, 'completed_at' => null,
                'updated_by' => $user, 'updated_at' => $ts,
            ]);
            $this->repo->audit($user, $ts, 'form_reopened', $ctx, null, $old, $new, ['reason' => $reason]);
        });
        $this->logEvent('SDV: form reopened', $ctx, ['reason' => $reason]);
    }

    /**
     * Sets a field's SDV requirement project-wide. 'default' removes the override so the action tags decide.
     */
    public function setPlan(string $field, string $requirement, string $user): array
    {
        $instrument = $this->proj->metadata[$field]['form_name'] ?? null;
        if ($instrument === null || !in_array($field, $this->plan->sdvFieldNames($instrument), true)) {
            throw new SdvException("Field '$field' cannot take part in SDV.");
        }
        [$infos, $changed] = $this->applyPlan($instrument, [$field], $requirement, $user);
        if (!empty($changed)) {
            $this->logEvent('SDV: plan changed', null, ['field' => $field, 'instrument' => $instrument,
                'from' => $changed[$field], 'to' => $infos[$field]['requirement']]);
        }
        return $infos[$field];
    }

    /**
     * Sets the SDV requirement of every field on an instrument project-wide, as one change.
     * @return array<string, array> field => updated plan entry
     */
    public function setPlanForInstrument(string $instrument, string $requirement, string $user): array
    {
        $fields = isset($this->proj->forms[$instrument]) ? $this->plan->sdvFieldNames($instrument) : [];
        if (empty($fields)) throw new SdvException("Form '$instrument' has no fields that can take part in SDV.");
        [$infos, $changed] = $this->applyPlan($instrument, $fields, $requirement, $user);
        if (!empty($changed)) {
            $this->logEvent('SDV: plan changed for whole form', null, [
                'instrument' => $instrument,
                'to' => $requirement === 'default' ? 'default (from action tags)' : $requirement,
                'fields' => implode(', ', array_map(fn($f, $from) => "$f ($from -> {$infos[$f]['requirement']})", array_keys($changed), $changed)),
            ]);
        }
        return $infos;
    }

    /**
     * Stores the overrides for the given fields of one instrument in one transaction, auditing each field that
     * changes. 'default' removes the override so the action tags decide.
     * @return array{0: array<string, array>, 1: array<string, string>} [field => updated plan entry, changed field => old requirement]
     */
    private function applyPlan(string $instrument, array $fields, string $requirement, string $user): array
    {
        if ($requirement !== 'default' && !in_array($requirement, SdvPlan::VALUES, true)) {
            throw new SdvException('Invalid requirement.');
        }
        $current = $this->plan->forInstrument($instrument);
        $infos = $changes = [];
        foreach ($fields as $field) {
            $info = $current[$field];
            $override = ($requirement === 'default' || $requirement === $info['default']) ? null : $requirement;
            $infos[$field] = ['requirement' => $override ?? $info['default'], 'override' => $override] + $info;
            if ($override !== $info['override']) $changes[$field] = $info;
        }
        if (empty($changes)) return [$infos, []];

        $ts = self::now();
        $formLevel = count($fields) > 1;
        $this->repo->transaction(function () use ($changes, $infos, $instrument, $user, $ts, $formLevel) {
            foreach ($changes as $field => $info) {
                $override = $infos[$field]['override'];
                $this->repo->setPlanOverride($field, $override, $user, $ts);
                $this->repo->audit($user, $ts, 'plan_changed', null, $field, $info['requirement'], $infos[$field]['requirement'],
                    ['instrument' => $instrument, 'override' => $override, 'default' => $info['default']] + ($formLevel ? ['whole_form' => true] : []));
            }
        });
        return [$infos, array_map(fn($i) => $i['requirement'], $changes)];
    }

    public function raiseQuery(FormContext $ctx, string $field, ?string $text, string $user): void
    {
        $text = self::cleanText($text, true, 'the query text');
        $this->locked($ctx, function (array $loaded) use ($ctx, $field, $text, $user) {
            self::requireOpenForm($loaded);
            $this->requireSdvField($loaded, $field);
            if (isset(self::activeQueryByField($loaded['queries'])[$field])) {
                throw new SdvException("Field '$field' already has an open query.");
            }
            $ts = self::now();
            if (isset($loaded['fieldRows'][$field])) {
                $this->repo->deleteField($ctx, $field);
                $this->repo->audit($user, $ts, 'field_unverified', $ctx, $field, $loaded['fieldRows'][$field]['status'], null, ['reason' => 'query raised']);
            }
            $queryId = $this->repo->insertQuery($ctx, $field, $user, $ts);
            $this->repo->insertMessage($queryId, 'raise', null, $text, $user, $ts);
            $this->repo->audit($user, $ts, 'query_raised', $ctx, $field, null, self::QUERY_OPEN, ['query_id' => $queryId]);
            $this->refreshOpenStatus($ctx, $user, $ts);
        });
        $this->logEvent('SDV: monitor query raised', $ctx, ['field' => $field]);
    }

    /**
     * Loads a query and its form context for a query action.
     */
    public function queryContext(int $queryId): array
    {
        $q = $this->repo->getQuery($queryId);
        if ($q === null) throw new SdvException('Query not found.');
        $ctx = new FormContext($this->repo->projectId, $q['record'], (int)$q['event_id'], $q['instrument'], (int)$q['instance']);
        return [$q, $ctx];
    }

    private function queryAction(FormContext $ctx, int $queryId, array $allowedFrom, callable $fn): void
    {
        $this->locked($ctx, function (array $loaded) use ($queryId, $allowedFrom, $fn) {
            $q = $this->repo->getQuery($queryId, true);
            if (!in_array($q['status'], $allowedFrom, true)) {
                throw new SdvException('This query is now "' . self::QUERY_STATUS_LABELS[$q['status']] . '". Refresh the page.');
            }
            $fn($q, $loaded, self::now());
        });
    }

    public function respondQuery(FormContext $ctx, int $queryId, string $code, ?string $comment, string $user): void
    {
        if (!in_array($code, self::RESPONSE_CODES, true)) throw new SdvException('Please choose a response.');
        $comment = self::cleanText($comment, false, 'the comment');
        $this->queryAction($ctx, $queryId, [self::QUERY_OPEN], function ($q, $loaded, $ts) use ($ctx, $queryId, $code, $comment, $user) {
            $this->repo->setQueryStatus($queryId, self::QUERY_RESPONDED, $ts);
            $this->repo->insertMessage($queryId, 'respond', $code, $comment, $user, $ts);
            $this->repo->audit($user, $ts, 'query_responded', $ctx, $q['field_name'], $q['status'], self::QUERY_RESPONDED, ['query_id' => $queryId, 'response' => $code]);
            $this->repo->updateForm($ctx, ['updated_by' => $user, 'updated_at' => $ts]);
        });
        $this->logEvent('SDV: monitor query responded', $ctx, ['query_id' => $queryId, 'response' => $code]);
    }

    public function reraiseQuery(FormContext $ctx, int $queryId, ?string $text, string $user): void
    {
        $text = self::cleanText($text, false, 'the query text');
        $this->queryAction($ctx, $queryId, [self::QUERY_RESPONDED], function ($q, $loaded, $ts) use ($ctx, $queryId, $text, $user) {
            if ($text === null) {
                // re-send the most recent query text unchanged
                foreach (array_reverse($this->repo->getMessages([$queryId])[$queryId] ?? []) as $m) {
                    if (in_array($m['action'], ['raise', 'reraise'], true) && $m['text'] !== null) { $text = $m['text']; break; }
                }
            }
            $this->repo->setQueryStatus($queryId, self::QUERY_OPEN, $ts);
            $this->repo->insertMessage($queryId, 'reraise', null, $text, $user, $ts);
            $this->repo->audit($user, $ts, 'query_reraised', $ctx, $q['field_name'], $q['status'], self::QUERY_OPEN, ['query_id' => $queryId]);
            $this->repo->updateForm($ctx, ['updated_by' => $user, 'updated_at' => $ts]);
        });
        $this->logEvent('SDV: monitor query re-raised', $ctx, ['query_id' => $queryId]);
    }

    public function acceptQuery(FormContext $ctx, int $queryId, bool $verify, string $user): void
    {
        $this->queryAction($ctx, $queryId, [self::QUERY_RESPONDED], function ($q, $loaded, $ts) use ($ctx, $queryId, $verify, $user) {
            $this->repo->setQueryStatus($queryId, self::QUERY_CLOSED, $ts);
            $this->repo->insertMessage($queryId, 'accept', null, null, $user, $ts);
            $this->repo->audit($user, $ts, 'query_accepted', $ctx, $q['field_name'], $q['status'], self::QUERY_CLOSED, ['query_id' => $queryId]);
            if ($verify) {
                self::requireOpenForm($loaded);
                $this->requireSdvField($loaded, $q['field_name']);
                $this->verifyOne($ctx, $loaded, $q['field_name'], $user, $ts);
            }
            $this->refreshOpenStatus($ctx, $user, $ts);
        });
        $this->logEvent('SDV: monitor query accepted', $ctx, ['query_id' => $queryId, 'field verified' => $verify ? 'yes' : 'no']);
    }

    public function closeQuery(FormContext $ctx, int $queryId, ?string $reason, string $user): void
    {
        $reason = self::cleanText($reason, false, 'the reason');
        $this->queryAction($ctx, $queryId, [self::QUERY_OPEN, self::QUERY_RESPONDED], function ($q, $loaded, $ts) use ($ctx, $queryId, $reason, $user) {
            $this->repo->setQueryStatus($queryId, self::QUERY_CLOSED, $ts);
            $this->repo->insertMessage($queryId, 'close', null, $reason, $user, $ts);
            $this->repo->audit($user, $ts, 'query_closed', $ctx, $q['field_name'], $q['status'], self::QUERY_CLOSED, ['query_id' => $queryId]);
            $this->refreshOpenStatus($ctx, $user, $ts);
        });
        $this->logEvent('SDV: monitor query closed', $ctx, ['query_id' => $queryId]);
    }

    // ---- bulk sync (dashboard) ------------------------------------------------------------------

    /**
     * Brings the dashboard up to date: prunes untouched rows that no longer apply, clears SDV state of deleted
     * form data, adds pending rows for saved forms that need SDV, and detects changes made outside data entry
     * (to verified fields, and to any SDV field of a complete form when the trigger looks beyond verified fields).
     * A failure on one form is recorded and the sync carries on with the rest.
     * @return array{added:int, removed:int, cleared:int, changedForms:int, failed:int, errors:string[]}
     */
    public function sync(string $user): array
    {
        $instruments = $this->plan->instrumentsRequiringSdv();
        $ts = self::now();
        $result = ['added' => 0, 'removed' => 0, 'cleared' => 0, 'changedForms' => 0, 'failed' => 0, 'errors' => []];
        $result['removed'] = $this->repo->prunePendingForms($instruments);

        $each = function (array $keys, callable $fn) use (&$result) {
            foreach ($keys as $r) {
                $ctx = new FormContext($this->repo->projectId, $r['record'], (int)$r['event_id'], $r['instrument'], (int)$r['instance']);
                try {
                    $fn($ctx);
                } catch (\Throwable $e) {
                    $result['failed']++;
                    if (count($result['errors']) < 5) $result['errors'][] = "{$ctx->record} / {$ctx->instrument}: " . $e->getMessage();
                }
            }
        };

        $each($this->staleCandidates(), function (FormContext $ctx) use ($user, &$result) {
            if ($this->clearStaleState($ctx, $user, 'dashboard sync')) $result['cleared']++;
        });
        $result['added'] = $this->repo->backfillForms($instruments, $ts);

        $affected = $this->formsWithChangedVerifiedFields() + $this->completeFormsWithChangedSnapshot();
        $each(array_values($affected), function (FormContext $ctx) use ($user, &$result) {
            if (!empty($this->detectChanges($ctx, $this->load($ctx), $user, 'dashboard sync'))) $result['changedForms']++;
        });
        return $result;
    }

    // Form instances whose data is gone, plus those of records with a deletion logged since the oldest form row
    private function staleCandidates(): array
    {
        $out = [];
        $key = fn($r) => implode("\x1f", [$r['record'], $r['event_id'], $r['instrument'], $r['instance']]);
        foreach ($this->repo->formsWithoutData() as $r) $out[$key($r)] = $r;
        $since = $this->repo->earliestFormCreatedAt();
        if ($since !== null) {
            $records = $this->repo->recordsWithDeletionsSince((int)date('YmdHis', strtotime($since)));
            foreach ($this->repo->formKeysForRecords($records) as $r) $out[$key($r)] = $r;
        }
        return array_values($out);
    }

    /** @return array<string, array> form key => row, for forms with a verified field whose value changed */
    private function formsWithChangedVerifiedFields(): array
    {
        // group current values per verified field, then compare hashes
        $byField = [];
        foreach ($this->repo->verifiedFieldValues() as $r) {
            $key = implode("\x1f", [$r['record'], $r['event_id'], $r['instrument'], $r['instance'], $r['field_name']]);
            $byField[$key]['row'] = $r;
            if ($r['value'] !== null) $byField[$key]['values'][] = $r['value'];
        }
        $affected = [];
        foreach ($byField as $entry) {
            $r = $entry['row'];
            if (ValueHasher::hash($entry['values'] ?? []) !== $r['value_hash']) {
                $affected[implode("\x1f", [$r['record'], $r['event_id'], $r['instrument'], $r['instance']])] = $r;
            }
        }
        return $affected;
    }

    /**
     * Complete forms where any SDV field differs from the completion snapshot. Only needed when the trigger
     * can fire on fields that were not verified; otherwise the verified-field check covers every case.
     * @return array<string, array> form key => row
     */
    private function completeFormsWithChangedSnapshot(): array
    {
        if (in_array($this->settings->trigger(), ['never', 'verified'], true)) return [];
        $affected = [];
        $blank = ValueHasher::hash([]);
        foreach ($this->plan->instrumentsRequiringSdv() as $instrument) {
            $fields = $this->plan->sdvFieldNames($instrument);
            [$forms, $valueRows] = $this->repo->completeFormsWithValues($instrument, $fields);
            $values = [];
            foreach ($valueRows as $v) {
                $values["{$v['record']}\x1f{$v['event_id']}\x1f{$v['instance']}"][$v['field_name']][] = (string)$v['value'];
            }
            foreach ($forms as $f) {
                $k = "{$f['record']}\x1f{$f['event_id']}\x1f{$f['instance']}";
                $snapshot = json_decode((string)$f['snapshot'], true) ?: [];
                foreach (ValueHasher::hashAll($fields, $values[$k] ?? []) as $field => $hash) {
                    if (($snapshot[$field] ?? $blank) !== $hash) {
                        $affected["{$f['record']}\x1f{$f['event_id']}\x1f$instrument\x1f{$f['instance']}"] = $f + ['instrument' => $instrument];
                        break;
                    }
                }
            }
        }
        return $affected;
    }

    // ---- presentation ---------------------------------------------------------------------------

    public function displayValue(string $field, array $raw): string
    {
        $raw = array_values(array_filter($raw, fn($v) => $v !== ''));
        if (empty($raw)) return '';
        $meta = $this->proj->metadata[$field] ?? [];
        if (in_array($meta['element_type'] ?? '', ['select', 'radio', 'checkbox', 'yesno', 'truefalse'], true)) {
            $enum = match ($meta['element_type']) {
                'yesno' => ['1' => 'Yes', '0' => 'No'],
                'truefalse' => ['1' => 'True', '0' => 'False'],
                default => parseEnum($meta['element_enum'] ?? ''),
            };
            $raw = array_map(fn($v) => isset($enum[$v]) ? strip_tags((string)$enum[$v]) . " ($v)" : $v, $raw);
        }
        return implode(', ', $raw);
    }

    public static function formatTs(?string $ts): string
    {
        if (empty($ts)) return '';
        return \DateTimeRC::format_user_datetime(substr($ts, 0, 16), 'Y-M-D_24');
    }

    /**
     * The JSON payload the data entry form UI renders from. Built from already-loaded state.
     */
    public function buildState(FormContext $ctx, Roles $roles, array $loaded, array $notices = []): array
    {
        $form = $loaded['form'];
        $status = $loaded['exists'] ? ($form['status'] ?? self::PENDING) : null;
        $active = self::activeQueryByField($loaded['queries']);
        $formOpen = $status !== null && $status !== self::COMPLETE && $status !== self::NOT_REQUIRED;

        $messages = $this->repo->getMessages(array_map(fn($q) => (int)$q['query_id'], array_values($active)));

        $fields = [];
        $required = $requiredVerified = $verified = 0;
        foreach ($loaded['plan'] as $field => $info) {
            $row = $loaded['fieldRows'][$field] ?? null;
            $sdv = $row ? ($row['status'] === 'verified' && $row['value_hash'] !== $loaded['hashes'][$field] ? 'changed' : $row['status']) : null;
            $isRequired = $info['requirement'] === SdvPlan::REQUIRED;
            if ($isRequired) $required++;
            if ($sdv === 'verified') { $verified++; if ($isRequired) $requiredVerified++; }

            $selectable = $info['requirement'] !== SdvPlan::EXCLUDED
                && (!$this->settings->monitorsOnlyRequired() || $isRequired);

            $q = $active[$field] ?? null;
            $lastQueryText = null;
            if ($q) {
                foreach (array_reverse($messages[(int)$q['query_id']] ?? []) as $m) {
                    if (in_array($m['action'], ['raise', 'reraise'], true) && $m['text'] !== null) { $lastQueryText = $m['text']; break; }
                }
            }

            $fields[$field] = [
                'label' => $info['label'],
                'requirement' => $info['requirement'],
                'default' => $info['default'],
                'override' => $info['override'],
                'tags' => $info['tags'],
                'sdv' => $sdv,
                'verifiedBy' => $row['verified_by'] ?? null,
                'verifiedAt' => self::formatTs($row['verified_at'] ?? null),
                'selectable' => $selectable,
                'queryId' => $q ? (int)$q['query_id'] : null,
                'queryStatus' => $q['status'] ?? null,
                'queryText' => $lastQueryText,
            ];
        }

        $queries = [];
        foreach ($active as $field => $q) {
            $queries[] = [
                'id' => (int)$q['query_id'],
                'field' => $field,
                'label' => $loaded['plan'][$field]['label'] ?? '',
                'value' => $this->displayValue($field, $loaded['values'][$field] ?? []),
                'status' => $q['status'],
                'statusLabel' => self::QUERY_STATUS_LABELS[$q['status']],
                'raisedBy' => $q['raised_by'],
                'raisedAt' => self::formatTs($q['raised_at']),
                'messages' => array_map(fn($m) => [
                    'action' => $m['action'], 'code' => $m['response_code'], 'text' => $m['text'],
                    'user' => $m['username'], 'ts' => self::formatTs($m['ts']),
                ], $messages[(int)$q['query_id']] ?? []),
            ];
        }

        $inlineRole = $roles->inlineRoleType();
        return [
            'ctx' => $ctx->toArray(),
            'exists' => $loaded['exists'],
            'role' => [
                'monitor' => $roles->isMonitor,
                'dataEntry' => $roles->isDataEntry,
                'dataManager' => $roles->isDataManager,
                'canRespond' => $roles->canRespond($this->settings, $ctx->instrument),
                'canEditPlan' => $roles->canEditPlan(),
            ],
            'form' => [
                'status' => $status,
                'statusLabel' => $status ? self::STATUS_LABELS[$status] : 'Not saved yet',
                'reason' => $form['status_reason'] ?? null,
                'updatedBy' => $form['updated_by'] ?? null,
                'updatedAt' => self::formatTs($form['updated_at'] ?? null),
                'completedBy' => $form['completed_by'] ?? null,
                'completedAt' => self::formatTs($form['completed_at'] ?? null),
                'open' => $formOpen,
            ],
            'counts' => ['required' => $required, 'requiredVerified' => $requiredVerified, 'verified' => $verified, 'activeQueries' => count($active)],
            'fields' => $fields,
            'queries' => $queries,
            'notices' => $notices,
            'settings' => [
                'showInline' => $inlineRole !== null && $this->settings->showInline($inlineRole),
                'includeFieldLabel' => $this->settings->includeFieldLabel(),
                'onlyRequired' => $this->settings->monitorsOnlyRequired(),
            ],
            'responseCodes' => self::RESPONSE_CODES,
        ];
    }

    /**
     * Full SDV history of a form instance: the module audit trail merged with query messages.
     */
    public function history(FormContext $ctx): array
    {
        $items = [];
        foreach ($this->repo->getAudit($ctx) as $a) {
            $detail = $a['detail'] ? json_decode($a['detail'], true) : [];
            $items[] = [
                'sort' => $a['ts'] . sprintf('%012d', $a['audit_id']),
                'ts' => self::formatTs($a['ts']), 'user' => $a['username'], 'action' => $a['action'],
                'field' => $a['field_name'],
                'change' => trim(($a['old_status'] ?? '') . ' → ' . ($a['new_status'] ?? ''), ' →'),
                'detail' => self::describeDetail($detail),
            ];
        }
        $queries = $this->repo->getQueries($ctx);
        $fieldByQuery = array_column($queries, 'field_name', 'query_id');
        foreach ($this->repo->getMessages(array_map('intval', array_keys($fieldByQuery))) as $qid => $msgs) {
            foreach ($msgs as $m) {
                if ($m['text'] === null && $m['response_code'] === null) continue;
                $items[] = [
                    'sort' => $m['ts'] . 'm' . sprintf('%011d', $m['msg_id']),
                    'ts' => self::formatTs($m['ts']), 'user' => $m['username'], 'action' => 'query_' . $m['action'] . '_text',
                    'field' => $fieldByQuery[$qid] ?? null, 'change' => '',
                    'detail' => trim(($m['response_code'] ? $m['response_code'] . ($m['text'] ? ': ' : '') : '') . ($m['text'] ?? '')),
                ];
            }
        }
        usort($items, fn($a, $b) => strcmp($b['sort'], $a['sort']));
        return array_map(function ($i) { unset($i['sort']); return $i; }, $items);
    }

    private static function describeDetail(array $detail): string
    {
        $parts = [];
        foreach ($detail as $k => $v) {
            if ($k === 'query_id') continue;
            $parts[] = str_replace('_', ' ', $k) . ': ' . (is_array($v) ? implode(', ', $v) : (string)$v);
        }
        return implode('; ', $parts);
    }

    private function logEvent(string $description, ?FormContext $ctx, array $changes): void
    {
        $lines = [];
        if ($ctx) {
            $lines[] = "instrument = '{$ctx->instrument}'";
            if ($ctx->instance > 1) $lines[] = "instance = {$ctx->instance}";
        }
        foreach ($changes as $k => $v) $lines[] = "$k = '" . str_replace("'", '"', (string)$v) . "'";
        \REDCap::logEvent($description, implode(",\n", $lines), '', $ctx?->record, $ctx?->eventId, $this->repo->projectId);
    }
}
