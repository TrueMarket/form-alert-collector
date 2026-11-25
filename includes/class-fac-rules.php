<?php
/**
 * What counts as a problem in a Form Alert health report.
 *
 * evaluate() turns one report into a map of rule key => problem. Each rule
 * only fires when the site actually sent mail it should have been able to
 * track, so a quiet site with no form submissions this week isn't flagged.
 * Not reporting at all is handled separately, by FAC_Collector::check_sites().
 *
 * Thresholds can be changed with the fac_thresholds filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FAC_Rules {

	/** @return array */
	public static function thresholds() {
		return apply_filters(
			'fac_thresholds',
			array(
				// API sends from the last 7 days still at Sent after 2 hours.
				'stuck_sent'           => 2,
				// Emails from the last 7 days still Pending after an hour.
				'stuck_pending'        => 2,
				// Failed emails in the last 24 hours.
				'failed_24h'           => 5,
				// Delivered emails in the last 7 days before zero opens counts as a problem.
				'opens_min_delivered'  => 10,
				// Hours without an unopened-email check run.
				'unopened_check_hours' => 3,
			)
		);
	}

	/**
	 * @param array      $report   The report.
	 * @param array|null $previous The site's report before this one, if any.
	 * @param string[]   $active   Rule keys the site has open right now.
	 * @param int|null   $now      Unix time; defaults to the report's generated_at.
	 * @return array Rule key => array( title, detail ).
	 */
	public static function evaluate( $report, $previous = null, $active = array(), $now = null ) {
		$t     = self::thresholds();
		$now   = $now ? $now : ( self::time( $report, 'generated_at' ) ? self::time( $report, 'generated_at' ) : time() );
		$found = array();

		$transport   = (string) self::get( $report, 'config.transport', '' );
		$api         = 'api' === $transport;
		$track_opens = (bool) self::get( $report, 'config.track_opens', false );
		$watched     = (int) self::get( $report, 'config.watched_emails', 0 );

		// How mail goes out.
		if ( 'default' === $transport ) {
			$found['no_mailgun'] = self::problem(
				'Not sending through Mailgun',
				'Neither the Mailgun API nor the SMTP fallback is set up, so mail goes out through the server\'s own mail(), which most hosts don\'t deliver reliably, and none of it is tracked.'
			);
		} elseif ( 'smtp' === $transport ) {
			// Plenty of sites are on the fallback because they have no domain of
			// their own yet. Only a site that was on the API counts; it stays
			// flagged until it's back on the API (or muted).
			if ( 'api' === self::get( $previous, 'config.transport', '' ) || in_array( 'api_dropped', $active, true ) ) {
				$why                  = self::get( $report, 'config.api_sending_enabled', true )
					? 'Check the Mailgun API key and sending domain on the Sending tab.'
					: 'Send via Mailgun API has been switched off on the Sending tab.';
				$found['api_dropped'] = self::problem(
					'Dropped to the SMTP fallback',
					'The site was sending through the Mailgun API and now uses the shared SMTP fallback, so mail still goes out but delivery and opens are no longer tracked. ' . $why
				);
			}
		}

		// Webhooks.
		if ( $api && ! self::get( $report, 'config.signing_key_set', false ) ) {
			$found['no_signing_key'] = self::problem(
				'No webhook signing key',
				'Every Mailgun webhook is rejected without it, so emails stay at Sent, bounces aren\'t alerted and opens aren\'t recorded. Add it on the Sending tab.'
			);
		} elseif ( $api ) {
			// Only emails sent after the newest one a webhook confirmed count
			// (Form Alert 2.2.26+), so once an outage is fixed the emails it
			// stranded don't keep this open for the rest of the week. Older
			// versions only report the week's total.
			$week  = (int) self::get( $report, 'counts.stuck_sent_api', 0 );
			$stuck = (int) self::get( $report, 'counts.stuck_sent_api_since_reconciled', $week );
			if ( $stuck >= $t['stuck_sent'] ) {
				$found['webhooks_missing'] = self::problem(
					'Webhooks aren\'t arriving',
					sprintf(
						'%s sent through Mailgun %s %s still at Sent after 2+ hours, so bounces and unopened emails aren\'t being alerted. %s',
						self::count( $stuck, 'email', 'emails' ),
						$stuck < $week ? 'since the last one a webhook confirmed' : 'this week',
						1 === $stuck ? 'is' : 'are',
						self::webhook_hint( $report, $now )
					)
				);
			}
		}

		// Opens.
		$delivered = (int) self::get( $report, 'counts.7d.delivered', 0 );
		if ( $track_opens && $delivered >= $t['opens_min_delivered'] && 0 === (int) self::get( $report, 'counts.7d.opened', 0 ) ) {
			$found['opens_missing'] = self::problem(
				'Opens aren\'t being recorded',
				sprintf( 'Track opens is on, but none of the %d emails delivered this week were opened. Check the domain\'s Mailgun webhook includes the opened event.', $delivered )
				. ( $watched > 0 ? ' Until then every watched email looks unopened, so the client gets false unopened alerts.' : '' )
			);
		}

		// Sends that never finish.
		$pending = (int) self::get( $report, 'counts.stuck_pending', 0 );
		if ( $pending >= $t['stuck_pending'] ) {
			$found['stuck_pending'] = self::problem(
				'Emails stuck at Pending',
				sprintf( '%s this week never finished sending (still Pending after an hour): the send is failing part-way, or another plugin is taking over wp_mail().', self::count( $pending, 'email', 'emails' ) )
			);
		}

		// Failures. The client is alerted about each one; this is for when they pile up.
		$failed = (int) self::get( $report, 'counts.24h.failed', 0 );
		$outage = (bool) self::get( $report, 'activity.outage_alarm_active', false );
		if ( $outage || $failed >= $t['failed_24h'] ) {
			$found['failures'] = self::problem(
				'Emails failing',
				sprintf( '%s failed in the last 24 hours.', self::count( $failed, 'email', 'emails' ) )
				. ( $outage ? ' The site has raised its "possible email notification outage" alarm: sends are failing and none are succeeding.' : '' )
				. ' The Email Log has the reasons.'
			);
		}

		// The unopened check only matters when it has something to check.
		if ( $track_opens && $watched > 0 ) {
			$last_check = self::time( $report, 'activity.last_unopened_check_at' );
			if ( ! self::get( $report, 'activity.unopened_check_scheduled', true ) ) {
				$found['unopened_check'] = self::problem(
					'Unopened check isn\'t running',
					'The hourly unopened-email check isn\'t scheduled, so unopened alerts aren\'t going out. Deactivating and reactivating Form Alert schedules it again.'
				);
			} elseif ( $last_check && $now - $last_check > $t['unopened_check_hours'] * HOUR_IN_SECONDS ) {
				$found['unopened_check'] = self::problem(
					'Unopened check isn\'t running',
					sprintf( 'The hourly unopened-email check last ran %s ago, so unopened alerts aren\'t going out on time.', human_time_diff( $last_check, $now ) )
				);
			}
		}

		// Texts. Unopened alerts text the alert numbers whenever Track opens is on.
		$texts_on = self::get( $report, 'config.delivery_failure_texts', false ) || self::get( $report, 'config.submission_texts', false ) || $track_opens;
		if ( (int) self::get( $report, 'config.alert_phone_numbers', 0 ) > 0 && $texts_on && ! self::get( $report, 'config.twilio_configured', false ) ) {
			$found['twilio_missing'] = self::problem(
				'Texts can\'t be sent',
				'Alert phone numbers are saved, but Twilio isn\'t fully set up on the Alerting & Logging tab, so no texts are going out.'
			);
		}

		return $found;
	}

	/** Why webhooks might not be arriving, from when they last arrived or were rejected. */
	private static function webhook_hint( $report, $now ) {
		$last     = self::time( $report, 'activity.last_webhook_at' );
		$rejected = self::time( $report, 'activity.last_webhook_rejected_at' );
		if ( $rejected && $rejected >= $last ) {
			return 'Webhooks are reaching the site but being rejected, so its signing key probably doesn\'t match Mailgun\'s.';
		}
		if ( ! $last ) {
			return 'No webhook has arrived since the site started sending health reports: check this domain has a webhook in Mailgun pointing at the site.';
		}
		return sprintf(
			'The last webhook arrived %s ago. Check the domain\'s webhook in Mailgun, and that nothing (a firewall, security plugin or maintenance mode) is blocking it.',
			human_time_diff( $last, $now )
		);
	}

	/** @return array{title: string, detail: string} */
	public static function problem( $title, $detail ) {
		return array(
			'title'  => $title,
			'detail' => $detail,
		);
	}

	/**
	 * A value from a nested array by dot path, e.g. 'counts.7d.delivered'.
	 *
	 * @param array|null $data
	 * @param string     $path
	 * @param mixed      $fallback
	 * @return mixed
	 */
	public static function get( $data, $path, $fallback = null ) {
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $data ) || ! array_key_exists( $part, $data ) ) {
				return $fallback;
			}
			$data = $data[ $part ];
		}
		return null === $data ? $fallback : $data;
	}

	/** A report time (ISO 8601) as a Unix timestamp; 0 for null or unparseable. */
	public static function time( $report, $path ) {
		$value = self::get( $report, $path, '' );
		$ts    = is_string( $value ) && '' !== $value ? strtotime( $value ) : false;
		return $ts ? (int) $ts : 0;
	}

	private static function count( $n, $one, $many ) {
		return $n . ' ' . ( 1 === (int) $n ? $one : $many );
	}
}
