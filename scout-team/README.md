# Scout Team

Team profiles with real credentials. Each person gets a profile page at
`/team/<name>/` and Person schema, and `[scout_team]` shows the whole team as
a grid on any page.

## Why it is its own post type

Scout Core (1.1.x) has no person or team type, and its field registry stores
one flat value per field. Credentials are lists (several bar admissions,
several schools, several awards), so Scout Team registers `scout_team` itself
and keeps each person's details in one protected meta value, `_scout_team`.
It still plugs into Scout Core wherever Scout Core offers a hook:

- the Person joins Scout Core's single JSON-LD graph through the public
  `scout_schema_graph` filter, with `worksFor` pointing at
  `home_url( '/#business' )`;
- blog posts by a linked writer get the full Person as their author in that
  same graph;
- law firm details switch on automatically when the Scout Core business type
  is LegalService or Attorney.

Without Scout Core it works on its own and prints its own small graph on
profile pages.

## What each person has

| Field | Where it shows | Schema |
|---|---|---|
| Name (title) | Everywhere | `name` |
| Photo (featured image) | Profile, grid | `image` |
| Role or title | Profile, grid | `jobTitle` |
| Short bio | Profile, grid, archive excerpt | `description` |
| Full bio (editor, optional) | Profile | none |
| Education: school, degree, year | Profile | `alumniOf`, `hasCredential` (degree) |
| Bar admissions: state or court, year, number, profile link | Profile, law sites and attorneys only | `hasCredential` (license, `validIn`), link added to `sameAs` |
| Awards: name, year, given by | Profile | `award` |
| Professional memberships | Profile | `memberOf` |
| Military service: branch, years | Profile, grid, preview card | none (schema.org has no property for it) |
| Languages | Profile | `knowsLanguage` |
| Email, phone (optional) | Profile when turned on | `email`, `telephone` |
| Profile links (LinkedIn, state bar, Avvo...) | Profile | `sameAs` |
| Writes posts as (a WordPress login) | Author links on posts | Post `author` |

The profile page also gets a `ProfilePage` node with the Person as its
`mainEntity`.

## Editing

**Team** in the dashboard menu lists everyone with a photo, role and trust
count. The edit screen has the Profile fields under the name, the optional
full bio below, and on the side a preview card and a trust checklist (real
photo, bio and role, byline, education, bar admissions for attorneys, profile
links).

## Settings

Scout Build (or Site Build) → Team.

| Setting | Default |
|---|---|
| Law firm details | Decide from the Scout Core business type |
| Show email and phone on profiles | Off (schema only) |
| Author names link to team profiles | On |
| Grid columns on wide screens | 3 |

## Shortcode

```
[scout_team]                  everyone who is live, in order
[scout_team columns="2"]      2 columns on wide screens
[scout_team ids="12,15"]      only these people, in this order
[scout_team bio="no"]         photo, name and role only
[scout_team heading="h2"]     heading level for names (h2, h3, h4)
```

## Theming

The profile and grid inherit the theme's fonts and colors. A theme can
restyle them with `--scout-team-accent`, `--scout-team-muted`,
`--scout-team-line`, `--scout-team-surface`, `--scout-team-radius` and
`--scout-team-gap` on `.scout-team-profile` and `.scout-team-grid`.
The stylesheet loads only on team pages and pages that use the shortcode.

On a profile page the plugin prints the photo itself, so the theme's own
featured image for that person is hidden to avoid showing it twice.

## Analytics

With Scout Analytics active: `team_profile_click` (grid card),
`team_profile_view` (profile page load), `team_call` and `team_email`
(contact buttons). Each carries `link_label`.

## Filters

- `scout_team_person_schema( $node, $post_id )`: change the Person node.

## Uninstall

Deleting the plugin removes the settings. Team profiles stay, because they
are content someone typed in. To remove them too, define
`SCOUT_PURGE_ON_UNINSTALL` as `true` in wp-config.php before deleting.
Photos always stay in the media library.
