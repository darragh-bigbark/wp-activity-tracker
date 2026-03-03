=== Site Activity Tracker ===
Contributors: darragh-bigbark
Tags: activity log, audit log, login tracking, security, user activity
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track every login, logout, post/page change, and plugin/theme update on your WordPress site — with timestamps, IP addresses, and browser details.

== Description ==

Site Activity Tracker gives administrators a complete audit trail of what is happening on their WordPress site:

**Login & Session Events**

* Successful logins — captures username, IP address, and browser/user agent
* Failed login attempts — records the attempted username and originating IP
* Logouts

**Content Changes**

* Post and page updates
* Status transitions (draft → publish, publish → trash, etc.)
* Post and page deletions

**Plugin & Theme Events**

* Plugin activation and deactivation
* Plugin installation and updates
* Plugin deletion
* Theme switching, installation, and updates

All events are time-stamped using the server's WordPress timezone setting.

**Admin Interface**

The plugin adds an *Activity Log* menu item in the WordPress admin sidebar. The log table can be filtered by event type and searched by username, IP address, or object name. Results are paginated (25 per page).

== Installation ==

1. Upload the `site-activity-tracker` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins → Installed Plugins** screen.
3. Visit **Activity Log** in the admin sidebar to view recorded events.

The database table is created automatically on activation and removed when the plugin is deleted.

== Frequently Asked Questions ==

= Does the plugin affect site performance? =

All logging is done via lightweight, asynchronous WordPress action hooks. Only relevant events write to the database, so the impact on normal page loads is negligible.

= Which IP address is recorded? =

The plugin checks `HTTP_CF_CONNECTING_IP` (Cloudflare), `HTTP_X_FORWARDED_FOR`, `HTTP_X_REAL_IP`, and `REMOTE_ADDR` in that order, using the first valid IP address it finds.

= Where is the data stored? =

All log entries are stored in a single custom table (`{prefix}wat_activity_log`) in your WordPress database.

= Can I export the log? =

Export functionality is planned for a future release.

== Screenshots ==

1. The Activity Log admin page showing recent events with filtering options.

== Changelog ==

= 1.0.0 =
* Initial release.
* Login, logout, and failed-login tracking with IP and user-agent capture.
* Post and page create/update/delete/status-change tracking.
* Plugin activation, deactivation, installation, update, and deletion tracking.
* Theme switch, installation, and update tracking.
* Admin log viewer with event-type filter, keyword search, and pagination.

== Upgrade Notice ==

= 1.0.0 =
Initial release — no upgrade steps required.
