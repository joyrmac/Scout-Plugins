# Scout Members

A membership the owner runs from the dashboard. People apply on the site, the
owner reads each application and approves it, and members sign in to see
members-only pages and events. Built for Bliss Boogie Connections first and
kept general, so any client with a members' club can use it.

With WooCommerce active, members pay their dues and renew through the site's
own checkout (see Dues through the checkout). Without it, everything else works
as before and the owner records payments with Paid today. Either way each
payment moves the renewal date forward one period through
`Scout_Members::paid()`.

## Where it lives

**Members** has its own sidebar item under Events, with the same Scout bolt
(a plain people icon when `SCOUT_HUB_BRAND` is false). Only people who can
list users (administrators) see it, because member records are personal.

- **Home**: members, applications to read, renewals in the next 30 days,
  each application with its answers and **Approve and welcome** or **Not
  accepted**, and the memberships renewing soon.
- **All members**: status, plan, renewal date and renewal price, each
  editable in place, and **Paid today**.
- **Renewals due**: payments to review (refunds, cancellations, a payment from
  someone declined, a dues order no member matched), then everyone renewing
  in the next 30 days, past due or lapsed, approved but not paid yet, and who
  paid in the last 30 days, with the latest line of each member's history.
- **Add from spreadsheet**: bring current members in from a CSV, such as a Squarespace
  orders export. Preview first; running the same file twice changes nothing.
- **Settings**: the membership page, plans, the next-step note sent after
  approval, Dues and renewals, the community agreements link, a minimum age,
  where application emails go, and the application questions.

## Dues through the checkout (WooCommerce)

Only when WooCommerce is active. No WooCommerce Subscriptions, and no card is
ever saved: every payment, renewals included, is a fresh checkout.

- **Each plan links to a product** (Settings, Dues and renewals). **Create the
  product for this plan** makes a simple, virtual product hidden from the
  shop, sold one at a time, at the plan price, named "Name on receipts, plan,
  1 year" (for example "Bliss Boogie Connections membership, Club Member, 1
  year") so receipts and card statements read plainly. A warning shows when
  the product's price and the plan's differ; the checkout charges the
  product's.
- **When new members pay**: after approval (the default) or with the
  application. After approval, approving puts them on **Approved, dues to
  pay**; the welcome email and their membership page carry **Pay your
  dues**, and members-only pages open the moment they pay. With the
  application, applicants go straight to the checkout after applying; the
  payment is held, their year starts the day they are approved, and anyone not
  accepted is listed under Renewals due to refund.
- **The pay link** carries the member and a signed key, so it works signed in
  or not, and ties the order to the member in the order's own meta. It stops
  working once used. Dues products cannot go in the cart any other way for
  people who have not applied.
- **A paid order** (processing or completed) makes the member active, moves
  the renewal date forward one period, sets the plan to the one paid for,
  shows the order under the member's account, and adds a note to the order
  and the member's history. Orders of dues alone complete themselves when a
  card gateway reports payment. A dues order with no pay link falls back to
  the buyer's account, then their email; one that matches nobody waits under
  Renewals due.
- **Refunds and cancellations** never take a membership away: the member is
  flagged under Renewals due and the owner chooses Keep the membership or End the
  membership.

## Renewals

- **Reminder emails** 30 days (yearly plans) and 7 days before the renewal
  date, once each, with **Renew now** and the amount. **Default: only members
  whose plan has a dues product get them**, so every email has a way to pay
  and a site without a checkout sends nothing new. Settings can send them to
  every member (without a product, the email carries the next-step note and
  the membership page) or turn them off. Renewing early adds a year from the
  renewal date, not from the day paid.
- **Grace period** (Settings): members keep access that long after their
  renewal date while they renew; their page says by when. Then access closes
  by itself and the membership waits for a payment. **Default: 14 days on a
  fresh install, 0 on a site that already had Scout Members before 0.4.0**,
  so an update keeps access ending the day after the renewal date, as
  before, until the owner sets a grace period.
- **Renewal price** (All members): empty is the plan price, 0 renews free
  (the member confirms with a $0 checkout), any other amount is that
  member's own price. Members brought in from a file on a $0 order are marked
  "Joined on a $0 order", so the owner can decide each one.
- **Imported members** keep their real end dates as renewal dates, so their
  first renewal goes through this flow.

Applications to read and renewals due also show in **Events, Needs you**, and
in the Events toolbar menu, so one screen shows everything waiting.

## On the site

