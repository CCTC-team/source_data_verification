# `source_data_verification` External Module Implementation Plan

## Context

`monitoring_qr_v1.1.0` is slow because of how it is built. It stores monitoring status in a `_monstat` field on every
form and keeps queries as JSON in the Data Resolution Workflow. Its log page rebuilds everything in temporary tables on
each load, and enabling it patches REDCap core files.

`source_data_verification_v1.0.0` is a new, separate module with its own indexed `sdv_*` tables. It keeps the
monitoring workflow and adds:

- an **SDV plan**: which fields need SDV, set by monitors without editing the data dictionary;
- **per-field and per-form SDV**.

Monitoring QR stays as it is, and both modules can run side by side. There is no migration in v1.

The repo is `redcap_source/modules/source_data_verification_v1.0.0/`, remote `CCTC-team/source_data_verification`,
branch `main`. The modules folder is bind-mounted into the running `CCTC_REDCap_Docker` container, so edits are live
straight away.

This plan is kept in the module repo, under `AIPlans/`. File paths in it are relative to the `CCTC_REDCap_Docker`
folder. Paths under `redcap_source/modules/source_data_verification_v1.0.0/` are inside this repo; paths such as
`redcap_cypress/...` are in the parent repo.

**Status (2026-10-15):** the module is built, all code-review findings are fixed, and everything is pushed. Still to
do:

