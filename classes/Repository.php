<?php

namespace CCTC\SourceDataVerificationModule\Classes;

use ExternalModules\AbstractExternalModule;

/**
 * All SQL for the module. Every statement is parameterised and scoped by project_id, and every
 * lookup against the module tables or the REDCap data table goes through an index.
 */
class Repository
{
    public const FORM = 'cctc_sdv_form';
    public const FIELD = 'cctc_sdv_field';
    public const PLAN = 'cctc_sdv_plan';
    public const QUERY = 'cctc_sdv_query';
    public const MSG = 'cctc_sdv_query_msg';
    public const AUDIT = 'cctc_sdv_audit';

    private const FORM_KEY = 'project_id = ? and record = ? and event_id = ? and instrument = ? and instance = ?';

    private string $dataTable;

    public function __construct(private readonly AbstractExternalModule $module, public readonly int $projectId)
    {
        $this->dataTable = \REDCap::getDataTable($projectId);
    }

    private function q(string $sql, array $params = [])
    {
        return $this->module->query($sql, $params);
    }

    private function rows(string $sql, array $params = []): array
    {
        $result = $this->q($sql, $params);
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        return $rows;
    }

    private static function in(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    // ---- transactions ---------------------------------------------------------------------------

    public function begin(): void { db_query('START TRANSACTION'); }
    public function commit(): void { db_query('COMMIT'); }
    public function rollback(): void { db_query('ROLLBACK'); }

    // ---- plan -----------------------------------------------------------------------------------

    public function getPlanOverrides(): array
    {
        $out = [];
        foreach ($this->rows('select field_name, requirement from ' . self::PLAN . ' where project_id = ?', [$this->projectId]) as $r) {
            $out[$r['field_name']] = $r['requirement'];
        }
        return $out;
    }

    public function setPlanOverride(string $field, ?string $requirement, string $user, string $ts): void
    {
        if ($requirement === null) {
            $this->q('delete from ' . self::PLAN . ' where project_id = ? and field_name = ?', [$this->projectId, $field]);
            return;
        }
        $this->q('insert into ' . self::PLAN . ' (project_id, field_name, requirement, updated_by, updated_at) values (?, ?, ?, ?, ?)
                  on duplicate key update requirement = values(requirement), updated_by = values(updated_by), updated_at = values(updated_at)',
            [$this->projectId, $field, $requirement, $user, $ts]);
    }

    // ---- form instance state --------------------------------------------------------------------

    public function ensureFormRow(FormContext $ctx, string $ts): void
    {
        $this->q('insert ignore into ' . self::FORM . ' (project_id, record, event_id, instrument, instance, status, created_at)
                  values (?, ?, ?, ?, ?, \'pending\', ?)', [...$ctx->keyParams(), $ts]);
    }

    public function getFormRow(FormContext $ctx, bool $forUpdate = false): ?array
    {
        $rows = $this->rows('select * from ' . self::FORM . ' where ' . self::FORM_KEY . ($forUpdate ? ' for update' : ''), $ctx->keyParams());
        return $rows[0] ?? null;
    }

    public function updateForm(FormContext $ctx, array $cols): void
    {
        $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($cols)));
        $this->q('update ' . self::FORM . " set $sets where " . self::FORM_KEY, [...array_values($cols), ...$ctx->keyParams()]);
    }

    /** @return array<string, array> field => row */
    public function getFieldRows(FormContext $ctx): array
    {
        $out = [];
        foreach ($this->rows('select * from ' . self::FIELD . ' where ' . self::FORM_KEY, $ctx->keyParams()) as $r) {
            $out[$r['field_name']] = $r;
        }
        return $out;
    }

