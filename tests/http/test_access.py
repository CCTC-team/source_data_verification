"""Form rights on the dashboard and export, plan changes from a form the user cannot open, CSV formula guard.
usage: python3 test_access.py <pid>   (a project made by setup_project.py RepeatingEvents_DoubleArm_withDAGs)
Temporarily removes form rights from roles and restores them at the end.
"""
import csv, io, re, sys
from rc import RC, sql, PREFIX

PID = int(sys.argv[1])
HIDDEN = 'data_types'
mon, de = RC('test_user4'), RC('test_user2')  # Monitor, DataEntry
results = []


def check(name, cond, detail=''):
    results.append(bool(cond))
    print(('PASS ' if cond else 'FAIL ') + name + ('' if cond else f'  -- {detail}'))


def role_rights(role):
    return sql(f"select data_entry from redcap_user_roles where project_id={PID} and role_name='{role}'")[0][0]


def set_role_rights(role, rights):
    sql(f"update redcap_user_roles set data_entry='{rights}' where project_id={PID} and role_name='{role}'")


def export(user, tab, **params):
    text = user.get('redcap_v14.7.0/ExternalModules/', {'prefix': PREFIX, 'page': 'export', 'pid': PID, 'tab': tab, **params})
    return list(csv.DictReader(io.StringIO(text.lstrip('﻿'))))


E1 = int(sql(f"select e.event_id from redcap_events_metadata e join redcap_events_arms a on a.arm_id=e.arm_id "
             f"where a.project_id={PID} and a.arm_num=1 and e.descrip='Event 1'")[0][0])
CTX_HIDDEN = {'record': '1-1', 'eventId': E1, 'instrument': HIDDEN, 'instance': 1}

# a query on the form that will be hidden, with text that starts with a tab (only possible in the database:
# the UI trims it) to exercise the CSV formula guard
mon.form_page(PID, '1-1', E1, HIDDEN, 1)
r = mon.ajax('raiseQuery', {'ctx': CTX_HIDDEN, 'field': 'ptname', 'text': 'access test'})
assert r.get('ok'), r
qid = r['state']['fields']['ptname']['queryId']
sql(f"update sdv_query_msg set text=concat(char(9), '=1+1') where query_id={qid} and action='raise'")
mon.module_page(PID, 'dashboard', post={'sync': '1'})

de_rights, mon_rights = role_rights('DataEntry'), role_rights('Monitor')
try:
    # ---- CSV guard (before rights change, as the monitor) --------------------------------------
    rows = [q for q in export(mon, 'queries') if q['query_id'] == str(qid)]
    check('CSV: text starting with a tab is prefixed with a quote', rows and rows[0]['latest_query'] == "'\t=1+1", rows and repr(rows[0]['latest_query']))

    # ---- dashboard and export follow form rights --------------------------------------------------
    set_role_rights('DataEntry', re.sub(rf'\[{HIDDEN},\d\]', f'[{HIDDEN},0]', de_rights))
    html = de.module_page(PID, 'dashboard', {'tab': 'forms'})
    check('forms tab hides a form the user cannot view', 'cctc-sdv-forms-table' in html and f'page={HIDDEN}' not in html,
          re.findall(rf'page={HIDDEN}', html)[:2])
    check('form filter does not offer the hidden form', f"<option value='{HIDDEN}'" not in html)
    html = de.module_page(PID, 'dashboard', {'tab': 'forms', 'form': HIDDEN})
    check('filtering on the hidden form does not reveal it', f'page={HIDDEN}' not in html, re.findall(rf'page={HIDDEN}', html)[:2])
    check('forms tab still lists viewable forms', 'page=text_validation' in de.module_page(PID, 'dashboard', {'tab': 'forms'}))
    html = de.module_page(PID, 'dashboard', {'tab': 'queries'})
    check('queries tab hides queries on the hidden form', f'page={HIDDEN}' not in html, re.findall(rf'page={HIDDEN}', html)[:2])
    html = de.module_page(PID, 'dashboard', {'tab': 'summary'})
    check('summary hides the hidden form', 'cctc-sdv-summary-table' in html and f'form={HIDDEN}' not in html, re.findall(rf'form={HIDDEN}', html)[:2])
    forms_csv = export(de, 'forms')
    check('forms CSV has no rows for the hidden form', forms_csv and all(r['form'] != HIDDEN for r in forms_csv), {r['form'] for r in forms_csv})
    queries_csv = export(de, 'queries')
    check('queries CSV has no rows for the hidden form', all(r['form'] != HIDDEN for r in queries_csv), {r['form'] for r in queries_csv})
    check('monitor (full rights) still sees the hidden form in exports', any(r['form'] == HIDDEN for r in export(mon, 'forms')))

    # ---- plan change from a form the monitor cannot open ----------------------------------------
    set_role_rights('Monitor', re.sub(rf'\[{HIDDEN},\d\]', f'[{HIDDEN},0]', mon_rights))
    before = sql(f"select count(*) from sdv_plan where project_id={PID} and field_name='ptname'")[0][0]
    mon.module_page(PID, 'plan')
    r = mon.ajax('setPlan', {'field': 'ptname', 'requirement': 'optional', 'ctx': CTX_HIDDEN})
    check('setPlan with a form the user cannot open is refused', not r.get('ok') and 'access' in r.get('error', ''), r)
    after = sql(f"select count(*) from sdv_plan where project_id={PID} and field_name='ptname'")[0][0]
    check('refused setPlan saved nothing', before == after, (before, after))
finally:
    set_role_rights('DataEntry', de_rights)
    set_role_rights('Monitor', mon_rights)
    mon.form_page(PID, '1-1', E1, HIDDEN, 1)
    mon.ajax('closeQuery', {'queryId': qid, 'reason': 'access test done'})

print(f"\n{sum(results)}/{len(results)} access checks passed")
sys.exit(0 if all(results) else 1)
