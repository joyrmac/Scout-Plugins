# Scout Writing changelog

## 0.1.4 (2026-11-04)

- **Files by the redesigned Scout menu: Home, Inbox, Reports, Your business, Your website, Get found, Policies, Site health and Scout tools.** Shared admin kit 1.8.0. Staff (anyone who cannot change site settings) see Home, Inbox and Your business. Greyed "Turn on" items show for operators only, so clients only see what their site has. Scout tools reads Build tools on white-label sites (dashboard redesign, Joy 2026-11-04).
- **Writing (all) and Writing settings move into the Writing room section.** Writing room is a Plus module, so all of its screens live in its own sidebar section instead of the Scout menu.

## 0.1.3 (2026-11-04)

- **Files by the new Scout menu map (Overview, Your business, Content, Search and AI, Site health, Inbox, Build).** Shared admin kit 1.7.0: the same seven items on every site, the Build group hidden from everyone who is not an operator (Site Standard §7 v1.2, Joy 2026-11-04).

## 0.1.2 (2026-11-04)

- **Updates group in the shared Scout nav, and every group always shows.** Site Sync and the Change log sit under Updates (Scout → Updates holds every way of updating the site), Spam protection under Website, and the Events, Members, Partners, RV Park, Newsletter and CRM screens under Business. The seven groups show on every site; one with nothing on is greyed with a Turn on hint. Shared admin kit 1.6.0 (Scout Site Standard rules 11 and 12).

## 0.1.1 (2026-11-04)

- **Updates come from the new Scout GitHub organization.** The Scout repos moved from joyrmac to Scout-Media-Raleigh on 2026-11-04. The update check, the release feed and the plugin details now use `Scout-Media-Raleigh/Scout-Plugins`. GitHub still forwards the old address, which is how this release reached the site.

## 0.1.0 (2026-11-03)

- First release. Kai's writing room moves out of the Bliss Boogie client plugin (Scout — Bliss Boogie 0.2.3) into its own Scout plugin, so it updates itself through Scout's release channel and any client can have one. Joy, 2026-11-03: "yes move the writing room into a scout plugin".
- Everything the room did stays: the **Your Writing** menu under Dashboard with its "New" tag, the toolbar button, the full-width dashboard welcome with the next prompt, the room with steps, search, filters, Star and Mark done, Write in place with autosave, voice memos, Send to Scout by email, Start a draft from this (a draft post, never published), My writing, and Writing (all) for administrators with a Sent to Scout view. Every action also works without JavaScript.
- **Prompts and look come from the theme.** A `writing` section in the theme's `scout-setup.json` names the prompts file and sets the menu name, the Send to Scout address, a few lines of wording, the colors and the fonts (format in the README). Without one, an administrator can upload a prompts file in **Writing settings**. With no prompts, writers see nothing and administrators see a short "No prompts yet" screen. Scout's neutral cerulean look fills in anything the theme does not set; Bliss Boogie's values reproduce the 0.2.3 room pixel for pixel (checked at 1440 and 390).
- **Kai's writing moves over in place.** On activation, `bb_writing` entries, their `_bb_*` details and `scout_bb_writing_marks` become `scout_writing`, `_sw_*` and `scout_writing_marks`: the same IDs, authors, dates, words, voice memos and draft-post links. Safe to run again; each move is logged in Writing settings.
- The old address `admin.php?page=scout-bb-writing&write=<id>` (the links in Kai's writing plan) redirects to the same prompt.
- Entries stay private: not public, not searchable, not in REST, feeds or sitemaps, and voice memos are attached to the private entry.
