"""Creates an SDV test project from a monitoring_qr fixture and configures the module.
usage: python3 setup_project.py <fixture-name> <title> [trigger]"""
import json, secrets, sys
from rc import RC, api, sql, PREFIX, VER

import os
# project-type fixtures are shared with the Monitoring QR module, a sibling directory under redcap_source/modules
FIX = os.path.join(os.path.dirname(os.path.abspath(__file__)), '../../../monitoring_qr_v1.1.0/automated_tests/fixtures')
name, title = sys.argv[1], sys.argv[2]
trigger = sys.argv[3] if len(sys.argv) > 3 else 'verified'

admin = RC('test_admin')
admin.get(f'{VER}/ExternalModules/manager/control_center.php')

# system enable (idempotent)
if not sql(f"select 1 from redcap_external_modules m join redcap_external_module_settings s on s.external_module_id=m.external_module_id "
           f"where m.directory_prefix='{PREFIX}' and s.`key`='version' and s.project_id is null"):
    print('system enable:', admin.post(f'{VER}/ExternalModules/manager/ajax/enable-module.php', {'prefix': PREFIX, 'version': 'v1.0.0'})[:200])

super_token = secrets.token_hex(32).upper()
sql(f"update redcap_user_information set api_token='{super_token}' where username='test_admin'")
try:
    odm = open(f'{FIX}/cdisc_files/ProjectTypes/{name}.xml').read()
    token = api(super_token, content='project', format='json', data=json.dumps([{'project_title': title, 'purpose': 0}]), odm=odm).strip()
finally:
    sql("update redcap_user_information set api_token=null where username='test_admin'")
pid = int(sql(f"select project_id from redcap_user_rights where api_token='{token}'")[0][0])
print('project', pid)

csv = open(f'{FIX}/import_files/{name}.csv').read()
print('import:', api(token, content='record', format='csv', type='flat', data=csv, overwriteBehavior='normal'))

roles = {r['role_label']: r['unique_role_name'] for r in json.loads(api(token, content='userRole', format='json'))}
users = {'test_user4': 'Monitor', 'test_user1': 'DataEntryPI', 'test_de1': 'DataEntryPI', 'test_dm': 'DataManager', 'test_user2': 'DataEntry'}
print('users:', api(token, content='user', format='json', data=json.dumps([{'username': u} for u in users])))
print('roles:', api(token, content='userRoleMapping', format='json',
                    data=json.dumps([{'username': u, 'unique_role_name': roles[r]} for u, r in users.items()])))
dags = json.loads(api(token, content='dag', format='json'))
if dags:
    print('dag:', api(token, content='userDagMapping', format='json',
                      data=json.dumps([{'username': 'test_de1', 'redcap_data_access_group': dags[0]['unique_group_name']}])))
sql(f"update redcap_projects set data_resolution_enabled=2 where project_id={pid}")
sql(f"update redcap_user_rights set api_token=null where project_id={pid} and username='test_admin'")

admin.get(f'{VER}/ProjectSetup/index.php', {'pid': pid})
print('project enable:', admin.post(f'{VER}/ExternalModules/manager/ajax/enable-module.php', {'prefix': PREFIX, 'version': 'v1.0.0'}, {'pid': pid})[:200])

role_id = {r: sql(f"select role_id from redcap_user_roles where project_id={pid} and role_name='{r}'")[0][0]
           for r in ['Monitor', 'DataEntryPI', 'DataEntry', 'DataManager']}
settings = {
    'monitor-roles____0': role_id['Monitor'],
    'data-entry-roles____0': role_id['DataEntryPI'], 'data-entry-roles____1': role_id['DataEntry'],
    'data-manager-roles____0': role_id['DataManager'],
    'sdv-flag-regex': '@ENDPOINT-\\w+', 'sdv-ignore-action-tag': '@NOTMONITORED',
    'trigger-reverification': trigger, 'monitor-show-inline': True, 'data-entry-show-inline': True,
}
print('config:', admin.post(f'{VER}/ExternalModules/manager/ajax/save-settings.php', {'settings': json.dumps(settings)},
                             {'pid': pid, 'moduleDirectoryPrefix': PREFIX})[:200])
open(f'pid_{name}.txt', 'w').write(str(pid))
