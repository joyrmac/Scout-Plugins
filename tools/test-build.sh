#!/usr/bin/env bash
# Build a labeled test zip of one plugin for a staging site.
#
#   bash tools/test-build.sh scout-build        # scout-build-0.7.8-test.1.zip
#   bash tools/test-build.sh scout-build 3      # scout-build-0.7.8-test.3.zip
#
# The zip's Version header (and the plugin's *_VERSION constant, when it has
# one) reads <version>-test.<n>. Only the copy inside the zip changes; the repo
# is never touched. WordPress's version_compare() sorts 0.7.8-test.1 below 0.7.8,
# so the real release of the same version replaces the test build on its own,
# and Scout Build's Site health lists it under "Test builds" until then.
#
# Without <n>, the number goes up by one for each zip already in the folder.
set -euo pipefail

slug="${1:-}"
root="$(cd "$(dirname "$0")/.." && pwd)"
if [ -z "$slug" ] || [ ! -f "$root/$slug/$slug.php" ]; then
  echo "Usage: bash tools/test-build.sh <plugin-folder> [test number]" >&2
  exit 1
fi

version="$(grep -m1 -oP '^\s*\*\s*Version:\s*\K[0-9][0-9A-Za-z.+-]*' "$root/$slug/$slug.php" || true)"
if [ -z "$version" ]; then
  echo "No Version: header in $slug/$slug.php" >&2
  exit 1
fi
if [[ "$version" == *-* ]]; then
  echo "$slug is already at $version, a pre-release. Set a plain version first." >&2
  exit 1
fi

n="${2:-}"
if [ -z "$n" ]; then
  n=1
  while [ -e "$PWD/$slug-$version-test.$n.zip" ]; do n=$((n + 1)); done
fi
if ! [[ "$n" =~ ^[0-9]+$ ]]; then
  echo "The test number must be a whole number." >&2
  exit 1
fi
test_version="$version-test.$n"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
cp -R "$root/$slug" "$work/$slug"
find "$work/$slug" -name '.DS_Store' -delete

main="$work/$slug/$slug.php"
# The header, then a define( 'SCOUT_..._VERSION', '<version>' ) with the same number.
sed -i -E "s/^([[:space:]]*\*[[:space:]]*Version:[[:space:]]*)${version//./\\.}[[:space:]]*\$/\1${test_version}/" "$main"
sed -i -E "s/(define\([[:space:]]*'SCOUT_[A-Z_]*VERSION',[[:space:]]*')${version//./\\.}('[[:space:]]*\))/\1${test_version}\2/" "$main"
if ! grep -qE "^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*${test_version//./\\.}[[:space:]]*\$" "$main"; then
  echo "Could not stamp the Version header in $slug/$slug.php" >&2
  exit 1
fi

out="$PWD/$slug-$test_version.zip"
(cd "$work" && zip -r "$out" "$slug" -x '*.DS_Store' -x '*/.git/*' >/dev/null)
echo "Built $out"
echo "Installs as $slug $test_version. Upload it on staging only (Plugins, Add New, Upload)."
