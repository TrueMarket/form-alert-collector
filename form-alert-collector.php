<?php
/**
 * Plugin Name: Form Alert Collector
 * Description: Receives the hourly health reports sent by every site running Form Alert, shows them on one page, and alerts the team in Slack when a site's email looks broken or the site stops reporting.
 * Version:     0.1.0
 * Author:      True Market
 * Author URI:  https://truemarket.ca
 * License:     GPL-2.0-or-later
 * Text Domain: fac
 *
 * ------------------------------------------------------------------------
 * HOW IT WORKS
 *
 * Installed once, on a site the team runs. Each client site's Form Alert
 * (2.2.24 or later) posts a signed JSON report here every hour (see Form Alert's
 * includes/class-wpel-health.php and the "Health reports" section of its
 * README for the format). This plugin:
 *
 *  1) RECEIVES  POST /wp-json/form-alert-collector/v1/report
 *     Checks the HMAC signature against the shared secret, then stores the
 *     report as that site's latest (one row per site, see FAC_Sites).
 *
 *  2) EVALUATES  each report against the rules in FAC_Rules: webhooks not
 *     arriving, opens not being recorded, mail not going through Mailgun,
 *     failures piling up, and so on. Sites only report numbers; every rule
 *     lives here, so rules can change without a Form Alert release.
 *
 *  3) WATCHES FOR SILENCE  every 15 minutes (WP-Cron, fac_check_sites): a
 *     site that hasn't reported for a few hours is flagged as not reporting,
 *     and its home page is checked to tell "site down" from "site up but not
 *     reporting". If no site at all has reported, that's treated as a
 *     problem with this collector, in one message, rather than every site
 *     being flagged.
 *
 *  4) ALERTS  in Slack (an incoming webhook) when a problem has lasted past
 *     a short grace period, again as a reminder while it lasts, and once
 *     more when it's resolved. Muted sites and non-production sites (Local,
 *     staging) are tracked but never alerted on.
 *
 * FILE LAYOUT
 *   includes/class-fac-sites.php      The sites table: one row per reporting site.
 *   includes/class-fac-rules.php      What counts as a problem in a report.
 *   includes/class-fac-slack.php      Slack incoming webhook + message formatting helpers.
 *   includes/class-fac-collector.php  REST endpoint, silence check, problem state + alerts.
 *   admin/class-fac-admin.php         Sites overview + Settings pages.
 *   vendor/plugin-update-checker/     Updates from GitHub (see fac_init_updater()).
 * ------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FAC_VERSION', '0.1.0' );
define( 'FAC_OPTION', 'fac_settings' );
define( 'FAC_DIR', plugin_dir_path( __FILE__ ) );
define( 'FAC_FILE', __FILE__ );

/**
 * A setting from the Settings page, falling back to a wp-config.php
 * constant while it's blank (the same pattern Form Alert uses).
 *
 * @param string $key      Key in the FAC_OPTION array.
 * @param string $constant e.g. 'FAC_SLACK_WEBHOOK'; '' for none.
 * @param mixed  $fallback Value when neither is set.
 * @return mixed
 */
function fac_setting( $key, $constant = '', $fallback = '' ) {
	$o     = get_option( FAC_OPTION, array() );
	$value = is_array( $o ) && isset( $o[ $key ] ) ? $o[ $key ] : '';
	if ( is_string( $value ) ) {
		$value = trim( $value );
	}
	if ( '' === $value && $constant && defined( $constant ) ) {
		$value = trim( (string) constant( $constant ) );
	}
	return '' === $value ? $fallback : $value;
}

// GitHub repo the update checker reads releases/tags from.
define( 'FAC_GITHUB_REPO', 'https://github.com/TrueMarket/form-alert-collector/' );

require_once FAC_DIR . 'includes/class-fac-sites.php';
require_once FAC_DIR . 'includes/class-fac-rules.php';
require_once FAC_DIR . 'includes/class-fac-slack.php';
require_once FAC_DIR . 'includes/class-fac-collector.php';

register_activation_hook( __FILE__, array( 'FAC_Collector', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FAC_Collector', 'deactivate' ) );

/**
 * Set up automatic updates from the GitHub repository, the same way Form
 * Alert does.
 *
 * Uses the Plugin Update Checker library (Yahnis Elsts) to compare the
 * installed version against the latest GitHub release/tag and surface
 * updates on the WordPress Plugins screen.
 */
function fac_init_updater() {
	$library = FAC_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

	if ( ! file_exists( $library ) ) {
		return;
	}

	require_once $library;

	$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		FAC_GITHUB_REPO,
		FAC_FILE,
		'form-alert-collector'
	);

	// The branch that holds the stable, production-ready code. On the default
	// branch, PUC prefers GitHub releases/tags over raw branch commits.
	$update_checker->setBranch( 'main' );

	// Only needed if the repo is made private: define this in wp-config.php
	// (fine-grained PAT, "Contents: Read-only", scoped to this repo).
	if ( defined( 'FAC_GITHUB_TOKEN' ) && FAC_GITHUB_TOKEN ) {
		$update_checker->setAuthentication( FAC_GITHUB_TOKEN );
	}
}
fac_init_updater();

// Registered at load time so the schedule exists when activation schedules the event.
add_filter( 'cron_schedules', array( 'FAC_Collector', 'cron_schedules' ) );

add_action( 'plugins_loaded', array( 'FAC_Collector', 'maybe_upgrade' ), 5 );
add_action( 'plugins_loaded', 'fac_boot' );

function fac_boot() {
	FAC_Collector::instance();

	if ( is_admin() ) {
		require_once FAC_DIR . 'admin/class-fac-admin.php';
		FAC_Admin::instance();
	}
}
