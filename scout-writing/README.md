# Scout Writing

A private writing room in the client's own dashboard. The client's writing plan
(steps, prompts, reference lists) sits in its own **Your Writing** menu, each
prompt has a place to answer it that saves as they type, and a welcome at the
top of the dashboard hands them their next prompt. When a piece feels ready,
**Send to Scout** emails it to us.

Built for Kai Baylis (Bliss Boogie) inside Scout — Bliss Boogie 0.2.0 to 0.2.3,
then moved here so it updates through Scout's release channel and any client
can have one (Joy, 2026-11-03).

## What the writer sees

- **Your Writing**, right under Dashboard, in the brand's accent with a small
  "New" tag, and a **✦ Your Writing** button in the top toolbar on every screen.
- **The room**: Step 0 (how to use it, a gentle week), then every step with
  its prompts. Search, a step picker, filters by where a piece goes and how
  long it takes, Starred only, Hide done, Open all / Close all.
- **Write** opens a panel under the prompt that autosaves. **Add a voice memo**
  attaches a file from the media library. **Send to Scout** marks it Sent to
  Scout and emails Scout. **Start a draft from this** (website and newsletter
  prompts) makes a normal WordPress draft post, never published.
- **My writing**: everything written, newest first, with Keep writing.
- **The dashboard welcome**: "Welcome back, Kai. Here's your next prompt.", the
  prompt (a starred one first, else the next one in the first step), Write this
  one, Pick a different prompt, Keep writing, and their progress.
- Without JavaScript every button still works: Write opens the prompt on its
  own page with a plain form, and Star and Mark done are links.

## What administrators see

- **All writing** in the site's Build section (Scout Build, Recon Build, Site
  Build or Scout; under Your Writing on a site without one): every entry with
  step, status, writer and last edited, and a **Sent to Scout** view. Each
  entry shows its prompt, voice memos and draft post.
- **Settings** (under Your Writing): where the prompts come from, an upload when the theme
  has none, the Send to Scout address, and the log of what moved over from the
  Bliss Boogie room.
- With no prompts yet, writers see nothing at all (no menu, no toolbar button,
  no welcome). Administrators see a short **No prompts yet** screen.

## Setting it up for a client: the theme's `scout-setup.json`

