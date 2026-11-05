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
| A business card | `card` | A card at `/card/<link name>/` when Scout Cards is on and they are live. Off: that address answers "not found". |
| Byline on their posts | `byline` | Author names, links and the article's schema author point at their profile. Needs "Writes posts as" picked, or the person picked in a post's "Written by" box. |
| The speakers page | `speaker` | Saved for the speakers page. |
| Chat answers | `chat` | Saved for Scout Chat. |

Existing profiles keep exactly what they had: profile page and team page on,
byline on when a login is linked, the rest off.

## Business card box

The **Business card** box on the edit screen (under Profile) holds what a
person's business card says. Everything in it is for the card only: it never
prints on the profile page or the team page, never goes into the schema and is
never sent by the REST API. `Scout_Team::card_keys()` lists the five keys.

| Field | Key | Where it shows |
|---|---|---|
| Intro (one or two sentences in their own voice) | `card_intro` | Their card |
| Why talk to them (one line) | `card_why` | Under their name on the team card (`/card/`) |
| Tags (comma separated) | `card_tags` | Their card and the team card |
| Card phone | `card_phone` | Their card and contact file (blank uses the business phone) |
| Card email | `card_email` | Their card and contact file (blank uses the business email) |

They save through the same `sanitize_profile()` and `store()` path as every
other field, in the same `_scout_team` value. The Cards screen keeps only the
switches for what each card shows. Before this box, the card phone and email
sat in a "Card only" group in the Shows on box.

## Business cards (Scout Cards)

Scout Cards reads each card from the profile: name, title, photo and its alt
text, `card_phone`, `card_email`, `card_intro`, `card_why`, `card_tags`, and the
profile links and short bio when the card is set to show them. It calls two
public functions, guarded so either plugin works alone:

- `Scout_Team::card( $post_id )`: what a card may show of one person (`intro`,
  `why` and `tags` among it), plus `on` (live with Card switched on). Role, bio
  and links come through `public_profile()`, so a card never shows what the
  profile page hides.
- `Scout_Team::card_ids()`: live people with Card on, in team page order.

A profile page that is off also stays out of the site's own search results, so
a person who shows on a card only never turns up there. **Add a person** opened
from the Cards screen (`&card=1`) starts with A business card switched on.

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
| Card phone, card email, card intro, why talk to them, tags | Business card only | never |
| Profile links (LinkedIn, state bar, Avvo...) | Profile | `sameAs` |
| Writes posts as (a WordPress login) | Author links on posts | Post `author` |
| Picked in "Written by" on a post | Author name and link on that post | Post `author` |
| Groups | Team page (Team group) | none |
| Shows on | See above | `url` left out when the profile page is off |

The profile page also gets a `ProfilePage` node with the Person as its
`mainEntity`. A photo with no alt text in the media library reads as the
person's name wherever a theme prints it.

## Editing

**People** in the Scout menu (its own People section) lists everyone: one
status line (how many people, how many on the team page, cards on, how many
need a look), filter chips (Everyone, each group, Needs a look), then one row
per person: the photo or initials on the left; then the name with Edit and
Send them a link on the right; the title and groups; where they show as tags
(including "Byline on N posts"); and, when needed, what needs a look. In the Everyone view the rows come in
sections: Runs the site (people whose login can manage the site), Team,
Speakers, Writers, Members (listed), then Everyone else. Rows follow the
team page order: the Order number on each person, then name.

The edit screen has the Profile fields under the name, then the Business card
box, the optional full bio below, and on the side: Shows on, Groups, a preview
card, a trust checklist and Send them a link.

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
title, short bio, photo, credentials with sources, and profile links. With A
business card on, the page also takes their card intro, "why talk to you" line
and tags. The owner copies the link and sends it; the plugin sends no email.
The page never asks for email, phone, card phone or card email.

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
  **Approve** copies it into the profile (an empty title, bio or card line
  keeps what is there; credentials and links are taken as sent, because the page starts
  filled in with what is there), **Discard** deletes it and the photo sent
  with it.
- WordPress's **Export** and **Erase Personal Data** tools cover waiting
  details, found by the email on the profile, its card email, or the login it
  writes posts as.

When a live person's address changes, Scout Redirects (when it is on, and its
"forward a page automatically" setting is on) gets a 301 from the old
`/team/` address to the new one. When a person goes live, is changed, or is
taken off the site, Scout Build's Site log (when it is on) gets one line.

