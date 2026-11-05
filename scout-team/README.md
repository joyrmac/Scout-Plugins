# Scout People

One profile for each person the public sees: your team, speakers, writers,
and members who want to be listed. Each person can have a profile page at
`/team/<name>/`, a spot on the team page, a byline on their posts and Person
schema. `[scout_team]` shows the team page as a grid on any page.

On screen it is called People. Under the hood nothing moved: the plugin
folder and slug stay `scout-team`, the post type stays `scout_team`, the
profile stays in the `_scout_team` meta value, and every `/team/` address
keeps working, so no data moves and no link breaks.

## Why it is its own post type

Scout Core (1.1.x) has no person type, and its field registry stores one flat
value per field. Credentials are lists (several bar admissions, several
schools, several awards), so this plugin registers `scout_team` itself and
keeps each person's details in one protected meta value, `_scout_team`. It
still plugs into Scout Core wherever Scout Core offers a hook:

- the Person joins Scout Core's single JSON-LD graph through the public
  `scout_schema_graph` filter, with `worksFor` pointing at
  `home_url( '/#business' )`;
- blog posts by a linked writer get the full Person as their author in that
  same graph;
- law firm details switch on automatically when the Scout Core business type
  is LegalService or Attorney.

Without Scout Core it works on its own and prints its own small graph on
profile pages.

## Groups

Groups say who a person is to the site. They are a private taxonomy,
`scout_people_group` (no pages of their own), managed under **Groups** in the
People section. Every site starts with **Team**, **Speakers**, **Writers** and
**Members** (members who asked to be listed), plus **Attorneys** when any
profile is marked as an attorney. A person can be in more than one group.

The team page (the grid and the `/team/` archive) shows the **Team** group.
A new person starts in Team. Groups are never deleted by the plugin.

## Shows on

Each person has six switches, stored in the profile as `shows`:

| Switch | Key | What it does |
|---|---|---|
| Their own profile page | `bio` | Off: visitors who open `/team/<name>/` get "page not found", the page leaves the sitemap and the team page lists them without a link. People who can edit them still see it, marked noindex. |
| The team page | `team` | On the grid and the `/team/` archive, when they are also in the Team group and live. |
| A business card | `card` | Saved for Scout Cards. |
| Byline on their posts | `byline` | Author links and the article's schema author point at their profile. Needs "Writes posts as" picked. |
| The speakers page | `speaker` | Saved for the speakers page. |
| Chat answers | `chat` | Saved for Scout Chat. |

Existing profiles keep exactly what they had: profile page and team page on,
byline on when a login is linked, the rest off.

## Card only contact

`card_phone` and `card_email` are for a person's business card only. They
never print on the profile page or the team page and never go into the
schema. The editor says so under the fields.

## Sources on credentials

Every line of bar admissions, education, awards and professional memberships
has a **Source**: where someone could check it, like a website or a
document. The profile page and the Person schema show only lines with a
source. The source itself is never printed.

Lines saved before this existed were marked "Added before sources were
tracked" when the site updated, so live pages did not change. A line saved
or imported with no source field at all is treated the same way. A new line
with an empty source is saved but stays hidden, and the person shows under
**Needs a look**. Memberships used to be one per line of text; each line
became a membership with that same source.

## Needs a look

A person needs a look when they sent their details through a link and those
wait for an OK, they have no photo, their photo has no alt text in the media
library, a credential line has no source, or they are not live yet (draft or
pending). `Scout_Team::needs_look( $post_id )` returns the reasons in
plain words.

## What each person has

