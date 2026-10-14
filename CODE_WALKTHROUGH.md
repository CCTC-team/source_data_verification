# Source Data Verification module: code walkthrough

This document explains how the module's code works, in the order things happen: installation, configuration,
deciding which fields need SDV, opening a form, a monitor's actions, saving, queries, and the dashboard.
For what the module does from a user's point of view, see [README.md](README.md).

Line numbers in the links were correct when this was written and may drift as the code changes.

---

## 0. The parts

```
config.json                      ← tells REDCap what the module is: settings, pages, AJAX actions
SourceDataVerificationModule.php ← entry point: REDCap calls its hook methods
classes/
  Settings.php    project settings with typed getters
  Roles.php       who the current user is: monitor, data entry or data manager, plus DAG and form rights
  FormContext.php identifies one form instance (record + event + instrument + instance)
  Repository.php  all SQL
  SdvPlan.php     which fields require SDV
  ValueHasher.php fingerprints field values
  SdvService.php  the business rules (verify, complete, queries, sync...)
  Dashboard.php   filtering and paging for the dashboard
dashboard.php / plan.php / export.php   the module's project pages
page_init.php   shared set-up for those pages
js/sdv-form.js  the SDV panel and field badges on the data entry form
sql/schema.sql  the 6 sdv_* tables
```

The PHP classes are layered, and each one only calls the layers below it:

**hooks / pages → `SdvService` → `SdvPlan` + `Repository` → database**

