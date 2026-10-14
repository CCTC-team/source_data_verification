"""Setting the SDV requirement of every field on a form at once from the SDV Plan page.
usage: python3 test_plan_form.py <pid>   (a project made by setup_project.py RepeatingEvents_DoubleArm_withDAGs)
Leaves the form's plan back on its defaults.
"""
import re, sys
from rc import RC, sql, VER

PID = int(sys.argv[1])
FORM = 'data_types'
admin, mon, dm = RC('test_admin'), RC('test_user4'), RC('test_dm')
results = []


def check(name, cond, detail=''):
    results.append(bool(cond))
    print(('PASS ' if cond else 'FAIL ') + name + ('' if cond else f'  -- {detail}'))


def overrides():
    return dict(sql(f"select p.field_name, p.requirement from sdv_plan p join redcap_metadata m on m.project_id=p.project_id "
                    f"and m.field_name=p.field_name where p.project_id={PID} and m.form_name='{FORM}'"))


def audit_count():
    return int(sql(f"select count(*) from sdv_audit where project_id={PID} and action='plan_changed' and detail like '%whole_form%'")[0][0])


def page_requirements(html):
    """field => selected requirement in the form's table on the plan page"""
    table = re.search(rf"<table class='cctc-sdv-table' data-instrument='{FORM}'>(.*?)</table>", html, re.S).group(1)
    return dict(re.findall(r"data-field='([^']+)' data-current='([^']+)'", table))


E1 = int(sql(f"select e.event_id from redcap_events_metadata e join redcap_events_arms a on a.arm_id=e.arm_id "
             f"where a.project_id={PID} and a.arm_num=1 and e.descrip='Event 1'")[0][0])

# start from defaults
html = mon.module_page(PID, 'plan')
mon.ajax('setPlanForm', {'instrument': FORM, 'requirement': 'default'})
html = mon.module_page(PID, 'plan')
defaults = page_requirements(html)
fields = list(defaults)
check('monitor sees the form-level control', f"class='cctc-sdv-plan-form-select' data-instrument='{FORM}'" in html)
check('form starts with a mix of requirements', len(set(defaults.values())) > 1, defaults)
check('excluded-by-tag field present', defaults.get('data_types_crfver') == 'excluded', defaults.get('data_types_crfver'))

# set everything to required
before = audit_count()
r = mon.ajax('setPlanForm', {'instrument': FORM, 'requirement': 'required'})
check('setPlanForm required succeeds', r.get('ok'), r)
check('response lists every field as required', r.get('ok') and set(r['fields']) == set(fields)
      and all(f['requirement'] == 'required' for f in r['fields'].values()), r)
check('overrides stored only for fields not required by default',
      set(overrides()) == {f for f, v in defaults.items() if v != 'required'}, overrides())
check('one audit row per changed field', audit_count() - before == sum(1 for v in defaults.values() if v != 'required'), audit_count() - before)
html = mon.module_page(PID, 'plan')
req = page_requirements(html)
check('plan page shows all fields required', set(req.values()) == {'required'}, req)
check('summary count updated', re.search(rf"\({FORM}\) - <span class='cctc-sdv-plan-count'>{len(fields)}</span> of {len(fields)}", html))
state, _ = mon.form_page(PID, '1-1', E1, FORM, 1)
check('data entry form now requires every field', state['counts']['required'] == len(fields), state['counts'])

# repeating the same setting changes nothing
before = audit_count()
r = mon.ajax('setPlanForm', {'instrument': FORM, 'requirement': 'required'})
check('repeat is accepted', r.get('ok'), r)
check('repeat writes no audit rows', audit_count() == before, audit_count() - before)

# per-field change still works on top of a form-level setting
mon.module_page(PID, 'plan')
r = mon.ajax('setPlan', {'field': 'ptname', 'requirement': 'optional'})
check('single field can still be changed afterwards', r.get('ok') and r['field']['requirement'] == 'optional', r)

# excluded, then back to defaults
r = mon.ajax('setPlanForm', {'instrument': FORM, 'requirement': 'excluded'})
check('setPlanForm excluded', r.get('ok') and all(f['requirement'] == 'excluded' for f in r['fields'].values()), r)
r = mon.ajax('setPlanForm', {'instrument': FORM, 'requirement': 'default'})
check('setPlanForm default succeeds', r.get('ok'), r)
check('default removes every override on the form', overrides() == {}, overrides())
check('default restores action-tag requirements', page_requirements(mon.module_page(PID, 'plan')) == defaults)

# a field that becomes required sends complete forms in which it is not verified back for re-verification
CTX = {'record': '1-1', 'eventId': E1, 'instrument': FORM, 'instance': 1}
OPTIONAL = next(f for f, v in defaults.items() if v == 'optional')


def complete_form(verify=()):
    state, _ = mon.form_page(PID, '1-1', E1, FORM, 1)
    if state['form']['status'] in ('complete', 'not_required'):
        assert mon.ajax('reopenForm', {'ctx': CTX, 'reason': 'plan form test'})['ok']
    for f in verify:
        assert mon.ajax('verifyField', {'ctx': CTX, 'field': f})['ok']
    assert mon.ajax('verifyAllRequired', {'ctx': CTX})['ok']
    r = mon.ajax('completeForm', {'ctx': CTX})
    assert r['ok'], r


