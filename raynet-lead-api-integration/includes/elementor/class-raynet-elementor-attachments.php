<?php
/**
 * Files uploaded through an Elementor form, attached to the RAYNET lead.
 *
 * Elementor moves an uploaded file into uploads/elementor/forms before any
 * action runs. Its e-mail action, which runs before this plugin's, deletes a
 * file set to "attach to e-mail" once the e-mail is sent. So the files are
 * copied aside while they still exist, and uploaded to RAYNET once the lead
 * has an id to attach them to.
 *
 * @package RaynetLeadApiIntegration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Collects and uploads form attachments.
 */
class Raynet_Elementor_Attachments {

	/**
	 * Largest file sent to RAYNET, in bytes.
	 */
	const MAX_FILE = 20971520;

	/**
	 * Largest total sent with one lead, in bytes.
	 */
	const MAX_TOTAL = 52428800;

	/**
	 * Largest total attached to the fallback e-mail, in bytes.
	 *
	 * Mail servers commonly refuse messages over 25 MB, and base64 makes a
	 * file a third larger; past this the files are only listed.
	 */
	const MAX_MAIL = 10485760;

	/**
	 * Prefix of the temporary directories, so stale ones can be found.
	 */
	const TEMP_PREFIX = 'raynet-att-';

	/**
	 * Files copied aside for the submission being handled.
	 *
	 * @var array<string,array<int,array<string,mixed>>> Field id => files.
	 */
	private static $pending = array();

	/**
	 * Temporary directories to remove when the request ends.
	 *
	 * @var string[]
	 */
	private static $temp = array();

	/**
	 * Hooks into Elementor's submission.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'elementor_pro/forms/record/actions_before', array( __CLASS__, 'collect' ), 10, 2 );
		add_action( 'shutdown', array( __CLASS__, 'cleanup' ) );
	}

	/**
	 * Copies the submission's uploaded files aside, before any action runs.
	 *
	 * Paths come from the record's `files`, which only Elementor's own upload
	 * handling fills — not from `raw_value`, which a visitor can post for a
	 * field that received no file. Each path must still resolve inside the
	 * forms upload directory.
	 *
	 * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record Submission.
	 * @return \ElementorPro\Modules\Forms\Classes\Form_Record The same record.
	 */
	public static function collect( $record ) {
		self::$pending = array();
		self::sweep();

		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return $record;
		}

		$settings = (array) $record->get( 'form_settings' );

		if ( ! in_array( Raynet_Elementor_Forms::ACTION_NAME, Raynet_Elementor_Forms::submit_actions( $settings ), true ) ) {
			return $record;
		}