    public function upsertVerifiedField(FormContext $ctx, string $field, string $hash, string $user, string $ts): void
    {
        $this->q('insert into ' . self::FIELD . ' (project_id, record, event_id, instance, field_name, instrument, status, value_hash, verified_by, verified_at)
                  values (?, ?, ?, ?, ?, ?, \'verified\', ?, ?, ?)
                  on duplicate key update status = \'verified\', value_hash = values(value_hash),
                      verified_by = values(verified_by), verified_at = values(verified_at)',
            [$ctx->projectId, $ctx->record, $ctx->eventId, $ctx->instance, $field, $ctx->instrument, $hash, $user, $ts]);
    }

    public function deleteField(FormContext $ctx, string $field): void
    {
        $this->q('delete from ' . self::FIELD . ' where project_id = ? and record = ? and event_id = ? and instance = ? and field_name = ?',
            [$ctx->projectId, $ctx->record, $ctx->eventId, $ctx->instance, $field]);
    }

    // Marks a verified field as changed only if it is still verified with the given hash. Returns true if it changed state.
    public function markFieldChanged(FormContext $ctx, string $field, string $oldHash): bool
    {
        $this->q('update ' . self::FIELD . ' set status = \'changed\'
                  where project_id = ? and record = ? and event_id = ? and instance = ? and field_name = ? and status = \'verified\' and value_hash = ?',
            [$ctx->projectId, $ctx->record, $ctx->eventId, $ctx->instance, $field, $oldHash]);
        return db_affected_rows() > 0;
    }

    // ---- queries --------------------------------------------------------------------------------

    public function getQueries(FormContext $ctx): array
    {
        return $this->rows('select * from ' . self::QUERY . ' where project_id = ? and record = ? and event_id = ? and instance = ? and instrument = ? order by query_id',
            [$ctx->projectId, $ctx->record, $ctx->eventId, $ctx->instance, $ctx->instrument]);
    }

    public function getQuery(int $queryId, bool $forUpdate = false): ?array
    {
        $rows = $this->rows('select * from ' . self::QUERY . ' where query_id = ? and project_id = ?' . ($forUpdate ? ' for update' : ''),
            [$queryId, $this->projectId]);
        return $rows[0] ?? null;
    }

    /** @return array<int, array[]> query_id => messages in order */
    public function getMessages(array $queryIds): array
    {
        if (empty($queryIds)) return [];
        $out = [];
        foreach ($this->rows('select * from ' . self::MSG . ' where query_id in (' . self::in($queryIds) . ') order by msg_id', array_values($queryIds)) as $r) {
            $out[(int)$r['query_id']][] = $r;
        }
        return $out;
    }

    public function insertQuery(FormContext $ctx, string $field, string $user, string $ts): int
    {
        $this->q('insert into ' . self::QUERY . ' (project_id, record, event_id, instrument, instance, field_name, status, raised_by, raised_at, updated_at)
                  values (?, ?, ?, ?, ?, ?, \'open\', ?, ?, ?)', [...$ctx->keyParams(), $field, $user, $ts, $ts]);
        return (int)db_insert_id();
    }

    public function setQueryStatus(int $queryId, string $status, string $ts): void
    {
        $this->q('update ' . self::QUERY . ' set status = ?, updated_at = ? where query_id = ? and project_id = ?', [$status, $ts, $queryId, $this->projectId]);
    }

    public function insertMessage(int $queryId, string $action, ?string $code, ?string $text, string $user, string $ts): void
    {
        $this->q('insert into ' . self::MSG . ' (query_id, action, response_code, text, username, ts) values (?, ?, ?, ?, ?, ?)',
            [$queryId, $action, $code, $text, $user, $ts]);
    }

    // ---- audit ----------------------------------------------------------------------------------

    public function audit(string $user, string $ts, string $action, ?FormContext $ctx, ?string $field,
                          ?string $oldStatus, ?string $newStatus, ?array $detail): void
    {
        $this->q('insert into ' . self::AUDIT . ' (project_id, ts, username, action, record, event_id, instrument, instance, field_name, old_status, new_status, detail)
                  values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->projectId, $ts, $user, $action, $ctx?->record, $ctx?->eventId, $ctx?->instrument, $ctx?->instance,
                $field, $oldStatus, $newStatus, $detail === null ? null : json_encode($detail)]);
    }

    public function getAudit(FormContext $ctx): array
    {
        return $this->rows('select * from ' . self::AUDIT . ' where ' . self::FORM_KEY . ' order by audit_id', $ctx->keyParams());
    }

    public function lastActionTs(string $action): ?string
    {
        $rows = $this->rows('select max(ts) ts from ' . self::AUDIT . ' where project_id = ? and action = ?', [$this->projectId, $action]);
        return $rows[0]['ts'] ?? null;
    }

    public function getPlanAudit(int $limit = 200): array
    {
        return $this->rows('select * from ' . self::AUDIT . ' where project_id = ? and action = \'plan_changed\' order by audit_id desc limit ' . (int)$limit,
            [$this->projectId]);
    }

    // ---- REDCap data ----------------------------------------------------------------------------

    /**
     * Raw values for the given fields of one form instance, read straight from the data table using
     * the (project_id, record, field_name) index. Checkbox fields return one value per ticked option.
     * @return array<string, string[]>
     */
    public function readValues(FormContext $ctx, array $fields): array
    {
        if (empty($fields)) return [];
        $sql = "select field_name, value from {$this->dataTable}
                where project_id = ? and record = ? and field_name in (" . self::in($fields) . ")
                  and event_id = ? and coalesce(instance, 1) = ?";
        $out = [];
        foreach ($this->rows($sql, [$ctx->projectId, $ctx->record, ...$fields, $ctx->eventId, $ctx->instance]) as $r) {
            $out[$r['field_name']][] = (string)$r['value'];
        }
        return $out;
    }

    public function recordGroupId(string $record): ?int
    {
        $rows = $this->rows("select value from {$this->dataTable} where project_id = ? and record = ? and field_name = '__GROUPID__' limit 1",
            [$this->projectId, $record]);
        return isset($rows[0]) ? (int)$rows[0]['value'] : null;
    }

    // ---- dashboard ------------------------------------------------------------------------------

    // Derived table of record => DAG id. Uses the data table's (project_id, field_name) index. The value is cast
    // to an integer because grouping on the TEXT column forces an on-disk temporary table (5x slower).
    private function dagJoin(): string
    {
        return "left join (select record, min(cast(value as unsigned)) as group_id from {$this->dataTable}
                           where project_id = ? and field_name = '__GROUPID__' group by record) g on g.record = f.record";
    }

