<?php
/**
 * Client for the WPZOOM AI proxy (ai.wpzoom.com).
 *
 * All AI traffic goes through the WPZOOM proxy — no API keys ever live on the
 * user's site. The proxy exposes:
 *   - POST /services/v1/claude   — forwards an Anthropic Messages body (the
 *     proxy owns model selection and may remap the requested model).
 *   - POST /services/v1/pexels   — Pexels photo search.
 *   - POST /services/v1/ai-quota — server-enforced free-generation quota,
 *     keyed by site URL (action: check | consume | refund).
 *   - POST /services/v1/ai-feedback — post-generation survey answers.
 *
 * @package Inspiro Starter Sites
 */

namespace Inspiro\Starter_Sites\Ai;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class AiProxyClient {

	/**
	 * Quota feature slug on the proxy. Distinct from the premium theme's
	 * 'demo-tailor' feature so the two free tiers are metered separately.
	 */
	const FEATURE = 'demo-generate';

	/**
	 * Model requested from the proxy. The proxy remaps any model that is not
	 * on its allowlist to its configured default, so a retired model here
	 * degrades gracefully without a plugin update.
	 */
	const MODEL = 'claude-sonnet-4-6';

	/**
	 * Options holding the email registration issued by the proxy's
	 * /ai-connect endpoint. The site_key authorizes quota calls; without it
	 * the server answers `registration_required`.
	 */
	const SITE_KEY_OPTION = 'inspiro_starter_sites_ai_site_key';
	const EMAIL_OPTION    = 'inspiro_starter_sites_ai_email';

	/**
	 * Proxy REST base, e.g. https://ai.wpzoom.com/wp-json/services/v1
	 *
	 * @return string
	 */
	public static function base_url() {
		$base = defined( 'INSPIRO_STARTER_SITES_AI_ENDPOINT' )
			? INSPIRO_STARTER_SITES_AI_ENDPOINT
			: 'https://ai.wpzoom.com/wp-json/services/v1';

		/**
		 * Filter the AI proxy base URL (e.g. to point at a staging proxy).
		 *
		 * @param string $base
		 */
		return untrailingslashit( apply_filters( 'inspiro_starter_sites/ai_endpoint', $base ) );
	}

	/**
	 * Shared proxy access token (same auth model as the other WPZOOM AI
	 * clients — the quota, not the token, is the enforcement mechanism).
	 *
	 * @return string
	 */
	public static function token() {
		$token = defined( 'INSPIRO_STARTER_SITES_AI_TOKEN' )
			? INSPIRO_STARTER_SITES_AI_TOKEN
			: 'OSDM0o2HIDRM1wlMVa4wasK11dZ1OIdl';

		return apply_filters( 'inspiro_starter_sites/ai_token', $token );
	}

	/**
	 * Build a service endpoint URL with the common identification query args.
	 *
	 * @param string $service claude|pexels|ai-quota
	 * @return string
	 */
	private function endpoint( $service ) {
		return add_query_arg(
			array(
				'token'    => rawurlencode( self::token() ),
				'site_url' => rawurlencode( get_site_url() ),
				'plugin'   => 'inspiro-starter-sites-ai',
			),
			self::base_url() . '/' . $service
		);
	}

