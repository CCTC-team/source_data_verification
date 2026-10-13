<?php

namespace CCTC\SourceDataVerificationModule\Classes;

/**
 * Filter parsing and row building shared by the dashboard page and the CSV export.
 */
class Dashboard
{
    public const PAGE_SIZES = [25, 50, 100, 250];
    private const SYNC_INTERVAL_SECONDS = 300;

    public array $groups;
    public bool $withDags;

    public function __construct(
        private readonly \Project $proj,
        private readonly Settings $settings,
        private readonly Repository $repo,
        private readonly SdvPlan $plan,
        private readonly SdvService $service,
        private readonly Roles $roles
    ) {
        $this->groups = $proj->getGroups() ?: [];
        $this->withDags = !empty($this->groups);
    }

    // ---- input ----------------------------------------------------------------------------------

    private static function str(array $in, string $key, int $max = 100): string
    {
        $v = isset($in[$key]) && is_string($in[$key]) ? trim($in[$key]) : '';
        return mb_substr($v, 0, $max);
    }

    private static function date(array $in, string $key, string $time): ?string
    {
        $v = self::str($in, $key, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? "$v $time" : null;
    }

    public function tab(array $in): string
    {
        $t = self::str($in, 'tab', 10);
        return in_array($t, ['forms', 'queries', 'summary'], true) ? $t : 'forms';
    }

    // The DAG filter: a user assigned to a DAG is always restricted to it
    private function groupFilter(array $in): string
    {
        if ($this->roles->groupId !== null) return (string)$this->roles->groupId;
        $g = self::str($in, 'site', 10);
        return ($g !== '' && isset($this->groups[(int)$g])) ? (string)(int)$g : '';
    }

    public function formFilters(array $in): array
    {
        $status = self::str($in, 'status', 20);
        $instrument = self::str($in, 'form');
        $event = self::str($in, 'event', 10);
        $openq = self::str($in, 'openq', 3);
        return [
            'record' => self::str($in, 'record'),
            'group_id' => $this->groupFilter($in),
            'event_id' => isset($this->proj->eventInfo[(int)$event]) ? (string)(int)$event : '',
            'instrument' => isset($this->proj->forms[$instrument]) ? $instrument : '',
            'status' => isset(SdvService::STATUS_LABELS[$status]) ? $status : '',
            'open_queries' => in_array($openq, ['yes', 'no'], true) ? $openq : '',
            'user' => self::str($in, 'user', 255),
            'from' => self::date($in, 'from', '00:00:00'),
            'to' => self::date($in, 'to', '23:59:59'),
        ];
    }

    public function queryFilters(array $in): array
    {
        $status = self::str($in, 'qstatus', 20);
        $instrument = self::str($in, 'form');
        $event = self::str($in, 'event', 10);
        $response = self::str($in, 'response');
        return [
            'record' => self::str($in, 'record'),
            'group_id' => $this->groupFilter($in),
            'event_id' => isset($this->proj->eventInfo[(int)$event]) ? (string)(int)$event : '',
            'instrument' => isset($this->proj->forms[$instrument]) ? $instrument : '',
            'field' => self::str($in, 'field'),
            'status' => isset(SdvService::QUERY_STATUS_LABELS[$status]) ? $status : '',
            'user' => self::str($in, 'user', 255),
            'response' => in_array($response, SdvService::RESPONSE_CODES, true) ? $response : '',
            'text' => self::str($in, 'text', 200),
            'from' => self::date($in, 'from', '00:00:00'),
            'to' => self::date($in, 'to', '23:59:59'),
        ];
    }

    public function paging(array $in): array
    {
        $size = (int)($in['size'] ?? 25);
        if (!in_array($size, self::PAGE_SIZES, true)) $size = 25;
        $page = max(0, (int)($in['pg'] ?? 0));
        $dir = self::str($in, 'dir', 4) === 'asc' ? 'asc' : 'desc';
        return [$size, $page, $dir];
    }

    // ---- sync -----------------------------------------------------------------------------------

    /**
     * Runs the sync when forced, or when the last one is older than SYNC_INTERVAL_SECONDS. The time of the
     * last sync is kept in the module audit table (module pages cannot write project settings for non-admins).
     */
    public function syncIfDue(bool $force): ?array
    {
        if (!$this->roles->canSync()) return null;
        $last = $this->repo->lastActionTs('sync');
        if (!$force && $last !== null && time() - strtotime($last) < self::SYNC_INTERVAL_SECONDS) return null;
        $result = $this->service->sync($this->roles->username);
        $this->repo->audit($this->roles->username, SdvService::now(), 'sync', null, null, null, null, $result);
        if ($force || $result['added'] || $result['removed'] || $result['cleared'] || $result['changedForms'] || $result['failed']) {
            \REDCap::logEvent('SDV: dashboard sync', "forms added = {$result['added']},\nforms removed = {$result['removed']},\n"
                . "forms cleared after data deletion = {$result['cleared']},\nforms with data changed after SDV = {$result['changedForms']},\n"
                . "forms that failed = {$result['failed']}", '', null, null, $this->repo->projectId);
        }
        return $result;
    }

    // ---- rows -----------------------------------------------------------------------------------

    public function eventName($eventId): string
    {
        return $this->proj->longitudinal ? (string)($this->proj->eventInfo[$eventId]['name_ext'] ?? $eventId) : '';
    }

    public function formName(string $instrument): string
    {
        return strip_tags((string)($this->proj->forms[$instrument]['menu'] ?? $instrument));
    }

    public function siteName($groupId): string
    {
        return $groupId === null || $groupId === '' ? '' : (string)($this->groups[(int)$groupId] ?? $groupId);
    }

    public function fieldText(string $field): string
    {
        if (!$this->settings->includeFieldLabel()) return $field;
        $label = SdvPlan::cleanLabel($this->proj->metadata[$field]['element_label'] ?? '');
        return $label === '' ? $field : "$field [$label]";
    }

    public function formUrl(string $record, $eventId, string $instrument, $instance): string
    {
        return APP_PATH_WEBROOT . 'DataEntry/index.php?' . http_build_query([
            'pid' => $this->repo->projectId, 'id' => $record, 'event_id' => $eventId, 'page' => $instrument, 'instance' => $instance,
        ]);
    }

    private static function key(array $r): string
    {
        return implode("\x1f", [$r['record'], $r['event_id'], $r['instrument'], $r['instance']]);
    }

    /**
     * Form rows decorated with required/verified counts and active query counts (two extra queries per page).
     */
    public function formRows(array $flt, ?int $limit, int $offset, string $dir): array
    {
        $rows = $this->repo->listForms($flt, $this->withDags, $limit, $offset, $dir);
        $records = array_values(array_unique(array_column($rows, 'record')));

        $verified = [];
        foreach ($this->repo->fieldRowsForRecords($records) as $f) {
            if ($f['status'] === 'verified') $verified[self::key($f)][$f['field_name']] = true;
        }
        $queries = [];
        foreach ($this->repo->activeQueryCountsForRecords($records) as $q) {
            $queries[self::key($q)] = (int)$q['c'];
        }

        foreach ($rows as &$r) {
            $k = self::key($r);
            $required = $this->plan->requiredFields($r['instrument']);
            $r['required'] = count($required);
            $r['required_verified'] = count(array_intersect($required, array_keys($verified[$k] ?? [])));
            $r['verified'] = count($verified[$k] ?? []);
            $r['active_queries'] = $queries[$k] ?? 0;
        }
        return $rows;
    }

    public function queryRows(array $flt, ?int $limit, int $offset, string $dir): array
    {
        $rows = $this->repo->listQueries($flt, $this->withDags, $limit, $offset, $dir);
        $messages = $this->repo->getMessages(array_map(fn($r) => (int)$r['query_id'], $rows));
        foreach ($rows as &$r) {
            $msgs = $messages[(int)$r['query_id']] ?? [];
            $r['messages'] = $msgs;
            $r['query_text'] = '';
            $r['response'] = '';
            foreach ($msgs as $m) {
                if (in_array($m['action'], ['raise', 'reraise'], true) && $m['text'] !== null) $r['query_text'] = $m['text'];
                if ($m['action'] === 'respond') $r['response'] = trim($m['response_code'] . ($m['text'] ? ': ' . $m['text'] : ''));
            }
        }
        return $rows;
    }

    /**
     * Status counts per instrument (and per site when the project uses DAGs).
     * @return array<string, array<string, array<string,int>>> site => instrument => status => count
     */
    public function summary(): array
    {
        $out = [];
        foreach ($this->repo->formSummary($this->withDags, $this->roles->groupId) as $r) {
            $site = $this->withDags ? ($r['group_id'] === null ? '' : (string)(int)$r['group_id']) : '';
            $out[$site][$r['instrument']][$r['status']] = (int)$r['c'];
        }
        return $out;
    }
}
