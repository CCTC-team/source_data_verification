"""End-to-end test of the SDV module through HTTP as real users. usage: python3 test_flow.py <pid>"""
import csv, io, json, re, sys
from rc import RC, sql, VER, PREFIX, BASE

PID = int(sys.argv[1])
results = []


def check(name, cond, detail=''):
    results.append((name, bool(cond)))
    print(('PASS ' if cond else 'FAIL ') + name + ('' if cond else f'  -- {detail}'))


def err(resp):
    return (resp or {}).get('error', '')


def event_id(arm, name):
    return int(sql(f"select e.event_id from redcap_events_metadata e join redcap_events_arms a on a.arm_id=e.arm_id "
                   f"where a.project_id={PID} and a.arm_num={arm} and e.descrip='{name}'")[0][0])


E1, E2 = event_id(1, 'Event 1'), event_id(2, 'Event 1')
A = {'record': '1-1', 'eventId': E1, 'instrument': 'text_validation', 'instance': 1}
B = {'record': '1-1', 'eventId': E1, 'instrument': 'text_validation', 'instance': 2}
C = {'record': '2-1', 'eventId': E2, 'instrument': 'text_validation', 'instance': 1}

mon, de, dm, de_dag = RC('test_user4'), RC('test_user1'), RC('test_dm'), RC('test_de1')

# ---- monitor opens the form ------------------------------------------------------------------------
state, ui = mon.form_page(PID, '1-1', E1, 'text_validation', 1)
check('monitor sees SDV payload on form', state is not None, mon.page_html[-500:] if state is None else '')
check('form row created on open as pending', state['form']['status'] == 'pending', state['form'])
check('two required fields from @ENDPOINT tags', state['counts']['required'] == 2, state['counts'])
check('@NOTMONITORED field excluded', state['fields']['text_validation_crfver']['requirement'] == 'excluded')
check('monitor UI is read-only with save hidden', ui['readonly'] and ui['hideSave'], ui)
check('monitor can edit plan', state['role']['canEditPlan'])

r = mon.ajax('completeForm', {'ctx': A})
check('complete blocked until required fields verified', not r['ok'] and 'ptname_v2' in err(r) and 'email_v2' in err(r), r)

r = mon.ajax('verifyField', {'ctx': A, 'field': 'ptname_v2'})
check('verify field', r['ok'] and r['state']['fields']['ptname_v2']['sdv'] == 'verified', r)
check('status moves to in_progress', r['state']['form']['status'] == 'in_progress', r['state']['form'])

r = mon.ajax('verifyField', {'ctx': A, 'field': 'text_validation_crfver'})
check('cannot verify excluded field', not r['ok'] and 'excluded' in err(r), r)

r = mon.ajax('raiseQuery', {'ctx': A, 'field': 'email_v2', 'text': ''})
check('query text required', not r['ok'], r)
r = mon.ajax('raiseQuery', {'ctx': A, 'field': 'email_v2', 'text': 'Please check email <b>against</b> source'})
check('raise query', r['ok'] and len(r['state']['queries']) == 1 and r['state']['queries'][0]['status'] == 'open', r)
qid = r['state']['queries'][0]['id']

r = mon.ajax('verifyField', {'ctx': A, 'field': 'email_v2'})
check('cannot verify a field with an open query', not r['ok'] and 'open query' in err(r), r)
r = mon.ajax('completeForm', {'ctx': A})
check('complete blocked by unverified/queried field', not r['ok'], r)

# ---- site responds ----------------------------------------------------------------------------------
state_de, ui_de = de.form_page(PID, '1-1', E1, 'text_validation', 1)
check('data entry sees open query and can respond', state_de['role']['canRespond'] and state_de['queries'][0]['id'] == qid, state_de and state_de['queries'])
check('data entry form not read-only', not ui_de['readonly'] and not ui_de['hideSave'], ui_de)
check('inline query text for data entry', state_de['settings']['showInline'] and state_de['fields']['email_v2']['queryText'].startswith('Please check'))
r = de.ajax('verifyField', {'ctx': A, 'field': 'ptname_v2'})
check('data entry cannot verify', not r['ok'] and 'Only monitors' in err(r), r)
r = de.ajax('respondQuery', {'queryId': qid, 'code': 'made up', 'comment': ''})
check('invalid response code rejected', not r['ok'], r)
r = de.ajax('respondQuery', {'queryId': qid, 'code': 'Value correct as per source', 'comment': 'checked against notes'})
check('data entry responds', r['ok'] and r['state']['queries'][0]['status'] == 'responded', r)

state_dm, _ = dm.form_page(PID, '1-1', E1, 'text_validation', 1)
r = dm.ajax('setPlan', {'field': 'ptname_v2', 'requirement': 'optional'})
check('data manager cannot change plan', not r['ok'] and 'Only monitors' in err(r), r)
r = dm.ajax('closeQuery', {'queryId': qid})
check('data manager cannot close query', not r['ok'], r)

