"""Concurrent SDV actions on one form instance from separate monitor sessions.
usage: python3 test_concurrency.py <pid> <record> <event_id> [instrument] [instance] [rounds]

Checks that the form row lock holds: parallel query raises on one field create exactly one query, and a form is
never marked SDV complete while a query raised in parallel is open. Uses a pending form instance (one per run).
"""
import sys, threading
from rc import RC, sql

PID, RECORD, EVENT = int(sys.argv[1]), sys.argv[2], int(sys.argv[3])
INSTRUMENT = sys.argv[4] if len(sys.argv) > 4 else 'text_validation'
INSTANCE = int(sys.argv[5]) if len(sys.argv) > 5 else 1
ROUNDS = int(sys.argv[6]) if len(sys.argv) > 6 else 5
CTX = {'record': RECORD, 'eventId': EVENT, 'instrument': INSTRUMENT, 'instance': INSTANCE}
KEY = f"project_id={PID} and record='{RECORD}' and event_id={EVENT} and instrument='{INSTRUMENT}' and instance={INSTANCE}"
SESSIONS = 4
results = []


def check(name, cond, detail=''):
    results.append(bool(cond))
    print(('PASS ' if cond else 'FAIL ') + name + ('' if cond else f'  -- {detail}'))


def parallel(calls):
    """Runs (session, action, payload) calls at the same moment; returns the responses in order."""
    out = [None] * len(calls)
    gate = threading.Barrier(len(calls))

    def run(i, s, action, payload):
        gate.wait()
        try:
            out[i] = s.ajax(action, payload)
        except Exception as e:  # transport failure counts as an unexpected error
            out[i] = {'ok': False, 'error': f'transport: {e}'}
    threads = [threading.Thread(target=run, args=(i, *c)) for i, c in enumerate(calls)]
    for t in threads: t.start()
    for t in threads: t.join()
    return out


def unexpected(resps):
    return [r['error'] for r in resps if not r.get('ok') and ('unexpected error' in r['error'] or r['error'].startswith('transport'))]


def active_queries(field=None):
    extra = f" and field_name='{field}'" if field else ''
    return int(sql(f"select count(*) from sdv_query where {KEY} and status <> 'closed'{extra}")[0][0])


def close_all(s):
    for (qid,) in sql(f"select query_id from sdv_query where {KEY} and status <> 'closed'"):
        s.ajax('closeQuery', {'queryId': int(qid), 'reason': 'concurrency test cleanup'})


mons = [RC('test_user4') for _ in range(SESSIONS)]
state = None
for m in mons:
    state, _ = m.form_page(PID, RECORD, EVENT, INSTRUMENT, INSTANCE)
required = [f for f, i in state['fields'].items() if i['requirement'] == 'required']
check('form has a required field', required, state['fields'].keys())
field = required[0]
lead = mons[0]

for n in range(1, ROUNDS + 1):
    # every session raises a query on the same field at once
    resps = parallel([(m, 'raiseQuery', {'ctx': CTX, 'field': field, 'text': f'round {n} session {i}'}) for i, m in enumerate(mons)])
    oks = sum(1 for r in resps if r.get('ok'))
    check(f'round {n}: one parallel raise succeeds', oks == 1, [r.get('error') for r in resps])
    check(f'round {n}: exactly one open query on the field', active_queries(field) == 1, active_queries(field))
    check(f'round {n}: no unexpected errors (raise)', not unexpected(resps), unexpected(resps))
    close_all(lead)

    # verify everything, then race "complete" against a new query on a required field
    r = lead.ajax('verifyAllRequired', {'ctx': CTX})
    check(f'round {n}: verify all required', r.get('ok'), r)
    resps = parallel([(mons[1], 'completeForm', {'ctx': CTX}), (mons[2], 'raiseQuery', {'ctx': CTX, 'field': field, 'text': f'race {n}'})])
    status = sql(f"select status from sdv_form where {KEY}")[0][0]
    open_q = active_queries()
    check(f'round {n}: never complete with an open query', not (status == 'complete' and open_q > 0), (status, open_q, resps))
    check(f'round {n}: exactly one of complete/raise wins', sum(1 for r in resps if r.get('ok')) == 1, resps)
    check(f'round {n}: no unexpected errors (complete vs raise)', not unexpected(resps), unexpected(resps))

    # back to an open form for the next round
    if status == 'complete':
        lead.ajax('reopenForm', {'ctx': CTX, 'reason': 'concurrency test'})
    close_all(lead)

dup = sql(f"select field_name, count(*) from sdv_query where {KEY} and status <> 'closed' group by field_name having count(*) > 1")
check('no duplicate open queries remain', not dup, dup)
print(f'\n{sum(results)}/{len(results)} concurrency checks passed')
sys.exit(0 if all(results) else 1)
