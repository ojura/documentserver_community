"""Thumbnails of txt, csv, tsv and pdf files, and txt and csv in the editor.

The connector makes its thumbnails through this app's conversion endpoint. That
endpoint used to take every file through Editor.bin first, which x2t cannot
make from a pdf (exit status 88) and will not make from a txt, csv or
tsv without an encoding and, for csv and tsv, a delimiter (89). Those files got
no preview, and the connector logged an error for each. Opening a txt or a csv
in the editor failed for the second reason.

Thumbnails: each file is uploaded, the connector's own preview provider makes
its thumbnail - Nextcloud has providers of its own for txt, so a request for the
preview alone could pass without this app - and the preview Nextcloud serves
for it is fetched the way the file list fetches it. Neither app may log an
error.

Editor: the csv and the txt are opened in a real browser. The editor has to show
ONLYOFFICE's import dialog, which it only does once it has loaded the start of
the file from the url the open was answered with. The test picks the delimiter
and the encoding in the dialog and confirms it, and the document model then has
to show the file's text, decoded, and for the csv split into cells at the
chosen delimiter.
"""
import argparse, asyncio, io, json, os, re, sys

import requests

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'lib'))
import config
import harness
from driver import Session, DISMISS_JS, TEXT_JS

FOLDER = 'rig-textformats'

# sdkjs's numbers: AscCommon.c_oAscCodePageUtf8, AscCommon.c_oAscCsvDelimiter.Semicolon
UTF8, SEMICOLON = 46, 2

ROWS = [('ime', 'vrijednost', 'grad'), ('Šime', '1', 'Zagreb'), ('Đurđa', '2', 'Split')]
TEXT = 'Bilješka: čćžšđ ČĆŽŠĐ'


def table(delimiter):
    return ''.join(delimiter.join(row) + '\n' for row in ROWS).encode('utf-8')


def minimal_pdf():
    """A one-page PDF with a line of text, built by hand so the rig needs no PDF tools."""
    stream = b'BT /F1 24 Tf 72 720 Td (Rig PDF thumbnail) Tj ET'
    objects = [
        b'<< /Type /Catalog /Pages 2 0 R >>',
        b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R '
        b'/Resources << /Font << /F1 5 0 R >> >> >>',
        b'<< /Length %d >>\nstream\n' % len(stream) + stream + b'\nendstream',
        b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ]
    out, offsets = io.BytesIO(), []
    out.write(b'%PDF-1.4\n')
    for number, body in enumerate(objects, 1):
        offsets.append(out.tell())
        out.write(b'%d 0 obj\n' % number + body + b'\nendobj\n')
    xref = out.tell()
    out.write(b'xref\n0 %d\n0000000000 65535 f \n' % (len(objects) + 1))
    for offset in offsets:
        out.write(b'%010d 00000 n \n' % offset)
    out.write(b'trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n' % (len(objects) + 1, xref))
    return out.getvalue()


FILES = {
    'comma.csv': table(','),
    'semicolon.csv': table(';'),
    'tabs.tsv': table('\t'),
    'notes.txt': (TEXT + '\nDruga linija.\n').encode('utf-8'),
    'paper.pdf': minimal_pdf(),
}


def dav(base, user, password, method, path, **kwargs):
    url = f'{base}/remote.php/dav/files/{user}/{path}'
    return requests.request(method, url, auth=(user, password), timeout=60, **kwargs)


def upload(base, user, password):
    """Upload the files and return their file ids by name."""
    dav(base, user, password, 'DELETE', FOLDER)
    dav(base, user, password, 'MKCOL', FOLDER).raise_for_status()
    ids = {}
    for name, content in FILES.items():
        dav(base, user, password, 'PUT', f'{FOLDER}/{name}', data=content).raise_for_status()
        found = dav(base, user, password, 'PROPFIND', f'{FOLDER}/{name}', headers={'Depth': '0'},
                    data='<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
                         '<d:prop><oc:fileid/></d:prop></d:propfind>')
        ids[name] = int(re.search(r'<oc:fileid>(\d+)</oc:fileid>', found.text).group(1))
    return ids


