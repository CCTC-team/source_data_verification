"""Minimal REDCap HTTP client for end-to-end testing the SDV module against the local Docker stack."""
import http.cookiejar, json, re, ssl, subprocess, urllib.parse, urllib.request, uuid

BASE = 'https://localhost:8443/'
VER = 'redcap_v14.7.0'
PREFIX = 'source_data_verification'
CTX = ssl.create_default_context(); CTX.check_hostname = False; CTX.verify_mode = ssl.CERT_NONE


def sql(q):
    out = subprocess.run(['docker', 'exec', 'CCTC_REDCap_Docker', 'mysql', '-uroot', '-proot', 'redcap', '-N', '-B', '-e', q],
                         capture_output=True, text=True)
    if out.returncode:
        raise RuntimeError(out.stderr)
    return [line.split('\t') for line in out.stdout.strip().split('\n') if line]


def multipart(fields):
    boundary = uuid.uuid4().hex
    body = b''
    for k, v in fields.items():
        body += f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n'.encode() + str(v).encode() + b'\r\n'
    body += f'--{boundary}--\r\n'.encode()
    return body, f'multipart/form-data; boundary={boundary}'


class RC:
    def __init__(self, username, password='Testing123'):
        self.username = username
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), urllib.request.HTTPSHandler(context=CTX))
        self.csrf = None
        page = self.get('')
        token = re.search(r"name='redcap_login_a38us_09i85' value='([^']*)'", page).group(1)
        page = self.post('', {'username': username, 'password': password, 'submitted': '1', 'redcap_login_a38us_09i85': token}, csrf=False)
        if 'name=\'password\'' in page and 'Log out' not in page:
            raise RuntimeError(f'login failed for {username}')

    def _open(self, req):
        with self.opener.open(req, timeout=120) as r:
            text = r.read().decode('utf-8', 'replace')
        m = re.search(r"redcap_csrf_token\s*=\s*'([^']+)'", text)
        if m:
            self.csrf = m.group(1)
        return text

    def get(self, path, params=None):
        url = BASE + path + ('?' + urllib.parse.urlencode(params) if params else '')
        return self._open(urllib.request.Request(url))

    def post(self, path, data, params=None, csrf=True):
        data = dict(data)
        if csrf and self.csrf:
            data['redcap_csrf_token'] = self.csrf
        url = BASE + path + ('?' + urllib.parse.urlencode(params) if params else '')
        return self._open(urllib.request.Request(url, data=urllib.parse.urlencode(data).encode()))

    # ---- module helpers ------------------------------------------------------------------------

    def form_page(self, pid, record, event_id, instrument, instance=1):
        html = self.get(f'{VER}/DataEntry/index.php', {'pid': pid, 'id': record, 'event_id': event_id, 'page': instrument, 'instance': instance})
        self.page_html = html
        m = re.search(r'const ajaxSettings = (\{.*?\});', html)
        self.ajax_settings = json.loads(m.group(1)) if m else None
        m = re.search(r'ajaxSettings\.csrfToken = ("[^"]*");', html)
        if m and self.ajax_settings is not None:
            self.ajax_settings['csrfToken'] = json.loads(m.group(1))
        m = re.search(r'CctcSdvForm\.init\([^,]+, (\{.*?\}), (\{.*?\})\);</script>', html, re.S)
        return (json.loads(m.group(1)), json.loads(m.group(2))) if m else (None, None)

    def module_page(self, pid, page, params=None, post=None):
        p = {'prefix': PREFIX, 'page': page, 'pid': pid}
        p.update(params or {})
        html = self.post(f'{VER}/ExternalModules/', post, p) if post is not None else self.get(f'{VER}/ExternalModules/', p)
        m = re.search(r'const ajaxSettings = (\{.*?\});', html)
        if m:
            self.ajax_settings = json.loads(m.group(1))
            m2 = re.search(r'ajaxSettings\.csrfToken = ("[^"]*");', html)
            if m2: self.ajax_settings['csrfToken'] = json.loads(m2.group(1))
        return html

    def ajax(self, action, payload):
        s = self.ajax_settings
        body, ctype = multipart({'verification': s['verification'], 'action': action, 'payload': json.dumps(payload),
                                 'redcap_external_module_csrf_token': s['csrfToken'], 'redcap_csrf_token': s['csrfToken']})
        req = urllib.request.Request(s['endpoint'] if s['endpoint'].startswith('http') else BASE.rstrip('/') + s['endpoint'],
                                     data=body, headers={'Content-Type': ctype})
        with self.opener.open(req, timeout=120) as r:
            resp = json.loads(r.read().decode())
        if not resp.get('success'):
            raise RuntimeError(f'ajax transport error: {resp}')
        s['verification'] = resp['verification']
        return resp.get('payload')


def api(token, **fields):
    data = urllib.parse.urlencode(dict(token=token, returnFormat='json', **fields)).encode()
    with urllib.request.urlopen(urllib.request.Request(BASE + 'api/', data=data), context=CTX, timeout=300) as r:
        return r.read().decode()
