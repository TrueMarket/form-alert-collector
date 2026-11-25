# Form Alert Collector

Collects the hourly health reports that [Form Alert](https://github.com/TrueMarket/form-alert) sends from every client site, lists every site on one page, and alerts the team in **Slack** when a site's email looks broken or the site stops reporting.

Form Alert already alerts each client when an email fails. This covers the failures nobody sees: webhooks that stop arriving (emails stay at Sent, so bounces and unopened emails go unalerted), opens that stop being recorded (so the client gets false unopened alerts), a site dropping off the Mailgun API, or a site going quiet altogether.

It's installed **once**, on a site the team runs. Client sites only need Form Alert (2.2.24 or later) with its health report settings filled in.

## Setup

1. Copy the `form-alert-collector` folder into the team's site's `wp-content/plugins/` and activate **Form Alert Collector**. Activation creates the sites table, generates a report secret, and schedules a check every 15 minutes. Later versions arrive as normal plugin updates (see [Updates](#updates)).
2. Go to **Form Alert Sites → Settings** and copy the **Report URL** and **Report secret**.
3. In Form Alert, under **Settings → Alerting & Logging → Health reports**, paste them into **Report URL** and **Report secret** and save. Do this on the boilerplate (sites cloned from it inherit the settings) and on each existing site. **Send report now** there sends the first report straight away; the site then appears under **Form Alert Sites**.
4. Create a Slack incoming webhook: at [api.slack.com/apps](https://api.slack.com/apps), **Create New App → From scratch**, pick the workspace, open **Incoming Webhooks**, switch it on, then **Add New Webhook** and pick the channel alerts should go to. Paste the URL into **Incoming webhook URL** on the Settings page, save, and click **Send test message**.
5. Set up a real cron job for this site, calling `wp-cron.php` every 5–15 minutes (most hosts have a scheduler for this; add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php` once it's in place). WP-Cron otherwise only runs when the site gets uncached visits, and the not-reporting check depends on it. Incoming reports trigger it too, but if they all stop, nothing would.
6. Put an uptime monitor on this site. If it goes down, nobody is watching the other sites.

The secret and Slack URL can instead be set with `FAC_SECRET` / `FAC_SLACK_WEBHOOK` in `wp-config.php`, used while the matching field is blank. Changing the secret stops every site's reports until each one has the new secret.

## What gets flagged

Each report is checked against these rules. A rule only fires when the site actually sent mail it should have been able to track, so a site with no form submissions this week isn't flagged.

| Problem | When |
|---|---|
| **Webhooks aren't arriving** | 2+ emails sent through the Mailgun API in the last 7 days are still at Sent after 2 hours. The message says whether webhooks are being rejected (wrong signing key), have never arrived, or stopped at a given time. |
| **No webhook signing key** | The site sends through the API but has no signing key, so every webhook is rejected. |
| **Opens aren't being recorded** | Track opens is on, 10+ emails were delivered in the last 7 days, and none were opened. Usually the `opened` event is missing from the Mailgun webhook. |
| **Dropped to the SMTP fallback** | The site was on the Mailgun API and now sends through the shared SMTP fallback (no tracking). Stays flagged until it's back on the API or muted. Sites that have always used the fallback aren't flagged. |
| **Not sending through Mailgun** | Neither the API nor the SMTP fallback is set up, so mail goes through the server's `mail()`. |
| **Emails stuck at Pending** | 2+ emails in the last 7 days never finished sending. |
| **Emails failing** | 5+ failures in the last 24 hours, or the site's outage alarm is active. |
| **Unopened check isn't running** | Track opens is on with watched emails, but the hourly unopened check isn't scheduled or hasn't run for 3 hours. |
| **Texts can't be sent** | Alert phone numbers are saved and texts are on, but Twilio isn't fully set up. |
| **Not reporting** | No report for 3 hours (adjustable in Settings). The collector loads the site's home page to tell "site down" from "site up but not reporting" (Form Alert deactivated, report settings changed, or WP-Cron stopped). |

Change the thresholds with the `fac_thresholds` filter:

```php
add_filter( 'fac_thresholds', function ( $t ) {
	$t['failed_24h'] = 10; // also: stuck_sent, stuck_pending, opens_min_delivered, unopened_check_hours
	return $t;
} );
```

The rules live here, not in Form Alert, so they can change without a Form Alert release. The report format is documented in Form Alert's `includes/class-wpel-health.php` (the file header and `snapshot()`).

## How alerts work

- **Grace period:** a problem found in a report must still be there 45 minutes later (in practice, in the next hourly report) before Slack hears about it, so one-off blips don't alert. "Not reporting" alerts straight away, since it's already hours old.
- **One message per site:** each alert lists everything open on that site, marking which problems are new. Many sites flagged in the same check (say, after an outage) are grouped into as few Slack posts as possible.
- **Reminders:** while a problem lasts, Slack hears about it again every 24 hours (adjustable; 0 = once only).
- **Fixed:** when a problem clears, Slack gets a "fixed" message saying how long it lasted.
- **Muting:** **Mute** on the Sites page stops a site's alerts while still showing its problems. Unmuting alerts any problems still open. **Forget** removes a site (for one that's been retired); it comes back if it reports again.
- **Non-production sites:** sites whose WordPress environment type isn't `production` (Local sites are `local`; many hosts set staging copies to `staging`) are listed but never alerted on. Sites cloned from a reporting site report under their own host.
- **No site reporting:** if no site at all has reported for the not-reporting window, the collector is far more likely to be at fault than every site at once. Slack gets one message saying so instead of an alert per site, and another when reports resume. Sites then get a full window to report again before they're flagged.
- **Slack failures:** a problem only counts as alerted once Slack accepts the message, so a failed post is retried by the next 15-minute check. The Settings page shows the last post's outcome.

**Check now** on the Sites page runs the 15-minute check straight away.

## Testing locally

Develop on the Plugin Development Local site, where Form Alert is also installed: point its Report URL at this site's own endpoint (`http://plugin-development.local/wp-json/form-alert-collector/v1/report`) with the secret from Settings, and **Send report now**. Local sites report as `local`, so they're listed but never alerted on.

## Updates

Like Form Alert, this plugin ships with the [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) library. It checks the `TrueMarket/form-alert-collector` GitHub repo for new tagged releases and shows them on the Plugins screen like any wordpress.org plugin.

The repo is public, so no token is needed. If it's ever made private, create a fine-grained personal access token scoped to only this repo with **Contents: Read-only** permission, and add it to `wp-config.php`:

```php
define( 'FAC_GITHUB_TOKEN', 'github_pat_...' );
```

### Releasing a new version

To ship an update, paste this to Claude Code, or follow it by hand:

```
Release a new version of this plugin. Bump the Version header and
FAC_VERSION in form-alert-collector.php together (patch bump unless
I say otherwise). Update the Stable tag in readme.txt and add a new
entry at the top of its == Changelog == section containing a short and
concise list of all changes in the past tense.

Show me the list of changes before commiting.

Commit everything, tag the commit vX.Y.Z to match, and push both main and the tag to origin.
```

The changelog is what WordPress shows in the "View version X.X details" popup on the Plugins screen, since the update checker reads it straight out of `readme.txt` for whichever tag it's looking at.