		$files   = (array) $record->get( 'files' );
		$ignored = Raynet_Elementor_Forms::id_list( $settings, Raynet_Elementor_Forms::IGNORED_KEY );
		$labels  = array();
		$base    = self::upload_dir();
		$total   = 0;

		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			$labels[ (string) $id ] = isset( $field['title'] ) && '' !== trim( (string) $field['title'] ) ? sanitize_text_field( (string) $field['title'] ) : (string) $id;
		}

		if ( '' === $base ) {
			return $record;
		}

		foreach ( $files as $field_id => $set ) {
			$field_id = (string) $field_id;

			if ( in_array( $field_id, $ignored, true ) || empty( $set['path'] ) || ! is_array( $set['path'] ) ) {
				continue;
			}

			foreach ( $set['path'] as $index => $path ) {
				$real = realpath( (string) $path );

				if ( false === $real || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) || ! is_file( $real ) || ! is_readable( $real ) ) {
					continue;
				}

				$name = self::original_name( $field_id, $index, $real );
				$size = (int) filesize( $real );
				$file = array(
					'name'   => $name,
					'type'   => self::mime( $name ),
					'size'   => $size,
					'label'  => isset( $labels[ $field_id ] ) ? $labels[ $field_id ] : $field_id,
					'path'   => '',
					'reason' => '',
				);

				if ( $size > self::MAX_FILE ) {
					$file['reason'] = 'size';
				} elseif ( $total + $size > self::MAX_TOTAL ) {
					$file['reason'] = 'total';
				} else {
					$copy = self::copy_aside( $real, $name );

					if ( '' === $copy ) {
						$file['reason'] = 'copy';
					} else {
						$file['path'] = $copy;
						$total       += $size;
					}
				}

				self::$pending[ $field_id ][] = $file;
			}
		}

		return $record;
	}

	/**
	 * Files collected for the current submission.
	 *
	 * @return array<string,array<int,array<string,mixed>>> Field id => files.
	 */
	public static function pending() {
		return self::$pending;
	}

	/**
	 * Lines for the lead note: which field carried which files.
	 *
	 * @return array<string,string> Field label => file names.
	 */
	public static function note_lines() {
		$lines = array();

		foreach ( self::$pending as $files ) {
			foreach ( $files as $file ) {
				$entry = $file['name'];

				if ( 'size' === $file['reason'] ) {
					$entry .= ' ' . __( '(nepřiloženo — větší než 20 MB)', 'raynet-lead-api-integration' );
				} elseif ( '' !== $file['reason'] ) {
					$entry .= ' ' . __( '(nepřiloženo)', 'raynet-lead-api-integration' );
				}

				$label           = $file['label'];
				$lines[ $label ] = isset( $lines[ $label ] ) ? $lines[ $label ] . ', ' . $entry : $entry;
			}
		}

		return $lines;
	}

	/**
	 * Local copies for the fallback e-mail, as far as a mail server takes them.
	 *
	 * Each copy already carries the visitor's file name, so the e-mail shows
	 * "nabidka.pdf" and not a temporary name. Files past MAX_MAIL are left
	 * out; the e-mail lists them by name.
	 *
	 * @return array{attach:string[],skipped:string[]} Paths to attach, names left out.
	 */
	public static function mail_files() {
		$out   = array(
			'attach'  => array(),
			'skipped' => array(),
		);
		$total = 0;

		foreach ( self::$pending as $files ) {
			foreach ( $files as $file ) {
				if ( '' === $file['path'] ) {
					continue;
				}

				if ( $total + $file['size'] > self::MAX_MAIL ) {
					$out['skipped'][] = $file['name'];
					continue;
				}

				$out['attach'][] = $file['path'];
				$total          += $file['size'];
			}
		}

		return $out;
	}

	/**
	 * Uploads the collected files to RAYNET and attaches them to the lead.
	 *
	 * The lead already exists, so a file that fails does not fail the
	 * submission; it is logged and reported to the administrator.
	 *
	 * @param Raynet_Lead_Api_Client $client  Client.
	 * @param int                    $lead_id Lead id.
	 * @return array{attached:int,failed:string[]} Outcome.
	 */
	public static function send( $client, $lead_id ) {
		$outcome = array(
			'attached' => 0,
			'failed'   => array(),
		);

		foreach ( self::$pending as $files ) {
			foreach ( $files as $file ) {
				if ( '' === $file['path'] ) {
					continue;
				}

				$uploaded = $client->upload_file( $file['path'], $file['name'], $file['type'] );

				if ( ! is_wp_error( $uploaded ) ) {
					$uploaded = $client->attach( 'lead', $lead_id, $uploaded );
				}

				if ( is_wp_error( $uploaded ) ) {
					$outcome['failed'][] = $file['name'] . ': ' . $uploaded->get_error_message();
				} else {
					$outcome['attached']++;
				}
			}
		}

		return $outcome;
	}

	/**
	 * Removes the temporary copies.
	 *
	 * @return void
	 */
	public static function cleanup() {
		foreach ( self::$temp as $dir ) {
			self::remove_dir( $dir );
		}

		self::$temp    = array();
		self::$pending = array();
	}

	/**
	 * Removes temporary directories an interrupted request left behind.
	 *
	 * A worker killed by a timeout never reaches `shutdown`.
	 *
	 * @return void
	 */
	private static function sweep() {
		$stale = glob( trailingslashit( get_temp_dir() ) . self::TEMP_PREFIX . '*', GLOB_ONLYDIR );

		foreach ( is_array( $stale ) ? $stale : array() as $dir ) {
			if ( filemtime( $dir ) < time() - HOUR_IN_SECONDS ) {
				self::remove_dir( $dir );
			}
		}
	}

	/**
	 * Removes one of this class's temporary directories with its files.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private static function remove_dir( $dir ) {
		if ( 0 !== strpos( basename( $dir ), self::TEMP_PREFIX ) || ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( trailingslashit( $dir ) . '*' ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}

		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best effort.
	}

	/**
	 * Directory Elementor stores form uploads in, resolved.
	 *
	 * @return string Real path, or an empty string.
	 */
	private static function upload_dir() {
		$uploads = wp_upload_dir( null, false );
		$path    = trailingslashit( $uploads['basedir'] ) . 'elementor/forms';

		/** This filter is documented in elementor-pro/modules/forms/fields/upload.php */
		$path = (string) apply_filters( 'elementor_pro/forms/upload_path', $path );
		$real = realpath( $path );

		return false === $real ? '' : $real;
	}

	/**
	 * The file's name as the visitor had it.
	 *
	 * Elementor stores the file under a random name; the original is still in
	 * $_FILES for this request.
	 *
	 * @param string $field_id Field id.
	 * @param int    $index    Position among the field's files.
	 * @param string $path     Stored file.
	 * @return string Name.
	 */
	private static function original_name( $field_id, $index, $path ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Elementor has validated the submission.
		$name = isset( $_FILES['form_fields'][ $field_id ][ $index ]['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['form_fields'][ $field_id ][ $index ]['name'] ) ) : '';

		// The stored file decides the extension: a renamed original could
		// otherwise claim a type the stored file does not have.
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( '' === $name ) {
			return basename( $path );
		}

		if ( '' !== $extension && strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) !== $extension ) {
			$name .= '.' . $extension;
		}

		return $name;
	}

	/**
	 * MIME type by file name.
	 *
	 * @param string $name File name.
	 * @return string MIME type.
	 */
	private static function mime( $name ) {
		$check = wp_check_filetype( $name );

		return ! empty( $check['type'] ) ? $check['type'] : 'application/octet-stream';
	}

	/**
	 * Copies a file to a temporary place of its own.
	 *
	 * @param string $path Source.
	 * @param string $name Name to base the temporary name on.
	 * @return string Copy, or an empty string on failure.
	 */
	private static function copy_aside( $path, $name ) {
		// A directory of its own per file, readable by this user only, keeps
		// the visitor's file name — wp_tempnam() would replace the extension
		// with ".tmp" — without two files of the same name colliding.
		$dir = trailingslashit( get_temp_dir() ) . self::TEMP_PREFIX . wp_generate_password( 16, false );

		if ( ! @mkdir( $dir, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Failure is handled.
			return '';
		}

		self::$temp[] = $dir;

		$copy = $dir . '/' . ( '' !== sanitize_file_name( $name ) ? sanitize_file_name( $name ) : 'priloha' );

		if ( ! @copy( $path, $copy ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is handled.
			return '';
		}

		@chmod( $copy, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Best effort.

		return $copy;
	}
}
