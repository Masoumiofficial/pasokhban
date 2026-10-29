<?php
/**
 * صفحهٔ «مخاطبین» در پیشخوان + خروجی CSV.
 *
 * @package Gapineh
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس مخاطبین.
 */
final class Gapineh_Contacts {

	/**
	 * قلاب‌ها.
	 */
	public static function hooks() {
		add_action( 'admin_post_gapineh_export_csv', array( __CLASS__, 'export_csv' ) );
	}

	/**
	 * بارگذاری دارایی‌ها.
	 *
	 * @param string $hook
	 */
	public static function assets( $hook ) {
		$is_settings  = Gapineh_Settings::is_page( $hook, 'settings' );
		$is_contacts  = Gapineh_Settings::is_page( $hook, 'contacts' );
		$is_inbox     = Gapineh_Settings::is_page( $hook, 'inbox' );
		$is_analytics = Gapineh_Settings::is_page( $hook, 'analytics' );
		$is_guide     = Gapineh_Settings::is_page( $hook, 'guide' );

		// صفحهٔ آمار قبلاً از این فهرست جا مانده بود و بی‌CSS رندر می‌شد.
		if ( ! $is_settings && ! $is_contacts && ! $is_inbox && ! $is_analytics && ! $is_guide ) {
			return;
		}

		wp_enqueue_style(
			'gapineh-admin',
			Gapineh::asset_url( 'assets/css/gapineh-admin.css' ),
			array(),
			GAPINEH_ASSET_VERSION
		);

		if ( $is_settings ) {
			wp_enqueue_script(
				'gapineh-settings',
				Gapineh::asset_url( 'assets/js/gapineh-settings.js' ),
				array(),
				GAPINEH_ASSET_VERSION,
				true
			);

			// تب «بررسی سلامت» بررسی‌ها را تک‌تک از REST می‌گیرد تا
			// پیشرفت زنده نشان داده شود و یک سرویس کند، بقیه را بلوکه نکند.
			wp_localize_script(
				'gapineh-settings',
				'GAPINEH_HEALTH',
				array(
					'url'      => esc_url_raw( rest_url( 'gapineh/v1/admin/health' ) ),
					'restUrl'  => esc_url_raw( rest_url( 'gapineh/v1' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'i18n'     => array(
						'running'   => __( 'در حال اجرا…', 'gapineh' ),
						'runAll'    => __( 'اجرای همهٔ بررسی‌ها', 'gapineh' ),
						'runGroup'  => __( 'اجرای این بخش', 'gapineh' ),
						'pending'   => __( 'هنوز اجرا نشده', 'gapineh' ),
						'copied'    => __( 'گزارش کپی شد', 'gapineh' ),
						'copyFail'  => __( 'کپی نشد؛ خودتان انتخاب و کپی کنید.', 'gapineh' ),
						'netErr'    => __( 'ارتباط با سرور قطع شد.', 'gapineh' ),
						'done'      => __( 'بررسی کامل شد', 'gapineh' ),
						'pass'      => __( 'سالم', 'gapineh' ),
						'fail'      => __( 'خطا', 'gapineh' ),
						'warn'      => __( 'هشدار', 'gapineh' ),
						'info'      => __( 'اطلاع', 'gapineh' ),
						'skip'      => __( 'رد شد', 'gapineh' ),
						'summary'   => __( 'جمع: %1$d بررسی — %2$d سالم، %3$d خطا، %4$d هشدار', 'gapineh' ),
					),
				)
			);
		}
	}

	/**
	 * رندر صفحهٔ مخاطبین.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'gapineh' ) );
		}

		if ( ! Gapineh_DB::installed() ) {
			Gapineh_DB::install();
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$only   = ! empty( $_GET['only_contact'] );
		// phpcs:enable WordPress.Security.NonceVerification

		$res = Gapineh_DB::list_contacts(
			array(
				'search'            => $search,
				'per_page'          => 50,
				'page'              => $page,
				'only_with_contact' => $only,
			)
		);

		$stats = self::stats();
		?>
		<div class="wrap psb-admin">
			<div class="psb-adm-hero">
				<div>
					<h1><span class="dashicons dashicons-groups"></span> <?php esc_html_e( 'مخاطبین', 'gapineh' ); ?></h1>
					<p class="psb-adm-lede"><?php esc_html_e( 'همهٔ کسانی که در سایت با دستیار گفت‌وگو کرده‌اند، به‌همراه مشخصات و وضعیت گفتگو.', 'gapineh' ); ?></p>
				</div>
				<a class="button button-primary button-hero psb-adm-cta" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gapineh_export_csv' . ( $search ? '&s=' . rawurlencode( $search ) : '' ) . ( $only ? '&only_contact=1' : '' ) ), 'gapineh_export_csv' ) ); ?>">
					⬇ <?php esc_html_e( 'خروجی CSV', 'gapineh' ); ?>
				</a>
			</div>

			<div class="psb-adm-stats">
				<div class="psb-adm-stat"><b><?php echo (int) $stats['total']; ?></b><span><?php esc_html_e( 'کل مخاطبین', 'gapineh' ); ?></span></div>
				<div class="psb-adm-stat"><b><?php echo (int) $stats['with_phone']; ?></b><span><?php esc_html_e( 'با شمارهٔ تماس', 'gapineh' ); ?></span></div>
				<div class="psb-adm-stat"><b><?php echo (int) $stats['messages']; ?></b><span><?php esc_html_e( 'کل پیام‌ها', 'gapineh' ); ?></span></div>
				<div class="psb-adm-stat"><b><?php echo (int) $stats['open']; ?></b><span><?php esc_html_e( 'گفتگوی باز', 'gapineh' ); ?></span></div>
			</div>

			<form method="get" class="psb-adm-tools">
				<input type="hidden" name="page" value="gapineh-contacts" />
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'جستجو در نام، نام خانوادگی، شماره، ایمیل…', 'gapineh' ); ?>" />
				<label class="psb-adm-check"><input type="checkbox" name="only_contact" value="1" <?php checked( $only ); ?> /> <?php esc_html_e( 'فقط آن‌ها که مشخصات داده‌اند', 'gapineh' ); ?></label>
				<button type="submit" class="button"><?php esc_html_e( 'جستجو', 'gapineh' ); ?></button>
			</form>

			<div class="psb-adm-card">
				<table class="psb-adm-table">
					<thead>
						<tr>
							<th>#</th>
							<th><?php esc_html_e( 'نام', 'gapineh' ); ?></th>
							<th><?php esc_html_e( 'نام خانوادگی', 'gapineh' ); ?></th>
							<th><?php esc_html_e( 'شمارهٔ تماس', 'gapineh' ); ?></th>
							<th><?php esc_html_e( 'ایمیل', 'gapineh' ); ?></th>
							<th><?php esc_html_e( 'پیام‌ها', 'gapineh' ); ?></th>
							<th><?php esc_html_e( 'وضعیت', 'gapineh' ); ?></th>
							<th><?php esc_html_e( 'آخرین فعالیت', 'gapineh' ); ?></th>
							<th><?php esc_html_e( 'IP', 'gapineh' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php
						if ( empty( $res['items'] ) ) :
							?>
							<tr><td colspan="10" class="psb-adm-empty"><?php esc_html_e( 'مخاطبی پیدا نشد.', 'gapineh' ); ?></td></tr>
							<?php
						else :
							$i = ( ( $page - 1 ) * 50 );
							foreach ( $res['items'] as $c ) :
								$i++;
								$name = $c->visitor_name ? $c->visitor_name : '—';
								$fam  = $c->visitor_family ? $c->visitor_family : '—';
								$ph   = $c->visitor_phone ? $c->visitor_phone : '—';
								$em   = $c->visitor_email ? $c->visitor_email : '—';
								?>
								<tr>
									<td class="psb-adm-num"><?php echo (int) $i; ?></td>
									<td><strong><?php echo esc_html( $name ); ?></strong></td>
									<td><?php echo esc_html( $fam ); ?></td>
									<td class="psb-adm-phone" dir="ltr"><?php echo esc_html( $ph ); ?></td>
									<td><?php echo esc_html( $em ); ?></td>
									<td>
										<span class="psb-adm-pill"><?php echo (int) $c->msg_count; ?></span>
										<span class="psb-adm-dim">(<?php echo (int) $c->visitor_msgs; ?> <?php esc_html_e( 'از کاربر', 'gapineh' ); ?>)</span>
									</td>
									<td>
										<?php if ( 'closed' === $c->status ) : ?>
											<span class="psb-adm-tag closed"><?php esc_html_e( 'بسته', 'gapineh' ); ?></span>
										<?php elseif ( 'agent' === $c->mode ) : ?>
											<span class="psb-adm-tag agent"><?php esc_html_e( 'اپراتور', 'gapineh' ); ?></span>
										<?php else : ?>
											<span class="psb-adm-tag ai"><?php esc_html_e( 'هوش مصنوعی', 'gapineh' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="psb-adm-dim"><?php echo esc_html( Gapineh_Jalali::format_mysql( $c->updated_at ) ); ?></td>
									<td class="psb-adm-dim" dir="ltr"><?php echo esc_html( $c->ip ); ?></td>
									<td>
										<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=gapineh-inbox&open=' . (int) $c->id ) ); ?>"><?php esc_html_e( 'گفتگو', 'gapineh' ); ?></a>
									</td>
								</tr>
								<?php
							endforeach;
						endif;
						?>
					</tbody>
				</table>
			</div>

			<?php if ( $res['pages'] > 1 ) : ?>
				<div class="psb-adm-pager">
					<?php for ( $p = 1; $p <= $res['pages']; $p++ ) : ?>
						<a class="button <?php echo ( $p === $page ) ? 'button-primary' : ''; ?>"
							href="<?php echo esc_url( add_query_arg( array( 'page' => 'gapineh-contacts', 'paged' => $p, 's' => $search ), admin_url( 'admin.php' ) ) ); ?>"><?php echo (int) $p; ?></a>
					<?php endfor; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * آمار کلی.
	 *
	 * @return array
	 */
	private static function stats() {
		global $wpdb;

		$sess = Gapineh_DB::sessions_table();
		$msgs = Gapineh_DB::messages_table();

		$total      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sess}" ); // phpcs:ignore WordPress.DB
		$with_phone = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sess} WHERE visitor_phone <> ''" ); // phpcs:ignore WordPress.DB
		$messages   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$msgs}" ); // phpcs:ignore WordPress.DB
		$open       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sess} WHERE status='open'" ); // phpcs:ignore WordPress.DB

		return compact( 'total', 'with_phone', 'messages', 'open' );
	}

	/**
	 * خروجی CSV.
	 */
	public static function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'gapineh' ) );
		}
		check_admin_referer( 'gapineh_export_csv' );

		// phpcs:disable WordPress.Security.NonceVerification
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$only   = ! empty( $_GET['only_contact'] );
		// phpcs:enable WordPress.Security.NonceVerification

		$res = Gapineh_DB::list_contacts(
			array(
				'search'            => $search,
				'per_page'          => 500,
				'page'              => 1,
				'only_with_contact' => $only,
			)
		);

		$filename = Gapineh_Jalali::file_stamp( 'gapineh-contacts' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );

		// BOM برای اینکه اکسل فارسی را درست نشان دهد
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		fputcsv(
			$out,
			array(
				'#',
				__( 'نام', 'gapineh' ),
				__( 'نام خانوادگی', 'gapineh' ),
				__( 'شمارهٔ تماس', 'gapineh' ),
				__( 'ایمیل', 'gapineh' ),
				__( 'زبان', 'gapineh' ),
				__( 'تعداد پیام', 'gapineh' ),
				__( 'پیام از کاربر', 'gapineh' ),
				__( 'حالت پاسخ‌گویی', 'gapineh' ),
				__( 'وضعیت', 'gapineh' ),
				__( 'آخرین پیام', 'gapineh' ),
				__( 'صفحهٔ ورود', 'gapineh' ),
				__( 'آخرین صفحه', 'gapineh' ),
				__( 'IP', 'gapineh' ),
				__( 'تاریخ شروع', 'gapineh' ),
				__( 'آخرین فعالیت', 'gapineh' ),
			)
		);

		$i = 0;
		foreach ( $res['items'] as $c ) {
			$i++;
			fputcsv(
				$out,
				array(
					$i,
					$c->visitor_name,
					isset( $c->visitor_family ) ? $c->visitor_family : '',
					isset( $c->visitor_phone ) ? "\t" . $c->visitor_phone : '',   // \t تا اکسل شماره را عدد نکند
					$c->visitor_email,
					$c->lang,
					(int) $c->msg_count,
					(int) $c->visitor_msgs,
					( 'agent' === $c->mode ) ? 'human' : 'ai',
					$c->status,
					wp_strip_all_tags( (string) $c->last_message ),
					$c->entry_url,
					$c->current_url,
					$c->ip,
					mysql2date( 'Y-m-d H:i', $c->created_at ),
					mysql2date( 'Y-m-d H:i', $c->updated_at ),
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
