#!/usr/bin/env bash
# Builds the dashboard and packages a clean plugin zip in dist/.
set -euo pipefail
cd "$(dirname "$0")/.."
SLUG="tokenwarden"
npm run build --silent
rm -rf "dist/$SLUG" "dist/$SLUG.zip" && mkdir -p "dist/$SLUG"
# Copy everything except the paths listed in .distignore.
tar -c --exclude-from=.distignore . | tar -x -C "dist/$SLUG"
(cd dist && zip -qr "$SLUG.zip" "$SLUG")
echo "dist/$SLUG.zip ($(du -h "dist/$SLUG.zip" | cut -f1))"
find "dist/$SLUG" -type f | sed "s|dist/$SLUG/||" | sort