- whole-form plan changes skip ignore-tagged fields (Phase 9);
- the E.129 Cypress features have never been run (Phase 10);
- excluded fields should never send a complete form back for re-verification (Phase 11, **needs the user's go-ahead**);
- value hashes can be reversed for fields with few possible values (Phase 12, **needs the user's decision**);
- README notes (see *Documentation*).

The original design notes are in `~/.claude/plans/parsed-chasing-nygaard.md`.

---

## Key References

- **`redcap_source/modules/source_data_verification_v1.0.0/CODE_WALKTHROUGH.md`** — map of the code: the classes,
  request flow, locking, change detection, sync and the pages. Read it first.
- **`redcap_source/modules/source_data_verification_v1.0.0/README.md`** — user-facing behaviour: roles, plan, form
  status rules, triggers, deleted data, dashboard, logging.
- **`redcap_source/modules/source_data_verification_v1.0.0/tests/http/README.md`** — the HTTP end-to-end suites.
  Each script, how to set up a project, and the typical run.
- **`redcap_cypress/cypress_runner/README.md`**, section *Run one External Module's tests (`EM_MODULE`)* — how module
  `automated_tests/` are run headless against the AIO container (Phase 10).
- **`AIPlans/InProgress/redcap_save_record_mon_qr old-new values plan.md`**, Phase 4 — a previous module Cypress run
  (`EM_MODULE=monitoring_qr_v1.0.1 docker compose run --rm cypress`), as a worked example.
- **`redcap_source/modules/monitoring_qr_v1.1.0/automated_tests/`** — the E.128 features and the step phrasing the
  E.129 features copied, plus the shared `fixtures/cdisc_files/ProjectTypes` used by `setup_project.py`.

---

## Key Design Decisions

1. **Own tables, not REDCap core storage.** State lives in `sdv_form`, `sdv_field`, `sdv_plan`, `sdv_query`,
   `sdv_query_msg` and `sdv_audit`. All are indexed, created on system enable, and never dropped.
   - The table names have no `cctc_` prefix, at the user's request.
   - The alternative was to copy Monitoring QR (status in a `_monstat` field, queries as JSON in the DRW). It was
     rejected because that design is what made Monitoring QR slow.
2. **Monitors own the SDV plan.** Monitors set which fields need SDV, on the SDV Plan page or from the form. They tick
   fields verified and mark forms complete. Data managers can only view.
3. **Queries are the module's own per-field threads**, kept apart from the DRW. REDCap's DRW stays available as normal.
4. **Store hashes, not values.** A verification stores a SHA-256 of the value that was checked, and a completed form
   stores a snapshot of hashes. No participant data is copied into the module's tables.
5. **Every state change takes the form row lock first.** It then runs as one transaction, and the whole transaction is
   retried on deadlock. All module SQL goes to the primary database, never a read replica.
   - The framework's `query()` is not used here, because it retries a single statement after MySQL has already
     rolled back the rest of the transaction.
6. **Two logs, deliberately.** Each serves a different reader:
   - **REDCap Logging** is the official audit trail that people and auditors read. The user's rule is that every
     state-changing action goes there.
   - **`sdv_audit`** is structured and indexed, and is written in the same transaction as the change. The module
     reads it for the form's SDV history, recent plan changes and sync timing.
   - Rebuilding history from REDCap's log text was rejected: it is slow, it breaks if the wording changes, and the
     log has no field or instance columns.
7. **Deleted data clears SDV state, it doesn't archive it.** The form and field rows are removed and active queries
   are closed. Query threads and the audit trail are kept.
   - Deletions are found from REDCap's log, because REDCap has no hook for deleting a record.
   - Archive tables were considered and not built.
8. **Sync is monitor-only.** It runs when a monitor opens the dashboard (at most every 5 minutes) or clicks **Sync
   now**.
   - Saves already update the dashboard through `redcap_save_record`.
   - The user doesn't use data import or record deletion, so a scheduled cron sync was offered and declined.
9. **Whole-form plan changes skip ignore-tagged fields.** *Required* or *Optional* for a whole form leaves fields with
   the ignore tag (e.g. `@NOTMONITORED`) excluded. A monitor can still change one of those fields on its own.
   *Default* still resets every field on the form. This is the user's decision of 2026-10-15; see Phase 9.
10. **A field that becomes required sends unverified complete forms back** (`ef2bc15`). When a plan change makes a
    field required, every form marked SDV complete in which it is not verified moves to *Re-verification required*,
    in the same transaction, with the reason "SDV plan changed: X now requires SDV". This keeps the rule that a
    complete form has every required field verified. This is the user's decision of 2026-10-14.
    - Rejected: **forward-only** (completed forms keep their status). It left forms showing *SDV complete* next to
      "1 of 4 required verified".
    - Rejected: **asking the monitor on each change**, with a count of affected forms. It was more UI for the same
      result.
    - Rejected: **a dashboard indicator** instead of a status change.
    - Accepted cost: setting the field back to optional doesn't restore *complete*. The confirm prompt warns about
      this, and the result message gives the number of forms sent back.
11. **The completion snapshot stores every field's hash**, including optional and excluded fields. The trigger and
    the plan can both change after a form is completed. Without a baseline for every field, switching the trigger
    from `verified` to `always` would make every non-blank unverified field look changed (a missing entry is
    compared as blank), and completed forms across the project would drop to re-verification at once. Phase 11
    filters fields when **comparing**, not when storing.

---

## Phase 1: Schema, settings, roles, plan, repository  ✅ DONE (`be7f3b6`)

- [x] **1a.** `sql/schema.sql`, `classes/Settings.php`, `classes/Roles.php`, `classes/SdvPlan.php`,
  `classes/Repository.php`, plus the system-enable hook that creates the tables.

## Phase 2: Service, hashing, AJAX actions  ✅ DONE (`be7f3b6`)

- [x] **2a.** `classes/SdvService.php`, `classes/ValueHasher.php`, and the `redcap_module_ajax` actions. Every action
  writes `sdv_audit` and calls `REDCap::logEvent`.

## Phase 3: Data entry form UI and change detection  ✅ DONE (`be7f3b6`)

- [x] **3a.** `js/sdv-form.js`, inline badges and the SDV panel, change detection in the save hook and on form open,
  and the read-only and hide-save options.

## Phase 4: SDV Plan page  ✅ DONE (`be7f3b6`)

- [x] **4a.** `plan.php`: per-field requirement dropdowns for monitors, read-only for everyone else.

## Phase 5: Dashboard, sync, CSV export  ✅ DONE (`be7f3b6`)

- [x] **5a.** `dashboard.php` (Forms, Queries and Summary tabs), `classes/Dashboard.php`, sync, and `export.php`.

## Phase 6: README, Cypress features, HTTP tests  ✅ DONE (`be7f3b6`)

- [x] **6a.** `README.md`, the `automated_tests/E.129.*` features (written, **not yet run**; see Phase 10), and the
  `tests/http/` suites.

---

## Phase 7: Code-review fixes  ✅ DONE

- [x] **7a. HIGH** (`8c591a5`) — locking order, deadlock retry and read replica:
  - `Repository::q` calls `db_query` with forceUsePrimary;
  - `Repository::transaction` retries the whole closure;
  - `ensureFormRow` uses INSERT … ON DUPLICATE KEY UPDATE.
  - Covered by `test_concurrency.py`, which reproduced duplicate queries on the old code.
- [x] **7b.** (`8c591a5`) — tables renamed from `cctc_sdv_*` to `sdv_*`. The local DB was renamed with RENAME TABLE.
- [x] **7c. MED** (`f4f7b99`) — four fixes, covered by `test_integrity.py`:
  - sync re-checks completion snapshots;
  - SDV state of deleted or re-created data is cleared;
  - sync is monitor-only, and one failing form doesn't stop the rest;
  - responding to a query needs edit rights to the form.
- [x] **7d. LOW** (`f5f2af7`) — four fixes, covered by `test_access.py`, which fails 9/12 on the old code:
  - the dashboard and export follow form rights;
  - `setPlan` checks access before saving;
  - the CSV guard covers tab and carriage return;
  - output is scrubbed of invalid UTF-8.

## Phase 8: Features added during review  ✅ DONE

- [x] **8a.** (`f4f7b99`) — **Set all fields on this form to** on the SDV Plan page (`setPlanForm`).
  Covered by `test_plan_form.py`.
- [x] **8b.** (`ef2bc15`, user) — complete forms are sent back for re-verification when an unverified field becomes
  required (`reverifyCompleteForms`).
- [x] **8c.** (`c6e496b`) — on read-only rows, REDCap's History and Data Resolution icons and the uploaded-file link
  stay clickable. Checked in headless Chrome against the old and new CSS.
- [x] **8d.** (`79b1681`, user) — `CODE_WALKTHROUGH.md`.

---

## Phase 9: Whole-form plan changes skip ignore-tagged fields

Test-first, as paired sub-items. The behaviour lives in `SdvService::setPlanForInstrument`.

- [ ] **9a. Test — MODIFY:** `redcap_source/modules/source_data_verification_v1.0.0/tests/http/test_plan_form.py`
  - Change the *Required* checks:
    - every field **except** `data_types_crfver` (tagged `@NOTMONITORED`) becomes required;
    - `data_types_crfver` stays `excluded`, with no `sdv_plan` row;
    - the response, the plan page and the data entry form's required count all agree.
  - Add the same check for *Optional*.
  - Add a check that *Default* still removes an override set on `data_types_crfver` with a single-field `setPlan`.
  - Run the test and watch the new checks fail against the current code, then pause for the user before 9b.
- [ ] **9b. Implementation — MODIFY:** `redcap_source/modules/source_data_verification_v1.0.0/classes/SdvService.php`
  - In `setPlanForInstrument`, when `$requirement` is `required` or `optional`, leave out fields whose `default` is
    `SdvPlan::EXCLUDED`. The ignore tag is the only way a default becomes excluded (see `SdvPlan::forInstrument`).
  - *Default* and *Excluded* still apply to every field.
  - Return the skipped fields too, so the response tells the page what was left alone. `applyPlan` already returns
    an entry for every field it was given.
  - Note: the Logging entry lists only the fields that changed, so the skipped ones don't appear there. That is
    intended.
  - Note: pass only the non-skipped fields to `applyPlan`. `reverifyCompleteForms` (Decision 10) then never sends a
    form back because of a skipped ignore-tagged field. The existing check "whole form -> required sends the complete
    form to reverify" in `test_plan_form.py` must still pass.
- [ ] **9c. MODIFY:** `redcap_source/modules/source_data_verification_v1.0.0/plan.php`
  - The confirm text counts only the fields that will change. Example: "Set the SDV requirement of 9 field(s) on
    data_types to required (1 field with @NOTMONITORED stays excluded) on ALL records?"
  - The success message names the skipped fields.
  - The fields' `default` values are already in the page: each select marks its default option.
- [ ] **9d.** Rerun `test_plan_form.py` (all pass), then `test_flow.py`, on a fresh project.

---

## Phase 10: Run the E.129 Cypress features

These have never been run. **Ask the user before 10c.**

- **The runner resets the database.** It runs REDCap's install SQL and the CCTC test users, which wipes every project
  in the container. That includes the HTTP-test projects (23–47) and any projects the user's other sessions are
  using. After the reset, the shared `tests/http/pid_*.txt` files point at projects that no longer exist. Tell the
  user, and recreate them with `setup_project.py` if they want.
- **There is a version mismatch.** The live container reports REDCap 14.7.0, but `redcap_cypress/cypress.env.json`
  has `"redcap_version": "15.5.36"`, and both `redcap_v14.7.0/` and `redcap_v15.5.36/` exist in the container.

Integration tests against the running app come after the code, as an accepted exception to test-first.

- [ ] **10a. Decide the target version** with the user. Read the cypress_runner README and
  `redcap_docker_aio/.env` first.
  - Option 1: run against 15.5.36, the AIO's declared version.
  - Option 2: point the runner at 14.7.0.
  - Record the choice and why in this plan.
- [ ] **10b. Check the features still match the module.** The E.129 features were written before Phases 7 to 9, so
  read them against the current behaviour:
  - sync is monitor-only;
  - responding to a query needs edit rights;
  - the whole-form plan control exists;
  - the tables are named `sdv_*`;
  - a field that becomes required sends unverified complete forms back for re-verification (Decision 10). Look for
    any scenario that completes a form and then makes a field required, and expects the form to stay complete. For
    example, `E.129.300` changes the plan and then completes forms.
  - Fix any step that now asserts old behaviour.
  - Files: `automated_tests/E.129.100 - SDV module configuration.feature`,
    `E.129.200 - Field and form SDV with monitor queries.feature`,
    `E.129.300 - SDV dashboard plan and DAGs.feature`, and `automated_tests/step_definitions/external_module.js`.
- [ ] **10c. Run** — **only after the user confirms the database reset.** With the runner image built
  (`docker compose build --ssh default` needs the ssh-agent key for `rctf` / `redcap_rsvc`):

  ```bash
  cd redcap_cypress/cypress_runner
  EM_MODULE=source_data_verification_v1.0.0 docker compose run --rm cypress
  ```

  The report lands in `redcap_cypress/cypress_runner/results/`.
- [ ] **10d. Fix failures.**
  - Fix step-phrasing problems in the features or `external_module.js`.
  - Fix real defects in the module, test-first: add an HTTP test that reproduces the defect, then fix it.
  - Record each fix as a deviation note under this item.
- [ ] **10e.** Rerun until everything passes. Record the pass count and attempts here, as Phase 4 of the
  `monitoring_qr` plan did.

Review: subagent — check that the E.129 features cover the URS statements in `automated_tests/urs/` and the
behaviour added in Phases 7 to 9 (deleted data, form rights, monitor-only sync, whole-form plan, complete forms sent
back when a field becomes required). List any gaps as new scenarios to write.

---

## Phase 11: Excluded fields never trigger re-verification

**Ask the user before starting.** Proposed on 2026-10-14 and not yet approved.

Today, under the `always` trigger (and the other triggers that look past verified fields), a change to an
**excluded** field on a complete form sends the form back for re-verification. The README describes the ignore tag as
excluding a field "from SDV entirely", so that is surprising. The fix keeps the snapshot as it is (Decision 11) and
skips fields whose **current** requirement is `excluded` when comparing.

Test-first, as paired sub-items.

- [ ] **11a. Test — MODIFY:** `redcap_source/modules/source_data_verification_v1.0.0/tests/http/test_triggers.py`
  (or `test_integrity.py`, which already imports data and sets the trigger with `configure()`)
  - Under `always`, complete a form, then change its `@NOTMONITORED` field by API import:
    - opening the form leaves it `complete`, with no *Data changed after SDV* notice;
    - a monitor's **Sync now** leaves it `complete`, and the sync result counts no changed forms.
  - Control: under `always`, a change to an **optional** field still sends the form back. This is today's behaviour
    and must not change.
  - Run it and watch the excluded-field checks fail against the current code. Pause for the user before 11b.
- [ ] **11b. Implementation — MODIFY:** `redcap_source/modules/source_data_verification_v1.0.0/classes/SdvService.php`
  - `pendingChanges`: in the snapshot comparison loop, skip fields where
    `$loaded['plan'][$field]['requirement'] === SdvPlan::EXCLUDED`.
  - `completeFormsWithChangedSnapshot` (sync): build `$fields` from `sdvFieldNames()` minus the fields that are
    currently excluded, using `$this->plan->forInstrument($instrument)`.
  - Leave `completeForm`'s snapshot as it is: every field, excluded ones included.
  - Note: a field that was verified and later excluded still loses its tick if its value changes, because
    `changedVerified` is not filtered. That is harmless, and the rule "a verified value that changes loses its tick"
    stays simple.
- [ ] **11c.** Rerun `test_triggers.py`, `test_integrity.py` and `test_flow.py` on a fresh project.

Review: subagent — check both comparison paths (form open/save, and sync) apply the same filter, and that no
trigger mode now misses a change to an optional or required field.

---

## Phase 12: Keyed hashes for verified values

**Needs the user's decision before any work.** Raised on 2026-10-14 and not agreed.

`sdv_field.value_hash` and `sdv_form.snapshot` are plain SHA-256 hashes of the values (Decision 4). For a field with
only a few possible values (yes/no, a 1–5 scale, coded answers), anyone who can read these tables can hash each
possible value and match it. The tables should therefore be treated as being as sensitive as the data. The fix is a
keyed hash: HMAC-SHA-256 with a secret per project.

- [ ] **12a. Decide with the user:** do this at all, or document that the `sdv_*` tables are as sensitive as the
  data? Record the answer and why in Key Design Decisions.
- [ ] **12b. Design**, only if 12a says go ahead. Write it up here for the user to approve before 12c:
  - **Where the secret lives.** Not in project settings, which project designers can export. The options are a
    module-owned table, or a system setting readable only by admins. A lost secret makes every stored hash
    unusable, so it has to be backed up with the database.
  - **Migration.** Old hashes can't be converted. Proposed: add a `hash_version` column to `sdv_field` and a version
    key in the snapshot JSON. When a form is loaded under the lock, compare old-version hashes with plain SHA-256;
    if they match, rewrite them with the HMAC. Rows nobody opens are migrated by the sync.
  - **Mixed versions** must never be compared directly. Every comparison goes through `ValueHasher`, so that is
    where both versions are handled.
- [ ] **12c. Implement** test-first, following the design. Touches `ValueHasher.php`, `SdvService.php` (load,
  verify, complete, change detection, sync), `Repository.php` and `sql/schema.sql`. Add a schema change that runs on
  module enable or upgrade.

Review: /cctc-gcp-review — the change rewrites verification evidence in place, so check the audit trail still shows
what was verified, when and by whom.

---

## Documentation

- [ ] **MODIFY:** `redcap_source/modules/source_data_verification_v1.0.0/README.md`
  - **Logging and audit trail:** add a sentence saying why there are two logs (Decision 6). REDCap Logging is the
    official trail. `sdv_audit` is structured, transactional data the module reads for history, plan changes and sync
    timing. This stops a reviewer taking it for duplication.
  - **SDV plan:** say that whole-form *Required* and *Optional* leave ignore-tagged fields excluded (Phase 9).
- [ ] **MODIFY:** `redcap_source/modules/source_data_verification_v1.0.0/CODE_WALKTHROUGH.md`
  - Plan section: describe the skip in `setPlanForInstrument`.
  - Logging section: describe the two-log split.
- [ ] **MODIFY:** `redcap_source/modules/source_data_verification_v1.0.0/tests/http/README.md`
  - Update the description of `test_plan_form.py` and its check count.
  - If Phase 11 goes ahead: update the description and check count of the suite that gets the new checks.
- [ ] **If Phase 11 goes ahead — MODIFY:** `README.md` (*Data changes after verification*) and `CODE_WALKTHROUGH.md`
  (section 8)
  - Say that changes to excluded fields never send a complete form back, under any trigger.
- [ ] **If Phase 12 goes ahead — MODIFY:** `README.md`, `CODE_WALKTHROUGH.md` (section 7, *Hashes instead of
  copies*) and this plan's Decision 4
  - Describe the keyed hash, where the secret lives, and that losing it invalidates stored verifications.
- [ ] **MODIFY:** `redcap_source/modules/source_data_verification_v1.0.0/automated_tests/urs/User Requirement Specification.spec`
  - Only needed if Phase 10's review adds scenarios for requirements the spec doesn't yet state.

---

## Verification

- [ ] `php -l` is clean for every changed PHP file. Run it in the container:
  `docker exec CCTC_REDCap_Docker php -l /var/www/html/modules/source_data_verification_v1.0.0/<file>`.
- [ ] Every HTTP suite passes on a **fresh project of your own**. Run
  `python3 setup_project.py RepeatingEvents_DoubleArm_withDAGs "<title>"`, then:
  - `test_flow.py`
  - `test_triggers.py`
  - `test_integrity.py`
  - `test_plan_form.py`
  - `test_access.py`
  - `test_concurrency.py`
- [ ] `smoke.py` passes on at least two other fresh project types (e.g. `NonLongitudinal_RepeatingInstruments_withDAGs`
  and `NonRepeating_SingleArm_noDAGs`).
- [ ] The E.129 Cypress features pass via `EM_MODULE=source_data_verification_v1.0.0` (Phase 10).
- [ ] If Phase 11 went ahead: under the `always` trigger, an import that changes an `@NOTMONITORED` field on a
  complete form leaves it complete, both on form open and after **Sync now**.
- [ ] Manual check on the SDV Plan page as Test_User4:
  - set a form with an `@NOTMONITORED` field to *Required*;
  - the confirm text and the result leave that field excluded;
  - *Default* restores every field.
- [ ] Committed and pushed to `CCTC-team/source_data_verification` `main`.

**Working alongside other sessions.** The user works in this repo from other sessions at the same time.

- Don't use `git stash`. To test old code, revert only your own hunks: `git diff > p; git apply -R p`, then
  `git apply p` to restore.
- Use a fresh HTTP-test project of your own, and don't overwrite the shared `tests/http/pid_*.txt`.
- Pull before you commit.