| Field | Where it shows | Schema |
|---|---|---|
| Name (title) | Everywhere | `name` |
| Photo (featured image) | Profile, grid | `image` |
| Role or title | Profile, grid | `jobTitle` |
| Short bio | Profile, grid, archive excerpt | `description` |
| Full bio (editor, optional) | Profile | none |
| Education: school, degree, year, source | Profile | `alumniOf`, `hasCredential` (degree) |
| Bar admissions: state or court, year, number, profile link, source | Profile, law sites and attorneys only | `hasCredential` (license, `validIn`), link added to `sameAs` |
| Awards: name, year, given by, source | Profile | `award` |
| Professional memberships: name, source | Profile | `memberOf` |
| Military service: branch, years | Profile, grid, preview card | none (schema.org has no property for it) |
| Languages | Profile | `knowsLanguage` |
| Email, phone (optional) | Profile when turned on | `email`, `telephone` |
| Card phone, card email | Business card only | never |
| Profile links (LinkedIn, state bar, Avvo...) | Profile | `sameAs` |
| Writes posts as (a WordPress login) | Author links on posts | Post `author` |
| Groups | Team page (Team group) | none |
| Shows on | See above | `url` left out when the profile page is off |

The profile page also gets a `ProfilePage` node with the Person as its
`mainEntity`. A photo with no alt text in the media library reads as the
person's name wherever a theme prints it.

## Editing

**People** in the Scout menu (its own People section) lists everyone: one
status line (how many people, how many on the team page, cards on, how many
need a look), filter chips (Everyone, each group, Needs a look), then one row
per person with their photo or initials, title, groups, where they show,
what needs a look, an Edit button and Send them a link. In the Everyone view the rows come in
sections: Runs the site (people whose login can manage the site), Team,
Speakers, Writers, Members (listed), then Everyone else. Rows follow the
team page order: the Order number on each person, then name.

The edit screen has the Profile fields under the name, the optional full bio
below, and on the side: Shows on (with the card only phone and email), Groups,
a preview card, a trust checklist and Send them a link.

## Add a person

**Add a person** on the People screen opens a short walkthrough in the People
section (`?page=scout-people&add=1`), three steps on one screen with a
numbered progress row:

1. **Who they are:** name, title or role, groups (Team ticked).
2. **Photo & short bio:** a photo from the media library, its alt text (empty
   uses their name) and the short bio.
3. **Where they show:** the same Shows on switches and defaults as the edit
   screen, and "Writes posts as", which turns the byline on.

**Save as draft** or **Publish** saves through the edit screen's own code
(`Scout_Team_Editor::store()`): the same cleaning, the same `_scout_team`
value, the same group rules. **Save and send them a link** saves a draft and
makes their link. Each one then opens the full edit screen with "Add
credentials and links here when you have them." Without JavaScript all three
steps show at once, the photo is a plain upload, and the form still posts
everything. "Use the full editor" opens WordPress's own Add screen.

## Send them a link

**Send them a link** (on each row of the People screen, on the edit screen,
and at the end of Add a person) makes a one-time link,
`/?scout_people=<token>`, to a page where the person fills in their own name,
title, short bio, photo, credentials with sources, and profile links. The
owner copies the link and sends it; the plugin sends no email. The page never
asks for email, phone or the card only contact.

- **The link:** 48 random hex characters. Only a keyed hash is stored
  (`_scout_team_link_hash`), with when it was made and when it ends
  (`_scout_team_link`). It ends after 14 days, works once, and takes at most
  8 sends an hour. A new link turns off the old one; the edit screen can turn
  it off. The full link shows once, to whoever made it.
- **The page:** the theme's own header and footer, plain markup, a small
  stylesheet that takes the button color from the theme's DESIGN.md through
  Scout Core. It has a nonce, a hidden honeypot field, checks the photo (JPG,
  PNG or WebP, 5 MB at most), cleans every field the way the edit screen does,
  and is never listed by search engines, cached, or tracked by the analytics
  tag. An ended or wrong link says so and nothing more.
- **What they send waits** in `_scout_team_pending`. The profile page, the
  team page and the schema never read it. The person shows under **Needs a
  look** ("Sent their details, waiting for your OK") and in To do. On the
  edit screen, **Sent their details** shows each field now and as sent:
  **Approve** copies it into the profile (an empty title or bio keeps what is
  there; credentials and links are taken as sent, because the page starts
  filled in with what is there), **Discard** deletes it and the photo sent
  with it.
