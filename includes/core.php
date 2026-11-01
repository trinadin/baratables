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

	/**
	 * Decrypt a stored value, telling "could not be decrypted" apart from "was empty".
	 *
	 * The key is derived from wp_salt('auth'), so rotating the site's salts -- a routine
	 * step after a security incident -- makes every stored credential unreadable. Returning
	 * an empty string for that case is indistinguishable from a database with no password,
	 * and the admin only sees a generic "could not connect". Null says exactly what
	 * happened, so the caller can ask for the password again.
	 *
	 * @param string $stored The stored value.
	 * @return string|null The plaintext, or null when the value could not be decrypted.
	 */
	public static function decrypt_or_null(string $stored): ?string {
		if ($stored === '') {
			return '';
		}
		if (strpos($stored, 'enc:v2:') === 0) {
			return self::decrypt_gcm(substr($stored, 7));
		}
		if (strpos($stored, 'enc:') !== 0) {
			// Not one of our ciphertexts: a legacy plaintext password, passed through as-is.
			return $stored;
		}
		return self::decrypt_legacy_cbc(substr($stored, 4));
	}

	/**
	 * Decrypt a stored value, failing closed to an empty string.
	 *
	 * Callers that need to tell "could not be decrypted" from "was empty" use
	 * decrypt_or_null() instead.
	 *
	 * @param string $stored The stored value.
	 * @return string The plaintext, or an empty string when it could not be decrypted.
	 */
	public static function decrypt(string $stored): string {
		$plaintext = self::decrypt_or_null($stored);
		return $plaintext === null ? '' : $plaintext;
	}

	private static function decrypt_gcm(string $payload): ?string {
		$decoded = self::decode_encrypted_payload($payload, self::CIPHER, self::TAG_LENGTH);
		if ($decoded === null) {
			return null;
		}
		[$raw, $iv_length] = $decoded;
		$key = self::get_key();
		$iv = substr($raw, 0, $iv_length);
		$tag = substr($raw, $iv_length, self::TAG_LENGTH);
		$ciphertext = substr($raw, $iv_length + self::TAG_LENGTH);
		$decrypted = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
		// A wrong key (rotated salts) or a tampered payload fails the GCM tag check.
		return $decrypted !== false ? $decrypted : null;
	}

	private static function decrypt_legacy_cbc(string $payload): ?string {
		$decoded = self::decode_encrypted_payload($payload, self::LEGACY_CIPHER);
		if ($decoded === null) {
			return null;
		}
		[$raw, $iv_length] = $decoded;
		$key = self::get_key();
		$iv = substr($raw, 0, $iv_length);
		$ciphertext = substr($raw, $iv_length);
		$decrypted = openssl_decrypt($ciphertext, self::LEGACY_CIPHER, $key, OPENSSL_RAW_DATA, $iv);
		return $decrypted !== false ? $decrypted : null;
	}

	/** @return array{0:string,1:int}|null Decoded bytes and IV length. */
	private static function decode_encrypted_payload(string $payload, string $cipher, int $minimum_after_iv = 0): ?array {
		if (!function_exists('openssl_decrypt') || !function_exists('openssl_cipher_iv_length')) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes authenticated/legacy encrypted binary stored by this plugin.
		$raw = base64_decode($payload, true);
		$iv_length = openssl_cipher_iv_length($cipher);
		if ($raw === false || !is_int($iv_length) || strlen($raw) < ($iv_length + $minimum_after_iv)) {
			return null;
		}
		return [$raw, $iv_length];
	}
}

/**
 * Byte-level normalisation shared by every reader that accepts text a third party produced.
 *
 * The importer, the live CSV source and the remote snapshot all parse files that were
 * exported by someone else's software. Without this, the same file can render correctly
 * through one path and as mojibake through another: Excel writes a UTF-8 BOM, some
 * exporters write UTF-16, and a byte-order mark that survives into the first header cell
 * becomes a stray U+FEFF in the rendered heading.
 */
