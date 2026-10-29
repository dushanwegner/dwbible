#!/usr/bin/env python3
"""
WHAT:  Writes data/bible-sources.json, the Bible editions' source notices the
       site's foot and the app's About show, from the factory's table of
       editions (GET /dwfactory/v1/lp/editions, field notice).
WHY:   The site and the app name the same sources, taken from one place
       (BKA23). The factory's table is that place; this file is its snapshot
       in the plugin, and the app copies the file (scripts/sync-bible-sources.mjs).
HOW:   Reads the six rows the site shows, by their factory key. A row without
       a notice in all five languages stops the run and leaves the file as it
       is: a half-written file would put English on a German page.
       --check compares and writes nothing (exit 1 when the file differs).
INPUT: .dwfactory.conf (the first walking up from here, else ~/.dwfactory.conf),
       or --from <a saved answer of GET /lp/editions>.
OUTPUT: data/bible-sources.json
RUN:   python3 tools/pull-bible-sources.py [--check] [--from editions.json]
"""
import argparse, base64, json, os, sys, urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'data', 'bible-sources.json')
LANGS = ['en', 'de', 'es', 'fr', 'it']
# dataset -> (factory key, the short name the closed foot line uses)
EDITIONS = [
    ('latin', 'la-clementine', 'Vulgata Clementina'),
    ('bible', 'en-douay-rheims', 'Douay-Rheims'),
    ('bibel', 'de-menge', 'Menge-Bibel'),
    ('spanish', 'es-scio', 'Scío de San Miguel'),
    ('french', 'fr-crampon', 'Crampon'),
    ('italian', 'it-martini', 'Martini'),
]
ABOUT = ("The Bible editions' source notices, as readers see them on latinprayer.org and in the Latin Prayer app. "
         "One snapshot of the factory's table of editions (GET /dwfactory/v1/lp/editions, field notice), "
         "written by tools/pull-bible-sources.py; never edited by hand. The app copies this file (scripts/sync-bible-sources.mjs).")


def conf():
    d = os.getcwd()
    path = None
    while d != '/':
        if os.path.isfile(os.path.join(d, '.dwfactory.conf')):
            path = os.path.join(d, '.dwfactory.conf'); break
        d = os.path.dirname(d)
    path = path or os.path.expanduser('~/.dwfactory.conf')
    out = {}
    for line in open(path, encoding='utf-8'):
        k, sep, v = line.strip().partition('=')
        if sep and k.strip().isupper():
            out[k.strip()] = v.strip().strip('"')
    return out


def fetch():
    c = conf()
    req = urllib.request.Request(c['DWFACTORY_URL'].rstrip('/') + '/wp-json/dwfactory/v1/lp/editions', headers={
        'Authorization': 'Basic ' + base64.b64encode(f"{c['DWFACTORY_USER']}:{c['DWFACTORY_PASS']}".encode()).decode(),
        'CF-Access-Client-Id': c.get('DWFACTORY_CF_ID', ''),
        'CF-Access-Client-Secret': c.get('DWFACTORY_CF_SECRET', ''),
        'User-Agent': 'agent/1.0 pull-bible-sources',
    })
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.load(r)


def build(answer):
    rows = {r.get('key'): r for r in (answer.get('editions') if isinstance(answer, dict) else answer) or []}
    editions, missing = {}, []
    for ds, key, name in EDITIONS:
        notice = (rows.get(key) or {}).get('notice') or {}
        gaps = [l for l in LANGS if not str(notice.get(l) or '').strip()]
        if gaps:
            missing.append(f"{key}: {','.join(gaps)}")
        editions[ds] = {'key': key, 'name': name, 'notice': {l: ' '.join(str(notice.get(l) or '').split()) for l in LANGS}}
    if missing:
        sys.exit('no notice in the factory for ' + '; '.join(missing) + ' — the file is left as it is')
    return {'about': ABOUT, 'origin': 'factory: GET /dwfactory/v1/lp/editions', 'languages': LANGS, 'editions': editions}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--check', action='store_true')
    ap.add_argument('--from', dest='src')
    a = ap.parse_args()
    answer = json.load(open(a.src, encoding='utf-8')) if a.src else fetch()
    text = json.dumps(build(answer), ensure_ascii=False, indent=2) + '\n'
    old = open(OUT, encoding='utf-8').read() if os.path.exists(OUT) else ''
    if a.check:
        print('same' if old == text else 'differs'); sys.exit(0 if old == text else 1)
    if old != text:
        open(OUT, 'w', encoding='utf-8').write(text)
    print(('wrote ' if old != text else 'unchanged ') + OUT)


if __name__ == '__main__':
    main()