# ---- monitor re-raises, site responds again, monitor accepts -----------------------------------------
mon.form_page(PID, '1-1', E1, 'text_validation', 1)
r = mon.ajax('reraiseQuery', {'queryId': qid, 'text': 'Recheck the domain please'})
check('re-raise query', r['ok'] and r['state']['queries'][0]['status'] == 'open', r)
r = de.ajax('respondQuery', {'queryId': qid, 'code': 'Value updated as per source', 'comment': ''})
check('second response', r['ok'], r)
r = mon.ajax('acceptQuery', {'queryId': qid, 'verify': True})
check('accept and verify', r['ok'] and r['state']['fields']['email_v2']['sdv'] == 'verified' and not r['state']['queries'], r)
r = mon.ajax('completeForm', {'ctx': A})
check('mark SDV complete', r['ok'] and r['state']['form']['status'] == 'complete' and r['state']['form']['completedBy'] == 'test_user4', r)
r = mon.ajax('verifyField', {'ctx': A, 'field': 'text_validation_monstat'})
check('complete form is locked for SDV changes', not r['ok'] and 'Reopen' in err(r), r)

# ---- data entry edits a verified field through the real form save (save hook) ------------------------
de.form_page(PID, '1-1', E1, 'text_validation', 1)
html = de.page_html
form = {'record_id': '1-1', 'text_validation_crfver': '1', 'ptname_v2': 'Name1', 'email_v2': 'changed@abc.com',
        'text_validation_monstat': '', 'text_validation_complete': '2', 'submit-action': 'submit-btn-savecontinue', 'hidden_edit_flag': '1'}
de.post(f'{VER}/DataEntry/index.php', form, {'pid': PID, 'id': '1-1', 'event_id': E1, 'page': 'text_validation', 'instance': 1})
DT = sql(f"select data_table from redcap_projects where project_id={PID}")[0][0]
val = sql(f"select value from {DT} where project_id={PID} and record='1-1' and event_id={E1} and field_name='email_v2' and instance is null")
check('form save stored new value', val and val[0][0] == 'changed@abc.com', val)
f = sql(f"select status from cctc_sdv_field where project_id={PID} and record='1-1' and field_name='email_v2' and instance=1")
check('save hook marked verified field as changed', f and f[0][0] == 'changed', f)
fm = sql(f"select status, status_reason from cctc_sdv_form where project_id={PID} and record='1-1' and event_id={E1} and instrument='text_validation' and instance=1")
check('save hook set form to reverify (trigger=verified)', fm and fm[0][0] == 'reverify', fm)
f2 = sql(f"select status from cctc_sdv_field where project_id={PID} and record='1-1' and field_name='ptname_v2' and instance=1")
check('unchanged verified field keeps its tick', f2 and f2[0][0] == 'verified', f2)

state, _ = mon.form_page(PID, '1-1', E1, 'text_validation', 1)
check('monitor sees changed field and reverify status', state['fields']['email_v2']['sdv'] == 'changed' and state['form']['status'] == 'reverify', state['form'])
r = mon.ajax('verifyField', {'ctx': A, 'field': 'email_v2'})
r = mon.ajax('completeForm', {'ctx': A})
check('re-verify and complete again', r['ok'] and r['state']['form']['status'] == 'complete', r)

# ---- change via data import (no save hook) is caught on next open ------------------------------------
tok = 'E2E' + 'A' * 29
sql(f"update redcap_user_rights set api_token='{tok}' where project_id={PID} and username='test_admin'")
try:
    import urllib.request, urllib.parse, ssl
    ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
    data = json.dumps([{'record_id': '1-1', 'redcap_event_name': 'event_1_arm_1', 'redcap_repeat_instrument': '', 'redcap_repeat_instance': 1, 'ptname_v2': 'Name1-import'}])
    body = urllib.parse.urlencode({'token': tok, 'content': 'record', 'format': 'json', 'type': 'flat', 'data': data}).encode()
    print('   api import:', urllib.request.urlopen(urllib.request.Request(BASE + 'api/', data=body), context=ctx).read().decode()[:200])
finally:
    sql(f"update redcap_user_rights set api_token=null where project_id={PID} and username='test_admin'")
state, _ = mon.form_page(PID, '1-1', E1, 'text_validation', 1)
check('API change detected when form opened', state['fields']['ptname_v2']['sdv'] == 'changed' and state['form']['status'] == 'reverify'
      and any('ptname_v2' in n for n in state['notices']), (state['form'], state['notices']))

# ---- SDV plan from the form --------------------------------------------------------------------------
r = mon.ajax('setPlan', {'ctx': A, 'field': 'text_validation_monstat', 'requirement': 'required'})
check('monitor marks field as requiring SDV from form', r['ok'] and r['state']['counts']['required'] == 3
      and r['state']['fields']['text_validation_monstat']['override'] == 'required', r)
