# HTTP end-to-end tests (developer use)

These Python scripts drive the module through REDCap's web interface and API as real users (monitor, data entry,
data manager, DAG-restricted data entry), against the local `CCTC_REDCap_Docker` container. Unlike the Cypress
features in `automated_tests/`, they do **not** reset the database: each run creates its own new project.

Requirements: Python 3.10+ (standard library only), the container running on https://localhost:8443 with REDCap
14.7.0, and the CCTC test users (password `Testing123`). The project-type fixtures come from the sibling
`monitoring_qr_v1.1.0` module. Run everything from this directory.

| Script | Purpose |
|---|---|
| `rc.py` | Minimal REDCap client: login, data entry pages, module pages, module AJAX (JSMO protocol), API, SQL via `docker exec` |
| `setup_project.py <fixture> <title> [trigger]` | Enables the module, creates a project from `ProjectTypes/<fixture>.xml`, imports data, assigns roles/DAG, configures the module. Writes `pid_<fixture>.txt` |
| `test_flow.py <pid>` | Full workflow: plan, verify, queries, complete, save-hook and import change detection, DAGs, dashboard, export, Logging (65 checks) |
| `test_triggers.py <pid>` | Each re-verification trigger mode (10 checks) |
| `test_concurrency.py <pid> <record> <event_id> [instrument] [instance] [rounds]` | Parallel monitor sessions on one pending form instance: concurrent query raises create one query; a form is never completed while a query raised in parallel is open |
| `smoke.py <pid>` | Verifies and completes every form instance needing SDV in any project type |
| `perf_seed.py <pid> <n>` + `perf_seed_activity.sql` | Seeds a large project and equivalent SDV/DRW activity |
| `bench.py` | Times the module against Monitoring QR's `GetMonitorQueries` (installed temporarily, then dropped) on the project in `pid_perf.txt` |

Typical run:

```sh
python3 setup_project.py RepeatingEvents_DoubleArm_withDAGs "SDV test"
python3 test_flow.py $(cat pid_RepeatingEvents_DoubleArm_withDAGs.txt)
python3 test_triggers.py $(cat pid_RepeatingEvents_DoubleArm_withDAGs.txt)
```

The scripts briefly set an API token for `test_admin` (and clear it again) and use the database root password from
the local Docker set-up. They are for the local development container only.
