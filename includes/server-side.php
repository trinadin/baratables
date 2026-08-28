<?php

if (!defined('ABSPATH')) {
	exit;
}

/** Public read-only endpoint for an eligible published external-database table. */
final class BaraTables_Server_Side_Endpoint {
	private const ACTION = 'btbl_server_table';
	private BaraTables_Service $service;

	public function __construct(BaraTables_Service $service) {
		$this->service = $service;
		add_action('wp_ajax_' . self::ACTION, [$this, 'handle']);
		add_action('wp_ajax_nopriv_' . self::ACTION, [$this, 'handle']);
	}

	public function handle(): void {
		nocache_headers();
		header('Cache-Control: no-store, private, max-age=0', true);
		$content_length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw shape/length must be checked before unslashing and sanitizing below.
		$raw_id = $_POST['table_id'] ?? '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See raw-validation contract above.
		$raw_token = $_POST['token'] ?? '';
		if ($content_length > 65536 || !is_scalar($raw_id) || !is_scalar($raw_token)) {
			$this->fail();
		}
		$raw_id = (string) wp_unslash($raw_id);
		$raw_token = (string) wp_unslash($raw_token);
		if (strlen($raw_id) > 191 || strlen($raw_token) !== 64) {
			$this->fail();
		}
		$id = sanitize_title($raw_id);
		$token = sanitize_text_field($raw_token);
		$definition = $id !== '' ? $this->service->find_definition($id, true) : null;
		if (!$definition || $token === '' || !hash_equals($this->service->get_server_side_token($definition), $token)) {
			$this->fail();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public, read-only published-data endpoint with a definition-bound token and bounded inputs.
		$request = is_array($_POST) ? wp_unslash($_POST) : [];
		$result = $this->service->get_server_side_page($definition, $request);
		if (is_wp_error($result)) {
			$this->fail($result->get_error_code() === 'server_side_rate_limited' ? 429 : 400);
		}
		unset($result['_raw']);
		wp_send_json($result);
	}

	private function fail(int $status = 400): void {
		wp_send_json([
			'draw' => 0,
			'recordsTotal' => 0,
			'recordsFiltered' => 0,
			'data' => [],
			'error' => __('This table request could not be completed.', 'baratables'),
		], $status);
	}
}
