<?php
/**
 * Response: an immutable HTTP response the router builds and sends.
 *
 * @package AgentMint\ProductMarkdownMirror
 */

namespace AgentMint\ProductMarkdownMirror;

defined( 'ABSPATH' ) || exit;

/**
 * Value object for a mirror HTTP response.
 *
 * Built and returned by Router::handle_request() so the serving logic is
 * fully testable; send() is the only side-effecting method.
 */
class Response {

	/**
	 * HTTP status code.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Response headers, name => value.
	 *
	 * @var array<string, string>
	 */
	private $headers;

	/**
	 * Response body.
	 *
	 * @var string
	 */
	private $body;

	/**
	 * Constructor.
	 *
	 * @param int                   $status  HTTP status code.
	 * @param array<string, string> $headers Headers, name => value.
	 * @param string                $body    Body.
	 */
	public function __construct( $status, array $headers, $body ) {
		$this->status  = (int) $status;
		$this->headers = $headers;
		$this->body    = (string) $body;
	}

	/**
	 * HTTP status code.
	 *
	 * @return int
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Headers, name => value.
	 *
	 * @return array<string, string>
	 */
	public function get_headers() {
		return $this->headers;
	}

	/**
	 * Body.
	 *
	 * @return string
	 */
	public function get_body() {
		return $this->body;
	}

	/**
	 * The shared honest-404 response.
	 *
	 * @return Response
	 */
	public static function not_found() {
		return new self(
			404,
			array(
				'Content-Type'           => 'text/plain; charset=UTF-8',
				'X-Content-Type-Options' => 'nosniff',
				'X-Robots-Tag'           => 'noindex',
				'Cache-Control'          => 'no-cache',
			),
			"Not found.\n"
		);
	}

	/**
	 * Send the response and terminate the request.
	 *
	 * @return void
	 */
	public function send() {
		status_header( $this->status );

		foreach ( $this->headers as $name => $value ) {
			header( $name . ': ' . $value );
		}

		echo $this->body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text/markdown body, not HTML; escaping would corrupt the document.

		exit;
	}
}
