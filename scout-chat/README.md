# Scout Chat

A chat assistant in the corner of the site. It answers the common questions,
sends people to book a call, takes change requests from existing clients, and
saves every other question, with the visitor's name and email, to an inbox.
Once it is connected to an AI server it answers free-text questions too.

Design: `Scout-Media-Raleigh/content/design/scout-features/DESIGN.md` 5.1
(panel variant).

## Two modes

| | Collect (default) | AI connected |
|---|---|---|
| Cost, our work, book a call, existing clients | Answered right away | Answered right away |
| Any other question | Saved and emailed; the team replies by email | AI answers; a person is one tap away |
| AI server down or slow | n/a | Falls back to collecting |

Nothing is rebuilt between the modes. Fill in the AI connection and it switches.

## Settings: Scout Build (or Site Build) → Chat

- **Show the chat on the site**: off until ticked.
- **Team names** (`Sean and Joy`): the button reads "Ask Sean & Joy"; replies
  say who answers. Blank: "our team" and "Ask us".
- **Photos**, **greeting card** (and who it's from), **assistant name**,
  **button text**.
- **Email new questions to**, **booking page** (blank uses the theme's booking
  page or Contact), **our work page** (blank hides that button).
- **AI server address** and **connection key**. The key can instead be
  defined in `wp-config.php` as `SCOUT_CHAT_AI_KEY`, which wins.

## Inbox: Scout Build → Chat questions

Each saved conversation shows who asked, the type (Question, Change request,
Wants a person), whether it's answered, the page it was asked on, and the
whole transcript. "Reply by email" opens a reply; tick Answered when done. The
sidebar shows the unanswered count.

## For Sean: the AI server contract

The AI provider's key lives on Sean's server only. WordPress never sees it.
The site holds a per-site connection key that the server checks.

**Request** (WordPress → server), `POST <AI server address>`:

```
Authorization: Bearer <connection key>
Content-Type: application/json

{
  "question": "Can you move my site from Squarespace?",
  "history":  [ { "from": "bot", "text": "..." }, { "from": "user", "text": "..." } ],
  "page":     "https://scoutraleigh.com/services/",
  "site":     "https://scoutraleigh.com/"
}
```

**Response** (200):

```
{ "answer": "Plain text, no HTML.", "handoff": false }
```

- `handoff: true` puts "Talk to a person" first under the answer.
- Any non-200, an empty answer, or no reply within 20 seconds makes the chat
  collect the question instead. Answers are trimmed to 2,000 characters.
- Must be `https://`.
- Answer rules the server must follow (from the Scout voice and DESIGN.md):
  no prices, no guarantees, no em dashes, no client names or results, and
  offer a person when unsure. Facts come from the Google Sheet facts source.

## Tracking

Through Scout Analytics: `chat_open`, `chip_click`, `handoff`,
`lead_submit`, plus `chat_book` and `chat_work` on the link buttons.

## Safety

- Both endpoints are public (visitors are not logged in), so each has a
  per-visitor rate limit (5 saves, 20 AI questions per 10 minutes) and the
  save endpoint has a honeypot field.
- Transcripts are trimmed (30 messages, 1,500 characters each) and stored as
  private posts that only editors can see.