- WordPress's **Export** and **Erase Personal Data** tools cover waiting
  details, found by the email on the profile, its card email, or the login it
  writes posts as.

When a live person's address changes, Scout Redirects (when it is on, and its
"forward a page automatically" setting is on) gets a 301 from the old
`/team/` address to the new one. When a person goes live, is changed, or is
taken off the site, Scout Build's Site log (when it is on) gets one line.

## Settings

Scout → People → **People settings** (or Settings → People settings on a site
without the Scout menu). The address stays `?page=scout-team`.

| Setting | Default |
|---|---|
| Law firm details | Decide from the Scout Core business type |
| Show email and phone on profiles | Off (schema only) |
| Author names link to profile pages | On |
| Grid columns on wide screens | 3 |

## Shortcode

```
[scout_team]                  the team page: live, in the Team group, team page on, in order
[scout_team columns="2"]      2 columns on wide screens
[scout_team ids="12,15"]      only these people, in this order, whatever their group
[scout_team bio="no"]         photo, name and role only
[scout_team heading="h2"]     heading level for names (h2, h3, h4)
```

## What this feature needs from the theme

- `--wp--preset--color--primary` from the theme's `theme.json` palette for the accent, else the text color. The grid and profile also take `--scout-team-accent`, `--scout-team-muted`, `--scout-team-line`, `--scout-team-surface`, `--scout-team-radius` and `--scout-team-gap` on `.scout-team-profile` and `.scout-team-grid`. Fonts and colors are inherited. The stylesheet loads only on team pages and pages that use the shortcode.
- `DESIGN.md` tokens on the "Send them a link" page, through Scout Core's `scout_design_css()`: `--scout-accent-fill`, `--scout-on-accent` and `--scout-radius-control`, each with a fallback.
- No template. Profiles print inside `the_content()` on `/team/<name>/`, and `/team/` is the type's own list page, so the theme's usual single and list templates do the job. The plugin prints the photo itself and hides the theme's featured image on a profile, so it never shows twice.
- A team page holding `[scout_team]` when the team belongs on a page the client edits instead of the `/team/` list.
- `wp_head()` for the Person schema when Scout Core is off.

## Analytics

With Scout Analytics active: `team_profile_click` (grid card),
`team_profile_view` (profile page load), `team_call` and `team_email`
(contact buttons). Each carries `link_label`.

## Filters

- `scout_team_person_schema( $node, $post_id )`: change the Person node.

## Uninstall

Deleting the plugin removes the settings, every link and any details still
waiting for an OK. Profiles and groups stay, because
they are content someone typed in. To remove profiles too, define
`SCOUT_PURGE_ON_UNINSTALL` as `true` in wp-config.php before deleting.
Photos always stay in the media library, and groups are never deleted.

<!-- visitor-data:start -->
## Visitor data

| What | Where it is kept | How long | Shared with |
|---|---|---|---|
| Which team member's profile a visitor views, and when they click a team member's profile, phone number or email link (the team member's name and the link clicked) (Only when the Scout Analytics plugin is installed and its tag is on the page.) | Not saved by this website. It is handed to the site's analytics tag in the visitor's browser. | Not kept by this plugin. How long Google keeps it depends on the site's Google account settings. | Google Analytics or Google Tag Manager, through the Scout Analytics plugin |
| Details a person sends about themselves through a one-time link the site owner gave them: name, title, short bio, photo, credentials with where to check each one, and profile links (Only when the site owner sends someone a link and they fill it in.) | This website's database, on that person's profile, as changes waiting for the owner's OK. The photo goes in the media library. | Until the owner approves them (they become the public profile) or discards them (they and the photo are deleted). | Nobody |
| How many times a link's form was sent, to stop abuse (Only when someone sends the link's form.) | This website's database, as a short-lived count tied to the link, not to a person | One hour | Nobody |

Team profiles are written by site staff, or by the person through a one-time link the owner sends them. The link page asks for no email or phone, sets no cookies, is never listed by search engines and keeps the site's analytics tag off. WordPress's export and erase tools find waiting details by the email on the person's profile or on their login.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