final class BaraTables_Text_Normalizer {
	/**
	 * Strip a byte-order mark and convert the payload to UTF-8.
	 *
	 * @param string $raw Raw bytes as read from disk or the network.
	 * @return string The same content as UTF-8, without a leading BOM.
	 */
	public static function normalize_text(string $raw): string {
		// The mb_convert_encoding() calls in this method are silenced on purpose. The input is
		// an arbitrary file, so a malformed byte sequence is an expected outcome rather than a
		// programming error; the function warns on those, and each call site already handles a
		// failed conversion by falling back to the original bytes. Letting the warning through
		// would print PHP notices over the admin screen for a file the user simply mis-saved.
		if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
			$raw = substr($raw, 3);
		} elseif (strncmp($raw, "\xFF\xFE", 2) === 0 && function_exists('mb_convert_encoding')) {
			// UTF-16 little-endian (BOM FF FE) -- used by some exporters; convert before parsing.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- untrusted file may be malformed.
			$raw = (string) @mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
		} elseif (strncmp($raw, "\xFE\xFF", 2) === 0 && function_exists('mb_convert_encoding')) {
			// UTF-16 big-endian (BOM FE FF).
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- untrusted file may be malformed.
			$raw = (string) @mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
		}
		if (function_exists('mb_check_encoding') && !mb_check_encoding($raw, 'UTF-8') && function_exists('mb_convert_encoding')) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- untrusted file may be malformed; failure falls back below.
			$converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8');
			if (is_string($converted) && $converted !== '') {
				$raw = $converted;
			}
		}
		return $raw;
	}

	/**
	 * Strip a byte-order mark and convert a BOM-declared encoding, WITHOUT guessing.
	 *
	 * The remote snapshot deliberately REJECTS malformed UTF-8 rather than guessing which
	 * legacy encoding the bytes were in. A human uploading a file can see the result of
	 * a guess and fix it; a remote source cannot be inspected before it is trusted. So
	 * this handles only the encodings the bytes themselves declare -- a UTF-8 BOM, or a
	 * UTF-16 BOM -- and leaves every other byte exactly as it was, so a malformed body
	 * still fails the source's own UTF-8 check.
	 *
	 * @param string $raw Raw bytes.
	 * @return string The same content without a leading BOM, and in UTF-8 if it declared UTF-16.
	 */
	public static function strip_declared_encoding(string $raw): string {
		if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
			return substr($raw, 3);
		}
		if (strncmp($raw, "\xFF\xFE", 2) === 0 && function_exists('mb_convert_encoding')) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- untrusted body may be malformed.
			return (string) @mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
		}
		if (strncmp($raw, "\xFE\xFF", 2) === 0 && function_exists('mb_convert_encoding')) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- untrusted body may be malformed.
			return (string) @mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
		}
		return $raw;
	}

	/**
	 * Read a file and return its content as normalised UTF-8 text.
	 *
	 * @param string $path Absolute path to a readable file.
	 * @return string The file content, or an empty string when it cannot be read.
	 */
	public static function read_file(string $path): string {
		if ($path === '' || !is_readable($path)) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A bounded CSV attachment is read directly; WP_Filesystem has no CSV parsing equivalent.
		$raw = @file_get_contents($path);
		if (!is_string($raw) || $raw === '') {
			return '';
		}
		return self::normalize_text($raw);
	}
}

final class BaraTables_Source_Type {
	public const WP_QUERY = 'wp_query';
	public const CUSTOM_QUERY = 'custom_query';
	public const CUSTOM_DATA = 'custom_data';
	public const CSV = 'csv';
	public const EXTERNAL_DB = 'external_db';
	public const REMOTE_SNAPSHOT = 'remote_snapshot';

	private const ALL = [
		self::WP_QUERY,
		self::CUSTOM_QUERY,
		self::CUSTOM_DATA,
		self::CSV,
		self::EXTERNAL_DB,
		self::REMOTE_SNAPSHOT,
	];

	public static function normalize($raw, string $default = self::WP_QUERY): string {
		$clean = sanitize_key((string) $raw);
		return in_array($clean, self::ALL, true) ? $clean : $default;
	}

	public static function labels(): array {
		// Same words the editor's Data source dropdown uses, so a table never changes what its
		// source is called between the editor and the list screens.
		return [
			self::WP_QUERY => __('WP query builder', 'baratables'),
			self::CSV => __('CSV file', 'baratables'),
			self::EXTERNAL_DB => __('External database', 'baratables'),
			self::REMOTE_SNAPSHOT => __('Remote snapshot', 'baratables'),
			self::CUSTOM_QUERY => __('Custom WP query', 'baratables'),
			self::CUSTOM_DATA => __('Manual data', 'baratables'),
		];
	}

	public static function uses_builder_fields(string $source): bool {
		return in_array($source, [self::WP_QUERY, self::CUSTOM_QUERY], true);
	}

	public static function supports_post_type_selection(string $source): bool {
		return self::uses_builder_fields($source);
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

	public static function is_remote_snapshot(string $source): bool {
		return $source === self::REMOTE_SNAPSHOT;
	}

	public static function uses_column_preview(string $source): bool {
		return in_array($source, [self::CSV, self::EXTERNAL_DB, self::REMOTE_SNAPSHOT], true);
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