def form_status():
    return sql(f"select status, status_reason from sdv_form where project_id={PID} and record='1-1' and event_id={E1} "
               f"and instrument='{FORM}' and instance=1")[0]


complete_form()
if OPTIONAL in (r := mon.ajax('state', {'ctx': CTX}))['state']['fields'] and r['state']['fields'][OPTIONAL]['sdv'] == 'verified':
    assert mon.ajax('reopenForm', {'ctx': CTX, 'reason': 'plan form test'})['ok']
    assert mon.ajax('unverifyField', {'ctx': CTX, 'field': OPTIONAL})['ok']
    complete_form()
mon.module_page(PID, 'plan')
r = mon.ajax('setPlan', {'field': OPTIONAL, 'requirement': 'required'})
status, reason = form_status()
check('optional -> required sends the complete form to reverify', status == 'reverify', (status, reason))
check('reverify reason names the plan change and field', reason == f'SDV plan changed: {OPTIONAL} now requires SDV', reason)
check('response counts the forms sent back', r.get('ok') and r.get('reverified', 0) >= 1, r)
check('form_reverify audited with the plan change as source', sql(
    f"select count(*) from sdv_audit where project_id={PID} and record='1-1' and instrument='{FORM}' and action='form_reverify' "
    f"and detail like '%SDV plan changed%'")[0][0] != '0')
state, _ = mon.form_page(PID, '1-1', E1, FORM, 1)
check('form shows Re-verification required and is open for SDV', state['form']['status'] == 'reverify' and state['form']['open'], state['form'])
r = mon.ajax('completeForm', {'ctx': CTX})
check('cannot complete again until the newly required field is verified', not r['ok'] and OPTIONAL in r.get('error', ''), r)
mon.ajax('verifyField', {'ctx': CTX, 'field': OPTIONAL})
r = mon.ajax('completeForm', {'ctx': CTX})
check('verify the field and complete again', r['ok'] and r['state']['form']['status'] == 'complete', r)

# a newly required field that is already verified leaves the form complete
mon.module_page(PID, 'plan')
mon.ajax('setPlan', {'field': OPTIONAL, 'requirement': 'default'})
complete_form(verify=[OPTIONAL])
mon.module_page(PID, 'plan')
r = mon.ajax('setPlan', {'field': OPTIONAL, 'requirement': 'required'})
check('already verified field: form stays complete', form_status()[0] == 'complete', form_status())

# required -> optional never sends a form back
r = mon.ajax('setPlan', {'field': OPTIONAL, 'requirement': 'default'})
check('required -> optional: form stays complete', form_status()[0] == 'complete' and r.get('reverified') == 0, (form_status(), r))

# whole form set to required
r = mon.ajax('setPlanForm', {'instrument': FORM, 'requirement': 'required'})
check('whole form -> required sends the complete form to reverify', form_status()[0] == 'reverify' and r.get('reverified', 0) >= 1, (form_status(), r))
mon.ajax('setPlanForm', {'instrument': FORM, 'requirement': 'default'})
logging = admin.get(f'{VER}/Logging/index.php', {'pid': PID})
check('REDCap Logging records the re-verification from the plan change', 'SDV plan changed' in logging)
# the form is left open (reverify) for the other suites, which raise queries on it

# validation and permissions
mon.module_page(PID, 'plan')
r = mon.ajax('setPlanForm', {'instrument': FORM, 'requirement': 'mandatory'})
check('invalid requirement rejected', not r.get('ok') and 'Invalid requirement' in r.get('error', ''), r)
r = mon.ajax('setPlanForm', {'instrument': 'no_such_form', 'requirement': 'required'})
check('unknown form rejected', not r.get('ok') and 'no fields' in r.get('error', ''), r)
html = dm.module_page(PID, 'plan')
check('data manager has no form-level control', 'cctc-sdv-plan-form-select' not in html)
dm.form_page(PID, '1-1', E1, FORM, 1)
r = dm.ajax('setPlanForm', {'instrument': FORM, 'requirement': 'required'})
check('data manager cannot set a whole form', not r.get('ok') and 'Only monitors' in r.get('error', ''), r)
check('data manager attempt changed nothing', overrides() == {}, overrides())

logging = admin.get(f'{VER}/Logging/index.php', {'pid': PID})
check('REDCap Logging has "SDV: plan changed for whole form"', 'SDV: plan changed for whole form' in logging)
errors = sql(f"select count(*) from redcap_external_modules_log l join redcap_external_modules m on m.external_module_id=l.external_module_id "
             f"where m.directory_prefix='source_data_verification' and l.project_id={PID} and l.message like 'Error%'")[0][0]
check('no module errors logged', errors == '0', errors)

print(f"\n{sum(results)}/{len(results)} plan form checks passed")
sys.exit(0 if all(results) else 1)
