=== eHowMe Image Optimizer ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.7
License: GPL-2.0-or-later

Serves AVIF/WebP versions of uploaded JPEG/PNG images, and AVIF versions of WebP uploads. Conversion happens on your own
worker server (see image-optimizer-worker), never on this host.

The site needs an Access Token issued by the worker dashboard. Without one the plugin's
API refuses every request and nothing is converted. The worker owner can disable a site
or rotate its token at any time; originals keep being served either way.

== Dashboard (Media > Image Optimizer) ==

* Bulk optimization: progress, savings and a list of sources; converts everything
  not optimized yet, or everything again ("Convert optimized images again").
  Stop takes the waiting images out of the queue (the ones being converted finish).
  Each count (in queue, not optimized yet, failed, excluded) opens the Media Library
  filtered to that state; the list view also has an "optimization state" dropdown.
* When the worker moves to a new address, "Check connection" switches to it by
  itself (the new address must accept this site's token).
* Image formats: AVIF and/or WebP.
* Conversion strategy: Smallest files / Balanced / High quality / Custom quality.
* Folders: the Media Library always; optionally other images in uploads (Elementor
  thumbnails...), themes, plugins, NextGEN gallery, wp-content/cache.
  Copies of folder images go to wp-content/uploads-optimized/@<folder>/.
* Maximum image size: scales large images down in the copies (originals untouched).
* New images: convert automatically after upload, or wait for bulk optimization.
* Advanced: CDN that varies by Accept (Cloudflare Pro+ "Vary for Images", BunnyCDN).

== Switching from Converter for Media ==

The copies Converter for Media already made can be moved over, so the library does
not have to be converted again:
1. Deactivate Converter for Media (do NOT delete it yet: deleting it removes
   wp-content/uploads-webpc).
2. Media > Image Optimizer > "Import from Converter for Media" (or wp ehio import).
   Copies are moved into wp-content/uploads-optimized; images with every enabled
   format are marked optimized, images missing a format are queued.
3. Delete Converter for Media.

== Firewalls and bot protection ==

The worker reaches the site from a server. If Cloudflare, the host or a security plugin
blocks it (worker dashboard shows HTTP 403/429 or "Blocked by Cloudflare"), let
/wp-json/ehio/ and ?ehio=src through. Exact steps are in Advanced Settings > Firewall.
Cloudflare Bot Fight Mode (free plan) cannot be skipped by a rule; keep it off.

== Connect ==

1. Worker dashboard (https://img.ehowme.com/) > Add site > copy the token (ehio_...).
   The plugin zip can be downloaded from the same dashboard.
2. Media > Image Optimizer > paste the token > Connect.
   The plugin checks the token with the worker; the token only works on the domain it
   was issued for.
3. Queue images not yet optimized, to convert the existing library.

Connection states shown on the settings page: Connected, Disabled by the worker,
Token revoked (paste the new one), Wrong site, Worker unreachable, Not connected.

== How it works ==

1. Uploading, editing or regenerating an image marks the attachment "pending".
   When connected, the site sends a signed wake-up ping to the worker.
2. The worker pulls jobs from GET /wp-json/ehio/v1/queue (HMAC-signed), downloads the
   originals, converts them, and uploads only results smaller than the original to
   POST /wp-json/ehio/v1/result. It then reports POST /wp-json/ehio/v1/complete.
3. Files are stored in wp-content/uploads-optimized/<same path>.jpg.avif / .jpg.webp.
4. Rules in wp-content/uploads/.htaccess serve AVIF, then WebP, then the original,
   based on the browser's Accept header, with "Vary: Accept". Page HTML is not changed,
   so Elementor CSS background images are covered too.

WebP uploads get an AVIF copy only: browsers with AVIF support receive the AVIF, all
others the original WebP. Animated WebP files are left as they are.

Deactivating the plugin removes the rules: the site immediately serves originals again.
Deleting an image (or a size) deletes its converted copies.

== Admin ==

Media > Image Optimizer: connection (token, Check connection, Disconnect), queue
status, queue existing images, retry failures, formats/quality, .htaccess status and
an nginx snippet. A notice is shown on every admin page while the site is not connected.

Media Library (list view column, and the attachment details in grid view):
status, size saved per format, per-size details, and two actions:
* Re-optimize now: convert this image again right away.
* Exclude: delete its AVIF/WebP copies and always serve the original (favicons,
  logos, images that must stay byte-for-byte). "Include and optimize" undoes it.
Both are also bulk actions. The site icon (favicon) is excluded automatically.

== WP-CLI ==

wp ehio status
wp ehio queue --all              # images uploaded before the plugin
wp ehio queue --all --force      # whole library (after a migration without uploads-optimized)
wp ehio queue 123 456            # re-optimize now (also lifts an exclusion)
wp ehio exclude 123 456          # serve the originals, delete the copies
wp ehio retry                    # failed -> pending
wp ehio stop                     # take waiting images out of the queue
wp ehio htaccess                 # rewrite delivery rules
wp ehio connect <token>          # store and verify an Access Token
wp ehio check                    # ask the worker for the current state
wp ehio disconnect               # remove the token from this site
wp ehio config --formats=avif,webp --quality-webp=80 --quality-avif=50

== Notes ==

* Apache and LiteSpeed (Hostinger) use the .htaccess rules. nginx-only hosts need the
  snippet shown on the admin page.
* CDNs: by default images are sent with "Cache-Control: private" (as Converter for
  Media does), so Cloudflare Free and similar CDNs pass them through and never cache
  one format for every browser. Browsers still cache them for 7 days. If the CDN keeps
  one copy per Accept header (Cloudflare Pro+ "Vary for Images", BunnyCDN), tick
  "My CDN keeps a separate copy per browser format" under Output.
* Page caches: the plugin marks its API answers as uncacheable (LiteSpeed Cache,
  WP Rocket, W3TC, Cache-Control for CDNs). If a cache still stores /wp-json/ehio/,
  exclude that path.
* Security plugins that block the REST API for anonymous requests must allow the
  ehio/v1 namespace.
* Add wp-content/uploads-optimized/ to .gitignore: it is generated and can be rebuilt.

== Updates ==

Updates come from GitHub Releases (https://github.com/Angkul/ehowme-image-optimizer).
WordPress shows them on Dashboard > Updates and Plugins; visiting Dashboard > Updates checks right away.

To release: raise the version in ehowme-image-optimizer.php (header and EHIO_VERSION) and
"Stable tag" here, add a changelog entry, commit, then push a tag with the same number:

    git tag v1.0.7 && git push origin main --tags

The GitHub Action builds ehowme-image-optimizer.zip and attaches it to the release.
It refuses to build when the tag and the version numbers in the files disagree.

== Changelog ==

= 1.0.7 =
* Start bulk optimization converts only images that are not optimized yet; turn on "Convert optimized images again" to redo all.
* Folders are counted as soon as they are turned on (and after an import), so "Not optimized yet" is right before you press Start.
* Import from Converter for Media: images whose copies were larger than the original count as optimized (original kept) instead of being converted again.
* Stop button icon.

= 1.0.6 =
* Updates through GitHub Releases, like plugins from WordPress.org.

= 1.0.5 =
* Status counts in Bulk optimization open the Media Library filtered to that state; new state filter in the list view.
* Stop button for bulk optimization (wp ehio stop).
* Shows when the worker owner paused the site.
* Follows the worker to a new address (img.ehowme.com) after Check connection.

= 1.0.4 =
* Sends all copies of an image in one request, fewer PHP requests on shared hosting.
* Firewall and bot-protection guide in Advanced Settings.

= 1.0.3 =
* General and Advanced Settings tabs, plugin icon.

= 1.0.2 =
* Import from Converter for Media.

= 1.0.0 =
* First release.
