# Scout Chat

A chat assistant in the corner of the site. It answers the common questions,
sends people to book a call, takes change requests from existing clients, and
saves every other question, with the visitor's name and email, to an inbox.
Once it is connected to an AI server it answers free-text questions too.

Design: `Scout-Internal/content/design/scout-features/DESIGN.md` 5.1
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
- **Email new questions to** (blank uses Send notices to in Business info), **booking page** (blank uses the theme's booking
  page or Contact), **our work page** (blank hides that button).
- **AI server address** and **connection key**. The key can instead be
  defined in `wp-config.php` as `SCOUT_CHAT_AI_KEY`, which wins.

## Messages: Scout Build → Chat messages

Each saved conversation shows who asked, the type (Question, Change request,
Wants a person), whether it's answered, the page it was asked on, and the
whole transcript. "Reply by email" opens a reply; tick Answered when done. The
sidebar shows the unanswered count. A change request that also went to Scout
Build's request list shows "In Requests" and is tracked there, so To do
lists it once.

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

<!-- visitor-data:start -->
## Visitor data

| What | Where it is kept | How long | Shared with |
|---|---|---|---|
| Name, email, phone, the chat conversation (up to the last 30 messages), the page the chat was on, and what kind of message it is (only when the visitor leaves their name and a phone number or email in the chat) | This website's database, as private Chat message posts | Until someone deletes it. Deleting the plugin keeps these posts. The site's privacy tools export and delete a person's messages by the email they gave; a message left with only a phone number is deleted by hand. | Emailed to the address set on the Chat screen, or when that is blank, to Send notices to in Business info (the site admin email by default), plus a short text alert to the alert address if one is set |
| For a change request: the request, with the visitor's name, email and phone, and the page (only when Scout Build is on and the visitor asks for a change in the chat) | This website's database, as a change request in Scout Build's request list | Until someone deletes it | Nobody |
| Questions typed in the chat, the earlier messages in the chat, and the page address (only when an AI server is connected) | Sent from this website to the AI server. This website does not store them unless the visitor later leaves contact details. | Not kept by this website | The AI server set on the Chat screen |
| A scrambled (hashed) copy of the visitor's IP address with a count, used to limit how many messages one visitor can send | A temporary record in this website's database | 10 minutes after the visitor's last chat message | Nobody |
| Chat opens, button taps and sent messages (the button words or message type, never names or messages) (only when Scout Analytics is on) | Passed to Scout Analytics on the page | Not kept by this website | Google Analytics (Google), through Scout Analytics |

| Cookie | Why | How long |
|---|---|---|
| `scoutChat (browser storage)` | Keeps the chat so far (up to 40 messages) so it stays in place when the visitor moves to another page | Until the browser tab is closed |
| `scoutChatTeaser (browser storage)` | Remembers that the visitor opened the chat or closed the greeting, so the greeting does not show again | Until the browser tab is closed |

Nothing is collected while the chat is switched off on the Chat screen. The chat sends no cookies with its messages.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
