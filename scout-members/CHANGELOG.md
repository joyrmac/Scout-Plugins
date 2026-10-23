# Scout Members changelog

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
