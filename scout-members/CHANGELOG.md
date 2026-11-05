# Scout Members changelog

## Unreleased (2026-11-05)

- **Colors and fonts from DESIGN.md (Site Standard rule 3):** the membership pages now read the theme's DESIGN.md (accent fill, on accent, line, surface 2 and card corner, from `design-tokens.css`) when the theme does not set `--scout-members-*`. Sites that set them, or set neither, look exactly the same.
- **All members rows stack**: name and email with one Edit button, then status, plan, renewal and payment tags. Edit opens the status, plan, renewal price and date fields under the row, with Save and a quiet Paid today. Without scripts the fields stay open. Real warnings (past due, lapsed, payment to review) keep amber; "Approved, dues not paid yet" is calm.
- **Home opens with the status line only.** The four number cards are gone; when nothing waits, the status line says how many members and plans there are.
- **Settings.** The application questions format is one line plus a More fold. Plan warnings (not for sale yet, checkout price differs) show as a line with an amber dot instead of a full-sentence chip. "Status, plan & renewal" and "Plans & approval". Shorter help.

## 0.4.7 (2026-11-05)

- **Fuller privacy export.** Export Personal Data now includes staff notes and any payment flagged for review. Erasing now says the login stays.

## 0.4.6 (2026-11-05)

- **Shared admin kit 1.11.0.** Plain menu names: To do, Business, People, Site features, Search, Legal pages and Site status (labels only, the group keys are the same). Ligatures on where the font has them, and one shared copy of the menu finder.
- **Notices go to the shared address.** When "Send new applications to" is blank, application and invitation notices now go to the notice address in Business info instead of the site email (the site email is still used without Scout Core).
- **Plainer words.** "Overview" is now "Home", "Renewals" is now "Renewals due", "Import" is now "Add from spreadsheet", "Membership settings" is now "Settings", and the browser tab says Members. "Shortcodes for your pages" is now "Add to a page". Long help text is cut to one short line.

## 0.4.5 (2026-11-05)

- **Every Members screen has its own name.** Overview, All members, Renewals, Import and Settings each show their own title and one sentence on what they are for, instead of "Your members" on all five.
- **A status line on top.** Overview says what needs you (or that you are all caught up), All members gives the count, Renewals leads with payments to review or renewals due, and Settings says whether the membership is open.
- **Settings is one column.** The form is split into boxes with real topics (Membership pages, Plans, Dues and renewals, Applying, Questions people answer) with one Save at the bottom. The shortcode list moved into a closed "Shortcodes for your pages" box.
- **No more blue buttons on every row.** Approve and welcome, Keep the membership and Done are outlined; Not accepted, End the membership, Paid today and Open the order are quiet text buttons.
- **Tables scroll inside their own box** on a narrow screen, and lose the zebra stripes. On Overview, applications and renewals now come before the members area map.
- The Members colors now come from the shared kit tokens instead of typed hex values. Nothing about saving, emails or access changed.
- **Shared admin kit 1.10.0.** Side-by-side boxes stack by the width of the main column, not the window, so nothing squeezes beside the section list. The kit adds one status line for the top of a screen, list rows and a closed More options box.

## 0.4.4 (2026-11-04)

- **Files by the redesigned Scout menu: Home, Inbox, Reports, Your business, Your website, Get found, Policies, Site health and Scout tools.** Shared admin kit 1.8.0. Staff (anyone who cannot change site settings) see Home, Inbox and Your business. Greyed "Turn on" items show for operators only, so clients only see what their site has. Scout tools reads Build tools on white-label sites (dashboard redesign, Joy 2026-11-04).

## 0.4.3 (2026-11-04)

- **Files by the new Scout menu map (Overview, Your business, Content, Search and AI, Site health, Inbox, Build).** Shared admin kit 1.7.0: the same seven items on every site, the Build group hidden from everyone who is not an operator (Site Standard §7 v1.2, Joy 2026-11-04).

## 0.4.2 (2026-11-04)

- **Updates group in the shared Scout nav, and every group always shows.** Site Sync and the Change log sit under Updates (Scout → Updates holds every way of updating the site), Spam protection under Website, and the Events, Members, Partners, RV Park, Newsletter and CRM screens under Business. The seven groups show on every site; one with nothing on is greyed with a Turn on hint. Shared admin kit 1.6.0 (Scout Site Standard rules 11 and 12).

## 0.4.1 (2026-11-04)

- **Updates come from the new Scout GitHub organization.** The Scout repos moved from joyrmac to Scout-Media-Raleigh on 2026-11-04. The update check, the release feed and the plugin details now use `Scout-Media-Raleigh/Scout-Plugins`. GitHub still forwards the old address, which is how this release reached the site.

## 0.4.0 (2026-11-03)

