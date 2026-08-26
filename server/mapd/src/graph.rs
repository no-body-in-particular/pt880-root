//! Routing graphs, and the routing service the watch falls back to.
//!
//! The whole-country graph is served as a file. A box out of it is cut on
//! demand: nodes are numbered in grid-cell order, so a rectangle of cells is
//! a handful of contiguous runs of ids and the cut is a copy and a
//! renumbering rather than a search.

use crate::mercator::CELL_DEG;
use crate::App;
use std::collections::HashMap;
use std::path::PathBuf;
use tiny_http::Request;

use crate::{header, num, send_bytes, send_status, send_text};

fn be32(b: &[u8], o: usize) -> u32 {
    u32::from_be_bytes([b[o], b[o + 1], b[o + 2], b[o + 3]])
}

fn be64f(b: &[u8], o: usize) -> f64 {
    f64::from_be_bytes([
        b[o], b[o + 1], b[o + 2], b[o + 3], b[o + 4], b[o + 5], b[o + 6], b[o + 7],
    ])
}

/// Cut a WGR2 graph down to a bounding box, producing a WGR2 graph.
pub fn subgraph(raw: &[u8], w: f64, s: f64, e: f64, n: f64) -> Option<Vec<u8>> {
    if raw.len() < 56 || &raw[0..4] != b"WGR2" {
        return None;
    }
    let nodes = be32(raw, 8) as usize;
    let arcs = be32(raw, 12) as usize;
    let cols = be32(raw, 16) as i64;
    let rows = be32(raw, 20) as i64;
    let minx = be64f(raw, 24);
    let miny = be64f(raw, 32);

    let nodes_at = 56usize;
    let adj_at = nodes_at + nodes * 8;
    let arcs_at = adj_at + (nodes + 1) * 4;
    let grid_at = arcs_at + arcs * 6;
    if raw.len() < grid_at + ((cols * rows) as usize + 1) * 4 {
        return None;
    }
    let cell = |c: i64| -> usize { be32(raw, grid_at + c as usize * 4) as usize };

    let clamp = |v: i64, hi: i64| v.max(0).min(hi - 1);
    let x0 = clamp(((w - minx) / CELL_DEG).floor() as i64, cols);
    let x1 = clamp(((e - minx) / CELL_DEG).floor() as i64, cols);
    let y0 = clamp(((s - miny) / CELL_DEG).floor() as i64, rows);
    let y1 = clamp(((n - miny) / CELL_DEG).floor() as i64, rows);
    if x1 < x0 || y1 < y0 {
        return None;
    }

    let ncols = (x1 - x0 + 1) as usize;
    let nrows = (y1 - y0 + 1) as usize;

    // One run of ids per row of cells, in the order they will be written.
    let mut runs: Vec<(usize, usize, usize)> = Vec::with_capacity(nrows);
    let mut kept = 0usize;
    for y in y0..=y1 {
        let from = cell(y * cols + x0);
        let to = cell(y * cols + x1 + 1);
        runs.push((from, to.max(from), kept));
        kept += to.saturating_sub(from);
    }
    if kept == 0 {
        return None;
    }

    let new_of = |id: usize| -> Option<usize> {
        let (mut lo, mut hi) = (0isize, runs.len() as isize - 1);
        while lo <= hi {
            let mid = ((lo + hi) / 2) as usize;
            let (from, to, base) = runs[mid];
            if id < from {
                hi = mid as isize - 1;
            } else if id >= to {
                lo = mid as isize + 1;
            } else {
                return Some(base + (id - from));
            }
        }
        None
    };

    let mut out_nodes = Vec::with_capacity(kept * 8);
    for &(from, to, _) in runs.iter() {
        out_nodes.extend_from_slice(&raw[nodes_at + from * 8..nodes_at + to * 8]);
    }

    let mut adj = Vec::with_capacity((kept + 1) * 4);
    let mut out_arcs = Vec::with_capacity(kept * 6);
    let mut at = 0u32;
    for &(from, to, _) in runs.iter() {
        for id in from..to {
            adj.extend_from_slice(&at.to_be_bytes());
            let a0 = be32(raw, adj_at + id * 4) as usize;
            let a1 = be32(raw, adj_at + (id + 1) * 4) as usize;
            for k in a0..a1 {
                let tgt = be32(raw, arcs_at + k * 6) as usize;
                if let Some(nt) = new_of(tgt) {
                    out_arcs.extend_from_slice(&(nt as u32).to_be_bytes());
                    out_arcs.extend_from_slice(&raw[arcs_at + k * 6 + 4..arcs_at + k * 6 + 6]);
                    at += 1;
                }
            }
        }
    }
    adj.extend_from_slice(&at.to_be_bytes());

    // Node order is already (row, column), which is the new cell order.
    let mut counts = vec![0u32; ncols * nrows + 1];
    for y in y0..=y1 {
        for x in x0..=x1 {
            let a = cell(y * cols + x);
            let b = cell(y * cols + x + 1);
            counts[((y - y0) as usize * ncols + (x - x0) as usize) + 1] =
                b.saturating_sub(a) as u32;
        }
    }
    for c in 1..=ncols * nrows {
        counts[c] += counts[c - 1];
    }
    let mut grid = Vec::with_capacity(counts.len() * 4);
    for c in counts {
        grid.extend_from_slice(&c.to_be_bytes());
    }

    let ox = minx + x0 as f64 * CELL_DEG;
    let oy = miny + y0 as f64 * CELL_DEG;

    let mut out = Vec::with_capacity(56 + out_nodes.len() + adj.len() + out_arcs.len() + grid.len());
    out.extend_from_slice(b"WGR2");
    out.extend_from_slice(&[2u8, 0, 0, 0]);
    out.extend_from_slice(&(kept as u32).to_be_bytes());
    out.extend_from_slice(&at.to_be_bytes());
    out.extend_from_slice(&(ncols as u32).to_be_bytes());
    out.extend_from_slice(&(nrows as u32).to_be_bytes());
    out.extend_from_slice(&ox.to_be_bytes());
    out.extend_from_slice(&oy.to_be_bytes());
    out.extend_from_slice(&(ox + ncols as f64 * CELL_DEG).to_be_bytes());
    out.extend_from_slice(&(oy + nrows as f64 * CELL_DEG).to_be_bytes());
    out.extend_from_slice(&out_nodes);
    out.extend_from_slice(&adj);
    out.extend_from_slice(&out_arcs);
    out.extend_from_slice(&grid);
    Some(out)
}

