<?php

namespace HRD_Auth_Sms;

defined( 'ABSPATH' ) || exit;

/**
 * Secure, reversible migration of mobile numbers from the Digits plugin.
 *
 * Flow: admin runs a dry-run preview (counts migratable / already-set /
 * invalid / conflicts), then applies. Apply backs up any existing number to
 * `_hrd_phone_backup` before writing, never overwrites an existing number
 * unless the admin explicitly confirms, skips duplicate/owned numbers, and
 * records a log. A revert restores from the backups.
 *
 * All endpoints require `manage_options` + a nonce.
 */
class DigitsMigration {

	const BACKUP_META = '_hrd_phone_backup';
	const LOG_OPTION  = 'hrd_digits_migration_log';

	public function __construct() {
		add_action( 'wp_ajax_hrd_digits_preview', [ $this, 'ajax_preview' ] );
		add_action( 'wp_ajax_hrd_digits_apply', [ $this, 'ajax_apply' ] );
		add_action( 'wp_ajax_hrd_digits_revert', [ $this, 'ajax_revert' ] );
	}

	/**
	 * Candidate user-meta keys Digits (and similar) use for the mobile number.
	 */
	public static function candidate_keys(): array {
		return (array) apply_filters( 'hrd_digits_meta_keys', [
			'digits_phone',
			'digits_phone_no',
			'digt_phone',
			'digit_phone',
		] );
	}

	private static function max_users(): int {
		return (int) apply_filters( 'hrd_digits_migration_max', 5000 );
	}

	/**
	 * Whether there is any Digits data (or the plugin) on this site.
	 *
	 * Runs on every settings-page render, so it MUST stay cheap. It uses a
	 * single index-friendly `meta_key IN (...) LIMIT 1` query (not a WP
	 * meta_query, which builds an OR self-join across usermeta that can time out
	 * on large sites) and caches the result.
	 */
	public static function digits_detected(): bool {
		$cached = get_transient( 'hrd_digits_detected' );
		if ( $cached !== false ) {
			return $cached === '1';
		}

		global $wpdb;
		$keys         = self::candidate_keys();
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$found        = (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$wpdb->usermeta} WHERE meta_key IN ($placeholders) LIMIT 1", $keys )
		);

		$found = $found || in_array( 'digits/digit.php', (array) get_option( 'active_plugins', [] ), true );

		set_transient( 'hrd_digits_detected', $found ? '1' : '0', DAY_IN_SECONDS );

