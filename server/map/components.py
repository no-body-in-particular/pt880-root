#!/usr/bin/env python3
"""
How many disconnected pieces a graph is in.

A graph built before the pruning pass keeps every car park, every farm track
that touches nothing, and every island the extract clipped in half. Snapping
to one of those means the search explores a handful of nodes and reports no
route - which is what Enschede did - and the failure looks like a bug in the
router rather than in the data underneath it.

Reads the graph directly, so it can answer for a country whose shapefile has
long since been cleaned up.
"""
import struct, sys, array

def main(path):
    with open(path, "rb") as f:
        head = f.read(56)
        if head[:4] != b"WGR2":
            print("not a WGR2 graph"); return 2
        maxkmh = struct.unpack(">H", head[6:8])[0]
        nodes, arcs, cols, rows = struct.unpack(">IIII", head[8:24])
        nodes_at = 56
        adj_at = nodes_at + nodes * 8
        arcs_at = adj_at + (nodes + 1) * 4
        f.seek(adj_at)
        adj = array.array("I"); adj.frombytes(f.read((nodes + 1) * 4))
        f.seek(arcs_at)
        raw = f.read(arcs * 6)
    if sys.byteorder == "little":
        adj.byteswap()

    # Union-find over the arcs. Iterative, path-halving: a recursive version
    # dies on a chain a million nodes long.
    parent = array.array("i", range(nodes))
    def find(x):
        while parent[x] != x:
            parent[x] = parent[parent[x]]
            x = parent[x]
        return x
    for u in range(nodes):
        for k in range(adj[u], adj[u + 1]):
            v = struct.unpack_from(">I", raw, k * 6)[0]
            if v >= nodes:
                continue
            a, b = find(u), find(v)
            if a != b:
                parent[a] = b

    sizes = {}
    for i in range(nodes):
        r = find(i)
        sizes[r] = sizes.get(r, 0) + 1
    big = max(sizes.values())
    print(f"  {nodes:>9,} nodes  {arcs:>10,} arcs  max {maxkmh or '-'} km/h")
    print(f"  {len(sizes):>9,} components; largest holds {big:,} ({big/nodes*100:.1f}%)")
    if len(sizes) > 1:
        stray = nodes - big
        print(f"  {stray:,} nodes ({stray/nodes*100:.1f}%) are on something the "
              f"rest of the network cannot reach")
    return 0

for p in sys.argv[1:]:
    print(p.split("/")[-1])
    main(p)
