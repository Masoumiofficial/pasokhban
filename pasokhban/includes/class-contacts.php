<?php
/**
 * صفحهٔ «مخاطبین» در پیشخوان + خروجی CSV.
 *
 * @package Pasokhban
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس مخاطبین.
 */
final class Pasokhban_Contacts {

	/**
	 * قلاب‌ها.
	 */
	public static function hooks() {
		add_action( 'admin_post_pasokhban_export_csv', array( __CLASS__, 'export_csv' ) );
	}

	/**
	 * بارگذاری دارایی‌ها.
	 *
	 * @param string $hook
	 */
	public static function assets( $hook ) {
		$is_settings  = Pasokhban_Settings::is_page( $hook, 'settings' );
		$is_contacts  = Pasokhban_Settings::is_page( $hook, 'contacts' );
		$is_inbox     = Pasokhban_Settings::is_page( $hook, 'inbox' );
		$is_analytics = Pasokhban_Settings::is_page( $hook, 'analytics' );

		// صفحهٔ آمار قبلاً از این فهرست جا مانده بود و بی‌CSS رندر می‌شد.
		if ( ! $is_settings && ! $is_contacts && ! $is_inbox && ! $is_analytics ) {
			return;
		}

		wp_enqueue_style(
			'pasokhban-admin',
			PASOKHBAN_URL . 'assets/css/pasokhban-admin.css',
			array(),
			PASOKHBAN_VERSION
		);

		if ( $is_settings ) {
			wp_enqueue_script(
				'pasokhban-settings',
				PASOKHBAN_URL . 'assets/js/pasokhban-settings.js',
				array(),
				PASOKHBAN_VERSION,
				true
			);

			// تب «بررسی سلامت» بررسی‌ها را تک‌تک از REST می‌گیرد تا
			// پیشرفت زنده نشان داده شود و یک سرویس کند، بقیه را بلوکه نکند.
			wp_localize_script(
				'pasokhban-settings',
				'PASOKHBAN_HEALTH',
				array(
					'url'   => esc_url_raw( rest_url( 'pasokhban/v1/admin/health' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'i18n'  => array(
						'running'   => __( 'در حال اجرا…', 'pasokhban' ),
						'runAll'    => __( 'اجرای همهٔ بررسی‌ها', 'pasokhban' ),
						'runGroup'  => __( 'اجرای این بخش', 'pasokhban' ),
						'pending'   => __( 'هنوز اجرا نشده', 'pasokhban' ),
						'copied'    => __( 'گزارش کپی شد', 'pasokhban' ),
						'copyFail'  => __( 'کپی نشد؛ خودتان انتخاب و کپی کنید.', 'pasokhban' ),
						'netErr'    => __( 'ارتباط با سرور قطع شد.', 'pasokhban' ),
						'done'      => __( 'بررسی کامل شد', 'pasokhban' ),
						'pass'      => __( 'سالم', 'pasokhban' ),
						'fail'      => __( 'خطا', 'pasokhban' ),
						'warn'      => __( 'هشدار', 'pasokhban' ),
						'info'      => __( 'اطلاع', 'pasokhban' ),
						'skip'      => __( 'رد شد', 'pasokhban' ),
						'summary'   => __( 'جمع: %1$d بررسی — %2$d سالم، %3$d خطا، %4$d هشدار', 'pasokhban' ),
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
			wp_die( esc_html__( 'دسترسی ندارید.', 'pasokhban' ) );
		}

		if ( ! Pasokhban_DB::installed() ) {
			Pasokhban_DB::install();
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$only   = ! empty( $_GET['only_contact'] );
		// phpcs:enable WordPress.Security.NonceVerification

		$res = Pasokhban_DB::list_contacts(
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
					<h1><span class="dashicons dashicons-groups"></span> <?php esc_html_e( 'مخاطبین', 'pasokhban' ); ?></h1>
					<p class="psb-adm-lede"><?php esc_html_e( 'همهٔ کسانی که در سایت با دستیار گفت‌وگو کرده‌اند، به‌همراه مشخصات و وضعیت گفتگو.', 'pasokhban' ); ?></p>
				</div>
				<a class="button button-primary button-hero psb-adm-cta" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pasokhban_export_csv' . ( $search ? '&s=' . rawurlencode( $search ) : '' ) . ( $only ? '&only_contact=1' : '' ) ), 'pasokhban_export_csv' ) ); ?>">
					⬇ <?php esc_html_e( 'خروجی CSV', 'pasokhban' ); ?>
				</a>
			</div>

			<div class="psb-adm-stats">
				<div class="psb-adm-stat"><b><?php echo (int) $stats['total']; ?></b><span><?php esc_html_e( 'کل مخاطبین', 'pasokhban' ); ?></span></div>
				<div class="psb-adm-stat"><b><?php echo (int) $stats['with_phone']; ?></b><span><?php esc_html_e( 'با شمارهٔ تماس', 'pasokhban' ); ?></span></div>
				<div class="psb-adm-stat"><b><?php echo (int) $stats['messages']; ?></b><span><?php esc_html_e( 'کل پیام‌ها', 'pasokhban' ); ?></span></div>
				<div class="psb-adm-stat"><b><?php echo (int) $stats['open']; ?></b><span><?php esc_html_e( 'گفتگوی باز', 'pasokhban' ); ?></span></div>
			</div>

			<form method="get" class="psb-adm-tools">
				<input type="hidden" name="page" value="pasokhban-contacts" />
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'جستجو در نام، نام خانوادگی، شماره، ایمیل…', 'pasokhban' ); ?>" />
				<label class="psb-adm-check"><input type="checkbox" name="only_contact" value="1" <?php checked( $only ); ?> /> <?php esc_html_e( 'فقط آن‌ها که مشخصات داده‌اند', 'pasokhban' ); ?></label>
				<button type="submit" class="button"><?php esc_html_e( 'جستجو', 'pasokhban' ); ?></button>
			</form>

			<div class="psb-adm-card">
				<table class="psb-adm-table">
					<thead>
						<tr>
							<th>#</th>
							<th><?php esc_html_e( 'نام', 'pasokhban' ); ?></th>
							<th><?php esc_html_e( 'نام خانوادگی', 'pasokhban' ); ?></th>
							<th><?php esc_html_e( 'شمارهٔ تماس', 'pasokhban' ); ?></th>
							<th><?php esc_html_e( 'ایمیل', 'pasokhban' ); ?></th>
							<th><?php esc_html_e( 'پیام‌ها', 'pasokhban' ); ?></th>
							<th><?php esc_html_e( 'وضعیت', 'pasokhban' ); ?></th>
							<th><?php esc_html_e( 'آخرین فعالیت', 'pasokhban' ); ?></th>
							<th><?php esc_html_e( 'IP', 'pasokhban' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php
						if ( empty( $res['items'] ) ) :
							?>
							<tr><td colspan="10" class="psb-adm-empty"><?php esc_html_e( 'مخاطبی پیدا نشد.', 'pasokhban' ); ?></td></tr>
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
										<span class="psb-adm-dim">(<?php echo (int) $c->visitor_msgs; ?> <?php esc_html_e( 'از کاربر', 'pasokhban' ); ?>)</span>
									</td>
									<td>
										<?php if ( 'closed' === $c->status ) : ?>
											<span class="psb-adm-tag closed"><?php esc_html_e( 'بسته', 'pasokhban' ); ?></span>
										<?php elseif ( 'agent' === $c->mode ) : ?>
											<span class="psb-adm-tag agent"><?php esc_html_e( 'اپراتور', 'pasokhban' ); ?></span>
										<?php else : ?>
											<span class="psb-adm-tag ai"><?php esc_html_e( 'هوش مصنوعی', 'pasokhban' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="psb-adm-dim"><?php echo esc_html( Pasokhban_Jalali::format_mysql( $c->updated_at ) ); ?></td>
									<td class="psb-adm-dim" dir="ltr"><?php echo esc_html( $c->ip ); ?></td>
									<td>
										<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=pasokhban-inbox&open=' . (int) $c->id ) ); ?>"><?php esc_html_e( 'گفتگو', 'pasokhban' ); ?></a>
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
							href="<?php echo esc_url( add_query_arg( array( 'page' => 'pasokhban-contacts', 'paged' => $p, 's' => $search ), admin_url( 'admin.php' ) ) ); ?>"><?php echo (int) $p; ?></a>
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

		$sess = Pasokhban_DB::sessions_table();
		$msgs = Pasokhban_DB::messages_table();

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
			wp_die( esc_html__( 'دسترسی ندارید.', 'pasokhban' ) );
		}
		check_admin_referer( 'pasokhban_export_csv' );

		// phpcs:disable WordPress.Security.NonceVerification
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$only   = ! empty( $_GET['only_contact'] );
		// phpcs:enable WordPress.Security.NonceVerification

		$res = Pasokhban_DB::list_contacts(
			array(
				'search'            => $search,
				'per_page'          => 500,
				'page'              => 1,
				'only_with_contact' => $only,
			)
		);

		$filename = Pasokhban_Jalali::file_stamp( 'pasokhban-contacts' ) . '.csv';

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
				__( 'نام', 'pasokhban' ),
				__( 'نام خانوادگی', 'pasokhban' ),
				__( 'شمارهٔ تماس', 'pasokhban' ),
				__( 'ایمیل', 'pasokhban' ),
				__( 'زبان', 'pasokhban' ),
				__( 'تعداد پیام', 'pasokhban' ),
				__( 'پیام از کاربر', 'pasokhban' ),
				__( 'حالت پاسخ‌گویی', 'pasokhban' ),
				__( 'وضعیت', 'pasokhban' ),
				__( 'آخرین پیام', 'pasokhban' ),
				__( 'صفحهٔ ورود', 'pasokhban' ),
				__( 'آخرین صفحه', 'pasokhban' ),
				__( 'IP', 'pasokhban' ),
				__( 'تاریخ شروع', 'pasokhban' ),
				__( 'آخرین فعالیت', 'pasokhban' ),
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
