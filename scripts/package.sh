#!/bin/bash
# package.sh - turn the built engine into a versioned artifact with a manifest.
# The version comes from the repo VERSION file, the same one /health reports, so the artifact, the
# manifest and the served answer cannot drift apart. The manifest records the git commit and the
# sha256 of the packed binary; rebuild the binary and the hash no longer matches.
#
#   bash scripts/package.sh
set -u
R=$(cd "$(dirname "$0")/.." && pwd) || exit 2
cd "$R" || exit 2
VER=$(tr -d ' \r\n' < VERSION 2>/dev/null)
if [ -z "$VER" ]; then
  echo "NO_VERSION"
  exit 2
fi
EXE=engine/build/kakasi_engine_sycl.exe
if [ ! -f "$EXE" ]; then
  echo "NO_BINARY $EXE"
  exit 2
fi
OUT=dist/typephp-kakasi-$VER-win64
mkdir -p "$OUT" || exit 2
cp "$EXE" "$OUT/kakasi_engine_sycl.exe" || exit 2
HEAD=$(git rev-parse --short HEAD 2>/dev/null)
SUM=$(sha256sum "$EXE" | cut -d' ' -f1)
SIZE=$(stat -c %s "$EXE" 2>/dev/null)
{
  echo "name=typephp-kakasi"
  echo "version=$VER"
  echo "platform=win64"
  echo "commit=$HEAD"
  echo "built_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "sha256=$SUM"
  echo "size=$SIZE"
} > "$OUT/manifest.txt" || exit 2
# The packed copy must be the binary the manifest describes.
PACKED=$(sha256sum "$OUT/kakasi_engine_sycl.exe" | cut -d' ' -f1)
if [ "$PACKED" != "$SUM" ]; then
  echo "PACKAGE_HASH_MISMATCH packed=$PACKED built=$SUM"
  exit 1
fi
echo "PACKAGE_OK dir=$OUT version=$VER sha256=$SUM size=$SIZE"

# The install pack is the directory above, zipped and self-tested. 7-Zip lives outside PATH on this
# host, so it is addressed by full path and a missing tool is reported instead of guessed.
SEVENZ="C:/Program Files/7-Zip/7z.exe"
ZIP="dist/typephp-kakasi-$VER-win64.zip"
if [ -x "$SEVENZ" ]; then
  rm -f "$ZIP"
  "$SEVENZ" a -tzip "$ZIP" "./$OUT/*" > /dev/null || { echo "ARCHIVE_FAILED"; exit 1; }
  "$SEVENZ" t "$ZIP" > /dev/null || { echo "ARCHIVE_TEST_FAILED"; exit 1; }
  echo "ARCHIVE_OK path=$ZIP sha256=$(sha256sum "$ZIP" | cut -d' ' -f1)"
else
  echo "ARCHIVE_SKIPPED no-7z"
fi

