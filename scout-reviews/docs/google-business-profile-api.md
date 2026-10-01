# Google Business Profile API: getting access

Scout Reviews 0.2.0 pulls reviews straight from Google. Google only allows
that after it approves the project, and approval can take a few weeks, so
apply well before you need it.

## Before you apply

Google reviews applications by hand. You are more likely to be approved when:

- The Business Profile is **verified** and has been active for a while (Google
  has asked for about 60 days).
- The profile lists the **website**, and that site is live.
- You apply from an email on the **business domain** (`@scoutraleigh.com`),
  and that account is an owner or manager of the profile.

## Steps

1. **Create a Google Cloud project.** Go to console.cloud.google.com, sign in
   with the business account, and create a project named "Scout Reviews".
   Note the **project number** on the project's dashboard.
2. **Request access.** Open Google's Business Profile API prerequisites page
   (developers.google.com/my-business, then "Prerequisites") and submit the
   access request form. Choose **Application for Basic API Access**. Give the
   project number, the business email, and the website. For the use case,
   write that you show your own business's reviews on your own website.
3. **Wait for the email.** Google replies to the business email. To check on
   your own, open the project's **APIs & Services > Enabled APIs**: while the
   quota for the Business Profile APIs reads 0, access is still pending.
4. **Once approved, enable the APIs** in the project:
   - My Business Account Management API
   - My Business Business Information API
   - Google My Business API (reviews still live here, on v4)
5. **Set up the sign-in screen.** APIs & Services > OAuth consent screen:
   type **External**, app name "Scout Reviews", the business email as support
   contact, and the scope `https://www.googleapis.com/auth/business.manage`.
   Add the business account as a test user.
6. **Create the credentials.** APIs & Services > Credentials > Create
   credentials > OAuth client ID > **Web application**. Under **Authorized
   redirect URIs**, paste the address shown in WordPress at Reviews >
   Settings (it looks like
   `https://scoutraleigh.com/wp-admin/admin-post.php?action=scout_reviews_google_callback`).
   Keep the client ID and secret in a password manager, never in email or
   the repo.
7. **Publish the app.** Back on the OAuth consent screen, click **Publish
   app**. While it stays in "Testing", Google signs you out every 7 days and
   the sync stops.

Then in WordPress: Reviews > Settings > paste the client ID and secret (or
put them in `wp-config.php`), click **Connect Google**, pick the business.
Reviews sync right away and then daily.

## What the sync will pull

From `accounts.locations.reviews.list` (v4): every review's name, star rating,
text, date, and any reply, plus the profile's **average rating** and **total
review count**, which fill the rating summary automatically.
