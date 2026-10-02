<?php
/**
 * RAYNET users a lead can be assigned to.
 *
 * Lists them once from RAYNET, keeps the list, and draws the owner picker
 * used on every screen that sets a lead's owner — so the owner is chosen by
 * name instead of a contact person id looked up by hand.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Users of the connected RAYNET instance.
 */
class Raynet_Lead_Users {

	/**
	 * Option holding the fetched list.
	 */
	const OPTION = 'raynet_lead_users';

	/**
	 * How long a fetched list is trusted before it is fetched again.
	 */
	const MAX_AGE = 12 * HOUR_IN_SECONDS;

	/**
	 * How long to wait after a failed fetch before trying again on its own.
	 *
	 * RAYNET blocks an IP for an hour after 20 bad logins; a screen retrying
	 * on every load with wrong credentials would lock the site out.
	 */
	const RETRY_AFTER = HOUR_IN_SECONDS;

	/**
	 * Stored state, for the connection the plugin uses now.
	 *
	 * A list fetched from another instance or account is no list at all:
	 * its person ids would name strangers, or nobody. It reads as never
	 * fetched, so the pickers fall back to an id box and the next admin
	 * screen fetches the right one.
	 *
	 * @return array{people:array<int,array{name:string,login:string}>,users:array<int,string>,fetched_at:int,failed_at:int,error:string} State.
	 */
	public static function state() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$empty  = array(
			'people'     => array(),
			'users'      => array(),
			'fetched_at' => 0,
			'failed_at'  => 0,
			'error'      => '',
		);

		if ( ! isset( $stored['connection'] ) || Raynet_Lead_Settings::connection_fingerprint() !== $stored['connection'] ) {
			return $empty;
		}

		$people = array();

		foreach ( isset( $stored['people'] ) && is_array( $stored['people'] ) ? $stored['people'] : array() as $id => $person ) {
			if ( (int) $id > 0 && is_array( $person ) ) {
				$people[ (int) $id ] = array(
					'name'  => isset( $person['name'] ) ? (string) $person['name'] : '',
					'login' => isset( $person['login'] ) ? (string) $person['login'] : '',
				);
			}
		}

		$users = array();

		foreach ( $people as $id => $person ) {
			$users[ $id ] = self::label( $id, $person, true );
		}

