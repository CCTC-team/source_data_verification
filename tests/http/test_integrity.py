"""Data-integrity and permission rules. usage: python3 test_integrity.py <pid>

- users with read-only rights to a form cannot respond to its queries
- only monitors run the project-wide sync
- sync finds changes to unverified fields of complete forms when the trigger looks beyond verified fields
- SDV state of deleted form data is cleared (on sync and on form open), so a re-created record starts afresh
Run on a project made by setup_project.py RepeatingEvents_DoubleArm_withDAGs.
"""
import json, re, sys
from rc import RC, sql, api, VER, PREFIX

PID = int(sys.argv[1])
admin, mon, de_ro, de_dag = RC('test_admin'), RC('test_user4'), RC('test_user2'), RC('test_de1')
results = []


def check(name, cond, detail=''):
    results.append(bool(cond))
    print(('PASS ' if cond else 'FAIL ') + name + ('' if cond else f'  -- {detail}'))


def event_id(arm, name):
    return int(sql(f"select e.event_id from redcap_events_metadata e join redcap_events_arms a on a.arm_id=e.arm_id "
                   f"where a.project_id={PID} and a.arm_num={arm} and e.descrip='{name}'")[0][0])


E1, E_ARM2 = event_id(1, 'Event 1'), event_id(2, 'Event 1')
role_id = {r: sql(f"select role_id from redcap_user_roles where project_id={PID} and role_name='{r}'")[0][0]
           for r in ['Monitor', 'DataEntryPI', 'DataEntry', 'DataManager']}
TOKEN = 'INT' + 'C' * 29


def configure(trigger):
    admin.get(f'{VER}/ProjectSetup/index.php', {'pid': PID})
    settings = {'monitor-roles____0': role_id['Monitor'], 'data-entry-roles____0': role_id['DataEntryPI'],
                'data-entry-roles____1': role_id['DataEntry'], 'data-manager-roles____0': role_id['DataManager'],
                'sdv-flag-regex': '@ENDPOINT-\\w+', 'sdv-ignore-action-tag': '@NOTMONITORED', 'trigger-reverification': trigger}
    r = admin.post(f'{VER}/ExternalModules/manager/ajax/save-settings.php', {'settings': json.dumps(settings)},
                   {'pid': PID, 'moduleDirectoryPrefix': PREFIX})
    assert 'success' in r, r


def import_row(record, instance, **values):
    row = {'record_id': record, 'redcap_event_name': 'event_1_arm_2', 'redcap_repeat_instrument': '',
           'redcap_repeat_instance': instance, 'ptname_v2': 'Initial', 'email_v2': 'a@b.org', 'text_validation_complete': '2', **values}
    out = api(TOKEN, content='record', format='json', data=json.dumps([row]))
    assert '"count": 1' in out or '"count":1' in out, out


def delete_record(record):
    out = api(TOKEN, content='record', action='delete', **{'records[0]': record})
    assert out.strip() == '1', out


def form_row(record, instance=1):
    rows = sql(f"select status from sdv_form where project_id={PID} and record='{record}' and event_id={E_ARM2} "
               f"and instrument='text_validation' and instance={instance}")
    return rows[0][0] if rows else None


def field_rows(record):
    return int(sql(f"select count(*) from sdv_field where project_id={PID} and record='{record}'")[0][0])


def complete(record, instance=1, query_field=None):
    ctx = {'record': record, 'eventId': E_ARM2, 'instrument': 'text_validation', 'instance': instance}
    state, _ = mon.form_page(PID, record, E_ARM2, 'text_validation', instance)
    if state['form']['status'] in ('complete', 'not_required'):
        assert mon.ajax('reopenForm', {'ctx': ctx, 'reason': 'integrity test'})['ok']
    assert mon.ajax('verifyAllRequired', {'ctx': ctx})['ok']
    r = mon.ajax('completeForm', {'ctx': ctx})
    assert r['ok'], r
    if query_field:
        assert mon.ajax('reopenForm', {'ctx': ctx, 'reason': 'integrity test'})['ok']
        r = mon.ajax('raiseQuery', {'ctx': ctx, 'field': query_field, 'text': 'check before delete'})
        assert r['ok'], r
        return r['state']['fields'][query_field]['queryId']


def sync(user):
    return user.module_page(PID, 'dashboard', post={'sync': '1'})


