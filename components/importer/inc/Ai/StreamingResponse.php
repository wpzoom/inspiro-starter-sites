<?php
/**
 * Keep-alive JSON response for long-running AJAX handlers.
 *
 * Web servers kill requests that stay silent too long (Apache FastCGI idles
 * out at 30s on many setups, nginx at 60s, Cloudflare at ~100s) — but an AI
 * generation legitimately takes 40-90s. This helper commits an HTTP 200 up
 * front, emits a whitespace byte every few seconds while the slow work runs,
 * then appends the JSON payload. Leading whitespace is valid JSON padding, so
 * jQuery's response parsing is unaffected.
 *
 * Consequence: once begin() has run, the HTTP status is committed — errors
 * must be reported inside the JSON body ({"success":false,...}), never via
 * wp_send_json_error().
 *
 * @package Inspiro Starter Sites
 */

namespace Inspiro\Starter_Sites\Ai;

defined( 'ABSPATH' ) || exit;

class StreamingResponse {

	/**
	 * Timestamp of the last emitted keep-alive byte.
	 *
	 * @var float
	 */
	private $last_tick = 0;

	/**
	 * Whether begin() has run.
	 *
	 * @var bool
	 */
	private $started = false;

	/**
	 * Minimum seconds between keep-alive bytes.
	 *
	 * @var int
	 */
	private $interval;

	/**
	 * @param int $interval Seconds between keep-alive bytes.
	 */
	public function __construct( $interval = 5 ) {
		$this->interval = max( 1, (int) $interval );
	}

	/**
	 * Commit the response and defeat every buffering layer between PHP and
	 * the client so keep-alive bytes actually reach the web server.
	 */
	public function begin() {
		if ( $this->started ) {
			return;
		}
		$this->started = true;

		ignore_user_abort( true );

		// PHP notices printed into the stream would corrupt the JSON payload.
		@ini_set( 'display_errors', '0' ); // phpcs:ignore
		@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore
		}

		// Discard buffered output (stray notices, BOMs) and disable buffering.
		while ( ob_get_level() > 0 ) {
			@ob_end_clean(); // phpcs:ignore
		}

		status_header( 200 );
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' ); // nginx: don't buffer this response.

		// Padding defeats minimum-buffer-size thresholds in server modules.
		echo str_repeat( ' ', 2048 ); // phpcs:ignore WordPress.Security.EscapeOutput
		flush();

		$this->last_tick = microtime( true );
	}

	/**
	 * Emit a keep-alive byte (throttled). Safe to call as often as convenient
	 * — pass this as the heartbeat callable into slow operations.
	 */
	public function tick() {
		if ( ! $this->started ) {
			return;
		}
		if ( ( microtime( true ) - $this->last_tick ) < $this->interval ) {
			return;
		}
		echo "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		flush();
		$this->last_tick = microtime( true );
	}

	/**
	 * Emit the payload in wp_send_json_success() shape and terminate.
	 *
	 * @param array $data Response data.
	 */
	public function finish_success( array $data ) {
		$this->finish( array( 'success' => true, 'data' => $data ) );
	}

	/**
	 * Emit the payload in wp_send_json_error() shape and terminate.
	 *
	 * @param array $data Error data (message, code, detail...).
	 */
	public function finish_error( array $data ) {
		$this->finish( array( 'success' => false, 'data' => $data ) );
	}

	/**
	 * @param array $payload Full response envelope.
	 */
	private function finish( array $payload ) {
		if ( ! $this->started ) {
			// Streaming never started — fall back to the regular WP responder.
			if ( ! empty( $payload['success'] ) ) {
				wp_send_json_success( $payload['data'] );
			}
			wp_send_json_error( $payload['data'] );
		}

		$json = wp_json_encode( $payload );

		// wp_json_encode() returns false on invalid UTF-8 (models can emit
		// it) — which would silently produce an empty body. Substitute.
		if ( false === $json ) {
			$json = wp_json_encode( $payload, JSON_INVALID_UTF8_SUBSTITUTE );
		}
		if ( false === $json ) {
			$json = '{"success":false,"data":{"message":"Response encoding failed."}}';
		}

		echo "\n" . $json; // phpcs:ignore WordPress.Security.EscapeOutput
		flush();

		exit;
	}
}