## Written by

Posts have a **Written by** box: pick one or more people from People as the
post's byline, with or without a WordPress login. Guest writers, speakers
with no login, and posts staff write for someone else can all be credited.

- **Where it is.** A panel in the block editor's post sidebar, and a box on
  the classic editor (also in the block editor for a post type without custom
  fields). It lists live people. Someone whose byline is off can be picked,
  with a note that the post leaves them out until it is on.
- **What it stores.** `_scout_people_authors` on the post: profile IDs, in
  order. It is registered for the REST API so the block editor saves it; only
  people who can edit the post can change it. Not visitor data.
- **What visitors see.** With people picked (live, Byline on), the post's
  author name is theirs, joined as "Ana Ruiz, Ben Cole & Dee Fox". The author
  link goes to the first person's profile page, or has no address when that
  page is off. `the_author_posts_link()` links each name to its own page. The
  article schema's `author` is each person's Person node (the same `@id` the
  profile page uses), never a second record.
- **With nobody picked**, nothing changes: the post's author and the linked
  login ("Writes posts as") work as before. Bylines off in People settings
  turns this off too. Admin screens always show the WordPress author.
- **Post types.** Posts only. Add others with the
  `scout_people_byline_post_types` filter.
- **The People screen** shows "Byline on N posts": posts where the person is
  picked, plus posts by their login where nobody else is picked, each once.

Public helpers for other plugins and themes:

- `Scout_Team::byline_people( $post_id )`: the profile IDs a post's byline
  credits. The people picked in Written by, or else the profile linked to the
  post's login. Empty when neither. Scout Build's "Real authors" check can
  read bylines from People through this.
- `Scout_Team_Byline::posts_for( $member, $limit )`: the newest live posts
  that credit a person, for a theme's "posts by" list.

### What this feature needs from the theme

Print the author with WordPress's own functions: `the_author()` (or
`get_the_author()`) for the name and `the_author_posts_link()` (or
`get_author_posts_url()`) for the link. The block theme's Post Author Name
block works too. Nothing else is needed. A theme that reads the login's
fields directly (for example `$user->display_name`) will keep showing the
login.

## The REST API

`/wp-json/wp/v2/scout_team` and the REST search follow the same rule as the
profile pages. For anyone who cannot edit posts, people whose own page is
off and people who are not live are left out, and asking for one by ID
answers "not found". Card only contact, details waiting for an OK and link
data are never sent to them. Editors and the block editor get every person,
as before.

## Settings

Scout → People → **People settings** (or Settings → People settings on a site
without the Scout menu). The address stays `?page=scout-team`.

| Setting | Default |
|---|---|
| Law firm details | Decide from the Scout Core business type |
| Show email and phone on profiles | Off (schema only) |
| Author names link to profile pages (and Written by) | On |
| Grid columns on wide screens | 3 |

## Shortcode

```
[scout_team]                  the team page: live, in the Team group, team page on, in order
[scout_team columns="2"]      2 columns on wide screens
[scout_team ids="12,15"]      only these people, in this order, whatever their group
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
- `scout_people_byline_post_types( $types )`: post types with a Written by box. Default `array( 'post' )`.

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
| Details a person sends about themselves through a one-time link the site owner gave them: name, title, short bio, photo, credentials with where to check each one, profile links, and for people with a business card their card intro, a line on why to talk to them and tags (Only when the site owner sends someone a link and they fill it in.) | This website's database, on that person's profile, as changes waiting for the owner's OK. The photo goes in the media library. | Until the owner approves them (they become the public profile) or discards them (they and the photo are deleted). | Nobody |
| How many times a link's form was sent, to stop abuse (Only when someone sends the link's form.) | This website's database, as a short-lived count tied to the link, not to a person | One hour | Nobody |

Team profiles are written by site staff, or by the person through a one-time link the owner sends them. The link page asks for no email or phone, sets no cookies, is never listed by search engines and keeps the site's analytics tag off. WordPress's export and erase tools find waiting details by the email on the person's profile or on their login. A person with Card switched on also shows on their business card (Scout Cards): name, title, photo, card phone, card email, card intro, "why talk to them" line and tags, and their profile links and short bio only when the card is set to show them. Profiles whose own page is off stay out of the site's search results.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
