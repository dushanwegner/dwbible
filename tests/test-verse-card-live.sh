#!/usr/bin/env bash
# test-verse-card-live.sh — a shared Bible verse shows a picture of that verse.
#
# WHAT:  Fetches verse URLs the way a link-preview crawler does and asserts the picture:
#          - the page carries exactly ONE og:image, and twitter:card=summary_large_image
#          - that og:image is THIS page's own URL with the OG flag, so the picture follows the
#            reader's language prefix instead of pointing at some other verse
#          - fetching it answers 200 image/png, big enough to be a real drawing (> 5 KB)
#          - a CHAPTER page, which has no picture of its own, does not claim the large card
# WHY:   dwsocial's test-site-card-live.sh asserts only that a large card is BACKED by an image,
#        so a verse with no picture at all passed it happily — which is how the per-verse card sat
#        dead in production while every instrument read green (issue 65, found 2026-09-20). The
#        failure is invisible in a browser: it shows only in the chat app of whoever got the link.
# INPUT: URLs as arguments; none = the production verse set, in three languages plus a range and
#        a chapter page. Against a local site pass its URLs.
# OUTPUT: ok / FAIL lines per URL; exit 0 when every URL passes.
# DEPENDS: curl, python3.
# RUN:   tests/test-verse-card-live.sh [URL ...]
# TESTED BY: itself — it is the guard. It goes red against any build where the verse card has no
#        picture; that is exactly the state it was written against.
set -uo pipefail

UA='facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'
if [ "$#" -eq 0 ]; then
  set -- "https://latinprayer.org/en/biblia/ioannes/3:16/" \
         "https://latinprayer.org/de/biblia/ioannes/3:16/" \
         "https://latinprayer.org/es/biblia/galatas/5:25-26/" \
         "https://latinprayer.org/en/biblia/ioannes/3/"
fi

exec python3 - "$UA" "$@" <<'PY'
import re
import subprocess
import sys
from html.parser import HTMLParser

ua, urls = sys.argv[1], sys.argv[2:]
failed = 0


def ok(cond, msg):
    global failed
    print(('  ok   ' if cond else '  FAIL ') + msg)
    if not cond:
        failed += 1


def curl(url, keep_body=True):
    """GET like a preview crawler. Returns (status, bytes, content-type, final url, body)."""
    out = subprocess.run(
        ['curl', '-skL', '--max-time', '30', '-A', ua, '-o', '-' if keep_body else '/dev/null',
         '-w', '\n__DW__%{http_code} %{size_download} %{content_type} %{url_effective}', url],
        capture_output=True).stdout.decode('utf-8', 'replace')
    body, _, tail = out.rpartition('\n__DW__')
    parts = (tail.split(' ', 3) + ['0', '0', '', ''])[:4]
    return int(parts[0] or 0), int(float(parts[1] or 0)), parts[2].strip(), parts[3].strip(), body


class Head(HTMLParser):
    """(key, content) of every <meta property=|name=> before <body>."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.meta, self.in_body = [], False

    def handle_starttag(self, tag, attrs):
        if tag == 'body':
            self.in_body = True
        if tag == 'meta' and not self.in_body:
            a = dict(attrs)
            key = (a.get('property') or a.get('name') or '').lower()
            if key:
                self.meta.append((key, a.get('content') or ''))

    handle_startendtag = handle_starttag


# A verse URL ends in chapter:verse (or a range); a chapter URL ends in the chapter alone.
VERSE = re.compile(r'/\d+[:,]\d+(-\d+)?/?$')

for url in urls:
    status, _, _, final, html = curl(url)
    is_verse = bool(VERSE.search(final.split('?')[0]))
    print(f'{url}  ->  {status}  ({"verse" if is_verse else "chapter/other"})')
    ok(status == 200, 'answers 200')
    if status != 200:
        continue

    head = Head()
    head.feed(html)

    def values(key):
        return [v for k, v in head.meta if k == key]

    images = values('og:image')
    card = (values('twitter:card') or [''])[0]

    if not is_verse:
        # No picture of its own: the honest card is the small one.
        ok(card != 'summary_large_image' or bool(images),
           f'no empty large card (twitter:card "{card}", {len(images)} og:image)')
        continue

    ok(len(images) == 1, f'exactly one og:image ({len(images)})')
    ok(card == 'summary_large_image', f'twitter:card is summary_large_image (got "{card}")')
    if not images:
        continue

    # The picture must be THIS page's own address plus the flag — not another verse's, and not
    # the site's fallback picture, either of which would preview the wrong thing.
    page = (values('og:url') or [final])[0].split('?')[0]
    ok(images[0].split('?')[0].rstrip('/') == page.rstrip('/'),
       f'og:image is this page + the OG flag: {images[0][:95]}')
    ok('dwbible_og' in images[0], 'og:image carries the dwbible_og flag')

    istatus, isize, itype, _, _ = curl(images[0], keep_body=False)
    ok(istatus == 200, f'og:image answers 200 (got {istatus})')
    ok(itype.startswith('image/png'), f'og:image is a PNG (got "{itype}")')
    ok(isize > 5 * 1024, f'og:image is a real drawing, not an empty frame ({isize // 1024} KB)')
    ok(isize < 1024 * 1024, f'og:image is under 1 MB ({isize // 1024} KB)')

print(f'\n{"OK" if not failed else str(failed) + " FAILED"} ({len(urls)} URLs)')
sys.exit(1 if failed else 0)
PY
