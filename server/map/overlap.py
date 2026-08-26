#!/usr/bin/env python3
"""
How much of our route is on the same roads a reference router picked.

Comparing total distance hides more than it shows: two routes can differ by
one per cent in length and be on different sides of a city. This walks both
lines at fifty metre steps and asks what fraction of ours has a reference
point within a hundred metres - which is "the same road" at this scale - and
then names the stretches where it does not.

That is the number worth tracking. Where the routes agree, our cost model is
doing the same thing as one built by people who do this full time; where they
part company is the list of things to look at.
"""
import json, math, subprocess, sys, os, urllib.request, urllib.error, time

MAP = os.path.dirname(os.path.abspath(__file__))

PAIRS = [
    (52.3702, 4.8952, 52.0859, 5.1089, "Amsterdam-Utrecht"),
    (51.9244, 4.4777, 52.0907, 5.1214, "Rotterdam-Utrecht"),
    (52.0859, 5.1089, 51.4311, 5.4800, "Utrecht-Eindhoven"),
    (53.2194, 6.5665, 52.2215, 6.8937, "Groningen-Enschede"),
    (51.4426, 3.5736, 51.9244, 4.4777, "Vlissingen-Rotterdam"),
    (52.1561, 5.3878, 52.0859, 5.1089, "Amersfoort-Utrecht"),
    (51.5719, 4.7683, 51.6978, 5.3037, "Breda-DenBosch"),
    (53.2012, 5.7999, 52.5168, 6.0830, "Leeuwarden-Meppel"),
    (52.3874, 4.6462, 52.1601, 4.4970, "Haarlem-Noordwijk"),
    (51.8433, 5.8544, 52.2215, 6.8937, "Nijmegen-Enschede"),
    (52.9908, 6.5642, 52.3702, 4.8952, "Assen-Amsterdam"),
]

def metres(a, b):
    dy = (b[1] - a[1]) * 110540
    dx = (b[0] - a[0]) * 111320 * math.cos(math.radians((a[1] + b[1]) / 2))
    return math.hypot(dx, dy)

def densify(pts, step=50):
    out = []
    for i in range(1, len(pts)):
        a, b = pts[i - 1], pts[i]
        n = max(1, int(metres(a, b) / step))
        for k in range(n):
            t = k / n
            out.append((a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t))
    out.append(tuple(pts[-1]))
    return out

class Near:
    """Grid index, so this is not quadratic on a two thousand point line."""
    CELL = 0.002
    def __init__(self, pts):
        self.g = {}
        for p in pts:
            self.g.setdefault((int(p[0] / self.CELL), int(p[1] / self.CELL)), []).append(p)
    def to(self, p):
        gx, gy = int(p[0] / self.CELL), int(p[1] / self.CELL)
        best = 1e9
        for dx in (-1, 0, 1):
            for dy in (-1, 0, 1):
                for q in self.g.get((gx + dx, gy + dy), ()):
                    d = metres(p, q)
                    if d < best:
                        best = d
        return best

def ours(flat, flon, tlat, tlon):
    out = "/tmp/ours_overlap.json"
    env = dict(os.environ, GEOJSON=out)
    r = subprocess.run(["php", os.path.join(MAP, "test_astar.php"), "netherlands",
                        str(flat), str(flon), str(tlat), str(tlon)],
                       capture_output=True, text=True, env=env)
    if not os.path.exists(out):
        return None, None
    line = json.load(open(out))
    os.remove(out)
    km = None
    for l in r.stdout.splitlines():
        if l.startswith("route:"):
            km = float(l.split()[1])
    return line, km

def osrm(flat, flon, tlat, tlon):
    u = (f"https://router.project-osrm.org/route/v1/driving/"
         f"{flon},{flat};{tlon},{tlat}?overview=full&geometries=geojson")
    with urllib.request.urlopen(u, timeout=40) as f:
        d = json.load(f)
    r = d["routes"][0]
    return r["geometry"]["coordinates"], r["distance"] / 1000