fn graph_file(app: &App, q: &HashMap<String, String>) -> Option<(String, PathBuf)> {
    let named = q.get("c").map(|s| s.as_str()).unwrap_or("");
    let name = if !named.is_empty() {
        named.to_string()
    } else if q.contains_key("w") && q.contains_key("n") {
        let lon = (num::<f64>(q, "w", 0.0) + num::<f64>(q, "e", 0.0)) / 2.0;
        let lat = (num::<f64>(q, "s", 0.0) + num::<f64>(q, "n", 0.0)) / 2.0;
        app.stores.at(lon, lat)?.name.clone()
    } else {
        return None;
    };
    if !name.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'-' || b == b'_') {
        return None;
    }
    let p = app.data.join(format!("{}.graph", name));
    if p.is_file() {
        Some((name, p))
    } else {
        None
    }
}

pub fn handle(app: &App, r: Request, q: &HashMap<String, String>, gz: bool) {
    let (name, path) = match graph_file(app, q) {
        Some(v) => v,
        None => return send_status(r, 404, "no graph"),
    };

    if q.contains_key("w") && q.contains_key("s") && q.contains_key("e") && q.contains_key("n") {
        let (w, s, e, n) = (
            num::<f64>(q, "w", f64::NAN),
            num::<f64>(q, "s", f64::NAN),
            num::<f64>(q, "e", f64::NAN),
            num::<f64>(q, "n", f64::NAN),
        );
        if !crate::sane_lon(w) || !crate::sane_lon(e)
                || !crate::sane_lat(s) || !crate::sane_lat(n) || w > e || s > n {
            return send_status(r, 400, "bad box");
        }
        // Cut fresh every time, and not written to disk.
        //
        // It used to be cached per bounding box, which means one file per
        // distinct box - and the box comes from the request. Five crafted
        // requests made five files; there is no number at which that stops.
        // Cutting a box out of the Netherlands takes a moment and the watch
        // asks for one perhaps once a country, so the cache was buying very
        // little and offering anyone on the internet a way to fill the disk.
        let raw = match std::fs::read(&path) {
            Ok(b) => b,
            Err(_) => return send_status(r, 404, "no graph"),
        };
        let body = match subgraph(&raw, w, s, e, n) {
            Some(c) => c,
            None => return send_status(r, 404, "empty box"),
        };

        if q.contains_key("info") {
            return send_text(r, &format!("{}\n", body.len()));
        }
        return send_bytes(r, body, "application/octet-stream", gz);
    }

    if q.contains_key("info") {
        let len = std::fs::metadata(&path).map(|m| m.len()).unwrap_or(0);
        return send_text(r, &format!("{} 0\n", len));
    }

    // The whole country. The compressed copy is made by build_graph.php, not
    // here: thirty-six megabytes of deflate inside a request is a timeout
    // waiting to happen.
    let gzpath = app.data.join(format!("{}.graph.gz", name));
    if gz && gzpath.is_file() {
        if let Ok(b) = std::fs::read(&gzpath) {
            let len = b.len();
            let _ = r.respond(tiny_http::Response::new(
                tiny_http::StatusCode(200),
                vec![
                    header("Content-Type", "application/octet-stream"),
                    header("Content-Encoding", "gzip"),
                    header("Vary", "Accept-Encoding"),
                    header("Cache-Control", "public, max-age=2592000"),
                ],
                std::io::Cursor::new(b),
                Some(len),
                None,
            ));
            return;
        }
    }
    match std::fs::read(&path) {
        Ok(b) => send_bytes(r, b, "application/octet-stream", false),
        Err(_) => send_status(r, 404, "no graph"),
    }
}

