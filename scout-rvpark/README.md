# Scout — North Crest RV Park (companion)

The worked example of the **Scout Core companion pattern**: a tiny plugin that
registers one client's specific content types without ever forking `scout-core`.

It registers two types through `scout_core_register_type()`:

- **Amenity** (`/amenities/`) — icon, summary, and a "feature on the homepage?"
  flag.
- **Site Type** — hookups, nightly rate, monthly rate, max rig length.

`scout-core` handles everything else for them automatically: the post type,
the REST-exposed meta, the editor meta box, and the `scout/field` block
bindings so the theme can display them.

## How it fits

- Requires **Scout Core** active (`Requires Plugins: scout-core`).
- In a real engagement this plugin lives in the **client's project repo**, not
  in this shared suite. It sits here as the reference copy.
- To start a new client, copy this file, rename it `scout-{client}`, and swap in
  that client's types. A law firm would register `attorney` and `practice_area`;
  a restaurant would register `menu` and `dish`.

## The universal vs. per-client split

| Lives in `scout-core` (shared) | Lives in the companion (per client) |
|---|---|
| Testimonial, FAQ, business identity | Amenity, Site Type (here) |
| The registration framework + bindings | Just the `scout_core_register_type()` calls |

That split is what lets a small team maintain one platform across the whole
roster instead of N bespoke codebases.
