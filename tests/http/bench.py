"""Benchmarks old Monitoring QR GetMonitorQueries vs the new SDV module on the perf project."""
import statistics, subprocess, sys, time
from rc import RC, sql

PID = int(open('pid_perf.txt').read())
RUNS = 5
E1 = int(sql(f"select e.event_id from redcap_events_metadata e join redcap_events_arms a on a.arm_id=e.arm_id "
             f"where a.project_id={PID} and a.arm_num=1 and e.descrip='Event 1'")[0][0])


def timed(fn, runs=RUNS):
    times = []
    for _ in range(runs):
        t = time.perf_counter(); fn(); times.append(time.perf_counter() - t)
    return statistics.median(times)


def mysql_call(q):
    out = subprocess.run(['docker', 'exec', 'CCTC_REDCap_Docker', 'mysql', '-uroot', '-proot', 'redcap', '-N', '-e', q],
                         capture_output=True, text=True)
    if out.returncode: raise RuntimeError(out.stderr)


rows = []
# ---- old module: the stored procedure behind its log page --------------------------------------------
import os
proc = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '../../../monitoring_qr_v1.1.0/sql-setup/0010__create_GetMonitorQueries.sql')).read()
subprocess.run(['docker', 'exec', '-i', 'CCTC_REDCap_Docker', 'mysql', '-uroot', '-proot', 'redcap', '--delimiter=$$'], input=proc, text=True, check=True)
try:
    base = "call GetMonitorQueries('_monstat', 0, 25, {pid}, 'desc', null, null, null, null, null, null, null, null, null, null, null, null, 'NONE', 2, 1, null, {dag})"
    rows.append(('Old: log page query (all items, page 1)', timed(lambda: mysql_call(base.format(pid=PID, dag='null')), 3)))
    rows.append(('Old: log page query as DAG user', timed(lambda: mysql_call(base.format(pid=PID, dag="'test_de1'")), 3)))
    open_only = "call GetMonitorQueries('_monstat', 0, 25, {pid}, 'desc', null, null, null, 'OPEN', null, null, null, null, null, null, null, null, 'NONE', 2, 1, null, null)"
    rows.append(('Old: log page, OPEN queries only', timed(lambda: mysql_call(open_only.format(pid=PID)), 3)))
finally:
    mysql_call('drop procedure if exists GetMonitorQueries')

# ---- new module: full HTTP page loads (includes REDCap page chrome) ---------------------------------
mon, de_dag = RC('test_user4'), RC('test_de1')
mon.module_page(PID, 'dashboard')  # first load runs the initial sync
rows.append(('New: dashboard forms tab, page 1', timed(lambda: mon.module_page(PID, 'dashboard', {'tab': 'forms'}))))
rows.append(('New: dashboard forms tab, last page', timed(lambda: mon.module_page(PID, 'dashboard', {'tab': 'forms', 'pg': 1599}))))
rows.append(('New: dashboard filtered (complete, form, site)', timed(lambda: mon.module_page(PID, 'dashboard', {'tab': 'forms', 'status': 'complete', 'form': 'text_validation'}))))
rows.append(('New: dashboard forms with active queries', timed(lambda: mon.module_page(PID, 'dashboard', {'tab': 'forms', 'openq': 'yes'}))))
rows.append(('New: dashboard queries tab', timed(lambda: mon.module_page(PID, 'dashboard', {'tab': 'queries'}))))
rows.append(('New: dashboard summary tab', timed(lambda: mon.module_page(PID, 'dashboard', {'tab': 'summary'}))))
rows.append(('New: dashboard as DAG user', timed(lambda: de_dag.module_page(PID, 'dashboard', {'tab': 'forms'}))))
rows.append(('New: forced sync (40k forms, 40k verified fields re-hashed)', timed(lambda: mon.module_page(PID, 'dashboard', post={'sync': '1'}), 3)))
rows.append(('New: data entry form page (monitor, with SDV panel)', timed(lambda: mon.form_page(PID, 'P00010', E1, 'text_validation', 1))))
mon.form_page(PID, 'P00010', E1, 'text_validation', 1)
ctx = {'record': 'P00010', 'eventId': E1, 'instrument': 'text_validation', 'instance': 1}
rows.append(('New: SDV state refresh (ajax)', timed(lambda: mon.ajax('state', {'ctx': ctx}))))
rows.append(('New: verify field (ajax round trip)', timed(lambda: (mon.ajax('reopenForm', {'ctx': ctx, 'reason': 'bench'}), mon.ajax('unverifyField', {'ctx': ctx, 'field': 'ptname_v2'}), mon.ajax('verifyField', {'ctx': ctx, 'field': 'ptname_v2'}), mon.ajax('completeForm', {'ctx': ctx})), 3) / 4))

print(f"\nPerf project {PID}: 5,000 records, 300,003 data rows, 40,000 form instances, 40,006 verified fields, 2,001 queries\n")
for name, secs in rows:
    print(f"{name:<62} {secs * 1000:>9.0f} ms")
