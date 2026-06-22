<?php

if (!defined('ABSPATH')) {
	exit;
}

final class BaraTables_Asset_Utils {
	public static function get_asset_version(string $plugin_path, string $relative): string {
		$path = trailingslashit($plugin_path) . ltrim($relative, '/');
		if (file_exists($path)) {
			$mtime = filemtime($path);
			if ($mtime) {
				return (string) $mtime;
			}
		}
		return '1';
	}
}


final class BaraTables_Id_Generator {
	public static function generate_chart_id(): string {
		return 'btbl-chart-' . wp_generate_uuid4();
	}
}

final class BaraTables_Crypto {
	private const CIPHER = 'aes-256-gcm';
	private const LEGACY_CIPHER = 'aes-256-cbc';
	private const TAG_LENGTH = 16;

	private static function get_key(): string {
		$secret = function_exists('wp_salt') ? wp_salt('auth') : (defined('AUTH_KEY') ? AUTH_KEY : '');
		return hash('sha256', (string) $secret, true);
	}

	public static function encrypt(string $plaintext): string {
		if ($plaintext === '') {
			return '';
		}
		if (!function_exists('openssl_encrypt') || !function_exists('openssl_cipher_iv_length')) {
			return '';
		}
		$key = self::get_key();
		$iv_length = openssl_cipher_iv_length(self::CIPHER);
		if (!is_int($iv_length) || $iv_length <= 0) {
			return '';
		}
		$iv = random_bytes($iv_length);
		$tag = '';
		$encrypted = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);
		if ($encrypted === false) {
			return '';
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes binary IV/tag/ciphertext for safe postmeta storage.
		return 'enc:v2:' . base64_encode($iv . $tag . $encrypted);
	}

	public static function decrypt(string $stored): string {
		if ($stored === '') {
			return $stored;
		}
		if (strpos($stored, 'enc:v2:') === 0) {
			return self::decrypt_gcm(substr($stored, 7));
		}
		if (strpos($stored, 'enc:') !== 0) {
			return $stored;
		}
		return self::decrypt_legacy_cbc(substr($stored, 4));
	}

	private static function decrypt_gcm(string $payload): string {
		if (!function_exists('openssl_decrypt') || !function_exists('openssl_cipher_iv_length')) {
			return '';
		}
		$key = self::get_key();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes binary IV/tag/ciphertext stored by encrypt().
		$raw = base64_decode($payload, true);
		if ($raw === false) {
			return '';
		}
		$iv_length = openssl_cipher_iv_length(self::CIPHER);
		if (!is_int($iv_length) || strlen($raw) < ($iv_length + self::TAG_LENGTH)) {
			return '';
		}
		$iv = substr($raw, 0, $iv_length);
		$tag = substr($raw, $iv_length, self::TAG_LENGTH);
		$ciphertext = substr($raw, $iv_length + self::TAG_LENGTH);
		$decrypted = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
		return $decrypted !== false ? $decrypted : '';
	}

	private static function decrypt_legacy_cbc(string $payload): string {
		if (!function_exists('openssl_decrypt') || !function_exists('openssl_cipher_iv_length')) {
			return '';
		}
		$key = self::get_key();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes legacy binary IV/ciphertext stored by previous encrypt().
		$raw = base64_decode($payload, true);
		if ($raw === false) {
			return '';
		}
		$iv_length = openssl_cipher_iv_length(self::LEGACY_CIPHER);
		if (!is_int($iv_length) || strlen($raw) < $iv_length) {
			return '';
		}
		$iv = substr($raw, 0, $iv_length);
		$ciphertext = substr($raw, $iv_length);
		$decrypted = openssl_decrypt($ciphertext, self::LEGACY_CIPHER, $key, OPENSSL_RAW_DATA, $iv);
		return $decrypted !== false ? $decrypted : '';
	}
}

final class BaraTables_Source_Type {
	public const WP_QUERY = 'wp_query';
	public const CUSTOM_QUERY = 'custom_query';
	public const CUSTOM_DATA = 'custom_data';
	public const CSV = 'csv';
	public const EXTERNAL_DB = 'external_db';

	private const ALL = [
		self::WP_QUERY,
		self::CUSTOM_QUERY,
		self::CUSTOM_DATA,
		self::CSV,
		self::EXTERNAL_DB,
	];

	public static function normalize($raw, string $default = self::WP_QUERY): string {
		$clean = sanitize_key((string) $raw);
		return in_array($clean, self::ALL, true) ? $clean : $default;
	}

	public static function labels(): array {
		return [
			self::WP_QUERY => __('WP Query', 'baratables'),
			self::CSV => __('CSV', 'baratables'),
			self::EXTERNAL_DB => __('External DB', 'baratables'),
			self::CUSTOM_QUERY => __('Custom Query', 'baratables'),
			self::CUSTOM_DATA => __('Custom Data', 'baratables'),
		];
	}

	public static function uses_builder_fields(string $source): bool {
		return in_array($source, [self::WP_QUERY, self::CUSTOM_QUERY], true);
	}

	public static function supports_post_type_selection(string $source): bool {
		return in_array($source, [self::WP_QUERY, self::CUSTOM_QUERY], true);
	}

	public static function is_csv(string $source): bool {
		return $source === self::CSV;
	}

	public static function is_external_db(string $source): bool {
		return $source === self::EXTERNAL_DB;
	}

	public static function is_custom_data(string $source): bool {
		return $source === self::CUSTOM_DATA;
	}

	public static function uses_column_preview(string $source): bool {
		return in_array($source, [self::CSV, self::EXTERNAL_DB], true);
	}
}


final class BaraTables_Taxonomy_Filters {
	public static function normalize(array $raw): array {
		if (empty($raw)) {
			return [];
		}
		if (isset($raw['taxonomy']) && isset($raw['terms'])) {
			return [$raw];
		}
		return array_values($raw);
	}
}