`SourceDataVerificationModule::services()` ([line 49](SourceDataVerificationModule.php#L49)) builds these objects for
a project and returns them as `[Settings, Repository, SdvPlan, SdvService, Project]`. It creates new objects on every
call, which matters after a plan change: calling it again gives a service that sees the new plan.

---

## 1. Enabling the module creates the tables

When an admin enables the module system-wide, REDCap calls `redcap_module_system_enable`
([SourceDataVerificationModule.php:84](SourceDataVerificationModule.php#L84)). It reads [sql/schema.sql](sql/schema.sql),
strips the comments, and runs each `CREATE TABLE IF NOT EXISTS` statement.

| Table | Holds |
|---|---|
| `sdv_form` | One row per form instance: its SDV status (`pending`, `in_progress`, `complete`, `not_required` or `reverify`) and the value snapshot taken at completion |
| `sdv_field` | One row per verified field, with a **hash** of the value that was checked |
| `sdv_plan` | Monitor overrides of a field's SDV requirement |
| `sdv_query` / `sdv_query_msg` | Monitor queries and their message threads |
| `sdv_audit` | Every SDV action, with old and new status |

The tables are never dropped, so the SDV history survives disabling and re-enabling the module.

## 2. An admin configures the project

The settings are defined in [config.json](config.json). The main ones are:

- **Monitor roles**, **data entry roles** and **data manager roles**. The first two are required.
- The **flag regex** (default `@SDV`), which marks a field as requiring SDV, and the **ignore tag**, which excludes a
  field.
- The **re-verification trigger**, which decides when a completed form drops back to "Re-verification required".

[Settings.php](classes/Settings.php) loads all the settings in one call. When the config is saved, `validateSettings`
([line 296](SourceDataVerificationModule.php#L296)) rejects a bad regex, a malformed ignore tag, or a role assigned to
two SDV roles. Each setting that changes is then written to the module log by `auditConfigurationChange`, which
compares the new values with a stored snapshot.

## 3. Who the user is (`Roles`)

On every request, [Roles.php](classes/Roles.php) matches the user's REDCap role against the configured role lists. That
gives `isMonitor`, `isDataEntry` or `isDataManager`, plus the user's DAG (`groupId`) and form rights. The permission
checks all live here:

- `canViewForm`: the user has any rights to the form.
- `canEditForm`: the user has form rights 1 (view & edit) or 3 (edit survey responses).
- `canRespond`: the user is data entry, or a data manager when the setting allows it, **and** can edit the form.
- `canEditPlan` and `canSync`: the user is a monitor or a super user.

`redcap_module_link_check_display` ([line 70](SourceDataVerificationModule.php#L70)) uses these to show the
*SDV Dashboard* and *SDV Plan* links only to users with an SDV role.

## 4. Which fields need SDV (`SdvPlan`)

[SdvPlan.php](classes/SdvPlan.php) answers "what is this field's requirement?":

1. `sdvFieldNames()` lists the form's fields, minus the record ID, descriptive fields and `<form>_complete`.
2. `forInstrument()` works out a **default** from each field's action tags:
   - it has the ignore tag → `excluded`
   - it matches the flag regex → `required`
   - otherwise → `optional`
3. A row in `sdv_plan` **overrides** the default. Monitors set these from the SDV Plan page or from the badge on the
   form.

The result is cached per instrument for the rest of the request.

Plan changes go through `SdvService::applyPlan()`, for both a single field (`setPlan`) and a whole form
(`setPlanForInstrument`). In the same transaction as the override, `reverifyCompleteForms()` sets every `complete` form
in which a **newly required** field is not verified to `reverify`, using
`Repository::lockCompleteFormsMissingVerification()` to find and lock those form rows. So a form marked SDV complete
always has every required field verified. Changes that make a field optional or excluded never change a form's status.

## 5. A user opens a data entry form

REDCap calls `redcap_data_entry_form` ([line 96](SourceDataVerificationModule.php#L96)), which calls `renderForm`:

1. It stops early if the module isn't configured (super users get a warning), the user has no SDV role, or the form
   has no SDV fields.
2. It builds a `FormContext` ([FormContext.php](classes/FormContext.php)). `fromInput()` checks that the event exists,
   the form is designated for that event, and an instance above 1 is only used on a repeating form.
3. It calls `$service->checkForm()` ([SdvService.php:89](classes/SdvService.php#L89)), which does three things:
   - **`load()`** reads everything about this form instance: the `sdv_form` row, the `sdv_field` rows, the queries,
     the current REDCap values, and a hash of each value.
   - **Deleted-data check.** If SDV state exists but the data is gone, or the log shows a deletion after the state was
     created, `clearStaleState()` wipes the state and closes the active queries.
   - **`detectChanges()`**, explained in [section 8](#8-detecting-data-changes-after-verification).
4. If the form needs SDV but has no `sdv_form` row yet, it creates one as `pending`.
5. `buildState()` ([line 774](classes/SdvService.php#L774)) turns all of this into a JSON payload: each field's
   requirement, verified/changed status, active query and counts, plus the user's permissions.
6. It writes out the CSS, the JS and an empty `<div id="cctc-sdv-panel">`, then calls
   `CctcSdvForm.init(jsModule, state, ui)`.

On the browser side, [js/sdv-form.js](js/sdv-form.js) draws:

- a **badge** under each field label (*SDV required*, *verified*, *changed*, *query open*) with Verify, Unverify and
  Raise query buttons;
- the **panel**, with the form status and the buttons *Verify all required*, *Mark SDV complete*,
  *Mark not required* and *Reopen*;
- for monitors and data managers, the form's Save buttons hidden and its inputs made read-only, unless the settings
  turn this off.

## 6. A monitor clicks a button (the AJAX route)

Each button calls `jsModule.ajax(action, payload)`. REDCap only allows the actions listed under `auth-ajax-actions` in
config.json, and routes them to `redcap_module_ajax` ([line 178](SourceDataVerificationModule.php#L178)):

1. **Gatekeeping:** the module must be configured, the user must have an SDV role, and actions in `MONITOR_ACTIONS`
   need a monitor.
2. **Plan actions** (`setPlan` and `setPlanForm`) are handled first, because they apply to the whole project rather
   than one form.
3. **Finding the form instance:** query actions look the form up from the `queryId`. The others build it from
   `payload.ctx`.
4. **`assertAccess`** checks the user's form rights, and that a user in a DAG is opening a record in their own DAG.
5. A `switch` passes the action to the matching `SdvService` method.
6. The response is the fresh `state`, which the JS re-renders.

Expected errors (`SdvException`, bad input) come back as `{ok:false, error}` and are shown to the user. Anything
unexpected is logged, and the user gets a generic message.

## 7. How an action runs safely

Every state-changing method follows the same pattern. Take `verifyField`
([line 358](classes/SdvService.php#L358)):

```
locked($ctx, fn)                      ← SdvService.php line 289
 └ repo->transaction(...)             ← START TRANSACTION, retried on deadlock
    ├ ensureFormRow                   ← make sure a row exists to lock
    ├ load($ctx, forUpdate: true)     ← SELECT ... FOR UPDATE locks the form row first, then reads
    ├ "Save the form first" check
    └ fn($loaded):
        requireOpenForm               ← not complete or not_required
        requireSdvField               ← not excluded, and required if the project is set to only-required
        no open query on the field
        verifyOne → sdv_field row with the value's hash + sdv_audit row
        refreshOpenStatus             ← pending ↔ in_progress
 COMMIT
logEvent('SDV: field verified')       ← REDCap project Logging
```

Two ideas run through the whole module:

- **Hashes instead of copies.** [ValueHasher](classes/ValueHasher.php) stores a SHA-256 of the verified value, sorted
  so the ticked options of a checkbox field always hash the same way. The module can then tell whether a value has
  changed without copying participant data into its own tables.
- **Lock first, then read.** `load(..., true)` locks the form row before it reads anything. Two monitors acting on the
  same form at the same time are therefore handled one after the other, and the second one sees the first one's
  changes.

`Repository::transaction()` ([Repository.php:68](classes/Repository.php#L68)) retries the whole transaction up to 3
times on a deadlock or lock wait timeout, so the function passed to it must only touch the database. All statements
go to the primary database, never a read replica, because a replica can't lock rows and may lag behind.

The other actions follow the same template with different rules:

- **`completeForm`:** every required field must be verified with its current value hash, and there must be no open
  queries. It stores a **snapshot** of every field's hash in `sdv_form.snapshot`.
- **`markNotRequired`:** needs a reason, and closes any open queries.
- **`reopenForm`:** only works on a form that is complete or not required. It needs a reason and clears the snapshot.

## 8. Detecting data changes after verification

`detectChanges` ([line 180](classes/SdvService.php#L180)) runs when the form is opened, when it is saved
(`redcap_save_record` → `onSave`), and during the dashboard sync. It:

1. Finds **verified fields whose value hash has changed**. Each one becomes `changed` and loses its tick.
2. On a **complete** form, also compares every field with the completion snapshot, which catches changes to fields
   that were never verified.
3. Applies the configured **trigger** (`triggerFires`, [line 256](classes/SdvService.php#L256)) to decide whether the
   form drops from `complete` to `reverify`. The options are `verified`, `always`, `required`, `previously_queried`,
   `previously_queried_or_required` and `never`.

It first checks without a lock and only opens a transaction if something has changed. Inside the transaction it works
the changes out again under the lock before writing anything.

The save hook catches every exception, so the module can never block a user's save.

## 9. Monitor queries

Each query is a small state machine:

```
raiseQuery (monitor) ──► open ──respondQuery (site)──► responded
                          ▲                               │
                          └────── reraiseQuery ───────────┤
                                                          ├─ acceptQuery (optionally verifies the field) ─► closed
                         closeQuery from open or responded ──────────────────────────────────────────────► closed
```

- A field can have only one active query at a time. Raising a query removes the field's verification.
- `queryAction` ([line 572](classes/SdvService.php#L572)) locks the query row and checks its current status. If
  someone else has already acted on it, the user is told to refresh rather than overwrite that change.
- Responses use a fixed list of codes (`RESPONSE_CODES`) plus an optional comment. Each step adds a row to
  `sdv_query_msg`, so the full thread is kept.

## 10. Dashboard, plan and export pages

All three pages start with [page_init.php](page_init.php), which calls `services()` and creates `Roles` and
`Dashboard`.

- **[dashboard.php](dashboard.php)** has three tabs: Forms, Queries and Summary, all filtered, paged and limited to the
  user's DAG. When a monitor opens it, `syncIfDue` runs a **sync** if the last one was more than 5 minutes ago.
  **Sync now** forces one. `SdvService::sync()` ([line 651](classes/SdvService.php#L651)):
  1. removes untouched pending rows for forms that no longer need SDV;
  2. clears the SDV state of deleted data;
  3. adds `pending` rows for saved forms that need SDV but have no row yet (`backfillForms`);
  4. finds verified fields and complete forms whose values have changed, which catches imports and API writes, and
     runs `detectChanges` on each.

  If one form fails, the sync records the error and carries on with the rest.
- **[plan.php](plan.php)** lets monitors set each field's requirement, or a whole form at once with `setPlanForm`, and
  shows recent plan changes.
- **[export.php](export.php)** downloads the Forms or Queries list as a CSV. Text that starts with `= + - @` is
  prefixed so spreadsheets don't run it as a formula, and each export is logged.

## 11. Logging

Every change is recorded in two places:

- **`sdv_audit`**: a detailed row with old and new status. It feeds the **History** view on the form (`history()`),
  merged with the query messages.
- **REDCap project Logging** (`logEvent`, [line 914](classes/SdvService.php#L914)): one readable entry per action, such
  as `SDV: field verified` or `SDV: form marked SDV complete`.

The audit row is written inside the transaction. The REDCap log entry is written only after the commit succeeds.

---

## Summary

Action tags plus monitor overrides decide which fields need SDV. Monitors verify fields against a hash of the current
value and resolve queries with sites. Every change is made under a lock, audited and logged. Data that changes later
is caught on form open, on save, or by the sync, and the form is sent back for re-verification.