def valhalla(flat, flon, tlat, tlon):
    """The second reference, so that disagreeing with one can be told apart
    from being wrong. OSRM and Valhalla are both built on OpenStreetMap and
    both optimise time, and they still differ by about a tenth: that spread
    is the standard to hold ours to."""
    body = json.dumps({
        "locations": [{"lat": flat, "lon": flon}, {"lat": tlat, "lon": tlon}],
        "costing": "auto",
        "directions_options": {"units": "kilometers"},
    }).encode()
    req = urllib.request.Request("https://valhalla1.openstreetmap.de/route", body,
                                 {"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=40) as f:
        d = json.load(f)
    t = d["trip"]
    # The shape is per-leg polyline6; decode it so it can be compared as a line.
    pts = []
    for leg in t.get("legs", []):
        pts.extend(decode6(leg.get("shape", "")))
    return pts, t["summary"]["length"]

def google(flat, flon, tlat, tlon):
    """The reference to be judged against.

    OSRM and Valhalla are open engines whose cost models are guesses about
    driving - a class of road is assumed to be worth a speed - and nothing
    checks the guess. Google's is fitted to what cars are observed to do on
    each road, which is what makes it worth agreeing with rather than merely
    one more opinion.

    Uses the Routes API. The legacy Directions endpoint still exists but new
    projects cannot enable it, so there is no reason to write against it.

    Two things this deliberately does not do: it does not cache, because the
    terms do not allow the results to be stored, and it does not put the key
    in this file. Export GOOGLE_API_KEY; without it the run falls back to
    comparing against OSRM and says so.

    The project needs the Routes API enabled and billing attached. Each pair
    is one billable request.
    """
    key = os.environ.get("GOOGLE_API_KEY")
    if not key:
        raise RuntimeError("GOOGLE_API_KEY not set")
    body = json.dumps({
        "origin": {"location": {"latLng": {"latitude": flat, "longitude": flon}}},
        "destination": {"location": {"latLng": {"latitude": tlat, "longitude": tlon}}},
        "travelMode": "DRIVE",
        # No departure time: a benchmark wants the road, not this afternoon's
        # traffic, and a number that changes by the hour cannot be regressed
        # against.
        "routingPreference": "TRAFFIC_UNAWARE",
    }).encode()
    req = urllib.request.Request(
        "https://routes.googleapis.com/directions/v2:computeRoutes", body,
        {"Content-Type": "application/json",
         "X-Goog-Api-Key": key,
         "X-Goog-FieldMask": "routes.distanceMeters,routes.duration,"
                             "routes.polyline.encodedPolyline"})
    try:
        with urllib.request.urlopen(req, timeout=40) as f:
            d = json.load(f)
    except urllib.error.HTTPError as e:
        detail = json.load(e).get("error", {}).get("message", str(e))
        raise RuntimeError(detail.split(".")[0])
    r = d["routes"][0]
    return (decode5(r["polyline"]["encodedPolyline"]),
            r["distanceMeters"] / 1000)

def decode5(enc):
    """Google's own polyline, five decimal places."""
    return _decode(enc, 1e5)

def decode6(enc):
    """The same encoding at Valhalla's six decimal places."""
    return _decode(enc, 1e6)

def _decode(enc, scale):
    out, lat, lon, i = [], 0, 0, 0
    while i < len(enc):
        for which in (0, 1):
            shift, result = 0, 0
            while True:
                b = ord(enc[i]) - 63
                i += 1
                result |= (b & 0x1F) << shift
                shift += 5
                if b < 0x20:
                    break
            d = ~(result >> 1) if result & 1 else (result >> 1)
            if which == 0:
                lat += d
            else:
                lon += d
        out.append((lon / scale, lat / scale))
    return out

def agreement(a, b):
    """What fraction of line a has a point of line b within a hundred metres,
    and the longest stretch where it does not."""
    A, idx = densify(a), Near(densify(b))
    d = [idx.to(p) for p in A]
    same = sum(1 for x in d if x <= 100) / len(A) * 100
    runs, cur = [], None
    for i, x in enumerate(d):
        if x > 250 and cur is None:
            cur = i
        if x <= 250 and cur is not None:
            runs.append((cur, i)); cur = None
    if cur is not None:
        runs.append((cur, len(A)))
    worst = max(runs, key=lambda r: r[1] - r[0], default=None)
    if worst and (worst[1] - worst[0]) * 50 > 1000:
        s, e = worst
        return same, f"{(e-s)*50/1000:.1f} km at {A[s][1]:.3f},{A[s][0]:.3f}"
    return same, ""

USE_GOOGLE = bool(os.environ.get("GOOGLE_API_KEY"))
GOOGLE_WARNED = False

print(f"{'route':<22}{'ours':>7}{'goog':>7}{'osrm':>7}{'valh':>7}"
      f"{'vs goog':>9}{'osrm/g':>8}{'valh/g':>8}   worst divergence from google")
mine, o_g, v_g = [], [], []
for flat, flon, tlat, tlon, name in PAIRS:
    a, akm = ours(flat, flon, tlat, tlon)
    if a is None:
        print(f"{name:<22}{'NO ROUTE':>7}")
        continue
    try:
        b, bkm = osrm(flat, flon, tlat, tlon)
        time.sleep(1)
        c, ckm = valhalla(flat, flon, tlat, tlon)
    except Exception as e:
        print(f"{name:<22}  reference unavailable: {e}")
        continue

    g, gkm = None, float("nan")
    if USE_GOOGLE:
        try:
            g, gkm = google(flat, flon, tlat, tlon)
        except Exception as e:
            # Losing the authority is worth saying once and then working
            # without, rather than losing the whole run with it.
            if not GOOGLE_WARNED:
                print(f"  (google unavailable: {e} - "
                      f"comparing against OSRM instead)")
                GOOGLE_WARNED = True

    if g is None:
        # No key: fall back to comparing against OSRM, as before.
        so, worst = agreement(a, b)
        sv, _ = agreement(a, c)
        sr, _ = agreement(b, c)
        mine.append(so); o_g.append(sv); v_g.append(sr)
        print(f"{name:<22}{akm:>7.1f}{'':>7}{bkm:>7.1f}{ckm:>7.1f}"
              f"{so:>8.1f}%{sv:>7.1f}%{sr:>7.1f}%   {worst}")
        time.sleep(1)
        continue

    sm, worst = agreement(a, g)      # ours against the authority
    so, _ = agreement(b, g)          # and how well the open engines do
    sv, _ = agreement(c, g)
    mine.append(sm); o_g.append(so); v_g.append(sv)
    print(f"{name:<22}{akm:>7.1f}{gkm:>7.1f}{bkm:>7.1f}{ckm:>7.1f}"
          f"{sm:>8.1f}%{so:>7.1f}%{sv:>7.1f}%   {worst}")
    time.sleep(1)

if mine:
    n = len(mine)
    print(f"\n{'mean agreement':<22}{'':>28}{sum(mine)/n:>8.1f}%"
          f"{sum(o_g)/n:>7.1f}%{sum(v_g)/n:>7.1f}%")
    if USE_GOOGLE:
        print("\nAll three columns are agreement with Google, which is the one "
              "reference\nfitted to observed traffic rather than assumed from "
              "road class. The two\nopen engines are there to say what score a "
              "serious router gets: matching\nGoogle exactly is not the target, "
              "and beating OSRM and Valhalla against it\nis.")
