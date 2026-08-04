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

		$decoded = json_decode( $text, true );

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

		$result = $this->post_with_heartbeat( $this->endpoint( 'claude' ), wp_json_encode( $body ), $heartbeat );

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
	 * Query or mutate the server-side free-generation quota for this site.
	 *
	 * @param string $action check|consume|refund
	 * @return array|WP_Error [ 'used' => int, 'limit' => int, 'remaining' => int, 'allowed' => bool ]
	 */
	public function quota( $action ) {
		$response = wp_remote_post(
			$this->endpoint( 'ai-quota' ),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'action'  => $action,
						'feature' => self::FEATURE,
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
			$msg = ( is_array( $data ) && isset( $data['message'] ) ) ? $data['message'] : ( 'HTTP ' . $code );
			return new WP_Error( 'ai_quota_error', $msg );
		}

		return $data;
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
