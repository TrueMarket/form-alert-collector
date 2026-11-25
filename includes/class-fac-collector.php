<?php
/**
 * Receives reports, keeps each site's problems up to date, and decides
 * what Slack hears about.
 *
 * A problem goes through three stages:
 *   - found: a report (FAC_Rules) or the silence check flags it. It's shown
 *     on the Sites page straight away.
 *   - alerted: once it has lasted past its grace period (GRACE for report
 *     problems, so a one-off blip that the next hourly report clears never
 *     alerts; none for "not reporting", which is already hours old), Slack
 *     gets one message per site listing everything open there. While it
 *     lasts, a reminder goes out every remind_hours (Settings).
 *   - resolved: when a report or check no longer finds it, Slack hears that
 *     it's fixed, if it had been told about it.
 * Muted and non-production sites go through the same stages but never
 * alert. A problem is only marked alerted once Slack accepts the message,
 * so a failed post is retried by the next check.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FAC_Collector {

	/** @var FAC_Collector */
	private static $instance;

	const ROUTE_NAMESPACE = 'form-alert-collector/v1';
	const ROUTE           = '/report';

	/** WP-Cron event for the silence check, see check_sites(). */
	const CRON_HOOK     = 'fac_check_sites';
	const CRON_SCHEDULE = 'fac_fifteen_minutes';

	const DB_VERSION_OPTION = 'fac_db_version';

	/** Option: whether no site at all is reporting, see fleet_quiet(). */
	const FLEET_OPTION = 'fac_fleet_state';

	/** Seconds a report problem must last before it alerts (past one more hourly report). */
	const GRACE = 2700;

	/** Reports timestamped further than this from now (seconds) are rejected, against replays. */
	const MAX_SKEW = 300;

	/** Largest report accepted, in bytes. Real ones are around 2 KB. */
	const MAX_BODY = 102400;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::CRON_HOOK, array( $this, 'check_sites' ) );
		// Puts the event back if it ever goes missing. The cron array is autoloaded, so this is cheap.
		self::schedule();
	}

	/* -------------------------------------------------------------------- */
	/* Setup                                                                 */
	/* -------------------------------------------------------------------- */

	public static function activate() {
		FAC_Sites::install();
		update_option( self::DB_VERSION_OPTION, FAC_VERSION, false );

		$o = get_option( FAC_OPTION, array() );
		$o = array_merge(
			array(
				'secret'        => '',
				'slack_webhook' => '',
				'silent_hours'  => 3,
				'remind_hours'  => 24,
			),
			is_array( $o ) ? $o : array()
		);
		// Sites can't report until there's a secret to sign with, so start with one.
		if ( '' === $o['secret'] && ! defined( 'FAC_SECRET' ) ) {
			$o['secret'] = wp_generate_password( 40, false );
		}
		update_option( FAC_OPTION, $o );

		self::schedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** Runs on every plugins_loaded; a single option read once up to date. */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) === FAC_VERSION ) {
			return;
		}
		FAC_Sites::install();
		update_option( self::DB_VERSION_OPTION, FAC_VERSION, false );
	}

	public static function cron_schedules( $schedules ) {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'Every 15 minutes',
		);
		return $schedules;
	}

	private static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/* -------------------------------------------------------------------- */
	/* Settings                                                              */
	/* -------------------------------------------------------------------- */

	/** What goes in Form Alert's Report URL field. */
	public static function report_url() {
		return rest_url( self::ROUTE_NAMESPACE . self::ROUTE );
	}

	public static function secret() {
		return (string) fac_setting( 'secret', 'FAC_SECRET' );
	}

	/** Hours without a report before a site counts as not reporting. */
	public static function silent_hours() {
		return max( 1, (int) fac_setting( 'silent_hours', '', 3 ) );
	}

	/** Hours between reminders about an open problem; 0 = never. */
	public static function remind_hours() {
		return max( 0, (int) fac_setting( 'remind_hours', '', 24 ) );
	}

	/** Whether a site's problems go to Slack: production and not muted. */
	public static function alertable( $row ) {
		return empty( $row['muted'] ) && in_array( (string) $row['environment'], array( 'production', '' ), true );
	}

	/** A report's site field as a table key: a lowercase host, or '' if unusable. */
	public static function site_key( $site ) {
		$site = is_string( $site ) ? strtolower( trim( $site ) ) : '';
		return substr( preg_replace( '/[^a-z0-9.\-]/', '', $site ), 0, 191 );
	}

	/* -------------------------------------------------------------------- */
	/* Receiving reports                                                     */
	/* -------------------------------------------------------------------- */

	public function register_routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'receive' ),
				'permission_callback' => '__return_true', // Reports are signed; checked in receive().
			)
		);
	}

	public function receive( WP_REST_Request $request ) {
		$body = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_BODY ) {
			return new WP_REST_Response( array( 'error' => 'report too large' ), 413 );
		}
		if ( ! $this->verify( (string) $request->get_header( 'X-WPEL-Timestamp' ), (string) $request->get_header( 'X-WPEL-Signature' ), $body, time() ) ) {
			return new WP_REST_Response( array( 'error' => 'bad signature' ), 403 );
		}
		$report = json_decode( $body, true );
		if ( ! is_array( $report ) || empty( $report['schema'] ) || '' === self::site_key( isset( $report['site'] ) ? $report['site'] : '' ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid report' ), 400 );
		}

		$this->process_report( $report, time() );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Form Alert signs "<timestamp>.<raw body>" with HMAC-SHA256 and the
	 * shared secret (see WPEL_Health_Report::send()).
	 */
	public function verify( $timestamp, $signature, $body, $now ) {
		$secret = self::secret();
		if ( '' === $secret || '' === $timestamp || '' === $signature || ! ctype_digit( $timestamp ) ) {
			return false;
		}
		if ( abs( $now - (int) $timestamp ) > self::MAX_SKEW ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', $timestamp . '.' . $body, $secret ), strtolower( trim( $signature ) ) );
	}

	/**
	 * Stores a verified report as its site's latest, re-evaluates the site's
	 * problems (which also clears "not reporting"), and tells Slack.
	 *
	 * @param array $report
	 * @param int   $now
	 */
	public function process_report( $report, $now ) {
		$site     = self::site_key( $report['site'] );
		$existing = FAC_Sites::get( $site );
		$found    = FAC_Rules::evaluate(
			$report,
			$existing ? $existing['report'] : null,
			$existing ? array_keys( $existing['problems'] ) : array(),
			$now
		);

		FAC_Sites::save_report( $site, $report, $now );
		$row = FAC_Sites::get( $site );
		if ( ! $row ) {
			return;
		}

		$items = self::alertable( $row ) ? $this->fleet_resumed( $now ) : array();
		$items = array_merge( $items, $this->apply_problems( $row, $found, $now ) );
		$this->deliver( $items, $now );
	}

	/* -------------------------------------------------------------------- */
	/* Silence check (WP-Cron, every 15 minutes)                             */
	/* -------------------------------------------------------------------- */

	/**
	 * Flags sites that haven't reported for silent_hours, and sends any
	 * alerts that came due since the last report: problems past their grace
	 * period, reminders, and retries of posts Slack didn't accept.
	 *
	 * @param int|null $now Unix time; for tests.
	 */
	public function check_sites( $now = null ) {
		$now    = $now ? (int) $now : time();
		$sites  = FAC_Sites::all();
		$silent = self::silent_hours() * HOUR_IN_SECONDS;

		// If no site at all has reported, it's far more likely this collector
		// stopped receiving reports than that every site broke at once: one
		// message, instead of flagging every site.
		$latest = 0;
		foreach ( $sites as $row ) {
			if ( self::alertable( $row ) ) {
				$latest = max( $latest, $row['last_report_at'] );
			}
		}
		if ( $latest && $now - $latest > $silent ) {
			$this->deliver( $this->fleet_quiet( $latest ), $now );
			return;
		}

		$fleet = $this->fleet_state();
		$items = array();
		foreach ( $sites as $row ) {
			// Report problems stay as the last report left them.
			$found = $row['problems'];
			unset( $found['silent'] );

			// Counted from when reports resumed after the whole fleet went quiet,
			// so every site gets a full window to report again.
			if ( $now - max( $row['last_report_at'], (int) $fleet['resumed_at'] ) > $silent ) {
				$found['silent'] = isset( $row['problems']['silent'] ) ? $row['problems']['silent'] : $this->silent_problem( $row );
			}
			$items = array_merge( $items, $this->apply_problems( $row, $found, $now ) );
		}
		$this->deliver( $items, $now );
	}

	private function silent_problem( $row ) {
		$problem          = FAC_Rules::problem(
			'Not reporting',
			trim( sprintf( 'No health report since %s. %s', wp_date( 'M j, g:i a', $row['last_report_at'] ), self::alertable( $row ) ? $this->probe( $row['home_url'] ) : '' ) )
		);
		$problem['grace'] = 0; // Already hours old.
		return $problem;
	}

	/** Checks the site's home page, to tell "site down" from "site up but not reporting". */
	private function probe( $url ) {
		if ( ! $url ) {
			return '';
		}
		// What wp_safe_remote_get() would refuse, with a clearer reason than its
		// "A valid URL was not provided".
		if ( ! wp_http_validate_url( $url ) ) {
			return 'Its address doesn\'t lead to a public server (the domain may have lapsed or moved), so the site may be down.';
		}
		// Safe variant: the URL comes from the site's own report.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
			)
		);
		if ( is_wp_error( $response ) ) {
			return sprintf( 'Its home page didn\'t respond (%s), so the site may be down.', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 500 ) {
			return sprintf( 'Its home page answers HTTP %d, so the site may be broken.', $code );
		}
		return sprintf( 'Its home page answers (HTTP %d), so the site is up: Form Alert may have been deactivated, its report settings changed, or WP-Cron stopped running.', $code );
	}

	private function fleet_state() {
		$state = get_option( self::FLEET_OPTION, array() );
		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'quiet'      => false,
				'since'      => 0,
				'resumed_at' => 0,
			)
		);
	}

	/** Slack items for "no site is reporting", sent once until reports resume. */
	private function fleet_quiet( $latest ) {
		$state = $this->fleet_state();
		if ( $state['quiet'] ) {
			return array();
		}
		$state['quiet'] = true;
		$state['since'] = $latest;
		update_option( self::FLEET_OPTION, $state, false );

		return array(
			array(
				'text' => sprintf(
					":warning: *No site has sent a health report since %s.* That usually means this collector isn't receiving them (check %s is up and nothing is blocking its REST API) rather than every site breaking at once. Sites won't be flagged one by one until reports arrive again.",
					FAC_Slack::esc( wp_date( 'M j, g:i a', $latest ) ),
					FAC_Slack::link( home_url(), wp_parse_url( home_url(), PHP_URL_HOST ) )
				),
			),
		);
	}

	/** Slack items for reports arriving again after fleet_quiet(). */
	private function fleet_resumed( $now ) {
		$state = $this->fleet_state();
		if ( ! $state['quiet'] ) {
			return array();
		}
		$state['quiet']      = false;
		$state['resumed_at'] = $now;
		update_option( self::FLEET_OPTION, $state, false );

		return array(
			array(
				'text' => sprintf( ':white_check_mark: *Health reports are arriving again.* Each site has %d hours to report before it\'s flagged as not reporting.', self::silent_hours() ),
			),
		);
	}

	/* -------------------------------------------------------------------- */
	/* Problem state + Slack                                                 */
	/* -------------------------------------------------------------------- */

	/**
	 * Replaces a site's problems with $found and returns the Slack items the
	 * change calls for: an alert when a problem past its grace period hasn't
	 * been sent yet or is due a reminder (listing everything open on the
	 * site), and a resolved message for alerted problems that are gone.
	 * Alert items carry the site id and rule keys, which deliver() marks as
	 * alerted once Slack accepts them.
	 *
	 * @param array $row   From FAC_Sites.
	 * @param array $found Rule key => array( title, detail[, grace] ).
	 * @param int   $now
	 * @return array[] Items: text[, id, keys].
	 */
	private function apply_problems( $row, $found, $now ) {
		$old = $row['problems'];
		$new = array();
		foreach ( $found as $key => $p ) {
			$prev        = isset( $old[ $key ] ) && is_array( $old[ $key ] ) ? $old[ $key ] : array();
			$new[ $key ] = array(
				'title'      => (string) $p['title'],
				'detail'     => (string) $p['detail'],
				'since'      => isset( $prev['since'] ) ? (int) $prev['since'] : $now,
				'alerted_at' => isset( $prev['alerted_at'] ) ? (int) $prev['alerted_at'] : 0,
				'grace'      => isset( $p['grace'] ) ? (int) $p['grace'] : self::GRACE,
			);
		}
		if ( $new !== $old ) {
			FAC_Sites::set_problems( $row['id'], $new );
		}

		if ( ! self::alertable( $row ) ) {
			return array();
		}

		$items  = array();
		$remind = self::remind_hours() * HOUR_IN_SECONDS;
		$ready  = array();
		$due    = false;
		foreach ( $new as $key => $p ) {
			if ( $now - $p['since'] < $p['grace'] ) {
				continue;
			}
			$ready[ $key ] = $p;
			if ( ! $p['alerted_at'] || ( $remind && $now - $p['alerted_at'] >= $remind ) ) {
				$due = true;
			}
		}
		if ( $due ) {
			$items[] = array(
				'text' => $this->alert_text( $row, $ready, $now ),
				'id'   => $row['id'],
				'keys' => array_keys( $ready ),
			);
		}

		$resolved = array();
		foreach ( array_diff_key( $old, $new ) as $key => $p ) {
			if ( ! empty( $p['alerted_at'] ) ) {
				$resolved[ $key ] = $p;
			}
		}
		if ( $resolved ) {
			$items[] = array( 'text' => $this->resolved_text( $row, $resolved, $new, $now ) );
		}

		return $items;
	}

	/** Posts the items to Slack, and marks their problems alerted if Slack accepted them. */
	private function deliver( $items, $now ) {
		if ( ! $items || ! FAC_Slack::send_all( wp_list_pluck( $items, 'text' ) ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( empty( $item['id'] ) || empty( $item['keys'] ) ) {
				continue;
			}
			$row = FAC_Sites::get_by_id( $item['id'] );
			if ( ! $row ) {
				continue;
			}
			$problems = $row['problems'];
			foreach ( $item['keys'] as $key ) {
				if ( isset( $problems[ $key ] ) ) {
					$problems[ $key ]['alerted_at'] = $now;
				}
			}
			FAC_Sites::set_problems( $row['id'], $problems );
		}
	}

	private function alert_text( $row, $problems, $now ) {
		$new = 0;
		foreach ( $problems as $p ) {
			if ( ! $p['alerted_at'] ) {
				$new++;
			}
		}
		$count = count( $problems );
		$head  = $new
			? sprintf( ':rotating_light: *%s*: %s', $this->site_link( $row ), 1 === $count ? 'a problem' : $count . ' problems' )
			: sprintf( ':warning: *%s*: still %s', $this->site_link( $row ), 1 === $count ? 'a problem' : $count . ' problems' );

		$lines = array( $head );
		foreach ( $problems as $p ) {
			$when    = $p['alerted_at'] ? 'for ' . human_time_diff( $p['since'], $now ) : 'new';
			$lines[] = sprintf( '• *%s.* %s _(%s)_', FAC_Slack::esc( rtrim( $p['title'], '.' ) ), FAC_Slack::esc( $p['detail'] ), $when );
		}
		$lines[] = $this->links( $row );
		return implode( "\n", $lines );
	}

	private function resolved_text( $row, $resolved, $open, $now ) {
		$lines = array( sprintf( ':white_check_mark: *%s*: %s', $this->site_link( $row ), 1 === count( $resolved ) ? 'fixed' : count( $resolved ) . ' problems fixed' ) );
		foreach ( $resolved as $p ) {
			$lines[] = sprintf( '• %s _(lasted %s)_', FAC_Slack::esc( rtrim( (string) $p['title'], '.' ) ), human_time_diff( (int) $p['since'], $now ) );
		}
		if ( $open ) {
			$lines[] = sprintf( 'Still open: %s.', FAC_Slack::esc( implode( ', ', wp_list_pluck( $open, 'title' ) ) ) );
		}
		return implode( "\n", $lines );
	}

	private function site_link( $row ) {
		return FAC_Slack::link( $row['home_url'], $row['site'] );
	}

	private function links( $row ) {
		$links = array();
		if ( ! empty( $row['report']['log_url'] ) ) {
			$links[] = FAC_Slack::link( $row['report']['log_url'], 'Email Log' );
		}
		$links[] = FAC_Slack::link( admin_url( 'admin.php?page=fac-sites' ), 'All sites' );
		return implode( ' · ', $links );
	}
}
