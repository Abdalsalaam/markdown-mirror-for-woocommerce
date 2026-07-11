#!/usr/bin/env bash
# Build the distributable plugin zip, honoring .distignore.
set -euo pipefail

cd "$(dirname "$0")/.."

SLUG="product-markdown-mirror"
DIST="dist"
STAGE="$DIST/$SLUG"

rm -rf "$STAGE" "$DIST/$SLUG.zip"
mkdir -p "$STAGE"

rsync -a --exclude-from=.distignore ./ "$STAGE/"

( cd "$DIST" && zip -qr "$SLUG.zip" "$SLUG" )

rm -rf "$STAGE"

echo "Built $DIST/$SLUG.zip"
unzip -l "$DIST/$SLUG.zip" | tail -3
