# SSM Jelas standalone autocheck

This package uses the `ssm-jelas-v3.html` design as `index.html` and preserves the standalone PHP SSM lookup.

## Files
- `index.html` — v3 page with automatic SSM number checking; no Check Status button.
- `ssm-jelas-status.php` — same-origin JSON endpoint used by the page.
- `ssm-jelas-lookup.php` — standalone SSM lookup logic derived from the One Stop SSM plugin validation flow.

## Hosting requirement
This is **not a file-only HTML package**. Upload all files to a web host that executes PHP and has PHP cURL + DOM enabled. Opening `index.html` directly with `file://`, or serving it from a static-only host, cannot execute `ssm-jelas-status.php` and the checker will show unavailable.

The input uses the same accepted SSM number formats as the plugin: 12 digits, or old 9-character formats (`123456789`, `A12345678`, `AB1234567`) with an optional suffix such as `-H`. Checks are debounced and start automatically after typing stops.