	/**
	 * Ask Claude (via the proxy) for a JSON object and return it decoded.
	 *
	 * @param string        $system     System prompt.
	 * @param string        $prompt     User prompt.
	 * @param int           $max_tokens Completion cap.
	 * @param callable|null $heartbeat  Invoked periodically while waiting on
	 *                                  the proxy so the caller can emit
	 *                                  keep-alive bytes (see StreamingResponse).
	 * @return array|WP_Error Decoded JSON object.
	 */
	public function claude_json( $system, $prompt, $max_tokens = 12000, $heartbeat = null ) {
		$text = $this->claude_text( $system, $prompt, $max_tokens, $heartbeat );

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		// Strip markdown fences, then slice out the first brace-balanced
		// object so stray prose before/after the JSON can't break the parse.
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/s', '', trim( $text ) );
		$text = $this->extract_json_object( $text );

		$decoded = $this->decode_json_lenient( $text );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'ai_parse_error', __( 'The AI returned a malformed response. Please try again.', 'inspiro-starter-sites' ) );
		}

		return $decoded;
	}

	/**
	 * Ask Claude (via the proxy) for raw text (e.g. an HTML document).
	 *
	 * @param string        $system     System prompt.
	 * @param string        $prompt     User prompt.
	 * @param int           $max_tokens Completion cap.
	 * @param callable|null $heartbeat  Keep-alive callback (see claude_json()).
	 * @return string|WP_Error Concatenated text content.
	 */
	public function claude_text( $system, $prompt, $max_tokens = 12000, $heartbeat = null ) {
		$body = array(
			'model'      => self::MODEL,
			'max_tokens' => (int) $max_tokens,
			'stream'     => false,
			// No-op on Sonnet 4.6/Haiku 4.5, but keeps generation on the fast
			// path if the proxy is switched to a model where thinking is on
			// by default (Sonnet 5+).
			'thinking'   => array( 'type' => 'disabled' ),
			'system'     => $system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		);

		return $this->request_claude( $body, $heartbeat );
	}

	/**
	 * Run a server-defined AI task: the proxy assembles the prompt from
	 * { task, vars } (see wpzoom-ai-prompts.php on the AI server), so no
	 * prompt engineering ships in this plugin's source.
	 *
	 * @param string        $task      Task slug (demo-plan, demo-page, ...).
	 * @param array         $vars      Task variables.
	 * @param callable|null $heartbeat Keep-alive callback.
	 * @return string|WP_Error Text response.
	 */
	public function claude_task( $task, array $vars, $heartbeat = null ) {
		return $this->request_claude(
			array(
				'task'        => $task,
				'vars'        => $vars,
				'stream'      => false,
				// Every task request needs an active email registration; the
				// server rate-limits per email/day.
				'site_key'    => (string) get_option( self::SITE_KEY_OPTION, '' ),
				// A VERIFIED premium license additionally unlocks the Premium
				// design level, using the same server-side gate as the licensed
				// quota. Absent or invalid keys silently get the standard
				// prompts — the server never errors on this.
				'license_key' => self::premium_license(),
			),
			$heartbeat
		);
	}

	/**
	 * claude_task() + JSON extraction.
	 *
	 * @param string        $task      Task slug.
	 * @param array         $vars      Task variables.
	 * @param callable|null $heartbeat Keep-alive callback.
	 * @return array|WP_Error Decoded JSON object.
	 */
	public function claude_task_json( $task, array $vars, $heartbeat = null ) {
		$text = $this->claude_task( $task, $vars, $heartbeat );

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$text    = preg_replace( '/^```(?:json)?\s*|\s*```$/s', '', trim( $text ) );
		$text    = $this->extract_json_object( $text );
		$decoded = $this->decode_json_lenient( $text );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'ai_parse_error', __( 'The AI returned a malformed response. Please try again.', 'inspiro-starter-sites' ) );
		}

		return $decoded;
	}

	/**
	 * json_decode with a repair pass: models occasionally emit CSS escape
	 * sequences (e.g. content:'\00B7') inside JSON string values — invalid
	 * JSON escapes that fail a strict parse. Doubling any backslash that
	 * doesn't start a valid JSON escape makes the document parseable again
	 * (the stray backslash is then stripped by the CSS sanitizer anyway).
	 *
	 * @param string $text Candidate JSON.
	 * @return array|null
	 */
	private function decode_json_lenient( $text ) {
		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		$repaired = preg_replace_callback(
			'/\\\\(?!["\\\\\/bfnrtu])/',
			static function () {
				return '\\\\';
			},
			$text
		);

		$decoded = json_decode( $repaired, true );
		if ( is_array( $decoded ) ) {
			error_log( '[inspiro-starter-sites AI] JSON repaired: invalid escape sequences doubled.' ); // phpcs:ignore
			return $decoded;
		}

		error_log( '[inspiro-starter-sites AI] JSON parse failed. First 300 chars: ' . substr( $text, 0, 300 ) ); // phpcs:ignore
		return null;
	}

	/**
	 * POST a request body to the Claude proxy and return the text content.
	 *
	 * @param array         $body      Request body (raw Anthropic or task form).
	 * @param callable|null $heartbeat Keep-alive callback.
	 * @return string|WP_Error
	 */
	private function request_claude( array $body, $heartbeat = null ) {
		$t0     = microtime( true );
		$result = $this->post_with_heartbeat( $this->endpoint( 'claude' ), wp_json_encode( $body ), $heartbeat );
		self::log_timing( 'claude:' . ( isset( $body['task'] ) ? $body['task'] : 'raw' ), $t0 );

		if ( is_wp_error( $result ) ) {
			error_log( '[inspiro-starter-sites AI] proxy transport error: ' . $result->get_error_message() ); // phpcs:ignore
			return new WP_Error( 'ai_proxy_error', $result->get_error_message() );
		}

		$code = $result['code'];
		$raw  = json_decode( $result['body'], true );

		if ( $code < 200 || $code >= 300 || empty( $raw['success'] ) ) {
			$msg = '';
			if ( is_array( $raw ) ) {
				$msg = isset( $raw['message'] ) ? $raw['message'] : ( isset( $raw['data']['error']['message'] ) ? $raw['data']['error']['message'] : '' );
			}
			error_log( '[inspiro-starter-sites AI] proxy error (HTTP ' . $code . '): ' . ( $msg ? $msg : substr( (string) $result['body'], 0, 500 ) ) ); // phpcs:ignore
			return new WP_Error( 'ai_proxy_error', $msg ? $msg : ( 'HTTP ' . $code ) );
		}

		// $raw['data'] is the verbatim Anthropic Messages response object.
		$claude = isset( $raw['data'] ) && is_array( $raw['data'] ) ? $raw['data'] : array();

		if ( isset( $claude['stop_reason'] ) && 'max_tokens' === $claude['stop_reason'] ) {
			return new WP_Error( 'ai_truncated', __( 'The AI response was cut off. Please try a shorter description.', 'inspiro-starter-sites' ) );
		}

		$text = '';
		if ( ! empty( $claude['content'] ) && is_array( $claude['content'] ) ) {
			foreach ( $claude['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		if ( '' === $text ) {
			return new WP_Error( 'ai_empty', __( 'The AI returned an empty response. Please try again.', 'inspiro-starter-sites' ) );
		}

		return $text;
	}

	/**
	 * POST JSON to a URL, invoking $heartbeat periodically *during* the
	 * request. libcurl fires the progress callback roughly once per second
	 * even while idle-waiting on the server — that's the keep-alive tick that
	 * stops web servers (Apache FastCGI: 30s idle) from killing our own
	 * request while we wait on a long AI generation.
	 *
	 * Falls back to a blocking wp_remote_post() when cURL is unavailable
	 * (no heartbeat there — very short-idle-timeout hosts may still drop it).
	 *
	 * @param string        $url       Endpoint.
	 * @param string        $json_body Request body.
	 * @param callable|null $heartbeat Keep-alive callback.
	 * @return array|WP_Error [ 'code' => int, 'body' => string ]
	 */
	private function post_with_heartbeat( $url, $json_body, $heartbeat = null ) {
		if ( ! function_exists( 'curl_init' ) ) {
			$response = wp_remote_post(
				$url,
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => $json_body,
					// Must outlast the proxy's own upstream cap; the proxy
					// emits keep-alive padding to survive Cloudflare.
					'timeout' => 300,
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			return array(
				'code' => (int) wp_remote_retrieve_response_code( $response ),
				'body' => wp_remote_retrieve_body( $response ),
			);
		}

		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_POST             => true,
				CURLOPT_POSTFIELDS       => $json_body,
				CURLOPT_HTTPHEADER       => array( 'Content-Type: application/json' ),
				CURLOPT_RETURNTRANSFER   => true,
				CURLOPT_TIMEOUT          => 300,
				CURLOPT_CONNECTTIMEOUT   => 30,
				CURLOPT_NOPROGRESS       => false,
				CURLOPT_PROGRESSFUNCTION => static function () use ( $heartbeat ) {
					if ( $heartbeat ) {
						call_user_func( $heartbeat );
					}
					return 0; // Non-zero would abort the transfer.
				},
			)
		);

		$body     = curl_exec( $ch );
		$code     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curl_err = curl_error( $ch );
		curl_close( $ch );

		if ( false === $body ) {
			return new WP_Error( 'ai_proxy_error', 'Proxy request failed: ' . $curl_err );
		}

		return array(
			'code' => $code,
			'body' => $body,
		);
	}

	/**
	 * Search Pexels via the proxy.
	 *
	 * @param string $query       Search phrase (English works best).
	 * @param int    $per_page    Photos to request (max 15).
	 * @param string $orientation landscape|portrait|square.
	 * @return array[] List of [ 'id' => int, 'url' => string ] (resized URL). Empty on failure.
	 */
	public function pexels_photos( $query, $per_page = 3, $orientation = 'landscape' ) {
		$t0       = microtime( true );
		$response = wp_remote_post(
			$this->endpoint( 'pexels' ),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'query'       => $query,
						'per_page'    => min( max( 1, (int) $per_page ), 15 ),
						'size'        => 'medium',
						'orientation' => in_array( $orientation, array( 'landscape', 'portrait', 'square' ), true ) ? $orientation : 'landscape',
					)
				),
				'timeout' => 30,
			)
		);

		self::log_timing( 'pexels:' . $query, $t0 );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$raw = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $raw['success'] ) || empty( $raw['data']['photos'] ) || ! is_array( $raw['data']['photos'] ) ) {
			return array();
		}

		$max_width = (int) apply_filters( 'inspiro_starter_sites/ai_image_max_width', 1920 );
		$photos    = array();

		foreach ( $raw['data']['photos'] as $photo ) {
			$id       = isset( $photo['id'] ) ? (int) $photo['id'] : 0;
			$original = isset( $photo['src']['original'] ) ? $photo['src']['original'] : '';
			if ( ! $id || ! $original ) {
				continue;
			}
			$photos[] = array(
				'id'  => $id,
				'url' => $original . sprintf( '?auto=compress&cs=tinysrgb&w=%d', $max_width ),
			);
		}

		return $photos;
	}

	/**
	 * The site's Inspiro Premium license key, when one is activated (the
	 * premium theme's EDD updater stores it — the options survive a switch
	 * back to Lite). A VERIFIED license unlocks the higher licensed
	 * generation limit on the server.
	 *
	 * @return string '' when no active license.
	 */
	public static function premium_license() {
		// The licensed limit is tied to USING Inspiro Premium, not merely
		// owning a key: switching to Lite or another premium theme leaves the
		// license options in the database, but they only count while Inspiro
		// Premium itself is active.
		if ( ! AiDemoGenerator::is_premium_inspiro() ) {
			return '';
		}

		if ( 'valid' !== get_option( 'inspiro_license_key_status' ) ) {
			return '';
		}

		return trim( (string) get_option( 'inspiro_license_key', '' ) );
	}

	/**
	 * Whether this site holds a registration key from /ai-connect.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return '' !== (string) get_option( self::SITE_KEY_OPTION, '' );
	}

	/**
	 * The email the site is registered with (empty when not connected).
	 *
	 * @return string
	 */
	public function connected_email() {
		return (string) get_option( self::EMAIL_OPTION, '' );
	}

	/**
	 * Register this site + email with the proxy. When the server requires
	 * email verification it responds `pending` (a 6-digit code was emailed)
	 * and the site_key is only released by verify(); otherwise the key is
	 * stored immediately. The server keys free quota by the verified email.
	 *
	 * @param string $email   User email.
	 * @param bool   $consent Whether the user opted into WPZOOM marketing emails.
	 * @return array|WP_Error [ 'email' => string, 'pending' => bool ].
	 */
	public function connect( $email, $consent = false ) {
		$response = wp_remote_post(
			$this->endpoint( 'ai-connect' ),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'email'   => $email,
						'consent' => $consent ? 1 : 0,
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ai_connect_unreachable', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['success'] ) ) {
			$msg = ( is_array( $data ) && ! empty( $data['message'] ) ) ? $data['message'] : ( 'HTTP ' . $code );
			return new WP_Error( 'ai_connect_error', $msg );
		}

		if ( ! empty( $data['pending'] ) ) {
			return array(
				'pending' => true,
				'email'   => sanitize_email( (string) ( isset( $data['email'] ) ? $data['email'] : $email ) ),
			);
		}

		if ( empty( $data['site_key'] ) ) {
			return new WP_Error( 'ai_connect_error', __( 'The AI service returned an unexpected response. Please try again.', 'inspiro-starter-sites' ) );
		}

		update_option( self::SITE_KEY_OPTION, sanitize_text_field( (string) $data['site_key'] ), false );
		update_option( self::EMAIL_OPTION, sanitize_email( (string) ( isset( $data['email'] ) ? $data['email'] : $email ) ), false );

		return array(
			'pending' => false,
			'email'   => $this->connected_email(),
		);
	}

	/**
	 * Confirm the emailed 6-digit code and store the released site_key.
	 *
	 * @param string $code 6-digit verification code.
	 * @return array|WP_Error [ 'email' => string ] on success.
	 */
	public function verify( $code ) {
		$response = wp_remote_post(
			$this->endpoint( 'ai-verify' ),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'code' => preg_replace( '/\D/', '', (string) $code ) ) ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ai_verify_unreachable', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || ! is_array( $data ) || empty( $data['success'] ) || empty( $data['site_key'] ) ) {
			$msg = ( is_array( $data ) && ! empty( $data['message'] ) ) ? $data['message'] : ( 'HTTP ' . $status );
			return new WP_Error( 'ai_verify_error', $msg );
		}

		update_option( self::SITE_KEY_OPTION, sanitize_text_field( (string) $data['site_key'] ), false );
		update_option( self::EMAIL_OPTION, sanitize_email( (string) ( isset( $data['email'] ) ? $data['email'] : '' ) ), false );

		return array( 'email' => $this->connected_email() );
	}

	/**
	 * Forget the stored registration (e.g. when the server no longer
	 * recognizes the site_key).
	 */
	public function disconnect() {
		delete_option( self::SITE_KEY_OPTION );
		delete_option( self::EMAIL_OPTION );
	}

	/**
	 * Query or mutate the server-side free-generation quota for this site.
	 *
	 * @param string $action check|consume|refund
	 * @return array|WP_Error [ 'used' => int, 'limit' => int, 'remaining' => int, 'allowed' => bool ].
	 *                        Error code `ai_registration_required` means the site
	 *                        must (re)connect with an email first.
	 */
	public function quota( $action ) {
		$response = wp_remote_post(
			$this->endpoint( 'ai-quota' ),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'action'      => $action,
						'feature'     => self::FEATURE,
						'site_key'    => (string) get_option( self::SITE_KEY_OPTION, '' ),
						// A verified premium license raises the limit
						// server-side; invalid ones silently fall back to
						// the free registration identity.
						'license_key' => self::premium_license(),
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ai_quota_unreachable', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['success'] ) ) {
			if ( is_array( $data ) && isset( $data['code'] ) && 'registration_required' === $data['code'] ) {
				return new WP_Error( 'ai_registration_required', __( 'Connect with your email to use free AI generations.', 'inspiro-starter-sites' ) );
			}
			$msg = ( is_array( $data ) && isset( $data['message'] ) ) ? $data['message'] : ( 'HTTP ' . $code );
			return new WP_Error( 'ai_quota_error', $msg );
		}

		return $data;
	}

	/**
	 * Send one post-generation survey submission to the proxy, where it is
	 * stored against the same identity the quota uses (verified license, or
	 * the email behind the site_key). The server dedupes on
	 * (plan_id, stage), so a resubmit corrects the previous answer.
	 *
	 * @param array $args {
	 *     @type string $plan_id Generation ID the feedback is about (required).
	 *     @type string $stage   'post-generate' | 'follow-up'.
	 *     @type int    $rating  1-5, or 0 when not answered.
	 *     @type string $kept    'kept' | 'discarded' | 'undecided' | ''.
	 *     @type string $missing What the demo was missing.
	 *     @type string $comment Anything else.
	 *     @type array  $context Run details (industry, pages, art direction…).
	 * }
	 * @return true|WP_Error
	 */
	public function feedback( array $args ) {
		$response = wp_remote_post(
			$this->endpoint( 'ai-feedback' ),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'feature'     => self::FEATURE,
						'site_key'    => (string) get_option( self::SITE_KEY_OPTION, '' ),
						'license_key' => self::premium_license(),
						'plan_id'     => isset( $args['plan_id'] ) ? (string) $args['plan_id'] : '',
						'stage'       => isset( $args['stage'] ) ? (string) $args['stage'] : 'post-generate',
						'rating'      => isset( $args['rating'] ) ? (int) $args['rating'] : 0,
						'kept'        => isset( $args['kept'] ) ? (string) $args['kept'] : '',
						'missing'     => isset( $args['missing'] ) ? (string) $args['missing'] : '',
						'comment'     => isset( $args['comment'] ) ? (string) $args['comment'] : '',
						'context'     => isset( $args['context'] ) && is_array( $args['context'] ) ? $args['context'] : array(),
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ai_feedback_unreachable', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['success'] ) ) {
			$msg = ( is_array( $data ) && isset( $data['message'] ) ) ? $data['message'] : ( 'HTTP ' . $code );
			return new WP_Error( 'ai_feedback_error', $msg );
		}

		return true;
	}

	/**
	 * Timing probe for performance analysis. Off by default; enable with
	 * `add_filter( 'inspiro_starter_sites/ai_timing', '__return_true' )` —
	 * durations land in the PHP error log tagged [iss-ai-timing].
	 *
	 * @param string $label Operation label.
	 * @param float  $t0    microtime(true) at operation start.
	 */
	public static function log_timing( $label, $t0 ) {
		if ( apply_filters( 'inspiro_starter_sites/ai_timing', false ) ) {
			error_log( sprintf( '[iss-ai-timing] %-40s %6.2fs', $label, microtime( true ) - $t0 ) ); // phpcs:ignore
		}
	}

	/**
	 * Return the first complete, brace-balanced {...} object in $text,
	 * ignoring braces inside string literals.
	 *
	 * @param string $text
	 * @return string
	 */
	private function extract_json_object( $text ) {
		$start = strpos( $text, '{' );
		if ( false === $start ) {
			return $text;
		}

		$depth  = 0;
		$in_str = false;
		$esc    = false;
		$len    = strlen( $text );

		for ( $i = $start; $i < $len; $i++ ) {
			$ch = $text[ $i ];

			if ( $in_str ) {
				if ( $esc ) {
					$esc = false;
				} elseif ( '\\' === $ch ) {
					$esc = true;
				} elseif ( '"' === $ch ) {
					$in_str = false;
				}
				continue;
			}

			if ( '"' === $ch ) {
				$in_str = true;
			} elseif ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $text, $start, $i - $start + 1 );
				}
			}
		}

		return substr( $text, $start );
	}
}