plan_html = mon.module_page(PID, 'plan')
check('plan page shows override', 'text_validation_monstat' in plan_html and 'Recent SDV plan changes' in plan_html)
r = mon.ajax('setPlan', {'field': 'text_validation_monstat', 'requirement': 'optional'})
check('plan reset to default removes override', r['ok'] and r['field']['override'] is None, r)
r = mon.ajax('setPlan', {'field': 'record_id', 'requirement': 'required'})
check('record id cannot be in SDV plan', not r['ok'], r)
dm_plan = dm.module_page(PID, 'plan')
check('plan page read-only for data manager', 'Only monitors can change the SDV plan' in dm_plan and 'cctc-sdv-plan-select' not in dm_plan)

# ---- not required / reopen ---------------------------------------------------------------------------
mon.form_page(PID, '1-1', E1, 'text_validation', 2)
r = mon.ajax('markNotRequired', {'ctx': B, 'reason': ''})
check('not required needs a reason', not r['ok'], r)
r = mon.ajax('markNotRequired', {'ctx': B, 'reason': 'Reduced SDV per monitoring plan'})
check('mark not required', r['ok'] and r['state']['form']['status'] == 'not_required', r)
r = mon.ajax('reopenForm', {'ctx': B, 'reason': 'Sponsor request'})
check('reopen', r['ok'] and r['state']['form']['status'] == 'pending', r)
r = mon.ajax('verifyAllRequired', {'ctx': B})
check('verify all required', r['ok'] and r['state']['counts']['requiredVerified'] == r['state']['counts']['required'], r)

# ---- DAG restriction ---------------------------------------------------------------------------------
st, _ = de_dag.form_page(PID, '1-1', E1, 'text_validation', 1)
check('DAG user sees own-site record', st is not None)
r = de_dag.ajax('state', {'ctx': C})
check('DAG user blocked from other-site record', not r['ok'] and 'access' in err(r), r)

# ---- history -----------------------------------------------------------------------------------------
mon.form_page(PID, '1-1', E1, 'text_validation', 1)
r = mon.ajax('history', {'ctx': A})
actions = [h['action'] for h in r['history']]
check('history includes full trail', r['ok'] and all(a in actions for a in ['field_verified', 'query_raised', 'query_responded', 'query_reraised', 'query_accepted', 'form_complete', 'field_changed', 'form_reverify']), actions)

# ---- dashboard ---------------------------------------------------------------------------------------
html = mon.module_page(PID, 'dashboard', post={'sync': '1'})
check('dashboard sync ran', 'Sync complete' in html, re.findall(r'class=.red.*', html)[:2])
check('dashboard lists forms', 'cctc-sdv-forms-table' in html and "data-record='2-1'" in html)
html = mon.module_page(PID, 'dashboard', {'tab': 'forms', 'status': 'not_required'})
check('dashboard status filter', 'No forms match' in html)
html = mon.module_page(PID, 'dashboard', {'tab': 'queries'})
check('queries tab lists closed query', f"data-query-id='{qid}'" in html and 'Recheck the domain please' in html)
html = mon.module_page(PID, 'dashboard', {'tab': 'summary'})
check('summary tab per site', 'cctc-sdv-summary-table' in html and 'Site:' in html)
html = de_dag.module_page(PID, 'dashboard', {'tab': 'forms'})
check('DAG user dashboard limited to own site', "data-record='1-1'" in html and "data-record='2-1'" not in html)
csv_text = mon.get(f'{VER}/ExternalModules/', {'prefix': PREFIX, 'page': 'export', 'pid': PID, 'tab': 'forms'})
rows = list(csv.reader(io.StringIO(csv_text.lstrip('﻿'))))
check('CSV export of forms', rows and rows[0][0] == 'record' and len(rows) > 2, csv_text[:300])
csv_q = mon.get(f'{VER}/ExternalModules/', {'prefix': PREFIX, 'page': 'export', 'pid': PID, 'tab': 'queries'})
check('CSV export of queries contains thread', 'Value updated as per source' in csv_q, csv_q[:300])

# ---- REDCap logging ----------------------------------------------------------------------------------
log_table = sql(f"select log_event_table from redcap_projects where project_id={PID}")[0][0]
logs = [r[0] for r in sql(f"select description from {log_table} where project_id={PID} and description like 'SDV:%'")]
for d in ['SDV: field verified', 'SDV: monitor query raised', 'SDV: monitor query responded', 'SDV: form marked SDV complete',
          'SDV: verified field value changed', 'SDV: form requires re-verification', 'SDV: plan changed', 'SDV: form reopened',
          'SDV: dashboard sync', 'SDV: data exported']:
    check(f'REDCap Logging has "{d}"', d in logs, sorted(set(logs)))
errs = sql(f"select message from redcap_external_modules_log l join redcap_external_modules m on m.external_module_id=l.external_module_id where m.directory_prefix='{PREFIX}' and message like 'Error%'")
check('no module errors logged', not errs, errs)

print(f"\n{sum(1 for _, ok in results if ok)}/{len(results)} checks passed")
