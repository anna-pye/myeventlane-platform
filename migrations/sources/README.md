# Migration source files

Version-controlled inputs for content seeding and migration workflows. These files are **not** imported automatically; they exist so fresh or staging environments can be populated deliberately without copying production databases.

## `blog-export.json`

**Purpose:** Seed data for four public `blog_post`-style organiser help articles (event planning guides).

**Schema:** JSON array of objects with `title`, `body` (HTML), `summary`, `status` (boolean), and `tags` (string array). No Drupal entity IDs, UUIDs, or author references.

**Why it is safe to version:**

- Contains **no real user data** — no emails, names, account IDs, or order/ticket information.
- Contains **no private or unpublished content** — all articles are public-facing marketing/help copy intended for the Organiser Hub / blog.
- Contains **no environment-specific entity IDs** — only editorial fields. Inline `data-list-item-id` values are CKEditor list markers, not Drupal identifiers.
- **Local dev URLs only:** some `body` HTML links point at `https://myeventlane.ddev.site/...`. These are placeholders for local development and should be rewritten to production paths when imported.

**When to use:** Manual or scripted blog seeding on empty environments. Do **not** run against environments that already have matching published content unless you intend to update or duplicate nodes.

**When to remove:** If blog seeding moves into module config (e.g. `myeventlane_front` organiser hub seeds) or a dedicated Drush command, this file can be retired after the replacement is verified.
