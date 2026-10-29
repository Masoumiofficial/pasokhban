<?php
/**
 * اینباکس گفتگوهای آنلاین در پیشخوان وردپرس.
 *
 * لیست مکالمات سمت سرور (PHP) رندر می‌شود تا حتی اگر جاوااسکریپت یا REST
 * به هر دلیلی کار نکند، مدیر همچنان داده‌ها را ببیند. جاوااسکریپت فقط
 * تازه‌سازی زنده و بازکردن رشتهٔ گفتگو را انجام می‌دهد.
 *
 * @package Gapineh
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس اینباکس.
 */
final class Gapineh_Inbox {

	/**
	 * قلاب‌های اینباکس.
	 */
	public static function hooks() {
		add_action( 'admin_post_gapineh_repair', array( __CLASS__, 'handle_repair' ) );
	}

	/**
	 * تلاش دوباره برای ساخت جدول‌ها (از دکمهٔ «تعمیر دیتابیس»).
	 */
	public static function handle_repair() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'gapineh' ) );
		}
		check_admin_referer( 'gapineh_repair' );

		Gapineh_DB::flush_cache();
		$res = Gapineh_DB::install();

		$msg = is_wp_error( $res )
			? 'db_error: ' . rawurlencode( $res->get_error_message() )
			: 'db_ok';

		wp_safe_redirect( add_query_arg( 'gapineh_repair', $msg, admin_url( 'admin.php?page=gapineh-inbox' ) ) );
		exit;
	}

	/**
	 * آزمون زنده: یک ردیف می‌نویسد، می‌خواند و پاک می‌کند.
	 *
	 * @return array { ok: bool, detail: string }
	 */
	public static function self_test() {
		if ( ! Gapineh_DB::tables_exist() ) {
			return array( 'ok' => false, 'detail' => __( 'جدول‌ها وجود ندارند.', 'gapineh' ) );
		}

		$probe = Gapineh_DB::create_session( array( 'lang' => 'fa', 'name' => '__selftest__' ) );

		if ( ! $probe ) {
			global $wpdb;
			return array(
				'ok'     => false,
				'detail' => __( 'نوشتن در جدول نشست‌ها ممکن نشد.', 'gapineh' ) . ' ' . $wpdb->last_error,
			);
		}

		$msg = Gapineh_DB::add_message( (int) $probe->id, 'visitor', '__selftest__' );
		$read = $msg ? Gapineh_DB::get_messages( (int) $probe->id ) : array();

		Gapineh_DB::get_session( (int) $probe->id );
		global $wpdb;
		$wpdb->delete( Gapineh_DB::messages_table(), array( 'session_id' => (int) $probe->id ) );
		$wpdb->delete( Gapineh_DB::sessions_table(), array( 'id' => (int) $probe->id ) );

		if ( ! $msg || empty( $read ) ) {
			return array( 'ok' => false, 'detail' => __( 'نوشتن/خواندن پیام ممکن نشد.', 'gapineh' ) );
		}

		return array( 'ok' => true, 'detail' => __( 'نوشتن، خواندن و حذف هر سه موفق بود.', 'gapineh' ) );
	}

	/**
	 * بارگذاری دارایی‌های صفحهٔ اینباکس.
	 *
	 * @param string $hook
	 */
	public static function assets( $hook ) {
		if ( ! Gapineh_Settings::is_page( $hook, 'inbox' ) ) {
			return;
		}

		$opts = Gapineh_Settings::instance()->get_options();

		wp_enqueue_style(
			'gapineh-inbox',
			Gapineh::asset_url( 'assets/css/gapineh-inbox.css' ),
			array(),
			GAPINEH_ASSET_VERSION
		);

		// بدون وابستگی به jQuery — یک IIFEٔ vanilla است.
		wp_enqueue_script(
			'gapineh-inbox',
			Gapineh::asset_url( 'assets/js/gapineh-inbox.js' ),
			array(),
			GAPINEH_ASSET_VERSION,
			true
		);

		wp_localize_script(
			'gapineh-inbox',
			'GAPINEH_INBOX',
			array(
				'restUrl' => esc_url_raw( rest_url( 'gapineh/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'agent'   => wp_get_current_user()->display_name,
				'i18n'    => array(
					'reply'     => __( 'پاسخ بده…', 'gapineh' ),
					'sending'   => __( 'در حال ارسال…', 'gapineh' ),
					'send'      => __( 'ارسال', 'gapineh' ),
					'noSession' => __( 'هنوز مکالمه‌ای ثبت نشده است.', 'gapineh' ),
					'errLoad'   => __( 'بارگذاری مکالمات ناموفق بود.', 'gapineh' ),
					'errSend'   => __( 'ارسال پاسخ ناموفق بود.', 'gapineh' ),
					'errOpen'   => __( 'بازکردن مکالمه ناموفق بود.', 'gapineh' ),
					'you'       => __( 'شما', 'gapineh' ),
					'visitor'   => __( 'بازدیدکننده', 'gapineh' ),
					'ai'        => __( 'هوش مصنوعی', 'gapineh' ),
					'system'    => __( 'سیستم', 'gapineh' ),
					'sources'   => __( 'منابع:', 'gapineh' ),
					'confirm'   => __( 'این مکالمه و همهٔ پیام‌هایش حذف شود؟', 'gapineh' ),
					'openChat'  => __( 'بستن گفتگو', 'gapineh' ),
					'reopen'    => __( 'بازکردن گفتگو', 'gapineh' ),
					'suggest'   => __( '✨ پیشنهاد پاسخ', 'gapineh' ),
					'thinking'  => __( 'در حال نوشتن پیش‌نویس…', 'gapineh' ),
					'summarizing' => __( 'در حال خلاصه‌کردن…', 'gapineh' ),
					'summarize' => __( '📋 خلاصهٔ مکالمه', 'gapineh' ),
					'errCopilot' => __( 'ساخت پیش‌نویس ناموفق بود.', 'gapineh' ),
					'inserted'  => __( 'پیش‌نویس در کادر ورودی قرار گرفت — ویرایشش کن و ارسال کن.', 'gapineh' ),
					'ordTitle'  => __( 'سفارش', 'gapineh' ),
					'ordStatus' => __( 'وضعیت', 'gapineh' ),
					'ordDate'   => __( 'تاریخ ثبت', 'gapineh' ),
					'ordTotal'  => __( 'مبلغ کل', 'gapineh' ),
					'ordShip'   => __( 'روش ارسال', 'gapineh' ),
					'ordCity'   => __( 'شهر مقصد', 'gapineh' ),
					'ordItems'  => __( 'اقلام', 'gapineh' ),
					'assigned'  => __( 'واگذار شد به', 'gapineh' ),
					'unassignedLbl' => __( 'واگذاری لغو شد', 'gapineh' ),
					'onlineNow' => __( 'آنلاین:', 'gapineh' ),
					'errAssign' => __( 'واگذاری ناموفق بود.', 'gapineh' ),
				),
				'copilot' => (bool) $opts['copilot_enabled'],
				'me'      => Gapineh_Team::current_id(),
				'isAdmin' => Gapineh_Team::is_admin(),
			)
		);
	}

	/**
	 * رندر صفحهٔ اینباکس.
	 */
	public static function render() {
		if ( ! Gapineh_Team::can_access() ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'gapineh' ) );
		}

		if ( ! Gapineh_DB::installed() ) {
			Gapineh_DB::install();
		}

		$opts = Gapineh_Settings::instance()->get_options();

		// فیلتر پیش‌فرض «همه» — وگرنه اگر نشست‌ها به هر دلیلی وضعیت
		// متفاوتی داشتند، لیست خالی به نظر می‌رسید و علت معلوم نبود.
		$diag  = self::diagnostics();
		$list  = Gapineh_DB::list_sessions( array( 'status' => 'all', 'per_page' => 40, 'page' => 1 ) );
		?>
		<?php $stats = self::quick_stats( $list['items'] ); ?>
		<div class="wrap gapineh-inbox-wrap psb-admin">

			<header class="ei-hero">
				<div class="ei-hero-main">
					<span class="ei-hero-ico"><span class="dashicons dashicons-format-chat"></span></span>
					<div class="ei-hero-txt">
						<h1 class="ei-h1"><?php esc_html_e( 'گفتگوهای آنلاین', 'gapineh' ); ?></h1>
						<p class="ei-sub"><?php esc_html_e( 'پاسخ‌ها پیش‌فرض با هوش مصنوعی است؛ با نوشتن پاسخ دستی، مکالمه به حالت اپراتور می‌رود.', 'gapineh' ); ?></p>
					</div>
				</div>
				<span class="ei-live"><i></i><?php esc_html_e( 'اینباکس فعال', 'gapineh' ); ?></span>
			</header>

			<div class="ei-stats">
				<span class="ei-stat <?php echo $stats['unread'] ? 'is-hot' : ''; ?>">
					<b><?php echo (int) $stats['unread']; ?></b>
					<span><?php esc_html_e( 'خوانده‌نشده', 'gapineh' ); ?></span>
				</span>
				<span class="ei-stat">
					<b><?php echo (int) $stats['open']; ?></b>
					<span><?php esc_html_e( 'باز', 'gapineh' ); ?></span>
				</span>
				<span class="ei-stat is-agent">
					<b><?php echo (int) $stats['agent']; ?></b>
					<span><?php esc_html_e( 'دست اپراتور', 'gapineh' ); ?></span>
				</span>
				<span class="ei-stat is-dim">
					<b><?php echo (int) $stats['total']; ?></b>
					<span><?php esc_html_e( 'کل مکالمات', 'gapineh' ); ?></span>
				</span>
			</div>

			<?php self::render_diagnostics( $diag ); ?>

			<div class="ei-app" id="ei-app">
				<aside class="ei-list">
					<div class="ei-list-tools">
						<input type="search" id="ei-search" placeholder="<?php esc_attr_e( 'جستجو…', 'gapineh' ); ?>" />
						<select id="ei-filter">
							<option value="all"><?php esc_html_e( 'همه', 'gapineh' ); ?></option>
							<option value="open"><?php esc_html_e( 'باز', 'gapineh' ); ?></option>
							<option value="closed"><?php esc_html_e( 'بسته', 'gapineh' ); ?></option>
						</select>
						<select id="ei-owner">
							<option value="me"><?php esc_html_e( 'مکالمات من', 'gapineh' ); ?></option>
							<option value="unassigned"><?php esc_html_e( 'واگذارنشده', 'gapineh' ); ?></option>
							<option value="all" selected><?php esc_html_e( 'همهٔ مکالمات', 'gapineh' ); ?></option>
						</select>
						<button type="button" class="button" id="ei-refresh" title="<?php esc_attr_e( 'تازه‌سازی', 'gapineh' ); ?>">↻</button>
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
							esc_html__( 'نمایش %1$s از %2$s مکالمه', 'gapineh' ),
							'<span id="ei-shown">' . (int) count( $list['items'] ) . '</span>',
							'<span id="ei-total">' . (int) $list['total'] . '</span>'
						);
						?>
					</div>
				</aside>

				<section class="ei-thread" id="ei-thread">
					<div class="ei-empty" id="ei-empty">
						<span class="dashicons dashicons-format-chat"></span>
						<p><?php esc_html_e( 'یک مکالمه را از لیست انتخاب کن.', 'gapineh' ); ?></p>
					</div>

					<header class="ei-thead" id="ei-thead" hidden>
						<div class="ei-tmeta">
							<strong id="ei-tname">—</strong>
							<span id="ei-tsub">—</span>
							<span id="ei-tcontact" class="ei-tcontact"></span>
						</div>
						<div class="ei-tactions">
							<label class="ei-assign">
								<span><?php esc_html_e( 'اپراتور', 'gapineh' ); ?></span>
								<select id="ei-assign">
									<option value="0"><?php esc_html_e( 'واگذارنشده', 'gapineh' ); ?></option>
									<?php foreach ( Gapineh_Team::agents() as $ag ) : ?>
										<option value="<?php echo (int) $ag['id']; ?>">
											<?php echo esc_html( $ag['name'] . ( $ag['online'] ? ' ●' : '' ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</label>
							<label class="ei-toggle">
								<input type="checkbox" id="ei-mode" />
								<span><?php esc_html_e( 'پاسخ دستی (اپراتور)', 'gapineh' ); ?></span>
							</label>
							<button type="button" class="button" id="ei-close">—</button>
							<button type="button" class="button-link-delete" id="ei-delete"><?php esc_html_e( 'حذف', 'gapineh' ); ?></button>
						</div>
					</header>

					<div class="ei-msgs" id="ei-msgs" hidden></div>

					<?php
					$canned = Gapineh_LiveChat::instance()->canned_responses();
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
							<button type="button" id="ei-suggest" class="button">✨ <?php esc_html_e( 'پیشنهاد پاسخ', 'gapineh' ); ?></button>
							<?php if ( ! empty( $opts['copilot_summary'] ) ) : ?>
								<button type="button" id="ei-summarize" class="button">📋 <?php esc_html_e( 'خلاصهٔ مکالمه', 'gapineh' ); ?></button>
							<?php endif; ?>
							<label class="ei-tone">
								<?php esc_html_e( 'لحن', 'gapineh' ); ?>
								<select id="ei-tone">
									<option value="friendly" <?php selected( $opts['copilot_tone'], 'friendly' ); ?>><?php esc_html_e( 'دوستانه', 'gapineh' ); ?></option>
									<option value="formal" <?php selected( $opts['copilot_tone'], 'formal' ); ?>><?php esc_html_e( 'رسمی', 'gapineh' ); ?></option>
									<option value="short" <?php selected( $opts['copilot_tone'], 'short' ); ?>><?php esc_html_e( 'خیلی کوتاه', 'gapineh' ); ?></option>
								</select>
							</label>
							<?php if ( class_exists( 'Gapineh_Woo' ) && Gapineh_Woo::active() ) : ?>
								<span class="ei-order">
									<input type="text" id="ei-order-id" placeholder="<?php esc_attr_e( 'شمارهٔ سفارش', 'gapineh' ); ?>" />
									<button type="button" id="ei-order-go" class="button">🔎 <?php esc_html_e( 'استعلام سفارش', 'gapineh' ); ?></button>
								</span>
							<?php endif; ?>
							<span class="ei-copilot-note"><?php esc_html_e( 'پیش‌نویس خودکار ارسال نمی‌شود — ویرایشش کن و بعد بفرست.', 'gapineh' ); ?></span>
							<div class="ei-copilot-out" id="ei-copilot-out" hidden></div>
						</div>
					<?php endif; ?>

					<footer class="ei-composer" id="ei-composer" hidden>
						<textarea id="ei-input" rows="2" placeholder="<?php esc_attr_e( 'پاسخ بده…', 'gapineh' ); ?>"></textarea>
						<button type="button" class="button button-primary" id="ei-send"><?php esc_html_e( 'ارسال', 'gapineh' ); ?></button>
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
			echo '<div class="ei-empty-list">' . esc_html__( 'مکالمه‌ای در این وضعیت نیست.', 'gapineh' ) . '</div>';
			return;
		}

		foreach ( $items as $s ) {
			$name  = $s->visitor_name ? $s->visitor_name : __( 'بازدیدکننده', 'gapineh' );
			$full  = trim( $name . ' ' . ( isset( $s->visitor_family ) ? (string) $s->visitor_family : '' ) );
			$unread = (int) $s->unread_agent;

			$mode = ( 'agent' === $s->mode )
				? '<span class="ei-tag agent">' . esc_html__( 'اپراتور', 'gapineh' ) . '</span>'
				: '<span class="ei-tag ai">AI</span>';
			$closed = ( 'closed' === $s->status )
				? '<span class="ei-tag closed">' . esc_html__( 'بسته', 'gapineh' ) . '</span>'
				: '';
			$assigned = ( ! empty( $s->assigned_to ) && (int) $s->assigned_to > 0 )
				? '<span class="ei-tag mine">' . esc_html( Gapineh_Team::agent_name( (int) $s->assigned_to ) ) . '</span>'
				: '';
			$has_phone = ! empty( $s->visitor_phone ) ? '<span class="ei-ico" title="' . esc_attr__( 'شمارهٔ تماس دارد', 'gapineh' ) . '">📞</span>' : '';

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
							? Gapineh_Jalali::format_mysql( $s->updated_at, 'time' )
							: Gapineh_Jalali::format_mysql( $s->updated_at, 'date' ) . ' — ' . Gapineh_Jalali::format_mysql( $s->updated_at, 'time' );
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

		$sess = Gapineh_DB::sessions_table();
		$msgs = Gapineh_DB::messages_table();

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
			'db_version'     => (string) get_option( 'gapineh_db_version', '—' ),
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

		return isset( $routes['/gapineh/v1/live/start'] )
			&& isset( $routes['/gapineh/v1/live/send'] )
			&& isset( $routes['/gapineh/v1/admin/sessions'] )
			&& isset( $routes['/gapineh/v1/admin/reply'] );
	}

	/**
	 * نمایش نوار تشخیصی.
	 *
	 * @param array $d
	 */
	private static function render_diagnostics( $d ) {
		$install_err = (string) get_option( 'gapineh_db_error', '' );
		$repair      = isset( $_GET['gapineh_repair'] ) ? sanitize_text_field( wp_unslash( $_GET['gapineh_repair'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$problem = ( ! $d['sess_exists'] || ! $d['msg_exists'] || '' !== $d['last_error'] || '' !== $install_err );
		?>
		<div class="ei-diag <?php echo $problem ? 'bad' : 'ok'; ?>">
			<details <?php echo $problem ? 'open' : ''; ?>>
				<summary>
					<strong><?php echo $problem ? esc_html__( '⚠ مشکل در دیتابیس — گفتگوها ذخیره نمی‌شوند', 'gapineh' ) : esc_html__( 'وضعیت دیتابیس', 'gapineh' ); ?></strong>
					<span>
						<?php
						printf(
							/* translators: 1: total sessions, 2: open, 3: closed, 4: messages */
							esc_html__( '%1$s مکالمه (%2$s باز / %3$s بسته) • %4$s پیام', 'gapineh' ),
							(int) $d['total'],
							(int) $d['open'],
							(int) $d['closed'],
							(int) $d['messages']
						);
						?>
					</span>
				</summary>

				<?php if ( '' !== $install_err ) : ?>
					<p class="ei-diag-err"><strong><?php esc_html_e( 'خطای ساخت جدول:', 'gapineh' ); ?></strong><br><code><?php echo esc_html( $install_err ); ?></code></p>
				<?php endif; ?>

				<?php if ( 'db_ok' === $repair ) : ?>
					<p class="ei-diag-ok">✅ <?php esc_html_e( 'جدول‌ها با موفقیت ساخته/تعمیر شدند.', 'gapineh' ); ?></p>
				<?php elseif ( 0 === strpos( $repair, 'db_error:' ) ) : ?>
					<p class="ei-diag-err">❌ <?php echo esc_html( rawurldecode( substr( $repair, 9 ) ) ); ?></p>
				<?php endif; ?>

				<table>
					<tr><td><?php esc_html_e( 'جدول نشست‌ها', 'gapineh' ); ?></td><td><code><?php echo esc_html( $d['sessions_table'] ); ?></code></td><td><?php echo $d['sess_exists'] ? '✅' : '❌ ساخته نشده'; ?></td></tr>
					<tr><td><?php esc_html_e( 'جدول پیام‌ها', 'gapineh' ); ?></td><td><code><?php echo esc_html( $d['messages_table'] ); ?></code></td><td><?php echo $d['msg_exists'] ? '✅' : '❌ ساخته نشده'; ?></td></tr>
					<tr><td><?php esc_html_e( 'نسخهٔ ساختار', 'gapineh' ); ?></td><td><code><?php echo esc_html( $d['db_version'] ); ?></code></td><td><?php echo ( Gapineh_DB::SCHEMA_VERSION === $d['db_version'] ) ? '✅' : '⚠ ثبت نشده'; ?></td></tr>
					<tr><td><?php esc_html_e( 'آزمون نوشتن/خواندن', 'gapineh' ); ?></td><td colspan="2"><?php echo $d['self_test']['ok'] ? '✅' : '❌'; ?> <?php echo esc_html( $d['self_test']['detail'] ); ?></td></tr>
					<tr><td><?php esc_html_e( 'مسیرهای REST', 'gapineh' ); ?></td><td colspan="2"><?php echo $d['routes_ok'] ? '✅ ثبت شده' : '❌ ثبت نشده'; ?></td></tr>
					<?php if ( '' !== $d['last_error'] ) : ?>
						<tr><td><?php esc_html_e( 'آخرین خطای MySQL', 'gapineh' ); ?></td><td colspan="2"><code><?php echo esc_html( $d['last_error'] ); ?></code></td></tr>
					<?php endif; ?>
				</table>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
					<input type="hidden" name="action" value="gapineh_repair" />
					<?php wp_nonce_field( 'gapineh_repair' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( '🔧 ساخت/تعمیر جدول‌ها', 'gapineh' ); ?></button>
				</form>

				<?php if ( ! $d['sess_exists'] ) : ?>
					<p class="ei-diag-fix">
						<?php esc_html_e( 'جدول ساخته نشده. دکمهٔ بالا را بزن؛ اگر باز هم خطا داد، کاربر دیتابیس در wp-config.php حق CREATE TABLE ندارد و باید از هاست بخواهی اضافه کند.', 'gapineh' ); ?>
					</p>
				<?php elseif ( 0 === $d['total'] ) : ?>
					<p class="ei-diag-fix">
						<?php esc_html_e( 'جدول سالم است ولی هنوز مکالمه‌ای ثبت نشده. در سایت روی حباب چت بزن، یک پیام بفرست، سپس این صفحه را تازه کن. اگر پیام فرستادی و اینجا چیزی نیست، بخش «آزمون نوشتن/خواندن» بالا را نگاه کن.', 'gapineh' ); ?>
					</p>
				<?php endif; ?>
			</details>
		</div>
		<?php
	}
}
