# Scout Team changelog

## 0.1.3 (2026-10-13)

- **Fix: no more "Bad gateway" from update checks.** Every Scout plugin used to download the same release list from GitHub on its own, one after another (up to 10 seconds each). When those caches expired, one dashboard page could wait on all of them, past WP Engine's 60-second limit, and show a 502 "Bad gateway". The plugins now share one download, cached for six hours; a failed download is retried after 15 minutes instead of hiding updates for six hours.

## 0.1.2 (2026-10-13)

- **One Scout section in the sidebar.** The team members list moved from its own sidebar item into the site's Build menu (Business tab), and the Build menu stays open while editing an entry. Sites without a Build menu keep the old sidebar item.

## 0.1.1 (2026-10-13)

- Ships the shared admin kit 1.3.0: the Scout menu bar is grouped into tabs (Home, Search, Website, Business, Reviews, Chat), and a long Scout sidebar shows one link per group.

## 0.1.0 (2026-10-13)

- First release. A `scout_team` post type with a profile page for each
  person at /team/name/ and a team archive at /team/.
- Profile fields: role, photo, short bio, education, bar admissions (law
  firm sites), awards and honors, professional memberships, military
  service, languages, optional email and phone, profile links, and the
  WordPress login they write posts as.
- Edit screen with a live preview card and a trust checklist (real photo,
  bio and role, byline, education, bar admissions, profile links).
- Person schema with hasCredential, alumniOf, memberOf, award,
  knowsLanguage and sameAs, wrapped in a ProfilePage. Joins the Scout Core
  graph with worksFor pointing at the business when Scout Core is active;
  prints its own graph otherwise.
- `[scout_team]` grid shortcode, author links that point to the writer's
  profile, and click events through Scout Analytics when it is installed.
- Settings under Scout Build / Site Build → Team.
