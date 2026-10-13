### Source Data Verification (SDV) ###

The Source Data Verification module supports risk-based SDV in clinical trials. Monitors decide which fields require
SDV, verify individual fields against source, raise monitor queries that site staff respond to, and mark each form as
**SDV complete**. Completed forms automatically drop back to **Re-verification required** when verified data changes.

All SDV state is kept in the module's own indexed database tables. The module does **not** use a monitoring-status
field on each form, does **not** use the Data Resolution Workflow, and does **not** modify any REDCap core files.

> This module replaces the approach used by the Monitoring QR module. The two can run side by side; existing
> Monitoring QR studies are not migrated.

#### System set up ####

Enabling the module in the Control Center creates the following tables if they do not already exist. They are never
dropped (disabling or upgrading the module keeps the full SDV history).

| Table | Holds |
|---|---|
| `sdv_form` | SDV status of each saved form instance (record / event / instrument / instance) |
| `sdv_field` | each verified field, with a SHA-256 hash of the value that was verified (no copy of the data) |
| `sdv_plan` | fields whose SDV requirement has been changed by a monitor |
| `sdv_query`, `sdv_query_msg` | monitor query threads and their messages |
| `sdv_audit` | append-only audit trail of every SDV action |

Tested with REDCap 14.7.0 on MariaDB 10.11 and PHP 8.2. Requires PHP 8.1+.

#### Project configuration ####

| Setting | Purpose |
|---|---|
| Monitor role(s) | Users in these roles mark fields as requiring SDV, verify fields, raise and resolve queries, and mark forms SDV complete / not required |
| Data entry role(s) | Users in these roles respond to monitor queries |
| Data manager role(s) | Users in these roles can view SDV status and queries (and respond if allowed below) |
| SDV action tag regex | Fields whose action tags match are **required** for SDV, e.g. `@SDV` (default) or `@ENDPOINT-\w+`. Do not include the surrounding `/` |
| SDV ignore action tag | Fields with this tag are **excluded** from SDV, e.g. `@SDV-IGNORE` |
| Monitors can only verify and query fields that require SDV | Restricts monitors to required fields |
| Re-verification trigger | When a form marked SDV complete returns to *Re-verification required* (see below) |
| Data managers can also respond | Lets data managers respond to monitor queries |
| Show open query text inline (per role) | Shows the query text next to the field label |
| Do not hide Save/Cancel, Do not make read-only | By default monitors and data managers see the form read-only without save buttons |
| Show field label | Shows `field [label]` in the query table and dashboard |

A role can only be one of monitor, data entry or data manager. All settings are super-user only, and every change is
written to the module's **View Logs** page (who, when, old value → new value).

Users need form access through their normal REDCap user rights. Users in a data access group only see their own
site's records, on forms and on the dashboard. Giving monitors **read-only** form rights in REDCap is recommended.

#### SDV plan: which fields require SDV ####

Each field's default requirement comes from its action tags:

- **required** — action tags match the SDV regex
- **excluded** — has the ignore tag
- **optional** — everything else. Monitors may verify optional fields, but they don't block completion

Monitors can override any field's requirement for **all records**, either on the **SDV Plan** page or from the
requirement dropdown in the field's SDV badge on the data entry form. Overrides are audited, logged, and listed under
*Recent SDV plan changes*. The record ID field, descriptive fields and the form status field never take part in SDV.

On the SDV Plan page, **Set all fields on this form to** gives every field on a form the same requirement in one step,
for example to fully verify an eligibility form or to switch SDV off for a whole form.
- *Required*, *Optional* and *Excluded* store an override on each field, including fields with the ignore tag. After
  that, changing those fields' action tags in the Online Designer has no effect.
- *Default (from action tags)* removes the form's overrides, so the action tags decide again.
- One audit entry is written per field that changes, and one Logging entry for the whole change
  (`SDV: plan changed for whole form`).

#### Workflow on the data entry form ####

Under each field label the module shows an SDV badge: *SDV required*, *SDV verified*, *Changed since SDV*, *Query
open* / *Query responded*, and, for monitors, the **Verify / Unverify / Raise query** buttons and the requirement
dropdown. An SDV panel below the form shows the form's status, *Required fields verified: x / y*, the active queries
and the action buttons. Every action is saved immediately (no page reload). The SDV history is loaded on request.

Form statuses:

| Status | Meaning |
|---|---|
| SDV pending | The form needs SDV and nothing has been done yet |
| SDV in progress | At least one field has been verified or queried |
| SDV complete | A monitor marked the form complete. All required fields were verified and no queries were open |
| SDV not required | A monitor marked SDV as not required, with a reason. Any open queries are closed |
| Re-verification required | Data changed after the form was marked complete (see trigger) |

Rules:

