# Scout Law Firm Tools

The pieces a law firm's website needs to stay careful. Core for every law
firm Scout builds for (catalog tier `build`, `law: true`). Screens file under
**Scout → Policies** (see `../docs/dashboard-map.md`).

## Bar rules differ by state, and the firm signs off

Every state bar has its own advertising rules, and every firm has its own way
it wants to be seen. This plugin gives starting wording for North Carolina,
Virginia, Florida and Washington, DC. It does not decide whether any wording
complies. **The firm's attorneys review and approve the final wording**, and
the approver's name, the date and the exact words are kept as the record.

## Disclaimers (0.1.0)

| Line | Shows by default | What it says |
|---|---|---|
| Not legal advice | Footer | The website is general information, and contacting the firm does not create an attorney-client relationship. |
| Before someone sends a form | Under forms | Don't send confidential details through the website. |
| Attorney advertising | Footer | A short label. The firm decides whether it shows. |
| Prior results | Next to case results | Prior results do not guarantee a similar outcome, in the state's starting wording. Replaces the wording on the Case results screen while this plugin is on. |
| Reviews and testimonials | Under reviews | Reviews reflect individual clients' experiences. Replaces the Review settings disclaimer on the site while this plugin is on. |

- A line always prints something: a blank box uses the starting wording.
- An approval belongs to one exact wording. Edited words read "Changed since
  approval" until the firm approves again. Extra spaces don't count as a change.
- Lines that show somewhere and wait on the firm add a row to the Scout Inbox
  (`scout_hub_inbox`).
- Every approval is kept in `scout_law_approval_log`, which stays when the
  plugin is deleted.

### For themes

```php
add_theme_support( 'scout-law-footer' ); // the theme places the footer lines
scout_law_disclaimers( 'footer' );        // where the theme wants them
```

Shortcodes: `[scout_disclaimers place="footer"]` (every line for a place) and
`[scout_disclaimer line="relationship"]` (one line).

### Filters

| Filter | What it changes |
|---|---|
| `scout_law_starting_wording` | The starting wording for a line and state. |
| `scout_law_disclaimer_text` | The wording a line prints (an empty return falls back to the starting wording). |
| `scout_law_disclaimer_approved` (action) | Runs when the firm's approval is recorded. |

## Coming next

In priority order: approval before publishing for practice pages, results and
bios (with the same kind of record), "Reviewed by" stamps with a six-month
review list on Home, and form entries that delete themselves after a set time.
