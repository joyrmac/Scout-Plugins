# Install & See It Work

A plain, no-experience-needed walkthrough to get the Scout plugins running on a
test WordPress so you can actually see them. Do it in order. Each step is
small.

---

## Step 1 — Get a free test WordPress (10 min)

You need a WordPress to play in. Easiest free option:

- Download **Studio by WordPress.com** (free desktop app):
  https://developer.wordpress.com/studio/
- Install it, click **Add site**, give it a name, done. It runs a real WordPress
  on your own computer. Nothing goes live.

(Already have a WP Engine staging site? You can use that instead.)

---

## Step 2 — Get the plugin files

Clone this repo, or download it as a zip from GitHub and unzip it:

```bash
git clone https://github.com/joyrmac/Scout-Plugins.git
```

Each top-level folder (`scout-core`, `scout-forms-guard`, and so on) is a complete
WordPress plugin.

**To install:** copy the plugin folders straight into your site's
`wp-content/plugins/` directory, then activate them from the Plugins screen.

**If you would rather upload zips:** zip each plugin folder on its own (so the
zip contains `scout-core/scout-core.php`, not just the loose files), then use
**Plugins → Add New Plugin → Upload Plugin**.

---

## Step 3 — Turn the plugins on, in this order

1. `scout-core` (the engine, do this one first)
2. `scout-forms-guard`
3. `scout-rvpark`

> `scout-forms-guard` only does something once Gravity Forms is installed. It's
> fine to activate it now; it just sits quietly until then.

> `scout-rvpark` is the reference companion plugin. On a real client build you
> would swap it for that client's own companion plugin.

---

## Step 4 — Fill in the business info (2 min)

Open the **Scout** admin screen. Type in the name, address, phone, email. Save.
This is the one place the business facts live, and every other plugin reads
from it.

---

## Step 5 — Look around (this is the fun part)

In the left admin menu you'll now see new items. Click them and add one of each:

- **Testimonials** → add one. Title = the person's name, body = their quote.
  Notice the extra boxes (rating, source).
- **FAQs** → add one. Title = the question, body = the answer.
- **Amenities** → add one (this came from `scout-rvpark`).

Then open any **Page** or **Post**. Scroll down. You'll see a **Scout SEO** box
with a Google-style preview that updates as you type. That's your SEO control,
and it comes from Scout Core's SEO module.

---

## Step 6 — Prove Google can read it (1 min)

Your site is local, so paste your page's HTML into the validator instead of the
URL:

1. View your homepage, right-click → **View Page Source**, copy it all.
2. Go to https://validator.schema.org/ → paste → **Run**.
3. You should see your business listed as `LocalBusiness` (or your configured
   type), plus any reviews and FAQs. That's Scout Core's schema module working.

---

## That's it

If you saw the new menu items, the SEO box, and the schema in the validator, the
whole engine works.