- **Mark SDV complete** needs every required field verified against its current value and no open or responded queries.
- **Verify all required fields** verifies every required field without an active query.
- A field with an active query cannot be verified. Raising a query on a verified field removes its verification.
- Forms marked complete or not required are locked: **Reopen SDV** (with a reason) to make changes.
- Monitor queries: monitor **raises** → site **responds** with one of *Value updated as per source*, *Value correct as
  per source*, *Value correct, error in source updated*, *Missing data not done* (plus an optional comment) → monitor
  **accepts** (optionally verifying the field), **re-raises** (optionally with new text) or **closes** the query.
  There is one active query per field at a time. Responding needs edit rights to the form (*View & Edit* or
  *Edit survey responses*); users with read-only rights to a form see its queries but cannot respond.

#### Data changes after verification ####

When a verified field's value changes, the field always loses its verification (*Changed since SDV*). Whether a form
marked **SDV complete** returns to **Re-verification required** depends on the trigger setting:

| Trigger | A complete form needs re-verification when |
|---|---|
| verified (recommended) | a field that had been verified changed |
| always | any field on the form changed |
| required | a field that requires SDV changed |
| previously_queried | a field that has been queried before changed |
| previously_queried_or_required | either of the two above |
| never | never (fields still lose their verification) |

Changes are detected:

- when the form is saved in data entry or a survey, through the standard `redcap_save_record` hook;
- when the form is next opened;
- in bulk by the dashboard's sync, which catches data import and API changes without the form being opened.

#### Deleted form data ####

SDV status belongs to the data that was verified. If a record, form or repeating instance is deleted, its SDV status
and verified fields are cleared and its active queries are closed. The query threads and the SDV history are kept. A
record or form that is later re-created starts again at *SDV pending*. Deletions are found:

- when the form is next opened or saved, from the project log (REDCap has no hook for deleting a record);
- by the dashboard's sync.

Each clearance is logged as `SDV: status cleared after form data was deleted`.

#### SDV Dashboard ####

- **Forms tab** — every saved form instance that needs SDV, with SDV status, required fields verified, active queries
  and last activity. Filter by record, site, event, form, status, active queries, user and date. Paged, with CSV
  export of the filtered list or everything.
- **Queries tab** — all monitor queries with status, latest query text and response. Filter by record, site, form,
  field, status, response, raising user, text and date. CSV export includes each query's full thread.
- **Summary tab** — counts per form and status, per site, with % done (complete or not required). Counts link to the
  filtered forms list.

When a monitor opens the dashboard, it syncs automatically, at most every 5 minutes. **Sync now**, shown only to
monitors, forces a sync. Other roles see the results of the last sync.

A sync does the following:

- adds forms saved since the last sync;
- removes untouched entries for forms that no longer need SDV;
- clears the SDV status of deleted form data;
- re-checks verified values, and the fields of complete forms when the trigger looks beyond verified fields, for
  changes made outside data entry.

If one form fails, the sync carries on with the rest and lists the failures.

#### Logging and audit trail ####

- Every SDV action is recorded in the project **Logging** page with the record, event and form. Descriptions start
  with `SDV:`, e.g. `SDV: field verified`, `SDV: monitor query raised`, `SDV: form marked SDV complete`,
  `SDV: verified field value changed`, `SDV: plan changed`, `SDV: data exported`.
- Every action is also written to the append-only `sdv_audit` table, shown as the form's SDV history.
- Configuration changes go to the module's **View Logs** page.

#### Performance ####

Measured on a project with 5,000 records, 300,003 data values, 40,000 form instances, 40,006 verified fields and
2,001 queries. Times are full page loads through the web server unless stated.

| Operation | Time |
|---|---|
| Data entry form with SDV panel | ~50 ms |
| Verify a field / refresh SDV state (AJAX) | ~20 ms |
| Dashboard forms / queries / summary tab | 80–220 ms |
| Dashboard as a DAG-restricted user | ~160 ms |
| Full sync (40,000 forms, 40,000 verified values re-checked) | ~1.3 s |
| *Monitoring QR log page query (`GetMonitorQueries`, SQL only) on the same data* | *5.3–7.5 s* |

Every query is parameterised, scoped by project and served by an index. The data entry form reads only the current
form instance's values.

#### Automation testing ####

Cypress/Gherkin features are in `automated_tests` (E.129.100–E.129.300), with EM-specific step definitions in
`automated_tests/step_definitions/external_module.js` and the requirements in `automated_tests/urs`. They follow the
same conventions as the other CCTC modules; see
[Setup_Overview.md](https://github.com/CCTC-team/CCTC_REDCap_Docker/blob/redcap_val/Setup_Overview.md).

---

## Who are we

The Cambridge Cancer Trials Centre (CCTC) is a collaboration between Cambridge University Hospitals NHS Foundation Trust, the University of Cambridge, and Cancer Research UK. Founded in 2007, CCTC designs and conducts clinical trials and studies to improve outcomes for patients with cancer or those at risk of developing it. In 2011, CCTC began hosting the Cambridge Clinical Trials Unit - Cancer Theme (CCTU-CT).
