<?php
/**
 * Posts to a Slack incoming webhook (Settings page, or FAC_SLACK_WEBHOOK in
 * wp-config.php while that's blank), plus helpers for Slack's mrkdwn.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FAC_Slack {

	/** Option holding the outcome of the last post, shown on the Settings page. */
	const STATUS_OPTION = 'fac_slack_status';

	/** Slack truncates very long messages; split well before its limit. */
	const MAX_CHARS = 3500;

	public static function webhook() {
		return (string) fac_setting( 'slack_webhook', 'FAC_SLACK_WEBHOOK' );
	}

	public static function is_configured() {
		return '' !== self::webhook();
	}

	/**
	 * Posts messages, splitting them across several posts when they're long
	 * (e.g. many sites going quiet at once). Messages are never split
	 * themselves, only grouped.
	 *
	 * @param string[] $messages Slack mrkdwn, already escaped.
	 * @return bool Whether every post was accepted (false too when Slack isn't set up).
	 */
	public static function send_all( $messages ) {
		$ok    = true;
		$batch = '';
		foreach ( $messages as $message ) {
			if ( '' !== $batch && strlen( $batch ) + strlen( $message ) > self::MAX_CHARS ) {
				$ok    = self::send( $batch ) && $ok;
				$batch = '';
			}
			$batch .= ( '' === $batch ? '' : "\n\n" ) . $message;
		}
		if ( '' !== $batch ) {
			$ok = self::send( $batch ) && $ok;
		}
		return $ok;
	}

	/**
	 * @param string $text Slack mrkdwn, already escaped.
	 * @return bool Whether Slack accepted it.
	 */
	public static function send( $text ) {
		$url = self::webhook();
		if ( '' === $url ) {
			return false;
		}
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'text'         => $text,
						'unfurl_links' => false,
						'unfurl_media' => false,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$status = array(
				'ok'    => false,
				'code'  => 0,
				'error' => $response->get_error_message(),
			);
		} else {
			$code   = (int) wp_remote_retrieve_response_code( $response );
			$ok     = 200 === $code;
			$status = array(
				'ok'    => $ok,
				'code'  => $code,
				// Slack answers "ok", or a short reason such as invalid_token or no_service.
				'error' => $ok ? '' : wp_html_excerpt( trim( wp_strip_all_tags( wp_remote_retrieve_body( $response ) ) ), 200, '…' ),
			);
		}
		$status['at'] = time();
		update_option( self::STATUS_OPTION, $status, false );
		return $status['ok'];
	}

	/** @return array ok, code, error, at; empty when nothing was sent yet. */
	public static function last_status() {
		$status = get_option( self::STATUS_OPTION, array() );
		return is_array( $status ) ? $status : array();
	}

	/** Escapes text for Slack mrkdwn: only &, < and > are special. */
	public static function esc( $text ) {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), (string) $text );
	}

	/** A Slack link, or the plain label when the URL isn't usable. */
	public static function link( $url, $label ) {
		$url = esc_url_raw( (string) $url, array( 'http', 'https' ) );
		if ( '' === $url ) {
			return self::esc( $label );
		}
		return '<' . str_replace( array( '<', '>', '|' ), array( '%3C', '%3E', '%7C' ), $url ) . '|' . self::esc( $label ) . '>';
	}
}
