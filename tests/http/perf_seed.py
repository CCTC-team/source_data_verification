"""Seeds N records into a RepeatingEvents_DoubleArm_withDAGs project. usage: python3 perf_seed.py <pid> <n>"""
import csv, io, json, random, ssl, sys, urllib.parse, urllib.request
from rc import sql, BASE

PID, N = int(sys.argv[1]), int(sys.argv[2])
random.seed(1)
cols = ['record_id', 'redcap_event_name', 'redcap_repeat_instrument', 'redcap_repeat_instance', 'redcap_data_access_group',
        'text_validation_crfver', 'ptname_v2', 'email_v2', 'text_validation_monstat', 'text_validation_complete',
        'data_types_crfver', 'ptname', 'notesbox', 'multiple_dropdown_auto', 'radio_button_manual',
        'checkbox___1', 'checkbox___2', 'checkbox___3', 'data_types_monstat', 'data_types_complete']


def row(rec, event, inst, dag):
    return {'record_id': rec, 'redcap_event_name': event, 'redcap_repeat_instrument': '', 'redcap_repeat_instance': inst,
            'redcap_data_access_group': dag, 'text_validation_crfver': '1', 'ptname_v2': f'Name {rec}', 'email_v2': f'{rec}@x.org',
            'text_validation_monstat': '2', 'text_validation_complete': '2', 'data_types_crfver': '1', 'ptname': f'P {rec}',
            'notesbox': 'notes ' * 5, 'multiple_dropdown_auto': '1', 'radio_button_manual': '100',
            'checkbox___1': '1', 'checkbox___2': str(random.randint(0, 1)), 'checkbox___3': '0', 'data_types_monstat': '2',
            'data_types_complete': '1'}


tok = 'PERF' + 'C' * 28
sql(f"update redcap_user_rights set api_token='{tok}' where project_id={PID} and username='test_admin'")
c = ssl.create_default_context(); c.check_hostname = False; c.verify_mode = ssl.CERT_NONE
try:
    for start in range(0, N, 500):
        buf = io.StringIO(); w = csv.DictWriter(buf, cols); w.writeheader()
        for i in range(start, min(N, start + 500)):
            rec, dag = f'P{i:05d}', ('dag1' if i % 2 else 'dag2')
            for inst in (1, 2, 3):
                w.writerow(row(rec, 'event_1_arm_1', inst, dag))
            w.writerow(row(rec, 'event_2_arm_1', '', dag))
        body = urllib.parse.urlencode({'token': tok, 'content': 'record', 'format': 'csv', 'type': 'flat', 'data': buf.getvalue(),
                                       'overwriteBehavior': 'normal'}).encode()
        out = urllib.request.urlopen(urllib.request.Request(BASE + 'api/', data=body), context=c, timeout=600).read().decode()
        print(start, out[:80], flush=True)
finally:
    sql(f"update redcap_user_rights set api_token=null where project_id={PID} and username='test_admin'")
dt = sql(f"select data_table from redcap_projects where project_id={PID}")[0][0]
print('data rows:', sql(f"select count(*) from {dt} where project_id={PID}")[0][0])