- `[scout_membership]` the membership page: the application for new people,
  and for members their status, plan, renewal date, Pay your dues or Renew
  now when it is time, and members-only events. Its section headings are H2,
  so they follow the page's H1; `level="3"` moves them down a level.
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

- `scout_members_status_changed` ( $user_id, $new, $old ). Statuses:
  applied, approved (dues to pay), active, paused, ended, declined.
- `scout_members_paid` ( $user_id, $paid_on, $new_renewal_date )
- `scout_members_heading_level` filter: the level of the top headings the
  shortcodes print (2). A shortcode's `level=""` wins.
- `scout_members_account_extra` filter: more for the member's account page.
- Uses Scout Events' `scout_events_needs_you`, `scout_events_detail_rows`,
  `scout_events_ticket_html`, `scout_events_card_tags` and
  `scout_events_imported_event` when Scout Events 0.6.0 or later is active.

## What this feature needs from the theme

- Custom properties the members pages read, each with a neutral fallback: `--scout-members-accent`, `--scout-members-on-accent`, `--scout-members-line`, `--scout-members-inset`, `--scout-members-radius`. Fonts and text color come from the theme.
- Stable class names for restyling: `.sm-portal`, `.sm-form`, `.sm-group`, `.sm-field`, `.sm-statement`, `.sm-check`, `.sm-button`, `.sm-account`, `.sm-badge`, `.sm-facts`, `.sm-gate`, `.sm-signin-form`, `.sm-signin-more`, `.sm-home`, `.sm-perks`, `.sm-perk`, `.sm-perk-title`, `.sm-perk-tag`, `.sm-perk-text`, `.sm-member-link`, `.sm-invitations`, `.sm-invite`, `.sm-invite-title`, `.sm-invite-meta`, `.sm-invite-answers`, `.sm-mine`, `.sm-invite-new`, `.sm-invite-form`, `.sm-pay`, `.sm-pay-note`, `.sm-thanks` (the order received note).
- A membership page holding `[scout_membership]`, and a Members home page (picked in Settings) that members land on after signing in. Pages under the home are the perks.
- Optional spots: `[scout_invitations]` on a members page, `[scout_member_signin]` where a sign-in form belongs, and `[scout_member_link]` or a menu custom link to `#scout-member-link` for the sign-in link in the header or footer.
- No template. A theme may pick its own members template by `Scout_Members::home_id()` (see "What themes rely on" below). `[scout_members_only]` and the Members only box need no theme code.

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

<!-- visitor-data:start -->
## Visitor data

| What | Where it is kept | How long | Shared with |
|---|---|---|---|
| Membership applications: first and last name, email, the plan chosen, the answers to the application questions the owner writes (these can include an address, phone number or date), the age and agreement confirmations, and the date applied | This website's database, on the person's account | Until someone deletes it. The site's privacy eraser tool removes the membership record by email address but leaves the account itself. | Emailed to the site's own address (the applicant's name and a link only) |
| A member account: a login name made from the email, the email address, first and last name, and a random starting password | This website's user accounts | Until someone deletes it | Nobody |
| Membership record: status, plan, join, renewal and payment dates, dues details, staff notes, renewal reminder dates, payments flagged for review, and a history of what happened | This website's database, on the member's account | Until someone deletes it. The history keeps only its newest 50 lines. The site's privacy eraser tool removes the record by email address. | Nobody |
| Member invitations: the title, the answers to the invitation questions (often a contact line), and a show-until date | This website's database, as private posts read by administrators and, once approved, by signed-in members | Until someone deletes it. A member who takes one down moves it to the WordPress trash. The site's privacy eraser tool deletes a member's invitations. | Emailed to the site's own address (the title and a link only) |
| For dues paid through the site's checkout: which member an order pays for, and a list of dues orders no member matched (only when WooCommerce is active) | The WooCommerce order and a list in this website's database | Until someone deletes it. An order leaves the unmatched list once staff match it. | Nobody |

| Cookie | Why | How long |
|---|---|---|
| `wordpress_logged_in_... and wordpress_sec_... (wordpress_... on a site without https), set by WordPress` | Keep a member signed in after they use the member sign-in form. This plugin asks WordPress to sign them in, and WordPress sets its own sign-in cookies. | WordPress's standard length: until the browser closes (at most 2 days), or 14 days when "Keep me signed in on this device" is ticked, unless the site changes it |

Staff can also add members from a spreadsheet. Membership changes are handed to Scout CRM Sync and Scout Newsletter when those plugins are on, and each of those lists what it keeps.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