		return $found;
	}

	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز.' ], 403 );
		}
		check_ajax_referer( 'hrd_sms_auth_nonce', 'nonce' );
	}

	/* ------------------------------- AJAX ------------------------------ */

	public function ajax_preview(): void {
		$this->guard();
		wp_send_json_success( $this->run( false, false, false ) );
	}

	public function ajax_apply(): void {
		$this->guard();
		$overwrite = ! empty( $_POST['overwrite'] );
		$mark      = ! empty( $_POST['mark_verified'] );
		wp_send_json_success( $this->run( true, $overwrite, $mark ) );
	}

	public function ajax_revert(): void {
		$this->guard();

		global $wpdb;
		$users = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT %d",
				self::BACKUP_META,
				self::max_users()
			)
		);
		$reverted = 0;

		foreach ( $users as $uid ) {
			$backup = get_user_meta( $uid, self::BACKUP_META, true );
			if ( $backup === '__none__' || $backup === '' ) {
				delete_user_meta( $uid, 'hrd_phone' );
			} else {
				update_user_meta( $uid, 'hrd_phone', $backup );
			}
			delete_user_meta( $uid, self::BACKUP_META );
			$reverted++;
		}

		delete_transient( 'hrd_digits_detected' );
		wp_send_json_success( [ 'reverted' => $reverted ] );
	}

	/* ----------------------------- Engine ------------------------------ */

	/**
	 * Collect candidate numbers per user and detect duplicates.
	 *
	 * One direct, index-friendly query over usermeta (meta_key IN …) instead of
	 * a WP meta_query self-join + N per-user get_user_meta() calls — so it scales
	 * to large sites without timing out.
	 */
	private function gather(): array {
		global $wpdb;

		$keys         = self::candidate_keys();
		$priority     = array_flip( array_values( $keys ) ); // meta_key => preference index
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$limit        = self::max_users() * max( 1, count( $keys ) );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_key, meta_value
				 FROM {$wpdb->usermeta}
				 WHERE meta_key IN ($placeholders) AND meta_value <> ''
				 ORDER BY user_id ASC
				 LIMIT %d",
				array_merge( $keys, [ $limit ] )
			)
		);

		// Pick the highest-priority candidate value per user.
		$best = [];
		foreach ( (array) $results as $r ) {
			$uid  = (int) $r->user_id;
			$rank = $priority[ $r->meta_key ] ?? 999;
			if ( ! isset( $best[ $uid ] ) || $rank < $best[ $uid ]['rank'] ) {
				$best[ $uid ] = [ 'val' => $r->meta_value, 'rank' => $rank ];
			}
		}

		$rows  = [];
		$count = [];
		foreach ( $best as $uid => $b ) {
			$norm         = Helper::hrd_normalize_mobile( $b['val'] );
			$rows[ $uid ] = $norm;
			if ( $norm ) {
				$count[ $norm ] = ( $count[ $norm ] ?? 0 ) + 1;
			}
		}

		return [ 'rows' => $rows, 'dupes' => array_filter( $count, static fn( $c ) => $c > 1 ) ];
	}

	/**
	 * Dry-run (apply=false) or perform (apply=true) the migration.
	 *
	 * @return array<string,int> Stats for the dashboard.
	 */
	private function run( bool $apply, bool $overwrite, bool $mark ): array {
		$gathered = $this->gather();
		$rows     = $gathered['rows'];
		$dupes    = $gathered['dupes'];

		$stats = [
			'total'      => count( $rows ),
			'migratable' => 0,
			'already'    => 0,
			'invalid'    => 0,
			'conflicts'  => 0,
			'migrated'   => 0,
		];
		$log = [];

		foreach ( $rows as $uid => $norm ) {
			if ( ! $norm ) {
				$stats['invalid']++;
				continue;
			}
			// Same normalised number on more than one user → never auto-assign.
			if ( isset( $dupes[ $norm ] ) ) {
				$stats['conflicts']++;
				continue;
			}

			$existing = get_user_meta( $uid, 'hrd_phone', true );
			if ( ! empty( $existing ) && ! $overwrite ) {
				$stats['already']++;
				continue;
			}

			// Number already owned by a different account → skip.
			$owner = Users::hrd_sms_user_exist( $norm );
			if ( $owner && (int) $owner !== (int) $uid ) {
				$stats['conflicts']++;
				continue;
			}

			$stats['migratable']++;

			if ( ! $apply ) {
				continue;
			}

			// Back up before changing anything (so the migration is reversible).
			update_user_meta( $uid, self::BACKUP_META, $existing !== '' ? $existing : '__none__' );
			update_user_meta( $uid, 'hrd_phone', $norm );

			if ( empty( get_user_meta( $uid, 'billing_phone', true ) ) ) {
				update_user_meta( $uid, 'billing_phone', $norm );
				update_user_meta( $uid, 'shipping_phone', $norm );
			}

			// Mark verified only when the admin trusts the Digits data.
			if ( $mark ) {
				update_user_meta( $uid, 'hrd_mobile_verified', time() );
			}

			$stats['migrated']++;
			if ( count( $log ) < 200 ) {
				$log[] = $uid . ' → ' . $norm;
			}
		}

		if ( $apply ) {
			update_option( self::LOG_OPTION, [
				'time'  => time(),
				'stats' => $stats,
				'log'   => $log,
			], false );
			delete_transient( 'hrd_digits_detected' );
		}

		return $stats;
	}
}

new DigitsMigration();
