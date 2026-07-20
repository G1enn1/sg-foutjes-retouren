<?php
/**
 * Settings storage + settings admin page.
 *
 * Static accessors (employees(), hubspot_token(), ...) are used across the
 * plugin. Secrets prefer a wp-config.php constant over the DB option.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class STM_Settings {

	/** Custom capability that gates the whole plugin. */
	const CAP = 'stm_access';

	/** Users with these emails are granted access on activation. */
	const DEFAULT_ACCESS_EMAILS = array( 'cunera@stralendgroen.nl', 'glenn@stralendgroen.nl' );

	/* ------------------------------------------------------------------ *
	 * Access control — only allow-listed users may see/use the plugin
	 * ------------------------------------------------------------------ */

	/** May the current user see and use the plugin? */
	public static function can_access() {
		if ( current_user_can( self::CAP ) ) {
			return true;
		}
		// Recovery: if no one has been granted access yet, let admins in so they
		// can configure the allow-list (prevents lock-out on a fresh install).
		if ( ! self::access_grants_exist() && current_user_can( 'manage_options' ) ) {
			return true;
		}
		return false;
	}

	/** Does at least one user currently hold the access capability? */
	public static function access_grants_exist() {
		$users = get_users( array(
			'capability' => self::CAP,
			'number'     => 1,
			'fields'     => 'ID',
		) );
		return ! empty( $users );
	}

	/** Grant / revoke access for a single user. */
	public static function set_access( $user_id, $allow ) {
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user ) {
			return;
		}
		if ( $allow ) {
			$user->add_cap( self::CAP );
		} else {
			$user->remove_cap( self::CAP );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Accessors
	 * ------------------------------------------------------------------ */

	/** @return array[] list of employee maps: id,name,email,ext,hubspot_owner_id */
	public static function employees() {
		$list = get_option( 'stm_employees', array() );
		return is_array( $list ) ? $list : array();
	}

	public static function category_weights() {
		$w = get_option( 'stm_category_weights', array() );
		return is_array( $w ) ? $w : array();
	}

	public static function hubspot_token() {
		if ( defined( 'STM_HUBSPOT_TOKEN' ) && STM_HUBSPOT_TOKEN ) {
			return STM_HUBSPOT_TOKEN;
		}
		return (string) get_option( 'stm_hubspot_token', '' );
	}

	public static function webhook_secret() {
		if ( defined( 'STM_WEBHOOK_SECRET' ) && STM_WEBHOOK_SECRET ) {
			return STM_WEBHOOK_SECRET;
		}
		return (string) get_option( 'stm_webhook_secret', '' );
	}

	/** Build an ext (2xx) -> employee index. */
	public static function ext_index() {
		$idx = array();
		foreach ( self::employees() as $e ) {
			if ( ! empty( $e['ext'] ) ) {
				$idx[ (string) $e['ext'] ] = $e;
			}
		}
		return $idx;
	}

	/** Build a lowercased-first-name -> employee index (webhook/export fallback). */
	public static function name_index() {
		$idx = array();
		foreach ( self::employees() as $e ) {
			$first = strtolower( trim( explode( ' ', $e['name'] )[0] ) );
			if ( $first ) {
				$idx[ $first ] = $e;
			}
		}
		return $idx;
	}

	/** owner_id -> employee, plus email -> employee (HubSpot resolution). */
	public static function owner_index() {
		$idx = array();
		foreach ( self::employees() as $e ) {
			if ( ! empty( $e['hubspot_owner_id'] ) ) {
				$idx[ (string) $e['hubspot_owner_id'] ] = $e;
			}
			if ( ! empty( $e['email'] ) ) {
				$idx[ strtolower( $e['email'] ) ] = $e;
			}
		}
		return $idx;
	}

	/* ------------------------------------------------------------------ *
	 * Admin page
	 * ------------------------------------------------------------------ */

	/** Process the settings form (hooked to admin_init). */
	public function maybe_save() {
		if ( ! isset( $_POST['stm_settings_nonce'] ) ) {
			return;
		}
		if ( ! self::can_access() ) {
			return;
		}
		check_admin_referer( 'stm_save_settings', 'stm_settings_nonce' );

		// Employees.
		$employees = array();
		$rows      = isset( $_POST['stm_emp'] ) && is_array( $_POST['stm_emp'] ) ? wp_unslash( $_POST['stm_emp'] ) : array();
		foreach ( $rows as $row ) {
			$name = sanitize_text_field( $row['name'] ?? '' );
			$ext  = preg_replace( '/\D/', '', $row['ext'] ?? '' );
			if ( '' === $name && '' === $ext ) {
				continue; // skip empty rows
			}
			$id = sanitize_key( $row['id'] ?? '' );
			if ( '' === $id ) {
				$id = sanitize_key( strtolower( strtok( $name, ' ' ) ) . '_' . $ext );
			}
			$employees[] = array(
				'id'               => $id,
				'name'             => $name,
				'email'            => sanitize_email( $row['email'] ?? '' ),
				'ext'              => $ext,
				'hubspot_owner_id' => preg_replace( '/\D/', '', $row['hubspot_owner_id'] ?? '' ),
			);
		}
		update_option( 'stm_employees', $employees );

		// HubSpot token — only overwrite the option when a constant is not in use.
		if ( ! ( defined( 'STM_HUBSPOT_TOKEN' ) && STM_HUBSPOT_TOKEN ) && isset( $_POST['stm_hubspot_token'] ) ) {
			$token = sanitize_text_field( wp_unslash( $_POST['stm_hubspot_token'] ) );
			if ( '' !== $token || isset( $_POST['stm_hubspot_token_clear'] ) ) {
				update_option( 'stm_hubspot_token', $token );
			}
		}

		// Access allow-list (the section is always submitted with this form).
		if ( isset( $_POST['stm_access_section'] ) ) {
			$allowed = isset( $_POST['stm_access_users'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['stm_access_users'] ) ) : array();
			foreach ( get_users( array( 'fields' => array( 'ID' ) ) ) as $u ) {
				self::set_access( $u->ID, in_array( (int) $u->ID, $allowed, true ) );
			}
		}

		add_settings_error( 'stm', 'stm_saved', __( 'Instellingen opgeslagen.', 'stralend-team-monitor' ), 'updated' );
		set_transient( 'stm_settings_notice', 1, 30 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'stm-settings', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_page() {
		if ( ! self::can_access() ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot Team Monitor.', 'stralend-team-monitor' ) );
		}
		$employees   = self::employees();
		$employees[] = array( 'id' => '', 'name' => '', 'email' => '', 'ext' => '', 'hubspot_owner_id' => '' ); // blank add-row
		$token_const = defined( 'STM_HUBSPOT_TOKEN' ) && STM_HUBSPOT_TOKEN;
		$secret      = self::webhook_secret();
		$webhook_url = add_query_arg( 'key', $secret, rest_url( 'team-monitor/v1/voys' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Team Monitor — Instellingen', 'stralend-team-monitor' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Instellingen opgeslagen.', 'stralend-team-monitor' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'stm_save_settings', 'stm_settings_nonce' ); ?>

				<h2><?php esc_html_e( 'Medewerkers', 'stralend-team-monitor' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Toestel = het 2xx-nummer zoals het in de Voys-export onder "Bestemming" staat (bijv. 210 voor "210/Sandra"). HubSpot owner-id vult zich vanzelf via de knop hieronder als je een e-mailadres invult.', 'stralend-team-monitor' ); ?>
				</p>
				<table class="widefat striped" id="stm-emp-table" style="max-width:900px">
					<thead><tr>
						<th><?php esc_html_e( 'Naam', 'stralend-team-monitor' ); ?></th>
						<th><?php esc_html_e( 'E-mail', 'stralend-team-monitor' ); ?></th>
						<th style="width:90px"><?php esc_html_e( 'Toestel (2xx)', 'stralend-team-monitor' ); ?></th>
						<th style="width:140px"><?php esc_html_e( 'HubSpot owner-id', 'stralend-team-monitor' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $employees as $i => $e ) : ?>
						<tr>
							<td>
								<input type="hidden" name="stm_emp[<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( $e['id'] ); ?>" />
								<input type="text" class="regular-text" name="stm_emp[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $e['name'] ); ?>" />
							</td>
							<td><input type="email" class="regular-text" name="stm_emp[<?php echo (int) $i; ?>][email]" value="<?php echo esc_attr( $e['email'] ); ?>" /></td>
							<td><input type="text" size="5" name="stm_emp[<?php echo (int) $i; ?>][ext]" value="<?php echo esc_attr( $e['ext'] ); ?>" /></td>
							<td><input type="text" size="12" name="stm_emp[<?php echo (int) $i; ?>][hubspot_owner_id]" value="<?php echo esc_attr( $e['hubspot_owner_id'] ); ?>" /></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="stm-add-row"><?php esc_html_e( '+ Rij toevoegen', 'stralend-team-monitor' ); ?></button></p>

				<h2><?php esc_html_e( 'HubSpot', 'stralend-team-monitor' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="stm_hubspot_token"><?php esc_html_e( 'Private App-token', 'stralend-team-monitor' ); ?></label></th>
						<td>
							<?php if ( $token_const ) : ?>
								<em><?php esc_html_e( 'Ingesteld via wp-config.php (STM_HUBSPOT_TOKEN) — veiligste optie.', 'stralend-team-monitor' ); ?></em>
							<?php else : ?>
								<input type="password" class="regular-text" id="stm_hubspot_token" name="stm_hubspot_token" value="<?php echo esc_attr( self::hubspot_token() ); ?>" autocomplete="off" />
								<p class="description"><?php esc_html_e( 'Scopes: sales-email-read, crm.objects.owners.read (+ tickets voor moeilijkheidsgraad). Nog veiliger: zet STM_HUBSPOT_TOKEN in wp-config.php.', 'stralend-team-monitor' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Voys Freedom — webhook', 'stralend-team-monitor' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Zet in Voys Freedom "Gespreksnotificaties" aan en wijs die naar onderstaande URL (POST). De sleutel beveiligt het endpoint.', 'stralend-team-monitor' ); ?></p>
				<p><code style="user-select:all"><?php echo esc_html( $webhook_url ); ?></code></p>

				<h2><?php esc_html_e( 'Toegang', 'stralend-team-monitor' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Alleen aangevinkte gebruikers zien het Team Monitor-menu en de cijfers. Standaard: Cunera en Glenn.', 'stralend-team-monitor' ); ?></p>
				<input type="hidden" name="stm_access_section" value="1" />
				<table class="widefat striped" style="max-width:640px">
					<thead><tr>
						<th style="width:60px"><?php esc_html_e( 'Toegang', 'stralend-team-monitor' ); ?></th>
						<th><?php esc_html_e( 'Gebruiker', 'stralend-team-monitor' ); ?></th>
						<th><?php esc_html_e( 'Rol', 'stralend-team-monitor' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( get_users( array( 'orderby' => 'display_name' ) ) as $user ) : ?>
						<tr>
							<td style="text-align:center">
								<input type="checkbox" name="stm_access_users[]" value="<?php echo (int) $user->ID; ?>" <?php checked( user_can( $user, self::CAP ) ); ?> />
							</td>
							<td><?php echo esc_html( $user->display_name ); ?><br><span class="description"><?php echo esc_html( $user->user_email ); ?></span></td>
							<td><?php echo esc_html( implode( ', ', $user->roles ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Opslaan', 'stralend-team-monitor' ) ); ?>
			</form>
		</div>

		<script>
		( function () {
			var btn = document.getElementById( 'stm-add-row' );
			if ( ! btn ) { return; }
			btn.addEventListener( 'click', function () {
				var tbody = document.querySelector( '#stm-emp-table tbody' );
				var idx   = tbody.querySelectorAll( 'tr' ).length;
				var tr    = document.createElement( 'tr' );
				tr.innerHTML =
					'<td><input type="hidden" name="stm_emp[' + idx + '][id]" value=""><input type="text" class="regular-text" name="stm_emp[' + idx + '][name]"></td>' +
					'<td><input type="email" class="regular-text" name="stm_emp[' + idx + '][email]"></td>' +
					'<td><input type="text" size="5" name="stm_emp[' + idx + '][ext]"></td>' +
					'<td><input type="text" size="12" name="stm_emp[' + idx + '][hubspot_owner_id]"></td>';
				tbody.appendChild( tr );
			} );
		} )();
		</script>
		<?php
	}
}
