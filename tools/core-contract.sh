#!/usr/bin/env bash
# Scout Core contract check.
#
# Other Scout plugins call into Scout Core. If a Scout Core change renames or
# removes one of these, every client site running the dependent plugin breaks
# on its next automatic update. This check runs in CI (Lint workflow) and fails
# the build first, so the dependent plugin is updated in the same change.
#
# Each line: file | exact text that must still be there | who depends on it.
# When a plugin starts relying on a new Scout Core API, add a line here.
set -euo pipefail
cd "$(dirname "$0")/.."

fail=0
while IFS='|' read -r file needle who; do
  file="$(echo "$file" | xargs)"; needle="$(echo "$needle" | sed 's/^ *//;s/ *$//')"; who="$(echo "$who" | xargs)"
  [ -z "$file" ] && continue
  if ! grep -qF -- "$needle" "$file"; then
    echo "::error file=${file}::Scout Core contract broken: '${needle}' is gone from ${file}. Used by: ${who}. Update the dependent plugin in this same change, then this line."
    fail=1
  else
    echo "ok  ${file}: ${needle}"
  fi
done <<'CONTRACT'
scout-core/includes/business.php | final class Scout_Core_Business | scout-cards (business identity: name, phone, email, city, region, postal, same_as)
scout-core/includes/business.php | public static function get() | scout-cards (Scout_Core_Business::get())
scout-core/includes/business.php | 'same_as' | scout-cards (social profile URLs)
scout-core/includes/admin.php | 'manage_options', 'scout', | scout-cards (adds Cards & Links under the 'scout' menu slug)
scout-core/includes/seo/class-seo-head.php | final class Scout_SEO_Head | scout-cards (removes Scout Core head tags on card pages)
scout-core/includes/seo/class-seo-head.php | add_action( 'wp_head', array( __CLASS__, 'output' ), 1 ) | scout-cards (remove_action must match this hook and priority)
CONTRACT

exit "$fail"
