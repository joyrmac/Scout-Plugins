# Scout Chat changelog


## 0.1.9 (2026-10-13)

- **No dead ends in the Scout menu.** WordPress's own list screens inside the shortened Scout menu (the Reviews list, Chat questions, team members, case results) now show the same tabs as every Scout screen, so Review inbox, Ask for reviews and Review settings are one tap away from the Reviews list. Shared admin kit 1.4.0.

## 0.1.8 (2026-10-13)

- **Fix: newer versions were being missed.** GitHub lists releases by tag name as text, so "0.4.9" came before "0.4.11" and the updater offered the wrong one (Scout Reviews stopped at 0.4.9). It now compares version numbers and reads up to three pages of releases instead of one.

## 0.1.7 (2026-10-13)

- **Fix: no more "Bad gateway" from update checks.** Every Scout plugin used to download the same release list from GitHub on its own, one after another (up to 10 seconds each). When those caches expired, one dashboard page could wait on all of them, past WP Engine's 60-second limit, and show a 502 "Bad gateway". The plugins now share one download, cached for six hours; a failed download is retried after 15 minutes instead of hiding updates for six hours.

## 0.1.6 (2026-10-13)

- **Shorter Scout sidebar.** The shared admin kit (1.3.0) shows a long Scout menu as one sidebar link per group (Home, Search, Website, Business, Reviews, Chat; Business is new and holds hours, team, case results, cards, call buttons, booking and proposals), in the same order as the tabs on each screen. Every screen still works and is one tap away in the tabs; only the sidebar got shorter. Count bubbles (like unanswered chat questions) carry over to the group's link.

## 0.1.5 (2026-10-13)

- **Cleaner Scout menu bar.** The shared admin kit (1.2.0) sorts the screens into five groups (Overview, Search, Website, Reviews, Chat). The open group's screens show as tabs underneath, so a site with many features no longer gets a wall of links, and on a phone the bar fits on one line.

## 0.1.4 (2026-10-13)

- **Fix: the section nav was unstyled on theme screens.** A theme carrying an older copy of the admin kit loaded its old stylesheet. The kit (1.1.1) now always loads the stylesheet that matches its own code.

## 0.1.3 (2026-10-12)

- **One section nav on every Scout screen.** The shared admin kit (1.1.0) prints the same row of pills on every screen in the Scout menu (Features, Site health, Business info, SEO, AI search, Image optimizer and so on), read live from the sidebar, so the screens read as one product and a new feature appears in the row by itself.

## 0.1.2 (2026-10-12)

- **The chat button's pulse no longer slows the page.** The ring now grows
  and fades with transform and opacity only, which the browser animates on
  the GPU. Before, it animated a shadow and repainted on every frame, which
  PageSpeed flagged.

## 0.1.1 (2026-10-12)

- On phones the chat button is a round 56px bolt button instead of the wide
  "Ask Sean & Joy" pill, so it no longer covers article text. The label is
  still read out by screen readers.

## 0.1.0 (2026-10-12)

- First release. A chat assistant in the corner of every page (design:
  Scout features DESIGN.md 5.1, panel variant): launcher pill with the team's
  photos, a greeting card that waits for the cookie notice, streaming replies,
  quick-reply buttons, full screen on phones.
- Answers the common paths right away: what it costs (The Build, The
  Partnership, The Whole System, no prices), our work, book a call, existing
  clients (request a change, talk to a person).
- Collect mode until an AI server is connected: any other question is saved
  to Scout Build → Chat questions with the visitor's name and email, and
  emailed to the team. AI mode passes free-text questions to the AI server and
  falls back to collecting if it fails.
- Inbox: who asked, type, answered or not, the full conversation, Reply by
  email. Unanswered count shows in the sidebar.
- Reports chat_open, chip_click, handoff and lead_submit through Scout
  Analytics. Honeypot and per-visitor rate limits on both endpoints.
