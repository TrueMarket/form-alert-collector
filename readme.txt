=== Form Alert Collector ===
Contributors: jimlaroche
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Stable tag: 0.1.1

Receives the hourly health reports sent by every site running Form Alert, lists every site on one page, and alerts the team in Slack when a site's email looks broken or the site stops reporting.

== Description ==

Installed once, on a site the team runs. Each client site's Form Alert (2.2.24 or later) posts a signed health report here every hour. The collector checks each report for quiet failures (webhooks not arriving, opens not being recorded, a switch to the SMTP fallback, emails stuck or failing, a site that stops reporting) and posts them to Slack after a short grace period, with reminders while they last and a message when they're fixed. See README.md in the plugin folder for setup and the full list of rules.

== Changelog ==

= 0.1.1 =
* Changed the "Webhooks aren't arriving" rule to count only emails sent after the newest one a webhook confirmed (on Form Alert 2.2.26 or later), so the problem cleared as soon as webhooks worked again instead of once the stranded emails were a week old

= 0.1.0 =
* Added the report endpoint, which accepted signed health reports from Form Alert and stored each site's latest
* Added rules for webhooks not arriving, a missing signing key, opens not being recorded, a drop to the SMTP fallback, mail not going through Mailgun, emails stuck at Pending, failures piling up, the unopened check not running, texts set up without Twilio and sites that stopped reporting
* Added Slack alerts with a grace period, reminders, fixed messages, a single message when no site was reporting, and retries when Slack rejected a post
* Added the Form Alert Sites page, with each site's status, sending method, last 7 days, Form Alert version, Email Log link and Mute and Forget actions
* Added the Settings page, with the report URL and secret, the Slack webhook and a test button, and the not-reporting and reminder intervals
* Added automatic updates from GitHub
