#!/usr/bin/env bash
# Build watchplayer.apk.
#
# No Gradle: the target is API 19 and the modern AGP stack fights that harder
# than it helps, so this drives the SDK tools directly —
# aapt -> javac -> d8 -> zipalign -> apksigner.
#
# Override ANDROID_SDK_ROOT / JAVA_HOME if yours live elsewhere.
set -e

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

SDK="${ANDROID_SDK_ROOT:-${ANDROID_HOME:-$HOME/AppData/Local/Android/Sdk}}"
[ -d "$SDK" ] || { echo "Android SDK not found at $SDK — set ANDROID_SDK_ROOT" >&2; exit 1; }

# Newest build-tools and platform present, rather than a pinned version.
BT="$(ls -d "$SDK"/build-tools/*/ 2>/dev/null | sort -V | tail -1)"
BT="${BT%/}"
AJAR="$(ls "$SDK"/platforms/*/android.jar 2>/dev/null | sort -V | tail -1)"
[ -n "$BT" ] && [ -n "$AJAR" ] || { echo "need build-tools and a platform android.jar under $SDK" >&2; exit 1; }

# keytool is not on PATH on this box even though java is (Oracle's javapath
# shim only exports java/javaw/javac), so locate it next to javac.
if command -v keytool >/dev/null 2>&1; then
  KEYTOOL=keytool
elif [ -n "$JAVA_HOME" ] && [ -x "$JAVA_HOME/bin/keytool" ]; then
  KEYTOOL="$JAVA_HOME/bin/keytool"
else
  KEYTOOL="$(ls "$HOME/../../Program Files/Java"/*/bin/keytool.exe 2>/dev/null | sort -V | tail -1)"
  KEYTOOL="${KEYTOOL:-/c/Program Files/Java/jdk-23/bin/keytool.exe}"
fi

# The SDK tools are native Windows binaries; hand them Windows paths.
w() { if command -v cygpath >/dev/null 2>&1; then cygpath -m "$1"; else echo "$1"; fi; }

# MSYS rewrites paths passed as arguments but not paths *inside* an @argfile,
# so the file lists javac and d8 read have to be converted explicitly.
wlist() { if command -v cygpath >/dev/null 2>&1; then cygpath -m -f -; else cat; fi; }

OUT="$HERE/build"
KS="$HERE/debug.keystore"
APK="$HERE/watchplayer.apk"

rm -rf "$OUT"
mkdir -p "$OUT/classes" "$OUT/dex" "$OUT/gen"

echo "[1/6] resources + manifest"
"$BT/aapt.exe" package -f -m \
    -M "$(w "$HERE/AndroidManifest.xml")" \
    -S "$(w "$HERE/res")" \
    -I "$(w "$AJAR")" \
    -J "$(w "$OUT/gen")" \
    -F "$(w "$OUT/base.apk")"

echo "[2/6] javac"
find "$HERE/src" "$OUT/gen" -name '*.java' | wlist > "$OUT/sources.txt"
javac -nowarn -encoding UTF-8 \
    -source 8 -target 8 \
    -bootclasspath "$(w "$AJAR")" \
    -classpath "$(w "$AJAR")" \
    -d "$(w "$OUT/classes")" \
    @"$OUT/sources.txt" 2>&1 | grep -v "^warning:" || true

echo "[3/6] dex"
find "$OUT/classes" -name '*.class' | wlist > "$OUT/classes.txt"
"$BT/d8.bat" --release --min-api 19 --lib "$(w "$AJAR")" \
    --output "$(w "$OUT/dex")" @"$OUT/classes.txt"

echo "[4/6] package dex into apk"
( cd "$OUT/dex" && "$BT/aapt.exe" add -k "$(w "$OUT/base.apk")" classes.dex >/dev/null )

echo "[5/6] zipalign"
"$BT/zipalign.exe" -f 4 "$(w "$OUT/base.apk")" "$(w "$OUT/aligned.apk")"

if [ ! -f "$KS" ]; then
  echo "      creating debug keystore"
  "$KEYTOOL" -genkeypair -v -keystore "$(w "$KS")" -storepass android -keypass android \
      -alias androiddebugkey -dname "CN=Android Debug,O=Android,C=US" \
      -keyalg RSA -keysize 2048 -validity 10000 >/dev/null 2>&1
fi

echo "[6/6] sign"
# minSdk 19 needs a v1 (JAR) signature; v2 is harmless alongside it.
"$BT/apksigner.bat" sign \
    --ks "$(w "$KS")" --ks-pass pass:android --key-pass pass:android \
    --min-sdk-version 19 \
    --v1-signing-enabled true --v2-signing-enabled true \
    --out "$(w "$APK")" "$(w "$OUT/aligned.apk")"

echo
echo "built: $APK"
ls -l "$APK"
echo
echo "install with:  adb install -r \"$APK\""
