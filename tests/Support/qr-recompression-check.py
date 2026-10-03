#!/usr/bin/env python3
"""
Does the flier's QR decode at all after an image pipeline has been through it?

    pip install opencv-python-headless numpy
    python3 tests/Support/qr-recompression-check.py

WHAT THIS IS, AND WHAT IT IS NOT
────────────────────────────────
It is a SMOKE CHECK. It renders each payload the flier mints, at the module size the layout
actually uses, pastes it onto a 1080-wide ground, downscales and JPEGs it twice, and asks
OpenCV to read it back. If that comes out clean, the encoder and the geometry are at least
self-consistent and a scanner has something to work with.

It is NOT a way to choose a quiet zone or a module size, and it was built believing it was.

    The first version of this reported that a 2-module quiet zone decoded when "sent" and
    failed when "forwarded" while the 4-module zone survived both — which is exactly the
    defect the design handoff warned about, so it looked like a confirmation.

    It was an artefact. Shifting the plate by ONE PIXEL flips pass to fail, and at some
    offsets the 2-module zone decodes where the 4-module one does not. What varies is how
    `cv2.resize` and JPEG's 8×8 blocks happen to land on the module grid, against one
    detector's thresholding. Nothing about that generalises.

    A real scanner samples continuously through a camera, at an angle, under its own
    exposure, with adaptive thresholding. None of it is modelled here.

So the four-module quiet zone in EventFlierLayout stands on the QR SPECIFICATION — the zone
is part of the symbol, not a margin around it — which was always the sufficient reason. And
the handoff's first verify item, scanning every format after a real WhatsApp round trip on a
real handset, is still open and this cannot close it.

Run it when the encoder or the layout changes. Read a failure as "look at this", not as a
threshold.
"""
import json, os, subprocess, sys

try:
    import numpy as np, cv2
except ImportError:
    sys.exit('needs: pip install opencv-python-headless numpy')

HERE = os.path.dirname(os.path.abspath(__file__))

# The payload shapes the flier mints, at the module sizes EventFlierLayout::QR uses.
CASES = {
    'story  · confirmed': ('https://afg.afrovanguard.org.ng/events/gala-2026?ref=AB12CD&c=flier', 7),
    'square · confirmed': ('https://afg.afrovanguard.org.ng/events/gala-2026?ref=AB12CD&c=flier', 6),
    'plain  · confirmed': ('https://afg.afrovanguard.org.ng/events/gala-2026?ref=AB12CD&c=flier', 6),
    'story  · open':      ('https://afg.afrovanguard.org.ng/events/gala-2026?c=flier', 7),
    'plain  · long slug': ('https://afg.afrovanguard.org.ng/events/continental-gala-2026?c=flier', 6),
}

open('/tmp/qr-recompress-cases.json', 'w').write(json.dumps([v[0] for v in CASES.values()]))
matrices = json.loads(subprocess.check_output(
    ['php', os.path.join(HERE, 'qr-bytes-dump.php'), '/tmp/qr-recompress-cases.json']).decode())

det = cv2.QRCodeDetector()


def plate(rows, module, quiet_modules):
    """The code on its own white plate, exactly as EventFlier draws it."""
    n = len(rows)
    quiet = module * quiet_modules
    side = n * module + quiet * 2
    img = np.full((side, side), 255, np.uint8)
    for r, row in enumerate(rows):
        for c, ch in enumerate(row):
            if ch == '1':
                y, x = r * module + quiet, c * module + quiet
                img[y:y + module, x:x + module] = 0
    return img


def survives(rows, module, text, offset):
    """Two rounds of downscale-and-JPEG, from one plate offset."""
    qr = plate(rows, module, 4)
    page = np.full((2400, 1080), 255, np.uint8)
    page[1400 + offset:1400 + offset + qr.shape[0], 80 + offset:80 + offset + qr.shape[1]] = qr

    cur = page
    for _ in (1, 2):
        small = cv2.resize(cur, (0, 0), fx=0.75, fy=0.75, interpolation=cv2.INTER_AREA)
        _, buf = cv2.imencode('.jpg', small, [cv2.IMWRITE_JPEG_QUALITY, 50])
        cur = cv2.imdecode(buf, cv2.IMREAD_GRAYSCALE)
    got, _, _ = det.detectAndDecode(cur)
    return got == text


# Several offsets per case, and the result reported as "how many of them decoded". One
# offset is a coin toss — that is the whole finding above — so a single number is the only
# reading that means anything, and it still means "there is signal here", not a threshold.
OFFSETS = range(0, 6)
weak = []

print(f'{"case":22} {"ver":>4} {"module":>7} {"decoded":>18}')
for label, (text, module) in CASES.items():
    rows = matrices.get(text)
    if rows is None:
        print(f'{label:22} {"—":>4} {module:7}   REFUSED BY THE ENCODER')
        weak.append(label)
        continue

    ok = sum(1 for o in OFFSETS if survives(rows, module, text, o))
    ver = (len(rows) - 17) // 4
    print(f'{label:22} {ver:4} {module:7} {ok:10}/{len(OFFSETS)} offsets')
    # Under half is worth a person looking; it is not proof of anything on its own.
    if ok * 2 < len(OFFSETS):
        weak.append(label)

print()
if weak:
    print('WORTH A LOOK — decoded at under half the offsets: ' + ', '.join(weak))
    print('Read this as "check the encoder and the module size by hand", not as a threshold.')
else:
    print('Every payload decoded at most offsets. The encoder and the geometry agree.')