		return array(
			'people'     => $people,
			'users'      => $users,
			'fetched_at' => isset( $stored['fetched_at'] ) ? (int) $stored['fetched_at'] : 0,
			'failed_at'  => isset( $stored['failed_at'] ) ? (int) $stored['failed_at'] : 0,
			'error'      => isset( $stored['error'] ) ? (string) $stored['error'] : '',
		);
	}

	/**
	 * Users, as contact person id => "Name (login)", for administrators.
	 *
	 * @return array<int,string> Users.
	 */
	public static function all() {
		return self::state()['users'];
	}

	/**
	 * Users by name only, for screens anyone editing a page can open.
	 *
	 * Elementor's editor is open to authors and editors, not only to
	 * administrators; they do not need every colleague's login, which is an
	 * e-mail address. The login is added only where two people share a name.
	 *
	 * @return array<int,string> Person id => name.
	 */
	public static function names() {
		$people = self::state()['people'];
		$count  = array_count_values( array_map( 'strtolower', array_column( $people, 'name' ) ) );
		$names  = array();

		foreach ( $people as $id => $person ) {
			$names[ $id ] = self::label( $id, $person, '' === $person['name'] || $count[ strtolower( $person['name'] ) ] > 1 );
		}

		return $names;
	}

	/**
	 * Display label of one person.
	 *
	 * @param int                               $id         Person id.
	 * @param array{name:string,login:string}   $person     Person.
	 * @param bool                              $with_login Add the login.
	 * @return string Label.
	 */
	private static function label( $id, array $person, $with_login ) {
		if ( '' === $person['name'] ) {
			return '' !== $person['login'] ? $person['login'] : '#' . (int) $id;
		}

		return $with_login && '' !== $person['login'] ? $person['name'] . ' (' . $person['login'] . ')' : $person['name'];
	}

	/**
	 * Fetches the list from RAYNET and stores it.
	 *
	 * A failure keeps the previous list, so a RAYNET outage does not empty
	 * the pickers.
	 *
	 * @param Raynet_Lead_Api_Client|null $client Client, or null for the configured one.
	 * @return int|WP_Error Number of users, or an error.
	 */
	public static function refresh( $client = null ) {
		$client = $client ? $client : Raynet_Lead_Api_Client::from_settings();
		$state  = self::state();
		$people = $client->get_users();

		if ( is_wp_error( $people ) ) {
			update_option(
				self::OPTION,
				array(
					'connection' => Raynet_Lead_Settings::connection_fingerprint(),
					'people'     => $state['people'],
					'fetched_at' => $state['fetched_at'],
					'failed_at'  => time(),
					'error'      => $people->get_error_message(),
				),
				false
			);

			return $people;
		}

		update_option(
			self::OPTION,
			array(
				'connection' => Raynet_Lead_Settings::connection_fingerprint(),
				'people'     => $people,
				'fetched_at' => time(),
				'failed_at'  => 0,
				'error'      => '',
			),
			false
		);

		return count( $people );
	}

	/**
	 * Refreshes the list when it is missing or old. Admin screens only.
	 *
	 * @return void
	 */
	public static function maybe_refresh() {
		if ( ! Raynet_Lead_Settings::is_configured() ) {
			return;
		}

		$state = self::state();

		if ( $state['failed_at'] && time() - $state['failed_at'] < self::RETRY_AFTER ) {
			return;
		}

		if ( $state['fetched_at'] && time() - $state['fetched_at'] < self::MAX_AGE ) {
			return;
		}

		self::refresh();
	}

	/**
	 * Choices for a select: the empty choice first, then every user.
	 *
	 * Keys are strings, as Elementor and HTML forms carry them.
	 *
	 * @param string $empty_label Label of the "no owner set here" choice.
	 * @param int    $current     Owner already chosen, kept even if no longer listed.
	 * @param bool   $names_only  Leave the logins out (see names()).
	 * @return array<string,string> Value => label.
	 */
	public static function options( $empty_label, $current = 0, $names_only = false ) {
		$options = array( '' => $empty_label );

		foreach ( $names_only ? self::names() : self::all() as $id => $label ) {
			$options[ (string) $id ] = $label;
		}

		$current = (int) $current;

		// An owner set before, by id or for a person RAYNET no longer lists,
		// stays visible and selected rather than silently becoming "inherit".
		if ( $current > 0 && ! isset( $options[ (string) $current ] ) ) {
			/* translators: %d: contact person id. */
			$options[ (string) $current ] = sprintf( __( 'ID %d (není mezi načtenými uživateli)', 'raynet-lead-api-integration' ), $current );
		}

		return $options;
	}

	/**
	 * Prints the owner picker.
	 *
	 * Falls back to a plain id box while no list has been fetched, so the
	 * owner can still be set — and an owner already set is never lost.
	 *
	 * @param string $name        Input name.
	 * @param string $id          Input id.
	 * @param int    $current     Current owner (contact person id), 0 for none.
	 * @param string $empty_label Label of the "no owner set here" choice.
	 * @param string $class       CSS class of the control.
	 * @return void
	 */
	public static function field( $name, $id, $current, $empty_label, $class = '' ) {
		$current = (int) $current;

		$state = self::state();

		if ( empty( $state['users'] ) ) {
			printf(
				'<input type="number" min="0" id="%1$s" name="%2$s" value="%3$s" class="%4$s" />',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( (string) $current ),
				esc_attr( $class )
			);

			if ( '' !== $state['error'] ) {
				/* translators: %s: error message. */
				$why = sprintf( __( 'ID kontaktní osoby, která je zároveň uživatelem. Seznam uživatelů z RAYNETu se nepodařilo načíst: %s', 'raynet-lead-api-integration' ), $state['error'] );
			} elseif ( $state['fetched_at'] ) {
				$why = __( 'ID kontaktní osoby, která je zároveň uživatelem. RAYNET nevrátil žádného uživatele s kontaktní osobou.', 'raynet-lead-api-integration' );
			} else {
				$why = __( 'ID kontaktní osoby, která je zároveň uživatelem. Seznam uživatelů z RAYNETu zatím není načtený — po úspěšném testu spojení se tu objeví výběr podle jména.', 'raynet-lead-api-integration' );
			}

			echo '<p class="description">' . esc_html( $why ) . '</p>';

			return;
		}

		printf( '<select id="%1$s" name="%2$s" class="%3$s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $class ) );

		foreach ( self::options( $empty_label, $current ) as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( (string) $value, $current > 0 ? (string) $current : '', false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}
}
