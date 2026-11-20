import json, math

SRC = 'ne110.json'
OUT = '/workspace/africa-gates/motion/src/africa.ts'
BOX = 1000.0          # square viewBox the paths are fitted into
EPS = 0.28            # Douglas-Peucker tolerance, in degrees

d = json.load(open(SRC))
feats = [f for f in d['features'] if f['properties'].get('CONTINENT') == 'Africa']

def rings(geom):
    if geom['type'] == 'Polygon':   return [geom['coordinates'][0]]
    if geom['type'] == 'MultiPolygon': return [p[0] for p in geom['coordinates']]
    return []

def rdp(pts, eps):
    if len(pts) < 3: return pts
    def dist(p, a, b):
        (x,y),(x1,y1),(x2,y2) = p,a,b
        dx,dy = x2-x1, y2-y1
        if dx == 0 and dy == 0: return math.hypot(x-x1, y-y1)
        t = max(0, min(1, ((x-x1)*dx + (y-y1)*dy) / (dx*dx+dy*dy)))
        return math.hypot(x-(x1+t*dx), y-(y1+t*dy))
    dmax, idx = 0.0, 0
    for i in range(1, len(pts)-1):
        dd = dist(pts[i], pts[0], pts[-1])
        if dd > dmax: dmax, idx = dd, i
    if dmax > eps:
        return rdp(pts[:idx+1], eps)[:-1] + rdp(pts[idx:], eps)
    return [pts[0], pts[-1]]

# ── bounds over everything we keep, so all countries share one projection ────
allpts = [p for f in feats for r in rings(f['geometry']) for p in r]
lons = [p[0] for p in allpts]; lats = [p[1] for p in allpts]
lon0, lon1 = min(lons), max(lons)
lat0, lat1 = min(lats), max(lats)
midlat = math.radians((lat0+lat1)/2)
# Equirectangular with a cos(mid-lat) correction: without it Africa reads
# stretched east-west, which is the tell of a map drawn by somebody not looking.
kx = math.cos(midlat)
w = (lon1-lon0)*kx
h = (lat1-lat0)
scale = BOX / max(w, h)
offx = (BOX - w*scale)/2
offy = (BOX - h*scale)/2

def proj(lon, lat):
    x = (lon-lon0)*kx*scale + offx
    y = (lat1-lat)*scale + offy      # north up
    return round(x, 1), round(y, 1)

def path_of(rs):
    out = []
    for r in rs:
        r = rdp([tuple(p[:2]) for p in r], EPS)
        if len(r) < 3: continue
        pts = [proj(lon, lat) for lon, lat in r]
        out.append('M' + 'L'.join(f'{x},{y}' for x, y in pts) + 'Z')
    return ''.join(out)

def centroid(rs):
    # area-weighted centroid of the largest ring — good enough to place a dot,
    # and immune to a country whose islands would drag a naive mean offshore.
    best, ba = None, -1
    for r in rs:
        pts = [proj(lon, lat) for lon, lat in [tuple(p[:2]) for p in r]]
        a = abs(sum(pts[i][0]*pts[i-1][1] - pts[i-1][0]*pts[i][1] for i in range(len(pts)))) / 2
        if a > ba: ba, best = a, pts
    if not best: return (0.0, 0.0)
    n = len(best)
    return (round(sum(p[0] for p in best)/n, 1), round(sum(p[1] for p in best)/n, 1))

rows = []
for f in sorted(feats, key=lambda f: f['properties']['NAME']):
    p = f['properties']
    rs = rings(f['geometry'])
    dpath = path_of(rs)
    if not dpath: continue
    cx, cy = centroid(rs)
    rows.append({
        'id':   p.get('ISO_A3') if p.get('ISO_A3') not in (None, '-99') else p.get('ADM0_A3'),
        'name': p.get('NAME'),
        'd':    dpath,
        'cx':   cx, 'cy': cy,
    })

def lit(s): return json.dumps(s, ensure_ascii=False)

with open(OUT, 'w') as fh:
    fh.write(f'''/**
 * Africa, as real geometry — generated, not drawn by hand.
 *
 * Source: Natural Earth 1:110m Admin 0 countries (public domain), filtered to
 * CONTINENT === "Africa", simplified with Douglas-Peucker at {EPS}° and projected
 * equirectangular with a cos(mid-latitude) correction into a {int(BOX)}x{int(BOX)} box.
 *
 * WHY GEOMETRY AND NOT A TILE LAYER: every tile host this project can reach is
 * blocked at the egress proxy, and Mapbox/MapTiler want a key. It turns out to be
 * the better answer anyway — a hairline vector outline is the look this piece
 * wants, and a satellite plate would fight the type sitting on top of it.
 *
 * REGENERATE: scripts/gen-africa.py in the repo root reads the Natural Earth
 * GeoJSON and rewrites this file. Do not edit the coordinates by hand.
 */
export type Country = {{
  /** ISO 3166-1 alpha-3. */
  id: string;
  name: string;
  /** SVG path data in the {int(BOX)}x{int(BOX)} box. */
  d: string;
  /** Area-weighted centroid of the largest ring, same coordinate space. */
  cx: number;
  cy: number;
}};

export const BOX = {int(BOX)};

export const AFRICA: Country[] = [
''')
    for r in rows:
        fh.write(f"  {{id: {lit(r['id'])}, name: {lit(r['name'])}, cx: {r['cx']}, cy: {r['cy']}, d: {lit(r['d'])}}},\n")
    fh.write('];\n\nexport const BY_ID: Record<string, Country> = Object.fromEntries(AFRICA.map((c) => [c.id, c]));\n')

print(f'{len(rows)} countries -> {OUT}')
import os; print('bytes', os.path.getsize(OUT))
