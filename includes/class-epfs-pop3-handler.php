<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all POP3 fetching, email parsing, and FluentSupport API calls.
 *
 * NOTE: This class assumes the PHP IMAP extension is installed and enabled.
 *
 * @since 1.0.0
 */
class EPFS_POP3_Handler {

	const CRON_HOOK = 'epfs_check_pop3_mailbox';

	/**
	 * Single instance of the class.
	 *
	 * @var EPFS_POP3_Handler
	 */
	private static $instance = null;

	/**
	 * Settings data object.
	 *
	 * @var array
	 */
	private $settings = array();

	/**
	 * Retrieves the one true instance of the class.
	 *
	 * @return EPFS_POP3_Handler
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->initialize();
		}
		return self::$instance;
	}

	/**
	 * Initialize the handler, load settings, and set up cron action.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function initialize() {
		$this->settings = EPFS_Admin_Settings::get_instance()->get_settings();
		add_action( self::CRON_HOOK, array( $this, 'process_pop3_mailbox' ) );
	}

	/**
	 * Setup or update the WP-Cron schedule.
	 *
	 * @since 1.0.0
	 *
	 * @param int $interval_seconds Optional new interval in seconds.
	 * @return void
	 */
	public function setup_cron_schedule( $interval_seconds = null ) {
		$this->clear_cron_schedule();

		$interval = is_numeric( $interval_seconds ) ? absint( $interval_seconds ) : $this->settings['cron_interval'];

		// Add custom interval if it's not a standard one.
		add_filter( 'cron_schedules', function( $schedules ) use ( $interval ) {
			$schedules['epfs_custom_interval'] = array(
				'interval' => $interval,
				'display'  => sprintf( __( 'EPFS Custom Interval (%d seconds)', 'epfs-piping' ), $interval ),
			);
			return $schedules;
		} );

		// Schedule the event.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'epfs_custom_interval', self::CRON_HOOK );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Log message.
			error_log( 'EPFS: Scheduled cron event for interval: ' . $interval . ' seconds.' );
		}
	}

	/**
	 * Clear the existing WP-Cron schedule.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function clear_cron_schedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * The main function hooked to WP-Cron to process the mailbox.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function process_pop3_mailbox() {
		// Re-load current settings and decrypt passwords right before use.
		$settings_manager = EPFS_Admin_Settings::get_instance();
		$this->settings   = $settings_manager->get_settings();

		$pop3_host      = $this->settings['pop3_host'];
		$pop3_username  = $this->settings['pop3_username'];
		$pop3_password  = $settings_manager->decrypt_value( $this->settings['pop3_password'] ); // Decrypt here!

		if ( empty( $pop3_host ) || empty( $pop3_username ) || empty( $pop3_password ) ) {
			error_log( 'EPFS: Mailbox credentials missing. Piping skipped.' );
			return;
		}

		// The mailbox spec for POP3 deletion after fetch. `/pop3` ensures POP3 protocol.
		$mailbox_spec = '{' . $pop3_host . '/pop3}INBOX';

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Use @ for IMAP functions to handle failures gracefully.
		$mbox = @imap_open( $mailbox_spec, $pop3_username, $pop3_password );

		if ( ! $mbox ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Log message.
			error_log( 'EPFS: IMAP connection failed: ' . imap_last_error() );
			return;
		}

		$total_messages = imap_num_msg( $mbox );
		error_log( 'EPFS: Connected to mailbox. Total messages: ' . $total_messages );

		$created_count = 0;
		$updated_count = 0;
		$processed_count = 0;

		for ( $i = 1; $i <= $total_messages; $i++ ) {
			$status = $this->process_single_message( $mbox, $i );

			if ( 'created' === $status ) {
				$created_count++;
				$processed_count++;
			} elseif ( 'updated' === $status ) {
				$updated_count++;
				$processed_count++;
			}

			// Pause briefly to avoid API/Server rate limits.
			usleep( 500000 ); // 0.5 second pause.
		}

		// Close the stream and expunge (delete) messages flagged for deletion.
		imap_close( $mbox, CL_EXPUNGE );

		// 4. Log the result if any tickets were created or updated.
		if ( $processed_count > 0 ) {
			$this->write_log_entry( $created_count, $updated_count );
		}
	}

	/**
	 * Processes a single email message: parsing, API interaction, and deletion.
	 *
	 * @since 1.0.0
	 *
	 * @param resource $mbox The IMAP stream resource.
	 * @param int $msg_num The message number to process.
	 * @return string 'created', 'updated', or 'skipped'.
	 */
	private function process_single_message( $mbox, $msg_num ) {
		try {
			// 1. Fetch Headers.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- See above.
			$header_info = @imap_headerinfo( $mbox, $msg_num );
			if ( ! $header_info || empty( $header_info->subject ) ) {
				error_log( "EPFS: Skipping message {$msg_num}: Headers or subject missing." );
				// Mark for deletion if unprocessable to avoid endless loop.
				imap_delete( $mbox, $msg_num );
				return 'skipped';
			}

			$parsed_data = $this->parse_email_data( $mbox, $msg_num, $header_info );

			if ( is_wp_error( $parsed_data ) ) {
				error_log( 'EPFS: ' . $parsed_data->get_error_message() );
				imap_delete( $mbox, $msg_num );
				return 'skipped';
			}

			// 2. Call FluentSupport API.
			$api_status = $this->send_to_fluentsupport( $parsed_data );

			if ( 'created' === $api_status || 'updated' === $api_status ) {
				error_log( "EPFS: Successfully processed message {$msg_num} with status: {$api_status}." );
				// 3. Mark for Deletion (successful piping).
				imap_delete( $mbox, $msg_num );
				return $api_status;
			} else {
				error_log( "EPFS: Failed to process message {$msg_num}. Keeping in mailbox." );
				return 'skipped';
			}

		} catch ( Exception $e ) {
			error_log( 'EPFS: Error processing message ' . $msg_num . ': ' . $e->getMessage() );
			return 'skipped';
		}
	}

	/**
	 * Extracts and decodes the necessary data from the email message.
	 *
	 * @since 1.0.0
	 *
	 * @param resource $mbox The IMAP stream resource.
	 * @param int $msg_num The message number to process.
	 * @param object $header_info The IMAP header information.
	 * @return array|WP_Error Parsed data or WP_Error on failure.
	 */
	private function parse_email_data( $mbox, $msg_num, $header_info ) {
		// Extract Sender Email.
		$from_address = $header_info->from[0]->mailbox . '@' . $header_info->from[0]->host;
		$from_name    = isset( $header_info->from[0]->personal ) ? $header_info->from[0]->personal : '';

		// 1. Check for Ticket ID/Hash in subject line (Reply Detection).
		$subject = imap_utf8( $header_info->subject );
		$ticket_id = $this->detect_ticket_id( $subject );

		// 2. Extract Body and Attachments (Simplified placeholder).
		$content = $this->get_email_body( $mbox, $msg_num );
		$attachments = $this->handle_email_attachments( $mbox, $msg_num );

		// 3. Customer ID Lookup (Placeholder - Full implementation requires customer API lookups).
		// For now, we assume if the user exists, FluentSupport handles the ID mapping via email.
		// If using the agent endpoint, we need the customer_id OR need to supply newCustomer fields.
		// We'll proceed assuming FluentSupport will create the user if needed, but we provide the data.

		return array(
			'subject'       => $subject,
			'content'       => $content,
			'from_email'    => $from_address,
			'from_name'     => $from_name,
			'ticket_id'     => $ticket_id,
			'attachments'   => $attachments,
		);
	}

	/**
	 * Recursively extracts the body content, prioritizing HTML then Plain Text.
	 * This is a highly simplified version. A robust solution needs full MIME recursion.
	 *
	 * @since 1.0.0
	 *
	 * @param resource $mbox The IMAP stream resource.
	 * @param int $msg_num The message number.
	 * @return string The email body content.
	 */
	private function get_email_body( $mbox, $msg_num ) {
		// Simplified body fetch: returns the full body, letting FluentSupport's endpoint clean it up.
		// A proper implementation would use imap_fetchstructure and recursively find the correct part.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- See above.
		$body = @imap_fetchbody( $mbox, $msg_num, 1 ); // Try to get part 1 (usually the main text/html part).

		if ( ! $body ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- See above.
			$body = @imap_body( $mbox, $msg_num ); // Fallback to entire body.
		}

		// Auto-decode based on IMAP function's interpretation.
		return imap_qprint( $body );
	}

	/**
	 * Detects a ticket ID or hash in the subject line for reply detection.
	 *
	 * @since 1.0.0
	 *
	 * @param string $subject The email subject line.
	 * @return string|null The detected ticket identifier or null.
	 */
	private function detect_ticket_id( $subject ) {
		// Pattern for [Ticket-HASH] or [#ID] used by many helpdesks, including FluentSupport.
		if ( preg_match( '/\[#?(\w+?)\]/', $subject, $matches ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Log message.
			error_log( 'EPFS: Reply detected via subject: ' . $matches[1] );
			return $matches[1];
		}
		// NOTE: A more robust check should also look at headers like In-Reply-To.
		return null;
	}

	/**
	 * Handles attachment extraction, decoding, and temporary saving.
	 *
	 * @since 1.0.0
	 *
	 * @param resource $mbox The IMAP stream resource.
	 * @param int $msg_num The message number.
	 * @return array An array of attachment payload data for the API.
	 */
	private function handle_email_attachments( $mbox, $msg_num ) {
		$attachments_for_api = array();

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- See above.
		$structure = @imap_fetchstructure( $mbox, $msg_num );

		if ( ! isset( $structure->parts ) || ! is_array( $structure->parts ) ) {
			return $attachments_for_api;
		}

		$parts = $structure->parts;

		foreach ( $parts as $part_num => $part ) {
			$filename = '';
			if ( isset( $part->dparameters ) ) {
				foreach ( $part->dparameters as $param ) {
					if ( 'filename' === strtolower( $param->attribute ) ) {
						$filename = imap_mime_header_decode( $param->value );
						$filename = $filename[0]->text;
						break;
					}
				}
			}

			// If we found a filename and the part is an attachment.
			if ( $filename ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- See above.
				$part_content = @imap_fetchbody( $mbox, $msg_num, $part_num + 1 ); // IMAP part numbering starts at 1.

				// Decode the content based on encoding type (3=Base64, 4=Quoted-Printable).
				if ( 3 === $part->encoding ) {
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Necessary for decoding email data.
					$file_content = base64_decode( $part_content );
				} elseif ( 4 === $part->encoding ) {
					$file_content = quoted_printable_decode( $part_content );
				} else {
					$file_content = $part_content;
				}

				if ( ! empty( $file_content ) ) {
					// Use wp_upload_bits to save the file securely in WordPress.
					// NOTE: Using a simple path, FluentSupport will handle the final move/sanitization.
					$upload = wp_upload_bits( $filename, null, $file_content, date( 'Y/m' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constant defined by core WP.

					if ( empty( $upload['error'] ) ) {
						// This is the data structure we believe the FS API will consume for attachments.
						$attachments_for_api[] = array(
							'file_name' => sanitize_file_name( $filename ),
							'file_size' => strlen( $file_content ), // Size in bytes.
							'file_url'  => $upload['url'],
						);
					} else {
						error_log( 'EPFS: Attachment upload error: ' . $upload['error'] );
					}
				}
			}
		}

		return $attachments_for_api;
	}

	/**
	 * Makes the authenticated API call to FluentSupport.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Parsed email data.
	 * @return string 'created', 'updated', or 'failed'.
	 */
	private function send_to_fluentsupport( $data ) {
		$settings_manager = EPFS_Admin_Settings::get_instance();
		$api_password = $settings_manager->decrypt_value( $this->settings['api_password'] );

		if ( empty( $this->settings['api_base_url'] ) || empty( $api_password ) ) {
			error_log( 'EPFS: API credentials or URL missing.' );
			return 'failed';
		}

		// Determine if this is a reply or a new ticket.
		if ( $data['ticket_id'] ) {
			// --- REPLY API CALL ---
			$api_url = trailingslashit( $this->settings['api_base_url'] ) . 'tickets/' . $data['ticket_id'] . '/responses';
			$payload = array(
				'content'           => $data['content'],
				'conversation_type' => 'response', // Reply to the customer.
				'close_ticket'      => 'no',
			);
			// NOTE: FluentSupport's reply endpoint may not support attachments easily.
			// A fallback would be to add a separate agent note with the attachment links if the initial reply fails.
		} else {
			// --- NEW TICKET API CALL ---
			$api_url = trailingslashit( $this->settings['api_base_url'] ) . 'tickets';

			// We use the agent endpoint here to create both customer and ticket in one call.
			$payload = array(
				'ticket' => array(
					'mailbox_id'      => $this->settings['mailbox_id'],
					'title'           => $data['subject'],
					'content'         => $data['content'],
					'source'          => 'email-pipe', // Custom source to indicate where it came from.
					'client_priority' => 'normal',
					// We use the customer creation fields instead of trying to find the ID first.
					// FluentSupport will check if the user exists by email and use/create them.
					'create_customer' => 'yes',
					'create_wp_user'  => 'no',
				),
				'newCustomer' => array(
					'email'      => $data['from_email'],
					'first_name' => $data['from_name'], // FluentSupport can usually parse the name from the email header.
					'last_name'  => '',
				),
			);

			// Add attachments if present.
			if ( ! empty( $data['attachments'] ) ) {
				// The API may require the attachments at the root level or nested differently.
				// Based on the 'GET' structure, we assume an array of attachment objects is passed.
				$payload['attachments'] = $data['attachments'];
				error_log( 'EPFS: Attempting to send ' . count( $data['attachments'] ) . ' attachments with new ticket.' );
			}
		}

		$response = wp_remote_post(
			esc_url_raw( $api_url ),
			array(
				'timeout'   => 15, // Longer timeout for potential file transfers.
				'headers'   => array(
					'Authorization' => 'Basic ' . base64_encode( $this->settings['api_username'] . ':' . $api_password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Necessary for Basic Auth.
					'Content-Type'  => 'application/json; charset=' . get_option( 'blog_charset' ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constant defined by core WP.
				),
				// FluentSupport REST API typically uses query parameters for ticket creation but JSON body for more complex posts.
				// We'll use a JSON body for consistency, though we might need to adjust this to use query args if API rejects JSON.
				'body'      => wp_json_encode( $payload ),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'EPFS: API request failed: ' . $response->get_error_message() );
			return 'failed';
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$json_response = json_decode( $response_body, true );

		if ( $response_code < 200 || $response_code >= 300 ) {
			error_log( 'EPFS: API response error (' . $response_code . '): ' . print_r( $json_response, true ) );
			return 'failed';
		}

		// Check if it's a new ticket creation or an existing ticket update.
		if ( $data['ticket_id'] ) {
			return 'updated'; // Reply was added successfully.
		} else {
			return 'created'; // New ticket was created successfully.
		}
	}

	/**
	 * Writes a log entry to the database.
	 *
	 * @since 1.0.0
	 *
	 * @param int $created_count Number of new tickets created.
	 * @param int $updated_count Number of tickets updated (replies).
	 * @return void
	 */
	private function write_log_entry( $created_count, $updated_count ) {
		// Only log if something happened.
		if ( 0 === $created_count && 0 === $updated_count ) {
			return;
		}

		$log_entries = get_option( 'epfs_piping_log', array() );

		// Only keep the last 500 log entries to prevent database bloat.
		$max_log_size = 500;
		if ( count( $log_entries ) >= $max_log_size ) {
			// Remove oldest entries.
			$log_entries = array_slice( $log_entries, count( $log_entries ) - $max_log_size + 1 );
		}

		$new_entry = array(
			'timestamp'     => current_time( 'timestamp' ),
			'created_count' => $created_count,
			'updated_count' => $updated_count,
		);

		$log_entries[] = $new_entry;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constant defined by core WP.
		update_option( 'epfs_piping_log', $log_entries );
	}
}
