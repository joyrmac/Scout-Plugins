# Scout Team changelog

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
