# Scout Chat changelog

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