    /**
     * @param array $flt keys: record, group_id, event_id, instrument, status, open_queries (yes/no), user, from, to
     * @return array{0:string,1:array} where-clause and params for cctc_sdv_form f
     */
    private function formWhere(array $flt): array
    {
        $w = ['f.project_id = ?'];
        $p = [$this->projectId];
        foreach (['record' => 'f.record', 'event_id' => 'f.event_id', 'instrument' => 'f.instrument', 'status' => 'f.status', 'instance' => 'f.instance'] as $k => $col) {
            if (isset($flt[$k]) && $flt[$k] !== '') { $w[] = "$col = ?"; $p[] = $flt[$k]; }
        }
        if (isset($flt['group_id']) && $flt['group_id'] !== '') { $w[] = 'g.group_id = ?'; $p[] = $flt['group_id']; }
        if (!empty($flt['user'])) { $w[] = '(f.updated_by = ? or f.completed_by = ?)'; $p[] = $flt['user']; $p[] = $flt['user']; }
        if (!empty($flt['from'])) { $w[] = 'coalesce(f.updated_at, f.created_at) >= ?'; $p[] = $flt['from']; }
        if (!empty($flt['to'])) { $w[] = 'coalesce(f.updated_at, f.created_at) <= ?'; $p[] = $flt['to']; }
        if (($flt['open_queries'] ?? '') !== '') {
            $w[] = ($flt['open_queries'] === 'yes' ? '' : 'not ') . 'exists (select 1 from ' . self::QUERY . " q
                    where q.project_id = f.project_id and q.record = f.record and q.event_id = f.event_id
                      and q.instance = f.instance and q.instrument = f.instrument and q.status <> 'closed')";
        }
        return [implode(' and ', $w), $p];
    }

    public function countForms(array $flt, bool $withDags): int
    {
        [$where, $p] = $this->formWhere($flt);
        $join = $withDags ? $this->dagJoin() : '';
        $params = $withDags ? [$this->projectId, ...$p] : $p;
        $rows = $this->rows('select count(*) c from ' . self::FORM . " f $join where $where", $params);
        return (int)$rows[0]['c'];
    }

    public function listForms(array $flt, bool $withDags, ?int $limit, int $offset, string $dir): array
    {
        [$where, $p] = $this->formWhere($flt);
        $join = $withDags ? $this->dagJoin() : '';
        $params = $withDags ? [$this->projectId, ...$p] : $p;
        $groupCol = $withDags ? 'g.group_id' : 'null as group_id';
        $dir = $dir === 'asc' ? 'asc' : 'desc';
        $sql = "select f.record, f.event_id, f.instrument, f.instance, f.status, f.status_reason, f.created_at, f.updated_by,
                       f.updated_at, f.completed_by, f.completed_at, $groupCol
                from " . self::FORM . " f $join where $where
                order by coalesce(f.updated_at, f.created_at) $dir, f.record, f.event_id, f.instrument, f.instance";
        if ($limit !== null) $sql .= ' limit ' . (int)$limit . ' offset ' . (int)$offset;
        return $this->rows($sql, $params);
    }

    // Verified/changed field rows and active query counts for a set of records (used for one page of results)
    public function fieldRowsForRecords(array $records): array
    {
        if (empty($records)) return [];
        return $this->rows('select record, event_id, instrument, instance, field_name, status from ' . self::FIELD .
            ' where project_id = ? and record in (' . self::in($records) . ')', [$this->projectId, ...$records]);
    }

    public function activeQueryCountsForRecords(array $records): array
    {
        if (empty($records)) return [];
        return $this->rows('select record, event_id, instrument, instance, count(*) c from ' . self::QUERY .
            " where project_id = ? and record in (" . self::in($records) . ") and status <> 'closed'
              group by record, event_id, instrument, instance", [$this->projectId, ...$records]);
    }

    private function queryWhere(array $flt): array
    {
        $w = ['f.project_id = ?'];
        $p = [$this->projectId];
        foreach (['record' => 'f.record', 'event_id' => 'f.event_id', 'instrument' => 'f.instrument', 'field' => 'f.field_name', 'status' => 'f.status', 'user' => 'f.raised_by'] as $k => $col) {
            if (isset($flt[$k]) && $flt[$k] !== '') { $w[] = "$col = ?"; $p[] = $flt[$k]; }
        }
        if (isset($flt['group_id']) && $flt['group_id'] !== '') { $w[] = 'g.group_id = ?'; $p[] = $flt['group_id']; }
        if (!empty($flt['from'])) { $w[] = 'f.updated_at >= ?'; $p[] = $flt['from']; }
        if (!empty($flt['to'])) { $w[] = 'f.updated_at <= ?'; $p[] = $flt['to']; }
        if (!empty($flt['response'])) {
            $w[] = 'exists (select 1 from ' . self::MSG . ' m where m.query_id = f.query_id and m.response_code = ?)';
            $p[] = $flt['response'];
        }
        if (!empty($flt['text'])) {
            $w[] = 'exists (select 1 from ' . self::MSG . ' m where m.query_id = f.query_id and m.text like ?)';
            $p[] = '%' . addcslashes($flt['text'], '%_\\') . '%';
        }
        return [implode(' and ', $w), $p];
    }

    public function countQueries(array $flt, bool $withDags): int
    {
        [$where, $p] = $this->queryWhere($flt);
        $join = $withDags ? $this->dagJoin() : '';
        $params = $withDags ? [$this->projectId, ...$p] : $p;
        return (int)$this->rows('select count(*) c from ' . self::QUERY . " f $join where $where", $params)[0]['c'];
    }

    public function listQueries(array $flt, bool $withDags, ?int $limit, int $offset, string $dir): array
    {
        [$where, $p] = $this->queryWhere($flt);
        $join = $withDags ? $this->dagJoin() : '';
        $params = $withDags ? [$this->projectId, ...$p] : $p;
        $groupCol = $withDags ? 'g.group_id' : 'null as group_id';
        $dir = $dir === 'asc' ? 'asc' : 'desc';
        $sql = "select f.*, $groupCol from " . self::QUERY . " f $join where $where order by f.updated_at $dir, f.query_id $dir";
        if ($limit !== null) $sql .= ' limit ' . (int)$limit . ' offset ' . (int)$offset;
        return $this->rows($sql, $params);
    }

    // Form status counts per instrument and DAG for the progress summary
    public function formSummary(bool $withDags, ?int $groupId): array
    {
        $join = $withDags ? $this->dagJoin() : '';
        $params = $withDags ? [$this->projectId, $this->projectId] : [$this->projectId];
        $groupCol = $withDags ? 'g.group_id' : 'null';
        $where = 'f.project_id = ?';
        if ($groupId !== null) { $where .= ' and g.group_id = ?'; $params[] = $groupId; }
        return $this->rows("select f.instrument, $groupCol as group_id, f.status, count(*) c
                            from " . self::FORM . " f $join where $where group by f.instrument, $groupCol, f.status", $params);
    }

    public function distinctUsers(): array
    {
        $rows = $this->rows('select distinct u from (
                select updated_by u from ' . self::FORM . ' where project_id = ? and updated_by is not null
                union select raised_by from ' . self::QUERY . ' where project_id = ?) x order by u', [$this->projectId, $this->projectId]);
        return array_column($rows, 'u');
    }

    // ---- sync -----------------------------------------------------------------------------------

    /**
     * Adds a 'pending' row for every saved instance of the given instruments, found via their
     * <instrument>_complete rows (data table index on project_id, field_name). Existing rows are untouched.
     */
    public function backfillForms(array $instruments, string $ts): int
    {
        if (empty($instruments)) return 0;
        $completeFields = array_map(fn($i) => "{$i}_complete", $instruments);
        $this->q('insert ignore into ' . self::FORM . " (project_id, record, event_id, instrument, instance, status, created_at)
                  select distinct project_id, record, event_id, substring(field_name, 1, char_length(field_name) - 9), coalesce(instance, 1), 'pending', ?
                  from {$this->dataTable}
                  where project_id = ? and field_name in (" . self::in($completeFields) . ") and record is not null",
            [$ts, $this->projectId, ...$completeFields]);
        return db_affected_rows();
    }

    /**
     * Removes 'pending' rows nobody has acted on whose instrument no longer requires SDV or whose
     * form data no longer exists (record or form deleted). Rows with any SDV activity are kept.
     */
    public function prunePendingForms(array $instrumentsRequiringSdv): int
    {
        $noLongerRequired = empty($instrumentsRequiringSdv) ? '1 = 1' : 'f.instrument not in (' . self::in($instrumentsRequiringSdv) . ')';
        $sql = 'delete f from ' . self::FORM . " f
                where f.project_id = ? and f.status = 'pending' and f.updated_at is null
                  and not exists (select 1 from " . self::FIELD . ' v where v.project_id = f.project_id and v.record = f.record
                                  and v.event_id = f.event_id and v.instance = f.instance and v.instrument = f.instrument)
                  and not exists (select 1 from ' . self::QUERY . " q where q.project_id = f.project_id and q.record = f.record
                                  and q.event_id = f.event_id and q.instance = f.instance and q.instrument = f.instrument)
                  and ($noLongerRequired
                       or not exists (select 1 from {$this->dataTable} d where d.project_id = f.project_id and d.record = f.record
                                      and d.field_name = concat(f.instrument, '_complete') and d.event_id = f.event_id
                                      and coalesce(d.instance, 1) = f.instance))";
        $this->q($sql, [$this->projectId, ...$instrumentsRequiringSdv]);
        return db_affected_rows();
    }

    /**
     * Current raw values of every verified field in the project, joined on the data table index, so
     * changes made by data import or the API (which fire no save hook) can be detected in bulk.
     */
    public function verifiedFieldValues(): array
    {
        return $this->rows('select v.record, v.event_id, v.instrument, v.instance, v.field_name, v.value_hash, d.value
                from ' . self::FIELD . " v
                left join {$this->dataTable} d on d.project_id = v.project_id and d.record = v.record
                     and d.field_name = v.field_name and d.event_id = v.event_id and coalesce(d.instance, 1) = v.instance
                where v.project_id = ? and v.status = 'verified'", [$this->projectId]);
    }
}
