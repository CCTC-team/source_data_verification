"""Tests each 'trigger-reverification' mode. usage: python3 test_triggers.py <pid>"""
import json, ssl, sys, urllib.parse, urllib.request
from rc import RC, sql, VER, PREFIX, BASE

PID = int(sys.argv[1])
admin, mon, de = RC('test_admin'), RC('test_user4'), RC('test_user1')
E2 = int(sql(f"select e.event_id from redcap_events_metadata e join redcap_events_arms a on a.arm_id=e.arm_id "
             f"where a.project_id={PID} and a.arm_num=2 and e.descrip='Event 1'")[0][0])
CTX = {'record': '2-1', 'eventId': E2, 'instrument': 'text_validation', 'instance': 2}
role_id = {r: sql(f"select role_id from redcap_user_roles where project_id={PID} and role_name='{r}'")[0][0]
           for r in ['Monitor', 'DataEntryPI', 'DataEntry', 'DataManager']}
results = []


def check(name, cond, detail=''):
    results.append(bool(cond))
    print(('PASS ' if cond else 'FAIL ') + name + ('' if cond else f'  -- {detail}'))


def configure(trigger):
    admin.get(f'{VER}/ProjectSetup/index.php', {'pid': PID})
    settings = {'monitor-roles____0': role_id['Monitor'], 'data-entry-roles____0': role_id['DataEntryPI'],
                'data-entry-roles____1': role_id['DataEntry'], 'data-manager-roles____0': role_id['DataManager'],
                'sdv-flag-regex': '@ENDPOINT-\\w+', 'sdv-ignore-action-tag': '@NOTMONITORED', 'trigger-reverification': trigger}
    r = admin.post(f'{VER}/ExternalModules/manager/ajax/save-settings.php', {'settings': json.dumps(settings)}, {'pid': PID, 'moduleDirectoryPrefix': PREFIX})
    assert 'success' in r, r


def api_set(values):
    tok = 'TRG' + 'B' * 29
    sql(f"update redcap_user_rights set api_token='{tok}' where project_id={PID} and username='test_admin'")
    try:
        c = ssl.create_default_context(); c.check_hostname = False; c.verify_mode = ssl.CERT_NONE
        row = {'record_id': '2-1', 'redcap_event_name': 'event_1_arm_2', 'redcap_repeat_instrument': '', 'redcap_repeat_instance': 2, **values}
        body = urllib.parse.urlencode({'token': tok, 'content': 'record', 'format': 'json', 'data': json.dumps([row])}).encode()
        out = urllib.request.urlopen(urllib.request.Request(BASE + 'api/', data=body), context=c).read().decode()
        assert '"count": 1' in out or '"count":1' in out, out
    finally:
        sql(f"update redcap_user_rights set api_token=null where project_id={PID} and username='test_admin'")


def state():
    s, _ = mon.form_page(PID, '2-1', E2, 'text_validation', 2)
    return s


def complete(extra_verify=()):
    s = state()
    if s['form']['status'] in ('complete', 'not_required'):
        assert mon.ajax('reopenForm', {'ctx': CTX, 'reason': 'trigger test'})['ok']
    assert mon.ajax('verifyAllRequired', {'ctx': CTX})['ok']
    for f in extra_verify:
        r = mon.ajax('verifyField', {'ctx': CTX, 'field': f})
        assert r['ok'], r
    r = mon.ajax('completeForm', {'ctx': CTX})
    assert r['ok'], r


n = [0]
def bump():
    n[0] += 1
    return str(n[0])


# 'always': changing an unverified optional field reopens the form
configure('always'); complete()
api_set({'text_validation_monstat': '2'})
s = state()
check('always: optional unverified change -> reverify', s['form']['status'] == 'reverify', s['form'])

# 'verified': optional unverified change keeps complete; verified change reopens
configure('verified'); complete()
api_set({'text_validation_monstat': '3'})
s = state()
check('verified: unverified change keeps complete', s['form']['status'] == 'complete', s['form'])
api_set({'ptname_v2': 'P' + bump()})
s = state()
check('verified: verified field change -> reverify', s['form']['status'] == 'reverify' and s['fields']['ptname_v2']['sdv'] == 'changed', s['form'])

# 'required': verified optional field change only untick it; required field change reopens
configure('required'); complete(['text_validation_monstat'])
api_set({'text_validation_monstat': '4'})
s = state()
check('required: optional verified change keeps complete but unticks field',
      s['form']['status'] == 'complete' and s['fields']['text_validation_monstat']['sdv'] == 'changed', (s['form'], s['fields']['text_validation_monstat']))
api_set({'email_v2': f"e{bump()}@x.org"})
s = state()
check('required: required field change -> reverify', s['form']['status'] == 'reverify', s['form'])

# 'previously_queried': only a change to a field that has had a query reopens
configure('previously_queried')
s = state()
if s['form']['status'] in ('complete', 'not_required'):
    mon.ajax('reopenForm', {'ctx': CTX, 'reason': 'trigger test'})
r = mon.ajax('raiseQuery', {'ctx': CTX, 'field': 'text_validation_monstat', 'text': 'check'})
qid = r['state']['fields']['text_validation_monstat']['queryId']
de.form_page(PID, '2-1', E2, 'text_validation', 2)
assert de.ajax('respondQuery', {'queryId': qid, 'code': 'Value correct as per source'})['ok']
mon.form_page(PID, '2-1', E2, 'text_validation', 2)
assert mon.ajax('acceptQuery', {'queryId': qid, 'verify': True})['ok']
complete()
api_set({'ptname_v2': 'P' + bump()})
s = state()
check('previously_queried: never-queried field change keeps complete', s['form']['status'] == 'complete', s['form'])
api_set({'text_validation_monstat': '5'})
s = state()
check('previously_queried: queried field change -> reverify', s['form']['status'] == 'reverify', s['form'])

# 'previously_queried_or_required'
configure('previously_queried_or_required'); complete()
api_set({'ptname_v2': 'P' + bump()})
s = state()
check('previously_queried_or_required: required field change -> reverify', s['form']['status'] == 'reverify', s['form'])

# 'never': stays complete, but the verified field loses its tick
configure('never'); complete()
api_set({'ptname_v2': 'P' + bump()})
s = state()
check('never: form stays complete', s['form']['status'] == 'complete', s['form'])
check('never: verified field still loses tick', s['fields']['ptname_v2']['sdv'] == 'changed', s['fields']['ptname_v2'])

configure('verified')
print(f"\n{sum(results)}/{len(results)} trigger checks passed")
