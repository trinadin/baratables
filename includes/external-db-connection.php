<?php

/**
 * The bounded wpdb connection for external MySQL/MariaDB sources.
 *
 * Applies connect and read transport timeouts before mysqli_real_connect(),
 * installs the TLS trust anchor when the source names a CA, fails closed rather
 * than bail over the site, and re-asserts a verified statement bound on reconnect.
 * Property prefixes avoid collisions with wpdb members.
 */

if (!defined('ABSPATH')) {
	exit;
}

final class BaraTables_External_Db_Connection extends wpdb {

	private bool $btbl_transport_timeouts = false;
	/** @var array{ca?:string} TLS settings for the external connection. */
	private array $btbl_tls = [];
	private string $btbl_session_timeout_sql = '';

	public function __construct($dbuser, $dbpassword, $dbname, $dbhost, array $tls = []) {
		$this->dbuser = $dbuser;
		$this->dbpassword = $dbpassword;
		$this->dbname = $dbname;
		$this->dbhost = $dbhost;
		$this->btbl_tls = $tls;
		$this->hide_errors();
		$this->db_connect(false);
	}

	/** Connect with hard transport bounds before any external source can block a request. */
	public function db_connect($allow_bail = true) {
		$this->is_mysql = true;
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_report -- Mirrors wpdb::db_connect(); required before a bounded external connection on PHP 8.1+.
		mysqli_report(MYSQLI_REPORT_OFF);
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- wpdb exposes no pre-connect transport-timeout hook.
		$this->dbh = mysqli_init();
		if (!$this->dbh) {
			return false;
		}
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_options -- Must be applied before connect; wpdb has no API for this bound.
		$connect_bounded = mysqli_options($this->dbh, MYSQLI_OPT_CONNECT_TIMEOUT, 3);
		if (!defined('MYSQLI_OPT_READ_TIMEOUT')) {
			$this->dbh = null;
			return false;
		}
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_options -- Must be applied before connect; wpdb has no API for this bound.
		$read_bounded = mysqli_options($this->dbh, MYSQLI_OPT_READ_TIMEOUT, 5);
		if (!$connect_bounded || !$read_bounded) {
			$this->dbh = null;
			return false;
		}
		$this->btbl_transport_timeouts = true;
		$host = $this->dbhost;
		$port = null;
		$socket = null;
		$is_ipv6 = false;
		$host_data = $this->parse_db_host($this->dbhost);
		if ($host_data) {
			list($host, $port, $socket, $is_ipv6) = $host_data;
		}
		if ($is_ipv6 && extension_loaded('mysqlnd')) {
			$host = '[' . $host . ']';
		}
		// TLS, when the source is configured for it.
		//
		// mysqli cannot use the operating system's trust store. Under mysqlnd -- the
		// driver PHP has shipped by default since 7.0 -- the MYSQLI_OPT_SSL_CA constant
		// does not even exist, and the only trust anchor mysqlnd will accept is the CA
		// handed to it through mysqli_ssl_set(). So an encrypted connection can only be
		// an AUTHENTICATED one when a CA path is supplied and the server certificate is
		// verified against it. Without that the transport is encrypted but the server is
		// anonymous, which is not a protection worth advertising -- and without TLS at
		// all the database password and every row travelled in cleartext on every render.
		$connect_flags = 0;
		if (!empty($this->btbl_tls)) {
			if (!defined('MYSQLI_CLIENT_SSL') || !defined('MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT')) {
				$this->dbh = null;
				return false;
			}
			$ca = (string) ($this->btbl_tls['ca'] ?? '');
			if ($ca === '' || !is_readable($ca)) {
				// Fail rather than fall back to an unverified connection: a missing or
				// unreadable CA path must not silently downgrade the transport.
				$this->dbh = null;
				return false;
			}
			// mysqli_ssl_set() installs the CA but always reports success: php-src
			// discards the driver's result. A wrong CA therefore fails at the
			// handshake, not here -- which is why the verification flag below is
			// what actually makes the connection fail closed. Empty strings rather
			// than nulls, so the call does not rely on the argument being nullable.
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_ssl_set -- Part of the same bounded external connection bootstrap; installs the TLS trust anchor.
			mysqli_ssl_set($this->dbh, '', '', $ca, '', '');
			// MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT makes mysqlnd check the server
			// certificate against that CA, and the host name against the certificate.
			$connect_flags = MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT;
		}
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_connect -- Bounded equivalent of wpdb::db_connect() for a separate read-only source.
		$connected = mysqli_real_connect($this->dbh, $host, $this->dbuser, $this->dbpassword, null, $port, $socket, $connect_flags);
		if (!$connected || $this->dbh->connect_errno) {
			$this->dbh = null;
			return false;
		}
		$this->init_charset();
		$this->has_connected = true;
		$this->set_charset($this->dbh);
		$this->ready = true;
		$this->set_sql_mode();
		$this->select($this->dbname, $this->dbh);
		if ($this->btbl_session_timeout_sql !== '') {
			// wpdb::check_connection() reaches this again after a mid-request drop; the
			// statement bound is per-session, so re-assert it on the new session.
			$suppressed = $this->suppress_errors(true);
			$this->query($this->btbl_session_timeout_sql);
			$this->suppress_errors($suppressed);
		}
		return true;
	}

	/** Records the verified statement bound so reconnects can re-assert it. */
	public function btbl_set_session_timeout(string $sql): void {
		$this->btbl_session_timeout_sql = $sql;
	}

	// Overrides wpdb::bail(); the signature is fixed by core, and neither argument is
	// wanted here -- a failed external connection must fail silently rather than print
	// WordPress's database-error page over the site.
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- core signature.
	public function bail($message, $error_code = '500') {
		$this->ready = false;
		return false;
	}

	public function btbl_connection_identity(): string {
		return $this->dbhost . '|' . $this->dbname . '|' . $this->dbuser;
	}

	public function btbl_transport_timeouts_enabled(): bool {
		return $this->btbl_transport_timeouts;
	}

}