PROVIDER_PHP = r"""<?php
\OC::$server->get(\OCP\App\IAppManager::class)->loadApp('onlyoffice');
$folder = \OC::$server->get(\OCP\Files\IRootFolder::class)->getUserFolder(%s)->get(%s);
$provider = \OC::$server->get(\OCA\Onlyoffice\Preview::class);
$out = [];
foreach ($folder->getDirectoryListing() as $file) {
	if (!$provider->isAvailable($file)) {
		$out[$file->getName()] = 'not available';
		continue;
	}
	$image = $provider->getThumbnail($file, 256, 256);
	$out[$file->getName()] = ($image && $image->valid()) ? $image->width() . 'x' . $image->height() : 'no thumbnail';
}
echo json_encode($out);
"""


def connector_errors():
    """Error-level lines the connector logged, which is where failed conversions go."""
    out = harness.app_exec(
        "grep -o '\"level\":[34][^\\n]*\"app\":\"onlyoffice\"[^\\n]*' "
        "/var/www/html/data/nextcloud.log | tail -20 || true")
    return [line for line in out.split('\n') if line.strip()]


def check_thumbnails(args, user, password, ids):
    ok = True
    print('\n==> thumbnails through the connector\'s preview provider')
    raw = harness.php_eval(PROVIDER_PHP % (json.dumps(user), json.dumps(FOLDER)))
    try:
        made = json.loads(raw[raw.index('{'):])
    except ValueError:
        print('   FAIL - the provider did not answer:', raw[:300])
        return False
    for name in FILES:
        result = made.get(name)
        good = bool(result) and re.fullmatch(r'\d+x\d+', result) is not None
        print(f'   {name:14s} {result}')
        if not good:
            ok = False
            print(f'   FAIL - no thumbnail for {name}')

    print('\n==> the previews Nextcloud serves, as the file list asks for them')
    for name, fileid in ids.items():
        r = requests.get(f'{args.base}/index.php/core/preview', auth=(user, password), timeout=120,
                         params={'fileId': fileid, 'x': 256, 'y': 256, 'a': 'true', 'forceIcon': '0'})
        kind = r.headers.get('Content-Type', '')
        print(f'   {name:14s} HTTP {r.status_code} {kind} {len(r.content)} bytes')
        if r.status_code != 200 or not kind.startswith('image/'):
            ok = False
            print(f'   FAIL - Nextcloud has no preview for {name}')
    return ok


DIALOG_JS = r"""(() => {
  // ONLYOFFICE's import dialog (Common.Views.OpenDialog): which of its
  // controls are showing. The dialog is position: fixed, which makes
  // offsetParent null even while it is on screen, so this asks for its box.
  try {
    const d = document.querySelector('iframe').contentDocument;
    const shown = el => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
    const win = [...d.querySelectorAll('.asc-window')].find(w => shown(w)
      && (w.querySelector('#id-codepages-combo') || w.querySelector('#id-delimiters-combo')));
    if (!win) return null;
    return JSON.stringify({
      encoding: !!win.querySelector('#id-codepages-combo'),
      delimiter: !!win.querySelector('#id-delimiters-combo'),
      preview: ((win.querySelector('#id-preview-data') || {}).innerText || '').slice(0, 80),
    });
  } catch (e) { return 'err: ' + e; }
})()"""

PICK_JS = r"""(() => {
  // Choose an item of one of the dialog's combo boxes, the way a click does.
  try {
    const d = document.querySelector('iframe').contentDocument;
    const combo = d.querySelector(%s);
    if (!combo) return 'no combo';
    const item = combo.querySelector('li[data-value="' + %s + '"] a');
    if (!item) return 'no item; values: ' + [...combo.querySelectorAll('li')].map(l => l.dataset.value).slice(0, 12).join(',');
    item.click();
    return 'picked ' + item.innerText.trim();
  } catch (e) { return 'err: ' + e; }
})()"""

