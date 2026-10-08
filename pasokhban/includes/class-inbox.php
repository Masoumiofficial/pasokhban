<?php
/**
 * اینباکس گفتگوهای آنلاین در پیشخوان وردپرس.
 *
 * لیست مکالمات سمت سرور (PHP) رندر می‌شود تا حتی اگر جاوااسکریپت یا REST
 * به هر دلیلی کار نکند، مدیر همچنان داده‌ها را ببیند. جاوااسکریپت فقط
 * تازه‌سازی زنده و بازکردن رشتهٔ گفتگو را انجام می‌دهد.
 *
 * @package Pasokhban
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس اینباکس.
 */
final class Pasokhban_Inbox {

	/**
	 * قلاب‌های اینباکس.
	 */
	public static function hooks() {
		add_action( 'admin_post_pasokhban_repair', array( __CLASS__, 'handle_repair' ) );
	}

	/**
	 * تلاش دوباره برای ساخت جدول‌ها (از دکمهٔ «تعمیر دیتابیس»).
	 */
	public static function handle_repair() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'pasokhban' ) );
		}
		check_admin_referer( 'pasokhban_repair' );

		Pasokhban_DB::flush_cache();
		$res = Pasokhban_DB::install();

		$msg = is_wp_error( $res )
			? 'db_error: ' . rawurlencode( $res->get_error_message() )
			: 'db_ok';

		wp_safe_redirect( add_query_arg( 'pasokhban_repair', $msg, admin_url( 'admin.php?page=pasokhban-inbox' ) ) );
		exit;
	}

	/**
	 * آزمون زنده: یک ردیف می‌نویسد، می‌خواند و پاک می‌کند.
	 *
	 * @return array { ok: bool, detail: string }
	 */
	public static function self_test() {
		if ( ! Pasokhban_DB::tables_exist() ) {
			return array( 'ok' => false, 'detail' => __( 'جدول‌ها وجود ندارند.', 'pasokhban' ) );
		}

		$probe = Pasokhban_DB::create_session( array( 'lang' => 'fa', 'name' => '__selftest__' ) );

		if ( ! $probe ) {
			global $wpdb;
			return array(
				'ok'     => false,
				'detail' => __( 'نوشتن در جدول نشست‌ها ممکن نشد.', 'pasokhban' ) . ' ' . $wpdb->last_error,
			);
		}

		$msg = Pasokhban_DB::add_message( (int) $probe->id, 'visitor', '__selftest__' );
		$read = $msg ? Pasokhban_DB::get_messages( (int) $probe->id ) : array();

		Pasokhban_DB::get_session( (int) $probe->id );
		global $wpdb;
		$wpdb->delete( Pasokhban_DB::messages_table(), array( 'session_id' => (int) $probe->id ) );
		$wpdb->delete( Pasokhban_DB::sessions_table(), array( 'id' => (int) $probe->id ) );

		if ( ! $msg || empty( $read ) ) {
			return array( 'ok' => false, 'detail' => __( 'نوشتن/خواندن پیام ممکن نشد.', 'pasokhban' ) );
		}

		return array( 'ok' => true, 'detail' => __( 'نوشتن، خواندن و حذف هر سه موفق بود.', 'pasokhban' ) );
	}

	/**
	 * بارگذاری دارایی‌های صفحهٔ اینباکس.
	 *
	 * @param string $hook
	 */
	public static function assets( $hook ) {
		if ( ! Pasokhban_Settings::is_page( $hook, 'inbox' ) ) {
			return;
		}

		$opts = Pasokhban_Settings::instance()->get_options();

		wp_enqueue_style(
			'pasokhban-inbox',
			PASOKHBAN_URL . 'assets/css/pasokhban-inbox.css',
			array(),
			PASOKHBAN_VERSION
		);

		// بدون وابستگی به jQuery — یک IIFEٔ vanilla است.
		wp_enqueue_script(
			'pasokhban-inbox',
			PASOKHBAN_URL . 'assets/js/pasokhban-inbox.js',
			array(),
			PASOKHBAN_VERSION,
			true
		);

		wp_localize_script(
			'pasokhban-inbox',
			'PASOKHBAN_INBOX',
			array(
				'restUrl' => esc_url_raw( rest_url( 'pasokhban/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'agent'   => wp_get_current_user()->display_name,
				'i18n'    => array(
					'reply'     => __( 'پاسخ بده…', 'pasokhban' ),
					'sending'   => __( 'در حال ارسال…', 'pasokhban' ),
					'send'      => __( 'ارسال', 'pasokhban' ),
					'noSession' => __( 'هنوز مکالمه‌ای ثبت نشده است.', 'pasokhban' ),
					'errLoad'   => __( 'بارگذاری مکالمات ناموفق بود.', 'pasokhban' ),
					'errSend'   => __( 'ارسال پاسخ ناموفق بود.', 'pasokhban' ),
					'errOpen'   => __( 'بازکردن مکالمه ناموفق بود.', 'pasokhban' ),
					'you'       => __( 'شما', 'pasokhban' ),
					'visitor'   => __( 'بازدیدکننده', 'pasokhban' ),
					'ai'        => __( 'هوش مصنوعی', 'pasokhban' ),
					'system'    => __( 'سیستم', 'pasokhban' ),
					'sources'   => __( 'منابع:', 'pasokhban' ),
					'confirm'   => __( 'این مکالمه و همهٔ پیام‌هایش حذف شود؟', 'pasokhban' ),
					'openChat'  => __( 'بستن گفتگو', 'pasokhban' ),
					'reopen'    => __( 'بازکردن گفتگو', 'pasokhban' ),
					'suggest'   => __( '✨ پیشنهاد پاسخ', 'pasokhban' ),
					'thinking'  => __( 'در حال نوشتن پیش‌نویس…', 'pasokhban' ),
					'summarizing' => __( 'در حال خلاصه‌کردن…', 'pasokhban' ),
					'summarize' => __( '📋 خلاصهٔ مکالمه', 'pasokhban' ),
					'errCopilot' => __( 'ساخت پیش‌نویس ناموفق بود.', 'pasokhban' ),
					'inserted'  => __( 'پیش‌نویس در کادر ورودی قرار گرفت — ویرایشش کن و ارسال کن.', 'pasokhban' ),
					'ordTitle'  => __( 'سفارش', 'pasokhban' ),
					'ordStatus' => __( 'وضعیت', 'pasokhban' ),
					'ordDate'   => __( 'تاریخ ثبت', 'pasokhban' ),
					'ordTotal'  => __( 'مبلغ کل', 'pasokhban' ),
					'ordShip'   => __( 'روش ارسال', 'pasokhban' ),
					'ordCity'   => __( 'شهر مقصد', 'pasokhban' ),
					'ordItems'  => __( 'اقلام', 'pasokhban' ),
					'assigned'  => __( 'واگذار شد به', 'pasokhban' ),
					'unassignedLbl' => __( 'واگذاری لغو شد', 'pasokhban' ),
					'onlineNow' => __( 'آنلاین:', 'pasokhban' ),
					'errAssign' => __( 'واگذاری ناموفق بود.', 'pasokhban' ),
				),
				'copilot' => (bool) $opts['copilot_enabled'],
				'me'      => Pasokhban_Team::current_id(),
				'isAdmin' => Pasokhban_Team::is_admin(),
			)
		);
	}

	/**
	 * رندر صفحهٔ اینباکس.
	 */
	public static function render() {
		if ( ! Pasokhban_Team::can_access() ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'pasokhban' ) );
		}

		if ( ! Pasokhban_DB::installed() ) {
			Pasokhban_DB::install();
		}

		$opts = Pasokhban_Settings::instance()->get_options();

		// فیلتر پیش‌فرض «همه» — وگرنه اگر نشست‌ها به هر دلیلی وضعیت
		// متفاوتی داشتند، لیست خالی به نظر می‌رسید و علت معلوم نبود.
		$diag  = self::diagnostics();
		$list  = Pasokhban_DB::list_sessions( array( 'status' => 'all', 'per_page' => 40, 'page' => 1 ) );
		?>
		<?php $stats = self::quick_stats( $list['items'] ); ?>
		<div class="wrap pasokhban-inbox-wrap psb-admin">

			<header class="ei-hero">
				<div class="ei-hero-main">
					<span class="ei-hero-ico"><span class="dashicons dashicons-format-chat"></span></span>
					<div class="ei-hero-txt">
						<h1 class="ei-h1"><?php esc_html_e( 'گفتگوهای آنلاین', 'pasokhban' ); ?></h1>
						<p class="ei-sub"><?php esc_html_e( 'پاسخ‌ها پیش‌فرض با هوش مصنوعی است؛ با نوشتن پاسخ دستی، مکالمه به حالت اپراتور می‌رود.', 'pasokhban' ); ?></p>
					</div>
				</div>
				<span class="ei-live"><i></i><?php esc_html_e( 'اینباکس فعال', 'pasokhban' ); ?></span>
			</header>

			<div class="ei-stats">
				<span class="ei-stat <?php echo $stats['unread'] ? 'is-hot' : ''; ?>">
					<b><?php echo (int) $stats['unread']; ?></b>
					<span><?php esc_html_e( 'خوانده‌نشده', 'pasokhban' ); ?></span>
				</span>
				<span class="ei-stat">
					<b><?php echo (int) $stats['open']; ?></b>
					<span><?php esc_html_e( 'باز', 'pasokhban' ); ?></span>
				</span>
				<span class="ei-stat is-agent">
					<b><?php echo (int) $stats['agent']; ?></b>
					<span><?php esc_html_e( 'دست اپراتور', 'pasokhban' ); ?></span>
				</span>
				<span class="ei-stat is-dim">
					<b><?php echo (int) $stats['total']; ?></b>
					<span><?php esc_html_e( 'کل مکالمات', 'pasokhban' ); ?></span>
				</span>
			</div>

			<?php self::render_diagnostics( $diag ); ?>

			<div class="ei-app" id="ei-app">
				<aside class="ei-list">
					<div class="ei-list-tools">
						<input type="search" id="ei-search" placeholder="<?php esc_attr_e( 'جستجو…', 'pasokhban' ); ?>" />
						<select id="ei-filter">
							<option value="all"><?php esc_html_e( 'همه', 'pasokhban' ); ?></option>
							<option value="open"><?php esc_html_e( 'باز', 'pasokhban' ); ?></option>
							<option value="closed"><?php esc_html_e( 'بسته', 'pasokhban' ); ?></option>
						</select>
						<select id="ei-owner">
							<option value="me"><?php esc_html_e( 'مکالمات من', 'pasokhban' ); ?></option>
							<option value="unassigned"><?php esc_html_e( 'واگذارنشده', 'pasokhban' ); ?></option>
							<option value="all"><?php esc_html_e( 'همهٔ مکالمات', 'pasokhban' ); ?></option>
						</select>
						<button type="button" class="button" id="ei-refresh" title="<?php esc_attr_e( 'تازه‌سازی', 'pasokhban' ); ?>">↻</button>
					</div>

					<div class="ei-online" id="ei-online" hidden></div>

					<!-- لیست سمت سرور رندر می‌شود؛ JS بعداً تازه‌اش می‌کند -->
					<div class="ei-items" id="ei-items">
						<?php self::render_items( $list['items'] ); ?>
					</div>

					<div class="ei-list-foot">
						<?php
						printf(
							/* translators: 1: shown count, 2: total count */
							esc_html__( 'نمایش %1$s از %2$s مکالمه', 'pasokhban' ),
							'<span id="ei-shown">' . (int) count( $list['items'] ) . '</span>',
							'<span id="ei-total">' . (int) $list['total'] . '</span>'
						);
						?>
					</div>
				</aside>

				<section class="ei-thread" id="ei-thread">
					<div class="ei-empty" id="ei-empty">
						<span class="dashicons dashicons-format-chat"></span>
						<p><?php esc_html_e( 'یک مکالمه را از لیست انتخاب کن.', 'pasokhban' ); ?></p>
					</div>

					<header class="ei-thead" id="ei-thead" hidden>
						<div class="ei-tmeta">
							<strong id="ei-tname">—</strong>
							<span id="ei-tsub">—</span>
							<span id="ei-tcontact" class="ei-tcontact"></span>
						</div>
						<div class="ei-tactions">
							<label class="ei-assign">
								<span><?php esc_html_e( 'اپراتور', 'pasokhban' ); ?></span>
								<select id="ei-assign">
									<option value="0"><?php esc_html_e( 'واگذارنشده', 'pasokhban' ); ?></option>
									<?php foreach ( Pasokhban_Team::agents() as $ag ) : ?>
										<option value="<?php echo (int) $ag['id']; ?>">
											<?php echo esc_html( $ag['name'] . ( $ag['online'] ? ' ●' : '' ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</label>
							<label class="ei-toggle">
								<input type="checkbox" id="ei-mode" />
								<span><?php esc_html_e( 'پاسخ دستی (اپراتور)', 'pasokhban' ); ?></span>
							</label>
							<button type="button" class="button" id="ei-close">—</button>
							<button type="button" class="button-link-delete" id="ei-delete"><?php esc_html_e( 'حذف', 'pasokhban' ); ?></button>
						</div>
					</header>

					<div class="ei-msgs" id="ei-msgs"></div>

					<?php
					$canned = Pasokhban_LiveChat::instance()->canned_responses();
					if ( $canned ) :
						?>
						<div class="ei-canned" id="ei-canned" hidden>
							<?php foreach ( $canned as $c ) : ?>
								<button type="button" data-text="<?php echo esc_attr( $c['text'] ); ?>"><?php echo esc_html( $c['label'] ); ?></button>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php $copilot_on = ! empty( $opts['copilot_enabled'] ); ?>
					<?php if ( $copilot_on ) : ?>
						<div class="ei-copilot" id="ei-copilot" hidden>
							<button type="button" id="ei-suggest" class="button">✨ <?php esc_html_e( 'پیشنهاد پاسخ', 'pasokhban' ); ?></button>
							<?php if ( ! empty( $opts['copilot_summary'] ) ) : ?>
								<button type="button" id="ei-summarize" class="button">📋 <?php esc_html_e( 'خلاصهٔ مکالمه', 'pasokhban' ); ?></button>
							<?php endif; ?>
							<label class="ei-tone">
								<?php esc_html_e( 'لحن', 'pasokhban' ); ?>
								<select id="ei-tone">
									<option value="friendly" <?php selected( $opts['copilot_tone'], 'friendly' ); ?>><?php esc_html_e( 'دوستانه', 'pasokhban' ); ?></option>
									<option value="formal" <?php selected( $opts['copilot_tone'], 'formal' ); ?>><?php esc_html_e( 'رسمی', 'pasokhban' ); ?></option>
									<option value="short" <?php selected( $opts['copilot_tone'], 'short' ); ?>><?php esc_html_e( 'خیلی کوتاه', 'pasokhban' ); ?></option>
								</select>
							</label>
							<?php if ( class_exists( 'Pasokhban_Woo' ) && Pasokhban_Woo::active() ) : ?>
								<span class="ei-order">
									<input type="text" id="ei-order-id" placeholder="<?php esc_attr_e( 'شمارهٔ سفارش', 'pasokhban' ); ?>" />
									<button type="button" id="ei-order-go" class="button">🔎 <?php esc_html_e( 'استعلام سفارش', 'pasokhban' ); ?></button>
								</span>
							<?php endif; ?>
							<span class="ei-copilot-note"><?php esc_html_e( 'پیش‌نویس خودکار ارسال نمی‌شود — ویرایشش کن و بعد بفرست.', 'pasokhban' ); ?></span>
							<div class="ei-copilot-out" id="ei-copilot-out" hidden></div>
						</div>
					<?php endif; ?>

					<footer class="ei-composer" id="ei-composer" hidden>
						<textarea id="ei-input" rows="2" placeholder="<?php esc_attr_e( 'پاسخ بده…', 'pasokhban' ); ?>"></textarea>
						<button type="button" class="button button-primary" id="ei-send"><?php esc_html_e( 'ارسال', 'pasokhban' ); ?></button>
					</footer>
				</section>
			</div>
		</div>
		<?php
	}

	/**
	 * حروف اول نام برای آواتار.
	 *
	 * برای نام فارسی دو حرف اولِ دو کلمه، و اگر یک کلمه بود دو حرف اولش.
	 * برای نام لاتین، حرف اول نام و نام خانوادگی.
	 *
	 * @param string $name
	 * @return string
	 */
	public static function initials( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return '؟';
		}

		$parts = array_values( array_filter( preg_split( '/\s+/u', $name ) ) );
		if ( count( $parts ) >= 2 ) {
			return mb_substr( $parts[0], 0, 1 ) . mb_substr( $parts[1], 0, 1 );
		}
		return mb_substr( $parts[0], 0, 2 );
	}

	/**
	 * رنگ آواتار از روی نام.
	 *
	 * از hash نام گرفته می‌شود تا هر بازدیدکننده همیشه همان رنگ را داشته
	 * باشد — وگرنه با هر رفرش رنگ‌ها عوض می‌شد و چشم نمی‌توانست عادت کند.
	 *
	 * @param string $name
	 * @return string
	 */
	public static function name_tint( $name ) {
		$h    = (int) hexdec( substr( md5( (string) $name ), 0, 6 ) );
		$hue  = $h % 360;
		return 'hsl(' . $hue . ', 62%, 52%)';
	}

	/**
	 * آمار سریع برای نوار بالای صفحه.
	 *
	 * @param array $items
	 * @return array
	 */
	public static function quick_stats( $items ) {
		$open = 0; $agent = 0; $unread = 0;
		foreach ( (array) $items as $s ) {
			if ( 'closed' !== ( isset( $s->status ) ? $s->status : '' ) ) { $open++; }
			if ( 'agent' === ( isset( $s->mode ) ? $s->mode : '' ) ) { $agent++; }
			$unread += (int) ( isset( $s->unread_agent ) ? $s->unread_agent : 0 );
		}
		return array(
			'open'   => $open,
			'agent'  => $agent,
			'unread' => $unread,
			'total'  => count( (array) $items ),
		);
	}

	/**
	 * رندر آیتم‌های لیست (قابل استفاده هم از PHP و هم از JS).
	 *
	 * @param array $items
	 */
	public static function render_items( $items ) {
		if ( empty( $items ) ) {
			echo '<div class="ei-empty-list">' . esc_html__( 'مکالمه‌ای در این وضعیت نیست.', 'pasokhban' ) . '</div>';
			return;
		}

		foreach ( $items as $s ) {
			$name  = $s->visitor_name ? $s->visitor_name : __( 'بازدیدکننده', 'pasokhban' );
			$full  = trim( $name . ' ' . ( isset( $s->visitor_family ) ? (string) $s->visitor_family : '' ) );
			$unread = (int) $s->unread_agent;

			$mode = ( 'agent' === $s->mode )
				? '<span class="ei-tag agent">' . esc_html__( 'اپراتور', 'pasokhban' ) . '</span>'
				: '<span class="ei-tag ai">AI</span>';
			$closed = ( 'closed' === $s->status )
				? '<span class="ei-tag closed">' . esc_html__( 'بسته', 'pasokhban' ) . '</span>'
				: '';
			$assigned = ( ! empty( $s->assigned_to ) && (int) $s->assigned_to > 0 )
				? '<span class="ei-tag mine">' . esc_html( Pasokhban_Team::agent_name( (int) $s->assigned_to ) ) . '</span>'
				: '';
			$has_phone = ! empty( $s->visitor_phone ) ? '<span class="ei-ico" title="' . esc_attr__( 'شمارهٔ تماس دارد', 'pasokhban' ) . '">📞</span>' : '';

			// آواتار: حرف اول نام + رنگی که از خودِ نام مشتق می‌شود، پس
			// هر بازدیدکننده همیشه همان رنگ را دارد.
			$initials = self::initials( $full );
			$tint     = self::name_tint( $full );
			?>
			<button type="button" class="ei-item<?php echo $unread ? ' is-unread' : ''; ?>" data-id="<?php echo (int) $s->id; ?>">
				<span class="ei-ava" style="--ei-ava: <?php echo esc_attr( $tint ); ?>"><?php echo esc_html( $initials ); ?></span>
				<span class="ei-item-body">
					<span class="ei-item-top">
						<span class="ei-item-name"><?php echo esc_html( $full ); ?></span>
						<?php
						// اگر مکالمه امروز بوده فقط ساعت، وگرنه تاریخ شمسی
						// کامل + ساعت. وگرنه در لیستی که پر از مکالمهٔ
						// دیروز و پریروز است، «۱۶:۳۰» هیچ اطلاعاتی نمی‌دهد.
						$is_today = ( gmdate( 'Y-m-d' ) === substr( (string) $s->updated_at, 0, 10 ) );
						$when     = $is_today
							? Pasokhban_Jalali::format_mysql( $s->updated_at, 'time' )
							: Pasokhban_Jalali::format_mysql( $s->updated_at, 'date' ) . ' — ' . Pasokhban_Jalali::format_mysql( $s->updated_at, 'time' );
						?>
						<span class="ei-item-time"><?php echo esc_html( $when ); ?></span>
					</span>
					<span class="ei-item-msg"><?php echo esc_html( $s->last_message ); ?></span>
					<span class="ei-item-meta">
						<?php echo $mode . $closed . $assigned . $has_phone; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php if ( $unread > 0 ) : ?>
							<span class="ei-badge"><?php echo (int) $unread; ?></span>
						<?php endif; ?>
					</span>
				</span>
			</button>
			<?php
		}
	}

	/**
	 * جمع‌آوری اطلاعات تشخیصی — تا اگر چیزی درست نبود، علت معلوم باشد.
	 *
	 * @return array
	 */
	private static function diagnostics() {
		global $wpdb;

		$sess = Pasokhban_DB::sessions_table();
		$msgs = Pasokhban_DB::messages_table();

		// شمارش‌ها مستقل از SHOW TABLES گرفته می‌شوند؛ اگر جدول نباشد
		// خودِ کوئری خطا می‌دهد و از last_error می‌فهمیم.
		$total  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $sess ); // phpcs:ignore WordPress.DB
		$sess_err = $wpdb->last_error;
		$open   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sess} WHERE status='open'" ); // phpcs:ignore WordPress.DB
		$closed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sess} WHERE status='closed'" ); // phpcs:ignore WordPress.DB
		$msg_n  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $msgs ); // phpcs:ignore WordPress.DB
		$msg_err = $wpdb->last_error;

		return array(
			'sessions_table' => $sess,
			'messages_table' => $msgs,
			'sess_exists'    => ( '' === $sess_err ),
			'msg_exists'     => ( '' === $msg_err ),
			'total'          => $total,
			'open'           => $open,
			'closed'         => $closed,
			'messages'       => $msg_n,
			'db_version'     => (string) get_option( 'pasokhban_db_version', '—' ),
			'last_error'     => $sess_err ? $sess_err : $msg_err,
			'self_test'      => self::self_test(),
			'routes_ok'      => self::routes_registered(),
		);
	}

	/**
	 * آیا مسیرهای REST گفتگو واقعاً ثبت شده‌اند؟
	 *
	 * @return bool
	 */
	private static function routes_registered() {
		// rest_get_server در وردپرس ۵+ همیشه هست، ولی گارد ارزان است
		// و جلوی fatal در زمینه‌های غیرعادی را می‌گیرد.
		if ( ! function_exists( 'rest_get_server' ) ) {
			return false;
		}

		$server = rest_get_server();
		$routes = $server->get_routes();

		return isset( $routes['/pasokhban/v1/live/start'] )
			&& isset( $routes['/pasokhban/v1/live/send'] )
			&& isset( $routes['/pasokhban/v1/admin/sessions'] )
			&& isset( $routes['/pasokhban/v1/admin/reply'] );
	}

	/**
	 * نمایش نوار تشخیصی.
	 *
	 * @param array $d
	 */
	private static function render_diagnostics( $d ) {
		$install_err = (string) get_option( 'pasokhban_db_error', '' );
		$repair      = isset( $_GET['pasokhban_repair'] ) ? sanitize_text_field( wp_unslash( $_GET['pasokhban_repair'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$problem = ( ! $d['sess_exists'] || ! $d['msg_exists'] || '' !== $d['last_error'] || '' !== $install_err );
		?>
		<div class="ei-diag <?php echo $problem ? 'bad' : 'ok'; ?>">
			<details <?php echo $problem ? 'open' : ''; ?>>
				<summary>
					<strong><?php echo $problem ? esc_html__( '⚠ مشکل در دیتابیس — گفتگوها ذخیره نمی‌شوند', 'pasokhban' ) : esc_html__( 'وضعیت دیتابیس', 'pasokhban' ); ?></strong>
					<span>
						<?php
						printf(
							/* translators: 1: total sessions, 2: open, 3: closed, 4: messages */
							esc_html__( '%1$s مکالمه (%2$s باز / %3$s بسته) • %4$s پیام', 'pasokhban' ),
							(int) $d['total'],
							(int) $d['open'],
							(int) $d['closed'],
							(int) $d['messages']
						);
						?>
					</span>
				</summary>

				<?php if ( '' !== $install_err ) : ?>
					<p class="ei-diag-err"><strong><?php esc_html_e( 'خطای ساخت جدول:', 'pasokhban' ); ?></strong><br><code><?php echo esc_html( $install_err ); ?></code></p>
				<?php endif; ?>

				<?php if ( 'db_ok' === $repair ) : ?>
					<p class="ei-diag-ok">✅ <?php esc_html_e( 'جدول‌ها با موفقیت ساخته/تعمیر شدند.', 'pasokhban' ); ?></p>
				<?php elseif ( 0 === strpos( $repair, 'db_error:' ) ) : ?>
					<p class="ei-diag-err">❌ <?php echo esc_html( rawurldecode( substr( $repair, 9 ) ) ); ?></p>
				<?php endif; ?>

				<table>
					<tr><td><?php esc_html_e( 'جدول نشست‌ها', 'pasokhban' ); ?></td><td><code><?php echo esc_html( $d['sessions_table'] ); ?></code></td><td><?php echo $d['sess_exists'] ? '✅' : '❌ ساخته نشده'; ?></td></tr>
					<tr><td><?php esc_html_e( 'جدول پیام‌ها', 'pasokhban' ); ?></td><td><code><?php echo esc_html( $d['messages_table'] ); ?></code></td><td><?php echo $d['msg_exists'] ? '✅' : '❌ ساخته نشده'; ?></td></tr>
					<tr><td><?php esc_html_e( 'نسخهٔ ساختار', 'pasokhban' ); ?></td><td><code><?php echo esc_html( $d['db_version'] ); ?></code></td><td><?php echo ( Pasokhban_DB::SCHEMA_VERSION === $d['db_version'] ) ? '✅' : '⚠ ثبت نشده'; ?></td></tr>
					<tr><td><?php esc_html_e( 'آزمون نوشتن/خواندن', 'pasokhban' ); ?></td><td colspan="2"><?php echo $d['self_test']['ok'] ? '✅' : '❌'; ?> <?php echo esc_html( $d['self_test']['detail'] ); ?></td></tr>
					<tr><td><?php esc_html_e( 'مسیرهای REST', 'pasokhban' ); ?></td><td colspan="2"><?php echo $d['routes_ok'] ? '✅ ثبت شده' : '❌ ثبت نشده'; ?></td></tr>
					<?php if ( '' !== $d['last_error'] ) : ?>
						<tr><td><?php esc_html_e( 'آخرین خطای MySQL', 'pasokhban' ); ?></td><td colspan="2"><code><?php echo esc_html( $d['last_error'] ); ?></code></td></tr>
					<?php endif; ?>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
					<input type="hidden" name="action" value="pasokhban_repair" />
					<?php wp_nonce_field( 'pasokhban_repair' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( '🔧 ساخت/تعمیر جدول‌ها', 'pasokhban' ); ?></button>
				</form>

				<?php if ( ! $d['sess_exists'] ) : ?>
					<p class="ei-diag-fix">
						<?php esc_html_e( 'جدول ساخته نشده. دکمهٔ بالا را بزن؛ اگر باز هم خطا داد، کاربر دیتابیس در wp-config.php حق CREATE TABLE ندارد و باید از هاست بخواهی اضافه کند.', 'pasokhban' ); ?>
					</p>
				<?php elseif ( 0 === $d['total'] ) : ?>
					<p class="ei-diag-fix">
						<?php esc_html_e( 'جدول سالم است ولی هنوز مکالمه‌ای ثبت نشده. در سایت روی حباب چت بزن، یک پیام بفرست، سپس این صفحه را تازه کن. اگر پیام فرستادی و اینجا چیزی نیست، بخش «آزمون نوشتن/خواندن» بالا را نگاه کن.', 'pasokhban' ); ?>
					</p>
				<?php endif; ?>
			</details>
		</div>
		<?php
	}
}