/// Routing, proxied from the public OSRM demo server and cached for a day.
/// The watch routes on its own graph when it has one; this is the fallback
/// for when it does not.
pub fn route(app: &App, r: Request, q: &HashMap<String, String>) {
    let flat: f64 = num(q, "flat", f64::NAN);
    let flon: f64 = num(q, "flon", f64::NAN);
    let tlat: f64 = num(q, "tlat", f64::NAN);
    let tlon: f64 = num(q, "tlon", f64::NAN);
    if !crate::sane_lat(flat) || !crate::sane_lon(flon)
            || !crate::sane_lat(tlat) || !crate::sane_lon(tlon) {
        return send_status(r, 400, "bad request");
    }

    let key = format!("{:.5}_{:.5}_{:.5}_{:.5}", flat, flon, tlat, tlon);
    let cached = app.tiles.join("routes").join(format!("{}.bin", key));
    if let Ok(md) = std::fs::metadata(&cached) {
        let fresh = md
            .modified()
            .ok()
            .and_then(|t| t.elapsed().ok())
            .map(|e| e.as_secs() < 86400)
            .unwrap_or(false);
        if fresh {
            if let Ok(b) = std::fs::read(&cached) {
                return send_bytes(r, b, "application/octet-stream", false);
            }
        }
    }

    let url = format!(
        "https://router.project-osrm.org/route/v1/driving/{:.6},{:.6};{:.6},{:.6}\
         ?overview=full&geometries=geojson&steps=true",
        flon, flat, tlon, tlat
    );
    let body = match ureq::get(&url).timeout(std::time::Duration::from_secs(20)).call() {
        Ok(res) => res.into_string().unwrap_or_default(),
        Err(_) => return send_status(r, 502, "no route"),
    };

    match crate::graph::encode_route(&body) {
        Some(bin) => {
            if let Some(d) = cached.parent() {
                let _ = std::fs::create_dir_all(d);
            }
            let _ = std::fs::write(&cached, &bin);
            send_bytes(r, bin, "application/octet-stream", false)
        }
        None => send_status(r, 502, "no route"),
    }
}

/// The watch's route format. Kept identical to route.php so a route from
/// either source reads the same on the device.
pub fn encode_route(json: &str) -> Option<Vec<u8>> {
    crate::route_encode::encode(json)
}
