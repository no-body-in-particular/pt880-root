#!/usr/bin/env bash
# Fetch the hosted firmware archives and verify them.
#
#   ./scripts/fetch-firmware.sh [destdir] [stock|patched|all]
#
# The archives are checked against the sha256 recorded here *and* against the
# SHA256SUMS each one carries, so a truncated or tampered download is caught
# rather than silently flashed.
set -e

DEST="${1:-./firmware/download}"
WHICH="${2:-all}"
BASE="http://coredump.ws/files"

STOCK_SHA=f324264310a7494859123db312aa353dfd740274733132d988e4fd2fd85fb612
PATCHED_SHA=c26271b84033488dd5ce0b60feb0ac95314b541b92adce5d4fad1728fd1183f3

mkdir -p "$DEST"

fetch() {
  name="pt880-firmware-$1.zip"
  want="$2"
  out="$DEST/$name"

  if [ -f "$out" ] && [ "$(sha256sum "$out" | cut -d' ' -f1)" = "$want" ]; then
    echo "$name: already present and verified"
  else
    echo "fetching $name..."
    curl -fL --progress-bar -o "$out" "$BASE/$name"
    got=$(sha256sum "$out" | cut -d' ' -f1)
    if [ "$got" != "$want" ]; then
      echo "$name: SHA256 MISMATCH" >&2
      echo "  expected $want" >&2
      echo "  got      $got" >&2
      exit 1
    fi
    echo "$name: sha256 ok"
  fi

  echo "extracting into $DEST/$1/"
  rm -rf "${DEST:?}/$1"
  mkdir -p "$DEST/$1"
  unzip -q -o "$out" -d "$DEST/$1"

  # each archive ships its own per-file sums
  ( cd "$DEST/$1" && sha256sum -c SHA256SUMS ) >/dev/null
  echo "$name: contents verified"
}

case "$WHICH" in
  stock)   fetch stock   "$STOCK_SHA" ;;
  patched) fetch patched "$PATCHED_SHA" ;;
  all)     fetch patched "$PATCHED_SHA"; fetch stock "$STOCK_SHA" ;;
  *) echo "usage: $0 [destdir] [stock|patched|all]" >&2; exit 2 ;;
esac

echo
echo "firmware in $DEST"
