# Visia Cresco Dealius (rebuild)

Imports active commercial real estate listings from the Dealius API into a **single**
`dealius_property` post type. Units/suites are ACF repeater rows on the property;
lease/sale availability and price/rate/size ranges are rolled up to parent meta at
import. Replaces the legacy two-post-type plugin (`th-cd-cresco-dealius`), which is
kept alongside this one for reference only — **deactivate it before activating this.**

## Requirements

- WordPress + **ACF Pro** (the field group ships as `acf-json/` and is loaded by the plugin).
- The companion theme templates (`single-dealius_property.php`, `archive-dealius_property.php`)
  and the `visia-cresco-property-filtering` plugin.

## Setup

1. Put API credentials in `wp-config.php` (never in code):

   ```php
   define( 'CRESCO_DEALIUS_EMAIL',    'service-account@example.com' );
   define( 'CRESCO_DEALIUS_PASSWORD', '...' );
   ```

   Environment variables of the same names also work. As a last resort, the admin
   screen (**Cresco Dealius**) accepts credentials stored in the DB — a warning is
   shown when that path is used.

2. Activate the plugin. The `dealius_property` CPT registers with the rewrite slug
   `properties`, so existing `/properties/...` URLs keep working. Rewrite rules are
   flushed on activation.

3. Run an import (see below). The CPT, ACF fields, and posts appear under **Properties**.

## Running the import

- **WP-CLI (preferred for full runs and cron):**

  ```bash
  wp cresco import --dry-run --pages=1     # safe preview, writes nothing
  wp cresco import --property=16022        # single property, skips retirement
  wp cresco import                         # full sync
  ```

- **Admin button:** *Cresco Dealius → Run import now* (fine for small page counts;
  long runs can hit the PHP request time limit — use WP-CLI).

- **Real server cron** (preferred over WP-Cron) — e.g. nightly:

  ```cron
  0 3 * * * cd /var/www/site && wp cresco import >/dev/null 2>&1
  ```

  A daily WP-Cron event (`cd_dealius_daily_sync`) is registered as a fallback.

## How import works

1. Authenticate (token cached, re-auth on expiry/401; tokens/URLs are never logged).
2. Resolve "active" status IDs via `/api/lookup/listingstatuses` (allow-list
   `On Market` / `In Contract`, filterable via `allowed_listing_statuses`; falls back
   to name matching).
3. Pull active listings paginated and **group by MainPropertyID** (the Level-1
   property = the building) — one WP post per Dealius property.
4. Per group: upsert the post (matched on `MainPropertyID` meta), write building meta,
   rebuild the `units` repeater (deduped by suite), compute rollups, write the
   `listingbrokers` repeater, set `associated_listing_ids`, sideload the featured image.
5. Retire properties no longer in the feed (guarded: only when the run reached the
   last page and imported > 0 listings).

The import is idempotent — safe to re-run; repeaters are rebuilt in full each time.

## Migration from the legacy plugin

Re-import rather than converting old rows:

1. Export the DB (safety).
2. Deactivate `th-cd-cresco-dealius`; activate this plugin.
3. `wp cresco import --dry-run` then a full `wp cresco import`.
4. (Optional) map each new post's slug to the old `properties` slug by `MainPropertyID`
   to preserve specific indexed URLs, then `wp rewrite flush`.
5. After verifying the new catalogue, delete the old `sub_properties` and `properties`
   posts (and their attachments).

## Security / open items to confirm against real payloads

- **Rotate the Dealius service-account password** — the legacy plugin committed it in
  plaintext (`th-cd-cresco-dealius/th-base-plugin.php`). Treat it as compromised.
- **Image/file URLs:** the single template appends `?token=` to `imageurl`/document
  URLs. If `/api/properties/{id}/files` or `ImageFileUrl` is token-gated, public
  visitors get a 401 and the token leaks/expires. Confirm whether a public/CDN URL is
  available; the featured image (sideloaded into the media library) is the safe path.
- **Suite / size source:** units use `AddressLine2` (fallback to the space `Name`) for
  the suite and `SpaceAvailableSf` for size. Confirm against a real listing payload.
- **Property types** are resolved via `/api/lookup/propertytypes` (cached), with the
  legacy hardcoded map as a fallback only.
