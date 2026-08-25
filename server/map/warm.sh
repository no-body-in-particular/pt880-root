#!/usr/bin/env bash
# Pre-render a country's tiles, in parallel, without starving the watch.
#
#     ./warm.sh netherlands 15 [workers]
#
# Workers default to a third of the cores, because this box runs other things
# and because the cost is not only the rendering: the filesystem is dm-crypt,
# so every one of a hundred and fifty thousand small files is encrypted on the
# way out, and the kcryptd threads that do it are already among the busiest on
# the machine. More renderers past that point only queue up behind the disk.
#
# Each worker yields whenever the web server is serving a request, so a
# download in progress always wins.
set -e

COUNTRY="${1:-netherlands}"
Z="${2:-15}"
N="${3:-$(( $(nproc) / 3 ))}"
[ "$N" -lt 1 ] && N=1

HERE="$(cd "$(dirname "$0")" && pwd)"
cd "$HERE"

echo "warming $COUNTRY z$Z with $N workers (load now: $(cut -d' ' -f1 /proc/loadavg))"
pids=()
for i in $(seq 0 $((N - 1))); do
    sudo -n -u hiawatha nice -n 19 ionice -c 3 \
        php warm.php "$COUNTRY" "$Z" "$i" "$N" 2>"/tmp/warm-$i.log" &
    pids+=($!)
done

trap 'kill "${pids[@]}" 2>/dev/null || true' INT TERM
wait "${pids[@]}"
echo "all workers done"
tail -n1 /tmp/warm-*.log
