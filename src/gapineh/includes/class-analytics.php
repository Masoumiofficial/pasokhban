<?php
/**
 * داشبورد آمار گفتگوها.
 *
 * @package Gapineh
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس آمار.
 */
final class Gapineh_Analytics {

	/**
	 * قلاب‌ها.
	 */
	public static function hooks() {
		add_action( 'admin_post_gapineh_export_stats', array( __CLASS__, 'export_csv' ) );
	}

	/**
	 * رندر داشبورد.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'gapineh' ) );
		}

		if ( ! Gapineh_DB::installed() ) {
			Gapineh_DB::install();
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$days = isset( $_GET['days'] ) ? (int) $_GET['days'] : 30;
		// phpcs:enable WordPress.Security.NonceVerification
		$days = in_array( $days, array( 7, 30, 90, 365 ), true ) ? $days : 30;

		$st = Gapineh_DB::get_stats( $days );
		?>
		<div class="wrap psb-admin psb-analytics">

			<div class="psb-adm-hero">
				<div>
					<h1><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e( 'آمار گفتگوها', 'gapineh' ); ?></h1>
					<p class="psb-adm-lede">
						<?php
						printf(
							/* translators: %d: number of days */
							esc_html__( 'گزارش %d روز اخیر', 'gapineh' ),
							(int) $days
						);
						?>
					</p>
				</div>
				<div class="psb-an-ranges">
					<?php
					foreach ( array( 7, 30, 90, 365 ) as $d ) {
						$url   = add_query_arg( array( 'page' => 'gapineh-analytics', 'days' => $d ), admin_url( 'admin.php' ) );
						$label = ( 365 === $d ) ? __( 'یک سال', 'gapineh' ) : sprintf( '%d %s', $d, __( 'روز', 'gapineh' ) );
						printf(
							'<a class="button %s" href="%s">%s</a>',
							$d === $days ? 'button-primary' : '',
							esc_url( $url ),
							esc_html( $label )
						);
					}
					?>
				</div>
			</div>

			<div class="psb-an-cards">
				<?php
				self::card( $st['new_sessions'], __( 'گفتگوی جدید', 'gapineh' ), __( 'از مجموع', 'gapineh' ) . ' ' . (int) $st['total_sessions'], '💬' );
				self::card( $st['visitor_msgs'], __( 'پیام کاربران', 'gapineh' ), (int) $st['agent_msgs'] . ' ' . __( 'پاسخ', 'gapineh' ), '✉️' );
				self::card(
					null !== $st['csat'] ? $st['csat'] . '٪' : '—',
					__( 'رضایت کاربران', 'gapineh' ),
					'👍 ' . (int) $st['thumbs_up'] . ' · 👎 ' . (int) $st['thumbs_down'],
					'⭐'
				);
				self::card(
					$st['avg_ms'] ? ( $st['avg_ms'] / 1000 ) . 's' : '—',
					__( 'میانگین زمان پاسخ', 'gapineh' ),
					__( 'هوش مصنوعی', 'gapineh' ),
					'⚡'
				);
				?>
			</div>

			<div class="psb-an-grid">
				<div class="psb-an-card psb-an-chart">
					<h2><?php esc_html_e( 'روند گفتگوها و پیام‌ها', 'gapineh' ); ?></h2>
					<?php echo self::bar_chart( $st['daily'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<div class="psb-an-legend">
						<span><i class="c1"></i> <?php esc_html_e( 'گفتگوی جدید', 'gapineh' ); ?></span>
						<span><i class="c2"></i> <?php esc_html_e( 'پیام', 'gapineh' ); ?></span>
					</div>
				</div>

				<div class="psb-an-card">
					<h2><?php esc_html_e( 'سهم پاسخ‌دهنده', 'gapineh' ); ?></h2>
					<?php echo self::donut( (int) $st['ai_msgs'], (int) $st['human_msgs'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<div class="psb-an-legend">
						<span><i class="c1"></i> <?php esc_html_e( 'هوش مصنوعی', 'gapineh' ); ?> — <?php echo (int) $st['ai_msgs']; ?></span>
						<span><i class="c3"></i> <?php esc_html_e( 'اپراتور انسانی', 'gapineh' ); ?> — <?php echo (int) $st['human_msgs']; ?></span>
					</div>
				</div>
			</div>

			<div class="psb-an-grid">
				<div class="psb-an-card">
					<h2><?php esc_html_e( 'پرتکرارترین سؤال‌ها', 'gapineh' ); ?></h2>
					<?php if ( empty( $st['top_questions'] ) ) : ?>
						<p class="psb-an-empty"><?php esc_html_e( 'هنوز داده‌ای نیست.', 'gapineh' ); ?></p>
					<?php else : ?>
						<ol class="psb-an-top">
							<?php foreach ( $st['top_questions'] as $q ) : ?>
								<li>
									<span class="q"><?php echo esc_html( $q['q'] ); ?></span>
									<span class="n"><?php echo (int) $q['n']; ?>×</span>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php endif; ?>
				</div>

				<div class="psb-an-card">
					<h2><?php esc_html_e( 'خلاصه', 'gapineh' ); ?></h2>
					<table class="psb-an-table">
						<tr><td><?php esc_html_e( 'کل مکالمات', 'gapineh' ); ?></td><td><b><?php echo (int) $st['total_sessions']; ?></b></td></tr>
						<tr><td><?php esc_html_e( 'کل پیام‌ها', 'gapineh' ); ?></td><td><b><?php echo (int) $st['total_messages']; ?></b></td></tr>
						<tr><td><?php esc_html_e( 'مخاطب با شمارهٔ تماس', 'gapineh' ); ?></td><td><b><?php echo (int) $st['with_contact']; ?></b></td></tr>
						<tr><td><?php esc_html_e( 'امتیاز مثبت', 'gapineh' ); ?></td><td><b>👍 <?php echo (int) $st['thumbs_up']; ?></b></td></tr>
						<tr><td><?php esc_html_e( 'امتیاز منفی', 'gapineh' ); ?></td><td><b>👎 <?php echo (int) $st['thumbs_down']; ?></b></td></tr>
					</table>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'gapineh_export_stats', 'days' => $days ), admin_url( 'admin-post.php' ) ), 'gapineh_export_stats' ) ); ?>">
						⬇ <?php esc_html_e( 'خروجی CSV روزانه', 'gapineh' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * یک کارت آمار.
	 *
	 * @param mixed  $value
	 * @param string $label
	 * @param string $sub
	 * @param string $icon
	 */
	private static function card( $value, $label, $sub, $icon ) {
		?>
		<div class="psb-an-stat">
			<span class="ico"><?php echo esc_html( $icon ); ?></span>
			<div>
				<b><?php echo esc_html( (string) $value ); ?></b>
				<span class="lbl"><?php echo esc_html( $label ); ?></span>
				<small><?php echo esc_html( $sub ); ?></small>
			</div>
		</div>
		<?php
	}

	/**
	 * نمودار میله‌ای SVG (بدون کتابخانهٔ خارجی).
	 *
	 * @param array $daily
	 * @return string
	 */
	private static function bar_chart( $daily ) {
		if ( empty( $daily ) ) {
			return '<p class="psb-an-empty">' . esc_html__( 'داده‌ای نیست.', 'gapineh' ) . '</p>';
		}

		$max = 1;
		foreach ( $daily as $d ) {
			$max = max( $max, (int) $d['chats'], (int) $d['messages'] );
		}

		$w     = 720;
		$h     = 200;
		$pad   = 24;
		$count = count( $daily );
		$bw    = ( $w - ( $pad * 2 ) ) / max( 1, $count );

		$svg = '<svg class="psb-an-svg" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img">';

		// خطوط افقی
		for ( $i = 0; $i <= 4; $i++ ) {
			$y = $pad + ( ( $h - $pad * 2 ) / 4 ) * $i;
			$svg .= '<line x1="' . $pad . '" y1="' . round( $y, 1 ) . '" x2="' . ( $w - $pad ) . '" y2="' . round( $y, 1 ) . '" class="grid"/>';
		}

		foreach ( $daily as $i => $d ) {
			$x      = $pad + ( $bw * $i );
			$inner  = max( 1, $bw - 3 );
			$ch     = ( (int) $d['chats'] / $max ) * ( $h - $pad * 2 );
			$mh     = ( (int) $d['messages'] / $max ) * ( $h - $pad * 2 );
			$tip    = esc_attr( $d['date'] . ' — ' . $d['chats'] . ' گفتگو، ' . $d['messages'] . ' پیام' );

			$svg .= '<g><title>' . $tip . '</title>';
			$svg .= '<rect class="b2" x="' . round( $x, 1 ) . '" y="' . round( $h - $pad - $mh, 1 ) . '" width="' . round( $inner, 1 ) . '" height="' . round( max( 0, $mh ), 1 ) . '" rx="1.5"/>';
			$svg .= '<rect class="b1" x="' . round( $x, 1 ) . '" y="' . round( $h - $pad - $ch, 1 ) . '" width="' . round( $inner, 1 ) . '" height="' . round( max( 0, $ch ), 1 ) . '" rx="1.5"/>';
			$svg .= '</g>';
		}

		$svg .= '</svg>';

		return $svg;
	}

	/**
	 * نمودار دونات SVG.
	 *
	 * @param int $ai
	 * @param int $human
	 * @return string
	 */
	private static function donut( $ai, $human ) {
		$total = $ai + $human;
		if ( 0 === $total ) {
			return '<p class="psb-an-empty">' . esc_html__( 'داده‌ای نیست.', 'gapineh' ) . '</p>';
		}

		$r     = 60;
		$c     = 2 * M_PI * $r;
		$frac  = $ai / $total;
		$dash1 = round( $c * $frac, 2 );
		$dash2 = round( $c * ( 1 - $frac ), 2 );
		$pct   = round( $frac * 100 );

		$svg  = '<svg class="psb-an-donut" viewBox="0 0 160 160" role="img">';
		$svg .= '<circle class="ring-bg" cx="80" cy="80" r="' . $r . '"/>';
		$svg .= '<circle class="ring-2" cx="80" cy="80" r="' . $r . '" stroke-dasharray="' . $c . '"/>';
		$svg .= '<circle class="ring-1" cx="80" cy="80" r="' . $r . '" stroke-dasharray="' . $dash1 . ' ' . $dash2 . '"/>';
		$svg .= '<text x="80" y="76" class="pct">' . $pct . '٪</text>';
		$svg .= '<text x="80" y="96" class="cap">' . esc_html__( 'هوش مصنوعی', 'gapineh' ) . '</text>';
		$svg .= '</svg>';

		return $svg;
	}

	/**
	 * خروجی CSV سری روزانه.
	 */
	public static function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'gapineh' ) );
		}
		check_admin_referer( 'gapineh_export_stats' );

		$days  = isset( $_GET['days'] ) ? (int) $_GET['days'] : 30; // phpcs:ignore WordPress.Security.NonceVerification
		$daily = Gapineh_DB::daily_series( $days );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . Gapineh_Jalali::file_stamp( 'gapineh-stats' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		fputcsv( $out, array( __( 'تاریخ', 'gapineh' ), __( 'گفتگوی جدید', 'gapineh' ), __( 'تعداد پیام', 'gapineh' ) ) );

		foreach ( $daily as $d ) {
			fputcsv( $out, array( $d['date'], (int) $d['chats'], (int) $d['messages'] ) );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