sql(f"update redcap_user_rights set api_token='{TOKEN}', record_delete=1 where project_id={PID} and username='test_admin'")
try:
    # ---- read-only form rights ---------------------------------------------------------------------
    CTX_DT = {'record': '1-1', 'eventId': E1, 'instrument': 'data_types', 'instance': 1}
    mon.form_page(PID, '1-1', E1, 'data_types', 1)
    r = mon.ajax('raiseQuery', {'ctx': CTX_DT, 'field': 'ptname', 'text': 'read-only respond test'})
    check('monitor raises query on data_types', r.get('ok'), r)
    qid = r['state']['fields']['ptname']['queryId']
    state, _ = de_ro.form_page(PID, '1-1', E1, 'data_types', 1)
    check('read-only user is not offered respond', state and state['role']['canRespond'] is False, state and state['role'])
    r = de_ro.ajax('respondQuery', {'queryId': qid, 'code': 'Value correct as per source'})
    check('read-only user cannot respond', not r.get('ok') and 'not permitted' in r.get('error', ''), r)
    state, _ = de_ro.form_page(PID, '1-1', E1, 'text_validation', 1)
    check('same user may respond on a form they can edit', state and state['role']['canRespond'] is True, state and state['role'])
    mon.form_page(PID, '1-1', E1, 'data_types', 1)
    mon.ajax('closeQuery', {'queryId': qid, 'reason': 'test done'})

    # ---- sync is monitor-only ----------------------------------------------------------------------
    html = sync(de_dag)
    check('non-monitor has no sync button', "id='cctc-sdv-sync'" not in html)
    check('non-monitor POST does not sync', 'Sync complete' not in html)
    check('no sync audited for non-monitor',
          sql(f"select count(*) from sdv_audit where project_id={PID} and action='sync' and username='test_de1'")[0][0] == '0')
    check('monitor sees sync button', "id='cctc-sdv-sync'" in mon.module_page(PID, 'dashboard'))

    # ---- sync re-checks complete-form snapshots -----------------------------------------------------
    configure('always')
    import_row('9-1', 1)
    complete('9-1')
    import_row('9-1', 1, text_validation_monstat='3')  # an unverified optional field
    html = sync(mon)
    check('sync: unverified change on complete form -> reverify (always)', form_row('9-1') == 'reverify', (form_row('9-1'), re.findall(r'Sync complete[^<]*', html)))
    configure('verified')

    # ---- deleted record, re-created, found by sync -------------------------------------------------
    complete('9-1')
    delete_record('9-1')
    import_row('9-1', 1)
    html = sync(mon)
    check('sync: re-created record no longer complete', form_row('9-1') in (None, 'pending'), form_row('9-1'))
    check('sync: verified fields of deleted data removed', field_rows('9-1') == 0, field_rows('9-1'))
    check('sync reports cleared form', re.search(r'[1-9]\d* cleared because', html), re.findall(r'Sync complete[^<]*', html))
    check('cleared state audited',
          sql(f"select count(*) from sdv_audit where project_id={PID} and record='9-1' and action='form_state_cleared'")[0][0] != '0')

    # ---- deleted record, re-created, found on form open (open query closed) ----------------------------
    qid = complete('9-1', query_field='email_v2')
    delete_record('9-1')
    import_row('9-1', 1)
    state, _ = mon.form_page(PID, '9-1', E_ARM2, 'text_validation', 1)
    check('form open: re-created record starts pending', state['form']['status'] == 'pending', state['form'])
    check('form open: notice explains reset', any('reset' in n for n in state['notices']), state['notices'])
    check('form open: no verified fields carried over', state['counts']['verified'] == 0, state['counts'])
    q = sql(f"select status from sdv_query where query_id={qid}")[0][0]
    check('open query closed when its data was deleted', q == 'closed', q)

    # ---- deleted without re-creation: sync clears it --------------------------------------------------
    complete('9-1')
    delete_record('9-1')
    sync(mon)
    check('sync: form of deleted record cleared', form_row('9-1') is None and field_rows('9-1') == 0, (form_row('9-1'), field_rows('9-1')))

    # ---- unrelated forms untouched ----------------------------------------------------------------
    import_row('9-2', 1)
    complete('9-2')
    import_row('9-1', 1)
    delete_record('9-1')
    sync(mon)
    check('other record stays complete after a deletion elsewhere', form_row('9-2') == 'complete', form_row('9-2'))
    state, _ = mon.form_page(PID, '9-2', E_ARM2, 'text_validation', 1)
    check('other record still complete on open', state['form']['status'] == 'complete', state['form'])

    logging = admin.get(f'{VER}/Logging/index.php', {'pid': PID})
    check('REDCap Logging has "SDV: status cleared after form data was deleted"', 'SDV: status cleared after form data was deleted' in logging)
    errors = sql(f"select count(*) from redcap_external_modules_log l join redcap_external_modules m on m.external_module_id=l.external_module_id "
                 f"where m.directory_prefix='{PREFIX}' and l.project_id={PID} and l.message like 'Error%'")[0][0]
    check('no module errors logged', errors == '0', errors)
finally:
    sql(f"update redcap_user_rights set api_token=null, record_delete=0 where project_id={PID} and username='test_admin'")
    configure('verified')

print(f"\n{sum(results)}/{len(results)} integrity checks passed")
sys.exit(0 if all(results) else 1)