- **Dues through the site's own checkout, when WooCommerce is active.** Each plan links to a WooCommerce product in Settings, Dues and renewals, and **Create the product for this plan** makes one: simple, virtual, hidden from the shop, at the plan price, named from the new **Name on receipts** setting ("Bliss Boogie Connections membership, Club Member, 1 year"). Without WooCommerce the plugin works as before.
- **Approve, then pay.** With a dues product, approving puts a member on the new status **Approved, dues to pay**. The welcome email and their membership page carry **Pay your dues**: a signed link that puts the dues in the cart, tied to that member, and opens the checkout, signed in or not. Members-only pages and events open the moment the order is paid. Settings can switch to **pay with the application** instead: the payment is held, the year starts the day they are approved, and anyone not accepted is listed for a refund.
- **A paid order makes the member active.** On a processing or completed dues order, `Scout_Members::paid()` runs for that member (matched by the order's own meta, then the buyer's account, then email), the renewal date moves forward one period, the order shows under the member's account, and both the order and the member's new history get a note. Orders of dues alone complete themselves when a gateway reports payment.
- **Refunds and cancellations are flagged, never revoked.** The new **Renewals** screen lists them (with Keep the membership or End the membership), a payment from someone not accepted, and any dues order that matched no member. They also show in Events, Needs you, and as a count on the menu.
- **Renewals without saved cards.** Reminder emails 30 days (yearly plans) and 7 days before the renewal date, once each, with **Renew now** and the amount. A **grace period** keeps access open after the renewal date; then it closes by itself until they pay. Renewals shows who is due, past due, lapsed, approved but unpaid, and who paid in the last 30 days.
- **Nothing changes on a site that does not opt in.** Reminders go by default only to members whose plan has a dues product (Settings can send them to every member, with the next-step note in place of a pay link, or turn them off), so a site without a checkout sends no new email. The grace period defaults to 14 days on a fresh install and to **0 on a site upgraded from an earlier version**, so access still ends the day after the renewal date until the owner sets one. A one-time upgrade step writes that 0, and saving Settings never resets it.
- **Comped members.** All members has a **Renewal price** per member: empty for the plan price, 0 to renew free (a $0 checkout to confirm), or any amount. The import marks people whose latest order was $0 (a full-value code) as "Joined on a $0 order", and keeps everyone's real end date as their renewal date.
- **Headings follow the page's H1.** `[scout_membership]`'s "Members-only events coming up" and `[scout_invitations]`' invitation titles and "Your invitations" are H2 (they were H3, which Scout Accessibility flagged as a skipped level on the Bliss membership page); invitation form headings are H3. `level="3"` on either shortcode, or the `scout_members_heading_level` filter, moves them. The text size is unchanged.
- New: `scout_members_paid` action, `Scout_Members::log()` and `history()`, the privacy export includes the history and the eraser removes it. Declares WooCommerce order storage (HPOS) compatibility.

## 0.3.3 (2026-10-30)

- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Scout plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Scout plugins were already updating themselves; only the label was wrong.

## 0.3.2 (2026-10-28)

- **Fix: every screen that holds items has the section nav.** Plugins load the Scout styles on their list screens (chat questions, reviews, case results, site checks, team members), which made the shared kit skip its tab row there, so there was no way back to the plugin's settings. The kit now prints the tab row on WordPress's own list and edit screens too. Shared admin kit 1.5.1.

## 0.3.1 (2026-10-23)

- Members-only pages no longer count as missing a description in Site health (needs the Scout Core release after 1.4.1; harmless before it).

## 0.3.0 (2026-10-23)

- **Member invitations.** `[scout_invitations]` on a members page shows the invitations the owner has published, each member's own with Take it down, and a form to post a new one. The questions are the owner's own (Settings, Invitation questions; same format as the application). New invitations wait under Members, Invitations, with a count in the menu and in Events, Needs you. Publish puts one up and emails the member; Move to Trash sends nothing. An optional "show until" date takes it down by itself. Only administrators see invitations in the dashboard, and the privacy tools export and erase them with the member's record.
- The application's question tools (`Scout_Members_Form::parse()`, `question()`, `input()`) are shared with invitations.

## 0.2.0 (2026-10-23)

- **Members home.** Choose a page in Settings. Members land there after signing in. It and every page under it are members only, and it lists the pages under it (title and excerpt) that the member can open, then the members-only events.
- **Plan locks.** The Members only box can limit a page to some plans, such as VIP. Pages under it follow. `[scout_members_only plan="VIP Member"]` does the same for part of a page.
- **Sign in on the site.** The members-only note and the membership page carry the sign-in form, so members never meet the WordPress sign-in page. `[scout_member_signin]` places it anywhere. The WordPress password page shows the site's logo or name.
- **Member link.** `[scout_member_link]`, or a menu link to `#scout-member-link`, reads "Member sign in" or "Members home".
- **Overview: The members area.** Every members page with who can open it, and Add a members page.
- **Privacy.** Members pages stay out of sitemaps and llms.txt, and their description and share tags no longer quote the page to people who cannot open it (Scout Core and Scout AEO read the page's own text).

## 0.1.1 (2026-10-23)

- Members-only events take sign-ups from members only, when Scout Events 0.7.0 sign-ups are on.
- Field styles use `:where()`, so a theme can restyle inputs without fighting specificity (theme session, 2026-10-22).

## 0.1.0 (2026-10-22)

- First release. Applications with the owner's own questions, approval with a welcome email, member accounts that stay out of the dashboard, plans with renewal dates and one-click payment records, members-only pages, blocks and events, a CSV import for members moving from another system, Needs you and toolbar items in Scout Events, and the WordPress privacy export and erase tools.