OK_JS = r"""(() => {
  try {
    const d = document.querySelector('iframe').contentDocument;
    const shown = el => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
    const ok = [...d.querySelectorAll('.asc-window .dlg-btn[result="ok"]')].find(shown);
    if (!ok) return 'no OK button';
    ok.click();
    return 'confirmed';
  } catch (e) { return 'err: ' + e; }
})()"""


async def wait_for(session, js, timeout):
    for _ in range(int(timeout / 2)):
        value = await session.eval(js)
        if value:
            return value
        await session.drain(2)
    return None


async def check_editor(args, user, password, name, fileid, picks, expected):
    print(f'\n==> {name} in the editor (fileid {fileid})')
    harness.reset()
    ok = True
    session = Session('A', 9222, user, password, args.base, fileid, args.chromium, (420, 400))
    try:
        await session.start()
        await session.login_and_open()
        await session.drain(10)
        await session.eval(DISMISS_JS)

        dialog = await wait_for(session, DIALOG_JS, args.load_wait)
        print('   import dialog:', dialog)
        if not dialog or not str(dialog).startswith('{'):
            print('   FAIL - the editor did not ask for the encoding and delimiter')
            return False

        for selector, value in picks:
            print(f'   {selector}:', await session.eval(PICK_JS % (json.dumps(selector), json.dumps(str(value)))))
        print('   ', await session.eval(OK_JS))

        missing = list(expected)
        for _ in range(int(args.load_wait / 3)):
            await session.drain(3)
            shown = str(await session.eval(TEXT_JS))
            missing = [e for e in expected if e not in shown]
            if not missing:
                break
        print('   document:', shown[:200])
        if missing:
            ok = False
            print(f'   FAIL - the document does not show {missing}')
    finally:
        await session.stop()

    harness.drop_sessions()
    code, out = harness.flush()
    print(f'   flush: exit={code} {out[:60]}')
    if code != 0:
        ok = False
        print('   FAIL - documentserver:flush reported an error')
    return ok


async def main():
    p = argparse.ArgumentParser()
    p.add_argument('--base', default=config.BASE)
    p.add_argument('--a', default=config.ADMIN)
    p.add_argument('--chromium', default=config.CHROMIUM)
    p.add_argument('--load-wait', type=float, default=45)
    args = p.parse_args()

    user, password = config.credentials(args.a)
    harness.reset()
    ids = upload(args.base, user, password)
    print('uploaded:', ids)

    results = {'thumbnails': check_thumbnails(args, user, password, ids)}
    results['semicolon.csv'] = await check_editor(
        args, user, password, 'semicolon.csv', ids['semicolon.csv'],
        [('#id-delimiters-combo', SEMICOLON), ('#id-codepages-combo', UTF8)],
        ['A1=ime', 'B1=vrijednost', 'C1=grad', 'A2=Šime', 'A3=Đurđa', 'C3=Split'])
    results['notes.txt'] = await check_editor(
        args, user, password, 'notes.txt', ids['notes.txt'],
        [('#id-codepages-combo', UTF8)],
        [TEXT, 'Druga linija.'])

    for label, errors in (('this app', harness.app_log_errors()), ('the connector', connector_errors())):
        if errors:
            results[f'log ({label})'] = False
            print(f'\n   FAIL - {label} logged errors:')
            for line in errors[:5]:
                print('     ', line[:240])

    dav(args.base, user, password, 'DELETE', FOLDER)
    left = harness.doc_folders()
    if left:
        results['document folders'] = False
        print(f'   FAIL - {left} document folder(s) left behind after the flush')

    print('\n   VERDICT:', ' '.join(f'{k}={"ok" if v else "FAIL"}' for k, v in results.items()))
    return 0 if all(results.values()) else 1


if __name__ == '__main__':
    sys.exit(asyncio.run(main()))
