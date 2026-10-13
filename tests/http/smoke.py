"""Generic SDV smoke test for any fixture project type: every saved form instance that needs SDV can be
opened, fully verified and completed, and appears correctly on the dashboard. usage: python3 smoke.py <pid>"""
import sys
from rc import RC, sql

PID = int(sys.argv[1])
dt = sql(f"select data_table from redcap_projects where project_id={PID}")[0][0]
mon = RC('test_user4')
ok = fail = 0

# saved form instances (via <form>_complete rows) for instruments that have an @ENDPOINT field
forms = {r[0] for r in sql(f"select distinct form_name from redcap_metadata where project_id={PID} and misc like '%@ENDPOINT%'")}
rows = sql(f"select record, event_id, substring(field_name, 1, char_length(field_name) - 9), coalesce(instance, 1) from {dt} "
           f"where project_id={PID} and field_name like '%\\_complete' order by record, event_id, instance")
targets = [(r, int(e), f, int(i)) for r, e, f, i in rows if f in forms]
print(f'{len(targets)} form instances needing SDV')

for record, event_id, form, inst in targets:
    ctx = {'record': record, 'eventId': event_id, 'instrument': form, 'instance': inst}
    state, _ = mon.form_page(PID, record, event_id, form, inst)
    label = f'{record}/{event_id}/{form}#{inst}'
    if state is None:
        fail += 1; print('FAIL no SDV panel', label); continue
    r1 = mon.ajax('verifyAllRequired', {'ctx': ctx})
    r2 = mon.ajax('completeForm', {'ctx': ctx})
    if r1['ok'] and r2['ok'] and r2['state']['form']['status'] == 'complete':
        ok += 1
    else:
        fail += 1; print('FAIL', label, r1.get('error'), r2.get('error'))

html = mon.module_page(PID, 'dashboard', {'tab': 'forms', 'status': 'complete', 'size': 250}, post={'sync': '1'})
shown = html.count("<tr data-record=")
print(f'dashboard shows {shown} complete form instances')
print(f'{ok} completed, {fail} failed' + ('' if shown == ok else f'  -- DASHBOARD MISMATCH {shown} != {ok}'))
