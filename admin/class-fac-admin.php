<?php
/**
 * Admin pages: the Sites overview (every reporting site, its status and
 * problems) and Settings (connection details for Form Alert, Slack, alert
 * timing).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FAC_Admin {

	/** @var FAC_Admin */
	private static $instance;

	/**
	 * Printed into the Slack webhook field once one is saved, so the URL
	 * never reaches the page source; submitting it unchanged keeps the saved
	 * value. Same approach as Form Alert's secret fields.
	 */
	const SAVED_SECRET_MASK = '••••••••••••';

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_fac_site', array( $this, 'handle_site_action' ) );
		add_action( 'admin_post_fac_test_slack', array( $this, 'handle_test_slack' ) );
		add_action( 'admin_post_fac_check_now', array( $this, 'handle_check_now' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	public function admin_menu() {
		add_menu_page( 'Form Alert Sites', 'Form Alert Sites', 'manage_options', 'fac-sites', array( $this, 'render_sites_page' ), 'dashicons-heart', 81 );
		add_submenu_page( 'fac-sites', 'Form Alert Sites', 'Sites', 'manage_options', 'fac-sites', array( $this, 'render_sites_page' ) );
		add_submenu_page( 'fac-sites', 'Form Alert Collector Settings', 'Settings', 'manage_options', 'fac-settings', array( $this, 'render_settings_page' ) );
	}

	/* -------------------------------------------------------------------- */
	/* Settings                                                              */
	/* -------------------------------------------------------------------- */

	public function register_settings() {
		register_setting( 'fac_settings_group', FAC_OPTION, array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );
	}

	public function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$previous = get_option( FAC_OPTION, array() );
		$previous = is_array( $previous ) ? $previous : array();
		$out      = array();

		$saved_secret = isset( $previous['secret'] ) ? (string) $previous['secret'] : '';
		$secret       = isset( $input['secret'] ) ? trim( (string) $input['secret'] ) : $saved_secret;
		if ( '' === $secret && ! defined( 'FAC_SECRET' ) ) {
			add_settings_error( FAC_OPTION, 'fac_secret', 'The report secret can\'t be blank: sites sign their reports with it. The previous one was kept.' );
			$secret = $saved_secret;
		}
		$out['secret'] = $secret;

		$saved_webhook = isset( $previous['slack_webhook'] ) ? (string) $previous['slack_webhook'] : '';
		$webhook       = isset( $input['slack_webhook'] ) ? trim( (string) $input['slack_webhook'] ) : self::SAVED_SECRET_MASK;
		if ( self::SAVED_SECRET_MASK === $webhook ) {
			$out['slack_webhook'] = $saved_webhook;
		} else {
			$out['slack_webhook'] = esc_url_raw( $webhook, array( 'https' ) );
			if ( '' !== $webhook && '' === $out['slack_webhook'] ) {
				add_settings_error( FAC_OPTION, 'fac_slack', 'The Slack webhook URL must start with https://. It wasn\'t saved.' );
			}
		}

		$out['silent_hours'] = max( 1, (int) ( isset( $input['silent_hours'] ) ? $input['silent_hours'] : 3 ) );
		$out['remind_hours'] = max( 0, (int) ( isset( $input['remind_hours'] ) ? $input['remind_hours'] : 24 ) );
		return $out;
	}

	/* -------------------------------------------------------------------- */
	/* Actions                                                               */
	/* -------------------------------------------------------------------- */

	/** Mute, unmute or forget a site, from the Sites page. */
	public function handle_site_action() {
		$id = isset( $_POST['site_id'] ) ? (int) $_POST['site_id'] : 0;
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$this->check_request( 'fac_site_' . $id );

		$row = FAC_Sites::get_by_id( $id );
		if ( $row && in_array( $do, array( 'mute', 'unmute', 'forget' ), true ) ) {
			if ( 'forget' === $do ) {
				FAC_Sites::delete( $id );
			} else {
				FAC_Sites::set_muted( $id, 'mute' === $do );
			}
		}
		$this->redirect( 'fac-sites', array( 'fac_done' => $row ? $do : 'missing' ) );
	}

	public function handle_test_slack() {
		$this->check_request( 'fac_test_slack' );
		if ( ! FAC_Slack::is_configured() ) {
			$this->redirect( 'fac-settings', array( 'fac_slack' => 'off' ) );
		}
		$ok = FAC_Slack::send(
			sprintf(
				':wave: Test message from the Form Alert Collector on %s. Alerts about client sites will arrive here.',
				FAC_Slack::link( home_url(), wp_parse_url( home_url(), PHP_URL_HOST ) )
			)
		);
		$this->redirect( 'fac-settings', array( 'fac_slack' => $ok ? 'ok' : 'fail' ) );
	}

	/** Runs the 15-minute check now, so a change shows without waiting. */
	public function handle_check_now() {
		$this->check_request( 'fac_check_now' );
		FAC_Collector::instance()->check_sites();
		$this->redirect( 'fac-sites', array( 'fac_done' => 'checked' ) );
	}

	private function check_request( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fac' ) );
		}
		check_admin_referer( $nonce_action );
	}

	private function redirect( $page, $args ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function notices() {
		if ( ! isset( $_GET['page'] ) || ! in_array( $_GET['page'], array( 'fac-sites', 'fac-settings' ), true ) ) {
			return;
		}
		$messages = array(
			'mute'    => array( 'success', 'Site muted: its problems still show here, but Slack won\'t hear about them.' ),
			'unmute'  => array( 'success', 'Site unmuted: Slack will hear about its problems again, including any still open.' ),
			'forget'  => array( 'success', 'Site forgotten. If it reports again, it comes back as a new site.' ),
			'missing' => array( 'warning', 'That site is no longer listed.' ),
			'checked' => array( 'success', 'Checked every site.' ),
		);
		$done = isset( $_GET['fac_done'] ) ? sanitize_key( wp_unslash( $_GET['fac_done'] ) ) : '';
		if ( isset( $messages[ $done ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $done ][0] ), esc_html( $messages[ $done ][1] ) );
		}

		$slack = isset( $_GET['fac_slack'] ) ? sanitize_key( wp_unslash( $_GET['fac_slack'] ) ) : '';
		if ( 'ok' === $slack ) {
			echo '<div class="notice notice-success is-dismissible"><p>Test message sent. Check the Slack channel.</p></div>';
		} elseif ( 'fail' === $slack ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( 'Slack didn\'t accept the test message: ' . $this->slack_failure( FAC_Slack::last_status() ) ) . '</p></div>';
		} elseif ( 'off' === $slack ) {
			echo '<div class="notice notice-warning is-dismissible"><p>Save a Slack webhook URL first.</p></div>';
		}
	}

	private function slack_failure( $status ) {
		$code  = isset( $status['code'] ) ? (int) $status['code'] : 0;
		$error = isset( $status['error'] ) ? trim( (string) $status['error'] ) : '';
		if ( ! $code ) {
			return 'Slack couldn\'t be reached' . ( '' !== $error ? ' (' . $error . ')' : '' ) . '.';
		}
		return 'HTTP ' . $code . ( '' !== $error ? ': ' . $error : '' ) . '. Check the webhook URL, and that its Slack app and channel still exist.';
	}

	/* -------------------------------------------------------------------- */
	/* Sites page                                                            */
	/* -------------------------------------------------------------------- */

	public function render_sites_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$sites  = FAC_Sites::all();
		$newest = '';
		foreach ( $sites as $row ) {
			if ( $row['plugin_version'] && ( '' === $newest || version_compare( $row['plugin_version'], $newest, '>' ) ) ) {
				$newest = $row['plugin_version'];
			}
		}

		// Sites needing attention first, then the rest that alert, then muted
		// and non-production ones; by host within each.
		usort(
			$sites,
			function ( $a, $b ) {
				$rank = function ( $row ) {
					if ( ! FAC_Collector::alertable( $row ) ) {
						return 2;
					}
					return $row['problems'] ? 0 : 1;
				};
				return array( $rank( $a ), $a['site'] ) <=> array( $rank( $b ), $b['site'] );
			}
		);

		$attention = 0;
		$quiet     = 0;
		foreach ( $sites as $row ) {
			if ( ! FAC_Collector::alertable( $row ) ) {
				$quiet++;
			} elseif ( $row['problems'] ) {
				$attention++;
			}
		}
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Form Alert Sites</h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'fac_check_now' ); ?>
				<input type="hidden" name="action" value="fac_check_now">
				<button type="submit" class="page-title-action">Check now</button>
			</form>
			<hr class="wp-header-end">

			<?php if ( ! FAC_Slack::is_configured() ) : ?>
				<div class="notice notice-warning inline"><p>Slack isn't set up, so problems only show here. Add a webhook URL in <a href="<?php echo esc_url( admin_url( 'admin.php?page=fac-settings' ) ); ?>">Settings</a>.</p></div>
			<?php endif; ?>

			<?php if ( ! $sites ) : ?>
				<div class="fac-empty">
					<h2>No reports yet</h2>
					<p>Sites appear here once they send their first health report. In Form Alert on each site (or on the boilerplate, so sites cloned from it inherit it), go to <strong>Settings → Alerting &amp; Logging → Health reports</strong> and enter the Report URL and secret from this plugin's <a href="<?php echo esc_url( admin_url( 'admin.php?page=fac-settings' ) ); ?>">Settings</a>. <strong>Send report now</strong> there sends the first one straight away.</p>
				</div>
			<?php else : ?>
				<p class="fac-summary">
					<?php
					echo esc_html(
						sprintf(
							'%d %s reporting. %d %s attention. %d muted or not production (shown, never alerted).%s',
							count( $sites ),
							1 === count( $sites ) ? 'site' : 'sites',
							$attention,
							1 === $attention ? 'needs' : 'need',
							$quiet,
							$newest ? ' Newest Form Alert: ' . $newest . '.' : ''
						)
					);
					?>
				</p>
				<table class="widefat striped fac-sites">
					<thead>
						<tr>
							<th>Site</th>
							<th>Status</th>
							<th>Last report</th>
							<th>Sending</th>
							<th>Last 7 days</th>
							<th>Form Alert</th>
							<th><span class="screen-reader-text">Actions</span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sites as $row ) : ?>
							<?php $this->render_site_row( $row, $newest ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<style>
			.fac-summary { font-size: 14px; }
			.fac-empty { max-width: 720px; margin-top: 16px; padding: 4px 16px 8px; background: #fff; border: 1px solid #dcdcde; }
			.fac-sites td { vertical-align: top; }
			.fac-sites .fac-env { display: inline-block; margin-top: 2px; color: #646970; }
			.fac-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-weight: 600; font-size: 12px; white-space: nowrap; }
			.fac-badge-ok { background: #edfaef; color: #1a7f37; }
			.fac-badge-problem { background: #fcf0f1; color: #b32d2e; }
			.fac-badge-off { background: #f0f0f1; color: #50575e; }
			.fac-problems { margin: 6px 0 0; }
			.fac-problems li { margin-bottom: 6px; }
			.fac-problems .fac-detail { display: block; color: #50575e; }
			.fac-behind { color: #b26200; }
			.fac-actions form { display: inline; }
			.fac-actions .button-link { margin-right: 10px; }
			.fac-actions details { margin-top: 6px; }
			.fac-actions pre { max-width: 520px; max-height: 360px; overflow: auto; padding: 8px; background: #f6f7f7; font-size: 11px; }
			@media (max-width: 782px) {
				.fac-sites thead { display: none; }
				.fac-sites td { display: block; }
			}
		</style>
		<?php
	}

	private function render_site_row( $row, $newest ) {
		$report    = $row['report'];
		$alertable = FAC_Collector::alertable( $row );
		$counts    = FAC_Rules::get( $report, 'counts.7d', array() );
		$transport = FAC_Rules::get( $report, 'config.transport', '' );
		$labels    = array(
			'api'     => 'Mailgun API',
			'smtp'    => 'SMTP fallback',
			'default' => 'PHP mail()',
		);
		$version   = $row['plugin_version'];
		$nonce     = wp_create_nonce( 'fac_site_' . $row['id'] );

		if ( $row['muted'] ) {
			$badge = array( 'off', 'Muted' );
		} elseif ( ! $alertable ) {
			$badge = array( 'off', ucfirst( $row['environment'] ) );
		} elseif ( isset( $row['problems']['silent'] ) ) {
			$badge = array( 'problem', 'Not reporting' );
		} elseif ( $row['problems'] ) {
			$badge = array( 'problem', 1 === count( $row['problems'] ) ? '1 problem' : count( $row['problems'] ) . ' problems' );
		} else {
			$badge = array( 'ok', 'OK' );
		}
		?>
		<tr>
			<td>
				<strong><a href="<?php echo esc_url( $row['home_url'] ); ?>" target="_blank"><?php echo esc_html( $row['site'] ); ?></a></strong>
				<?php if ( 'production' !== $row['environment'] && '' !== $row['environment'] ) : ?>
					<br><span class="fac-env"><?php echo esc_html( $row['environment'] ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<span class="fac-badge fac-badge-<?php echo esc_attr( $badge[0] ); ?>"><?php echo esc_html( $badge[1] ); ?></span>
				<?php if ( $row['problems'] ) : ?>
					<ul class="fac-problems">
						<?php foreach ( $row['problems'] as $p ) : ?>
							<li>
								<strong><?php echo esc_html( $p['title'] ); ?></strong>
								<?php echo esc_html( '(' . human_time_diff( (int) $p['since'] ) . ( empty( $p['alerted_at'] ) ? ', not alerted yet' : '' ) . ')' ); ?>
								<span class="fac-detail"><?php echo esc_html( $p['detail'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</td>
			<td title="<?php echo esc_attr( wp_date( 'Y-m-d H:i:s', $row['last_report_at'] ) ); ?>">
				<?php echo esc_html( human_time_diff( $row['last_report_at'] ) . ' ago' ); ?>
			</td>
			<td>
				<?php echo esc_html( isset( $labels[ $transport ] ) ? $labels[ $transport ] : '—' ); ?>
				<?php if ( FAC_Rules::get( $report, 'config.track_opens', false ) ) : ?>
					<br><span class="fac-env">Tracking opens</span>
				<?php endif; ?>
			</td>
			<td>
				<?php
				echo esc_html(
					sprintf(
						'%d logged · %d delivered · %d failed · %d opened',
						isset( $counts['logged'] ) ? $counts['logged'] : 0,
						isset( $counts['delivered'] ) ? $counts['delivered'] : 0,
						isset( $counts['failed'] ) ? $counts['failed'] : 0,
						isset( $counts['opened'] ) ? $counts['opened'] : 0
					)
				);
				?>
			</td>
			<td>
				<?php echo esc_html( $version ? $version : '—' ); ?>
				<?php if ( $version && $newest && version_compare( $version, $newest, '<' ) ) : ?>
					<br><span class="fac-behind">Behind</span>
				<?php endif; ?>
			</td>
			<td class="fac-actions">
				<?php if ( ! empty( $report['log_url'] ) ) : ?>
					<a class="button-link" href="<?php echo esc_url( $report['log_url'] ); ?>" target="_blank">Email Log</a>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fac_site">
					<input type="hidden" name="site_id" value="<?php echo esc_attr( $row['id'] ); ?>">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
					<button type="submit" class="button-link" name="do" value="<?php echo $row['muted'] ? 'unmute' : 'mute'; ?>"><?php echo $row['muted'] ? 'Unmute' : 'Mute'; ?></button>
					<button type="submit" class="button-link button-link-delete" name="do" value="forget" onclick="return confirm( 'Forget this site? It comes back if it reports again.' );">Forget</button>
				</form>
				<details>
					<summary>Latest report</summary>
					<pre><?php echo esc_html( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
				</details>
			</td>
		</tr>
		<?php
	}

	/* -------------------------------------------------------------------- */
	/* Settings page                                                         */
	/* -------------------------------------------------------------------- */

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o            = get_option( FAC_OPTION, array() );
		$o            = is_array( $o ) ? $o : array();
		$saved_secret = isset( $o['secret'] ) ? (string) $o['secret'] : '';
		$slack_status = FAC_Slack::last_status();
		?>
		<div class="wrap">
			<h1>Form Alert Collector Settings</h1>
			<?php settings_errors( FAC_OPTION ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'fac_settings_group' ); ?>

				<h2 class="title">Connecting sites</h2>
				<p class="description">Enter these in Form Alert under <strong>Settings → Alerting &amp; Logging → Health reports</strong>, on each site or on the boilerplate (sites cloned from it inherit them).</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Report URL</th>
						<td><code id="fac_report_url"><?php echo esc_html( FAC_Collector::report_url() ); ?></code>
						<button type="button" class="button-link" data-fac-copy="fac_report_url" style="margin-left:8px;">Copy</button></td>
					</tr>
					<tr>
						<th scope="row"><label for="fac_secret">Report secret</label></th>
						<td>
							<?php // Printed in full (unlike the Slack URL) so it can be copied into each site; this page is admin-only. ?>
							<input name="<?php echo esc_attr( FAC_OPTION ); ?>[secret]" id="fac_secret" type="password" class="regular-text code" autocomplete="off" value="<?php echo esc_attr( $saved_secret ); ?>"<?php echo '' === $saved_secret && defined( 'FAC_SECRET' ) ? ' placeholder="Using FAC_SECRET from wp-config.php"' : ''; ?>>
							<button type="button" class="button" data-fac-reveal="fac_secret" style="margin-left:4px;">Show</button>
							<button type="button" class="button-link" data-fac-copy="fac_secret" style="margin-left:8px;">Copy</button>
							<p class="description">Shared by every site: it signs their reports, so reports without it are rejected. Changing it stops every site's reports until each has the new one.
							<?php if ( defined( 'FAC_SECRET' ) ) : ?>
								A <code>FAC_SECRET</code> constant in <code>wp-config.php</code> is used while this is blank.
							<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>

				<hr style="margin:24px 0;">

				<h2 class="title">Slack</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fac_slack_webhook">Incoming webhook URL</label></th>
						<td>
							<input name="<?php echo esc_attr( FAC_OPTION ); ?>[slack_webhook]" id="fac_slack_webhook" type="password" class="regular-text" autocomplete="new-password" value="<?php echo ! empty( $o['slack_webhook'] ) ? esc_attr( self::SAVED_SECRET_MASK ) : ''; ?>" placeholder="<?php echo defined( 'FAC_SLACK_WEBHOOK' ) ? 'Using FAC_SLACK_WEBHOOK from wp-config.php' : 'https://hooks.slack.com/services/...'; ?>">
							<?php if ( FAC_Slack::is_configured() ) : ?>
								<button type="submit" class="button" form="fac-test-slack-form" style="margin-left:4px;">Send test message</button>
							<?php endif; ?>
							<p class="description">At <a href="https://api.slack.com/apps" target="_blank">api.slack.com/apps</a>: <strong>Create New App → From scratch</strong>, pick your workspace, open <strong>Incoming Webhooks</strong>, switch it on, then <strong>Add New Webhook</strong> and pick the channel alerts should go to. Paste the URL it gives you here and save.</p>
							<?php if ( ! empty( $slack_status['at'] ) ) : ?>
								<p class="description">
									<?php
									echo esc_html(
										! empty( $slack_status['ok'] )
											? 'Last message accepted ' . human_time_diff( (int) $slack_status['at'] ) . ' ago.'
											: 'Last message failed ' . human_time_diff( (int) $slack_status['at'] ) . ' ago: ' . $this->slack_failure( $slack_status ) . ' Alerts that failed are retried every 15 minutes.'
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<hr style="margin:24px 0;">

				<h2 class="title">Alerts</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fac_silent_hours">Not reporting after</label></th>
						<td><input name="<?php echo esc_attr( FAC_OPTION ); ?>[silent_hours]" id="fac_silent_hours" type="number" min="1" style="width:70px" value="<?php echo esc_attr( FAC_Collector::silent_hours() ); ?>"> hours
						<p class="description">Sites report hourly, but WP-Cron only runs when a site gets visits, so quiet sites can be late. Under 3 hours means false alarms.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="fac_remind_hours">Remind every</label></th>
						<td><input name="<?php echo esc_attr( FAC_OPTION ); ?>[remind_hours]" id="fac_remind_hours" type="number" min="0" style="width:70px" value="<?php echo esc_attr( FAC_Collector::remind_hours() ); ?>"> hours
						<p class="description">While a problem lasts, Slack hears about it again this often. 0 = only once. Mute a site on the Sites page to stop its alerts altogether.</p></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<form id="fac-test-slack-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'fac_test_slack' ); ?>
				<input type="hidden" name="action" value="fac_test_slack">
			</form>
		</div>
		<script>
		( function () {
			document.addEventListener( 'click', function ( e ) {
				var reveal = e.target.closest( '[data-fac-reveal]' );
				if ( reveal ) {
					var input = document.getElementById( reveal.getAttribute( 'data-fac-reveal' ) );
					var show  = 'password' === input.type;
					input.type         = show ? 'text' : 'password';
					reveal.textContent = show ? 'Hide' : 'Show';
					return;
				}
				var btn = e.target.closest( '[data-fac-copy]' );
				if ( ! btn ) {
					return;
				}
				var el   = document.getElementById( btn.getAttribute( 'data-fac-copy' ) );
				var text = 'value' in el && 'CODE' !== el.tagName ? el.value : el.textContent;
				function done() {
					var label = btn.textContent;
					btn.textContent = 'Copied';
					setTimeout( function () {
						btn.textContent = label;
					}, 1500 );
				}
				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( text ).then( done );
				} else {
					var area = document.createElement( 'textarea' );
					area.value = text;
					document.body.appendChild( area );
					area.select();
					try {
						document.execCommand( 'copy' );
						done();
					} catch ( err ) {}
					document.body.removeChild( area );
				}
			} );
		} )();
		</script>
		<?php
	}
}