Everything comes from a `writing` section in the active theme's
`scout-setup.json` (the child theme's first), so it travels with the theme and
updates with every theme release. Every key is optional except `prompts`.

```json
{
  "writing": {
    "prompts": "writing-prompts.json",
    "menu_label": "Your Writing",
    "menu_tag": "New",
    "send_to": "team@scoutraleigh.com",
    "text": {
      "kicker": "Your 2027 writing",
      "dashboard_kicker": "Your writing · Bliss Boogie 2027",
      "search_hint": "Search, for example: consent, book, Level 2",
      "week": [
        { "label": "Early in the week", "title": "One important piece", "text": "One prompt from Step 1. About 20 minutes." },
        { "label": "Later in the week", "title": "One social batch", "text": "Three five-minute prompts from Step 3." },
        { "label": "Whenever", "title": "Anything else", "text": "A fun prompt, a class note, a radio idea." }
      ]
    },
    "brand": {
      "paper": "#FFFCF7", "wash": "#F7F2E9", "ink": "#2E2A3C", "ink_soft": "#4A4452",
      "blue": "#1F4F7A", "blue_tint": "#E8EEF4",
      "gold": "#B4893F", "gold_text": "#86590A", "gold_tint": "#F5EEDF", "line": "#E7DFD1",
      "accent": "#8E5413", "accent_dark": "#6F420E", "on_accent": "#FFFCF7", "highlight": "#E0B95E",
      "frame": "#D9C49A", "rule_soft": "#E6D9BF", "bar": "#EFE8DA", "tip": "#FBF4EA",
      "heading_font": "EB Garamond",
      "body_font": "Didact Gothic",
      "font_files": [
        { "family": "EB Garamond", "file": "assets/fonts/eb-garamond-var.woff2", "style": "normal", "weight": "400 800" },
        { "family": "EB Garamond", "file": "assets/fonts/eb-garamond-italic-var.woff2", "style": "italic", "weight": "400 800" },
        { "family": "Didact Gothic", "file": "assets/fonts/didact-gothic-400.woff2", "style": "normal", "weight": "400" }
      ]
    }
  }
}
```

| Key | What it does | Default |
|---|---|---|
| `prompts` | Path of the prompts file inside the theme. | none (then the uploaded file) |
| `menu_label` | The menu and toolbar name. | Your Writing |
| `menu_tag` | Small tag beside the menu name; `""` for none. | New |
| `send_to` | One address, a comma list, or a JSON list. Settings wins when filled in. | team@scoutraleigh.com |
| `text.kicker` | Small label above the room's title. | Your writing |
| `text.dashboard_kicker` | Small label on the dashboard welcome (after ✦). | Your writing · *site name* |
| `text.search_hint` | Placeholder in the search box. | Search, for example: a word from any prompt |
| `text.week` | The three "gentle week" cards in Step 0. | Generic three |

**Brand.** Colors are hex. `paper` is the room's background, `wash` soft
panels, `ink` and `ink_soft` text, `blue` the one emphasis word, links and the
Send button, `gold` thin rules, `gold_text` small labels, `line` hairlines,
`accent` the menu, the dashboard's big button and edge, `accent_dark` their
hover, `on_accent` text on the accent, `highlight` the menu edge and "New" tag,
and `frame`, `rule_soft`, `bar`, `tip` the dashboard welcome's border, divider,
progress track and tip box. Any color left out falls back to Scout's neutral
cerulean look. `heading_font` and `body_font` take one family name (a fallback
is added) or a full CSS list; `font_files` are paths inside the theme and load
only on the writing screens. No fonts load from outside the site.

**Without a theme file**, an administrator can upload the same JSON in
**Settings** under Your Writing. The theme's file always wins when it exists.

## The prompts file

```json
{
  "dest":  [ { "key": "site", "label": "Website" } ],
  "time":  [ { "key": "5", "label": "5 min" } ],
  "steps": [ { "n": 1, "key": "important", "title": "Most important", "lead": "Most", "accent": "important", "tail": "", "lede": "One line under the title." } ],
  "blocks": [ { "id": "book", "step": 1, "label": "Priority one", "title": "The book", "accent": "book", "intro": [ "Paragraph." ], "groups": [ "Why and who" ] } ],
  "groups": { "1|Why and who": { "intro": "Shown under the group title." } },
  "prompts": [ { "id": "important-01", "step": 1, "group": "Why and who", "q": "The question.", "why": "Why it matters.", "dest": [ "site" ], "t": "5" } ],
  "social_rules": { "step": 3, "always": [ "..." ], "never": [ "..." ] },
  "guests": { "step": 4, "title": "...", "intro": "...", "groups": [ { "title": "...", "names": [ "..." ] } ] },
  "inventory": [ { "tier": "...", "name": "...", "sub": "...", "where": "...", "becomes": "..." } ],
  "inventory_step": 8
}
```

- A prompt needs an `id` (lowercase letters, numbers and dashes, up to 40, never
  changed once people write to it), a `step` and `q`. Everything else is optional.
- `accent` is the word in a step or block title set in italic color.
- `blocks` fold groups into titled sections inside a step (the first starts
  open). `step1_blocks` (the Bliss Boogie file's name) is read as Step 1's blocks.
- A group whose `groups` entry has an `issue` object (`month`, `theme`, `send`,
  `job`) shows as a newsletter-issue card; a block of them is a two-column grid.
- `social_rules`, `guests` and `inventory` are reference lists. Without a
  `step` they sit in the step whose key is `social`, `radio` or `have`.
  A step with no prompts is reference only and hides while filtering.
- Website (`site`) and newsletter (`news`) prompts offer Start a draft.

## Privacy

Entries are private posts of type `scout_writing`: not public, not queryable,
not searchable, not in REST, feeds or sitemaps. The list and edit screens are
for administrators; writers use the room, which only ever opens their own
entries. Voice memos are attached to the private entry, so their attachment
pages are not public either. Words are stored escaped and given back exactly
as typed.

## Data

- Post type `scout_writing`, one per prompt per person. Meta: `_sw_prompt_id`,
  `_sw_step`, `_sw_destinations`, `_sw_status` (draft, ready, done),
  `_sw_sent_at`, `_sw_sent_mail`, `_sw_memos`, `_sw_draft_post`, `_sw_draft_at`.
  A draft post carries `_sw_writing_entry`.
- Stars and done marks: user meta `scout_writing_marks`.
- Options: `scout_writing_settings`, `scout_writing_prompts_upload` (not
  autoloaded), `scout_writing_migration_log`.
- Filter `scout_writing_recipients` (and the older `scout_bb_writing_recipients`)
  changes who gets Send to Scout.
- Deleting the plugin keeps every entry.

## Moving from Scout — Bliss Boogie 0.2.x

On activation, and on the first admin screen after an update, any
`bb_writing` entries, `_bb_*` meta and `scout_bb_writing_marks` are renamed in
place to the names above: the same IDs, authors, dates, words, memos and draft
links. It is safe to run again, and each move is logged in Settings.
The room's old address, `admin.php?page=scout-bb-writing&write=<id>`, redirects
to the same prompt here, so links already sent keep working.

Scout — Bliss Boogie 0.3.0 stops loading its own copy of the room while this
plugin is active. If this plugin is switched off, that copy runs again and
moves the writing back under its names first; switching this back on moves it
forward again.

## What this feature needs from the theme

No visitor-facing output: the writing room lives in the dashboard. From the
theme it reads:

- **`scout-setup.json`:** the `writing` section (child theme first):
  `prompts`, `menu_label`, `menu_tag`, `send_to`, `text`, and `brand` with its
  colors, `heading_font`, `body_font` and `font_files`. Full list in Setting
  it up for a client, above.
- **Files:** the prompts file and the font files named there, as paths inside
  the theme.
- **DESIGN.md:** none. The room's look comes from `writing.brand`.

<!-- visitor-data:start -->
## Visitor data

This plugin keeps nothing about the people who visit the site and sets no cookies.

The writing room works only in the dashboard for signed-in site staff, and what they write is never shown on the website. It does not collect anything from visitors.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
