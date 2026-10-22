# Scout Case Results

A law firm's case results, each one approved by the firm, with the firm's
disclaimer printed next to every result.

## Bar rules differ by state, and the firm signs off

North Carolina, Virginia and Florida each regulate how lawyers advertise past
results, and the rules differ (for example, what a disclaimer has to say, how
amounts may be shown, and what counts as a comparison). This plugin gives a
starting wording per state. It does not decide whether a result or its
wording complies. **The firm reviews and approves every result and the
disclaimer wording before anything goes live**, and the approver's name and
date are kept on each result as the record.

## What it does

- **Case results** in the dashboard: headline (the title field), practice
  area, result type (verdict, settlement or other), optional amount, short
  description and the date of the result.
- **The firm's approval is required.** Tick "Approved by the firm" and add the
  approver's name (the date fills in with today if left blank). Without both,
  publishing saves the result as Pending review with a plain message. The
  same rule applies to quick edit, bulk edit and anything saved by code, and
  the grid only ever shows approved, published results.
- **One disclaimer per site.** Choose the state (North Carolina, Virginia,
  Florida or another state) for the starting wording, then edit it. It prints
  on every result card. There is no per-result switch, and a blank setting
  falls back to the state wording, so it can never print empty.
- **A results grid** with practice-area filters. The filters are plain links
  (`?result_area=slug`) that work without JavaScript; with it, the grid
  filters in place and announces the count to screen readers.
- **Preview on the edit screen** built from the same headline field and the
  same card markup the site prints, with the disclaimer.
- **Wording check.** Words that can read as a promise or a ranking ("best",
  "#1", "guarantee", "specialist" and similar) are flagged with a plainer
  suggestion as you type. It advises; the firm makes the call. Filter:
  `scout_results_flagged_words`.
- **No structured data.** A case result is not a review or a rating, so no
  schema is printed.

## Shortcodes

| Shortcode | Shows |
|---|---|
| `[scout_results]` | Every approved result, with practice-area filters |
| `[scout_results area="car-accidents"]` | One practice area (comma-separate for more) |
| `[scout_results heading="Case results" limit="6"]` | A heading and a cap |
| `[scout_results filters="no"]` | The grid without filters |
| `[scout_result id="12"]` | One result |

## Styling

The grid inherits the theme's fonts and colors. A theme can set these on
`.scout-results`: `--scout-results-surface`, `--scout-results-border`,
`--scout-results-accent`, `--scout-results-radius`, `--scout-results-gap`.

## Analytics

With Scout Analytics active, a filter click sends `results_filter` with
`link_label` through `window.scoutTrack`.

## Settings

Scout Build (or Site Build) → Case results.

| Setting | Default |
|---|---|
| State rule set | North Carolina |
| Disclaimer wording | The state's starting wording |

Filters: `scout_results_default_disclaimer` (state wording),
`scout_results_disclaimer` (final text; an empty return falls back to the
state wording).

## Removing it

Deleting the plugin removes the setting. Case results and practice areas
stay, because the firm approved them. To remove them too, define
`SCOUT_PURGE_ON_UNINSTALL` as `true` in wp-config.php before deleting.
