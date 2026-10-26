# Scout Members

A membership the owner runs from the dashboard. People apply on the site, the
owner reads each application and approves it, and members sign in to see
members-only pages and events. Built for Bliss Boogie Connections first and
kept general, so any client with a members' club can use it.

Card billing for dues is planned for a later version. Until then the owner
records payments with one click and each renewal date moves forward a period.
The model already has the plan, renewal date and `Scout_Members::paid()` that
billing will call.

## Where it lives

**Members** has its own sidebar item under Events, with the same Scout bolt
(a plain people icon when `SCOUT_HUB_BRAND` is false). Only people who can
list users (administrators) see it, because member records are personal.

- **Overview**: members, applications to read, renewals in the next 30 days,
  each application with its answers and **Approve and welcome** or **Not
  accepted**, and the memberships renewing soon.
- **All members**: status, plan and renewal date, each editable in place, and
  **Paid today**.
- **Import**: bring current members in from a CSV, such as a Squarespace
  orders export. Preview first; running the same file twice changes nothing.
- **Settings**: the membership page, plans, the next-step note sent after
  approval, the community agreements link, a minimum age, where application
  emails go, and the application questions.

Applications to read and renewals due also show in **Events, Needs you**, and
in the Events toolbar menu, so one screen shows everything waiting.

## On the site

- `[scout_membership]` the membership page: the application for new people,
  and for members their status, plan, renewal date and members-only events.
- **Members home** (Settings): where members land after signing in. It and
  every page under it are members only, and it lists the pages under it that
  the member can open (title and excerpt), then the members-only events. Add a
  perk by adding a page under it. `[scout_members_home]` moves the list.
- **Plan locks**: the Members only box can limit a page to some plans (VIP).
  Pages under it follow. `[scout_members_only plan="VIP Member"]` for part of
  a page.
- `[scout_invitations]` member invitations, on a members page: the ones the
  owner has published, a form to post one, and each member's own with a way
  to take it down. New ones wait under **Members, Invitations** until the
  owner publishes them. Questions: Settings, Invitation questions.
- `[scout_member_signin]` the sign-in form. The members-only note and the
  membership page already carry it.
- `[scout_member_link]`, or a menu custom link to `#scout-member-link`: reads
  "Member sign in", then "Members home" once a member is signed in.
- `[scout_members_only] … [/scout_members_only]` hides the part in between.
- The **Members only** box on any page or post hides the whole page (search
  engines are told to skip it). On an event (with Scout Events) the event
  stays on the calendar with a Members only tag, and only members get the
  ticket button. events.csv takes a `members_only` column (yes or no).

Members sign in on the site, land on the members home (or the membership
page when there is none), and never see the dashboard. The WordPress password
page carries the site's logo or name.

Members pages stay out of sitemaps and llms.txt, and Scout Core's description
and share tags say "A page for members." to anyone who cannot open them.

## Application questions

Written in Settings, one per line: `type | Question | extra`. A star after the
type makes it required. Types: `heading`, `text`, `email`, `tel`, `date`,
`url`, `textarea`, `yesno` (extra labels an "if yes" field), `choice` (extra
lists options separated by `;`), `agree` (a statement; extra is the tick-box
words), `check`, `address`. First name, last name and email are always asked.

## Privacy

Answers are stored on the member's user record and shown only to
administrators. Emails name the applicant and nothing else. The WordPress
privacy tools (Tools, Export and Erase Personal Data) export and erase a
person's membership record. Deleting the plugin keeps member accounts.

## Hooks

- `scout_members_status_changed` ( $user_id, $new, $old )
- `scout_members_account_extra` filter: more for the member's account page.
- Uses Scout Events' `scout_events_needs_you`, `scout_events_detail_rows`,
  `scout_events_ticket_html`, `scout_events_card_tags` and
  `scout_events_imported_event` when Scout Events 0.6.0 or later is active.

## Styling

Class names stay stable for themes: `.sm-portal`, `.sm-form`, `.sm-group`,
`.sm-field`, `.sm-statement`, `.sm-check`, `.sm-button`, `.sm-account`,
`.sm-badge`, `.sm-facts`, `.sm-gate`, `.sm-signin-form`, `.sm-signin-more`,
`.sm-home`, `.sm-perks`, `.sm-perk`, `.sm-perk-title`, `.sm-perk-tag`,
`.sm-perk-text`, `.sm-member-link`, `.sm-invitations`, `.sm-invite`,
`.sm-invite-title`, `.sm-invite-meta`, `.sm-invite-answers`, `.sm-mine`,
`.sm-invite-new`, `.sm-invite-form`. Colors are custom properties:
`--scout-members-accent`, `--scout-members-on-accent`, `--scout-members-line`,
`--scout-members-inset`, `--scout-members-radius`.

## What themes rely on (keep stable)

The Bliss Boogie theme (2.5.0) builds on these. Change one only together with
the theme, and say so in the changelog.

- `Scout_Members_Front::member_link()` stays public and returns
  `array( 'label' => …, 'url' => … )`.
- `Scout_Members::home_id()` stays public (the theme picks a members template
  for the home and the pages under it).
- The application form `#sm-apply`: each group is a `fieldset` that is a
  direct child of the form, and the submit button sits in its own `<p>`. The
  theme turns the fieldsets into click-through steps.
- `.sm-next` is the account view's next-step note (a `div`). Do not style it
  globally: the theme uses the same class name on its step buttons.
