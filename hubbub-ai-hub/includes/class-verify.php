<?php
/**
 * Verify a bot: checks whether an IP address really belongs to an AI crawler, using the IP lists
 * each company publishes, plus reverse DNS for the companies that verify that way.
 */

defined( 'ABSPATH' ) || exit;

class Hubbub_AI_Hub_Verify {

	/** Extra published lists for bots not in the directory's own data. */
	const EXTRA_LISTS = array(
		'bingbot' => array( 'company' => 'Microsoft', 'url' => 'https://www.bing.com/toolbox/bingbot.json' ),
	);

	/** Companies that document reverse-DNS verification: host suffixes per company. */
	const RDNS = array(
		'Google'    => array( '.googlebot.com', '.google.com', '.googleusercontent.com' ),
		'Microsoft' => array( '.search.msn.com' ),
		'Apple'     => array( '.applebot.apple.com' ),
		'Amazon'    => array( '.crawl.amazonbot.amazon' ),
	);

	const CACHE_HOURS = 12;

	/** Published lists, keyed by URL: [ 'url' => [ 'tokens' => [...], 'company' => '' ] ]. */
	public static function lists() {
		$out = array();
		foreach ( Hubbub_AI_Hub::data()['crawlers'] as $c ) {
			$url = isset( $c['ip_list'] ) ? (string) $c['ip_list'] : '';
			if ( ! $url || ! preg_match( '/\.json$/i', wp_parse_url( $url, PHP_URL_PATH ) ) ) {
				continue; // Some companies publish an HTML page, not a machine-readable list.
			}
			if ( ! isset( $out[ $url ] ) ) {
				$out[ $url ] = array( 'tokens' => array(), 'company' => $c['company'] );
			}
			$out[ $url ]['tokens'][] = $c['token'];
		}
		foreach ( self::EXTRA_LISTS as $token => $x ) {
			if ( ! isset( $out[ $x['url'] ] ) ) {
				$out[ $x['url'] ] = array( 'tokens' => array( $token ), 'company' => $x['company'] );
			}
		}
		return $out;
	}

	/** Fetch and parse the lists not yet cached, in parallel where possible. */
	private static function load( $urls ) {
		$got     = array();
		$missing = array();
		foreach ( $urls as $url ) {
			$cached = get_transient( 'hubbub_ipl_' . md5( $url ) );
			if ( is_array( $cached ) ) {
				$got[ $url ] = $cached;
			} else {
				$missing[] = $url;
			}
		}
		if ( ! $missing ) {
			return $got;
		}
		$bodies = array();
		$class  = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : ( class_exists( 'Requests' ) ? 'Requests' : '' );
		if ( $class && count( $missing ) > 1 ) {
			$reqs = array();
			foreach ( $missing as $url ) {
				$reqs[ $url ] = array( 'url' => $url, 'type' => 'GET', 'headers' => array( 'Accept' => 'application/json' ), 'options' => array( 'timeout' => 6, 'useragent' => 'HubbubBotVerifier/1.0 (+' . home_url( '/' ) . ')' ) );
			}
			try {
				foreach ( $class::request_multiple( $reqs ) as $url => $res ) {
					$bodies[ $url ] = ( is_object( $res ) && isset( $res->status_code ) && 200 === (int) $res->status_code ) ? $res->body : '';
				}
			} catch ( \Exception $e ) {
				$bodies = array();
			}
		}
		foreach ( $missing as $url ) {
			if ( ! isset( $bodies[ $url ] ) ) {
				$r              = wp_remote_get( $url, array( 'timeout' => 6, 'user-agent' => 'HubbubBotVerifier/1.0 (+' . home_url( '/' ) . ')' ) );
				$bodies[ $url ] = ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) ? wp_remote_retrieve_body( $r ) : '';
			}
			$parsed = self::parse( $bodies[ $url ] );
			// Cache failures briefly so one slow list does not slow every check.
			set_transient( 'hubbub_ipl_' . md5( $url ), $parsed, $parsed['prefixes'] ? self::CACHE_HOURS * HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS );
			$got[ $url ] = $parsed;
		}
		return $got;
	}

	/** Parse the common { creationTime, prefixes: [ { ipv4Prefix | ipv6Prefix } ] } format. */
	public static function parse( $body ) {
		$j   = json_decode( (string) $body, true );
		$out = array( 'updated' => '', 'prefixes' => array() );
		if ( ! is_array( $j ) || empty( $j['prefixes'] ) || ! is_array( $j['prefixes'] ) ) {
			return $out;
		}
		$out['updated'] = isset( $j['creationTime'] ) ? substr( (string) $j['creationTime'], 0, 10 ) : '';
		foreach ( $j['prefixes'] as $p ) {
			$cidr = isset( $p['ipv4Prefix'] ) ? $p['ipv4Prefix'] : ( isset( $p['ipv6Prefix'] ) ? $p['ipv6Prefix'] : '' );
			if ( $cidr && false !== strpos( $cidr, '/' ) ) {
				$out['prefixes'][] = $cidr;
			}
		}
		return $out;
	}

	/** Whether an IP falls inside a CIDR range (IPv4 or IPv6). */
	public static function in_cidr( $ip, $cidr ) {
		list( $net, $bits ) = array_pad( explode( '/', $cidr, 2 ), 2, '' );
		$a = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$b = @inet_pton( $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( substr( $a, 0, $bytes ) !== substr( $b, 0, $bytes ) ) {
			return false;
		}
		$rem = $bits % 8;
		if ( ! $rem ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $rem ) ) & 0xff );
		return ( $a[ $bytes ] & $mask ) === ( $b[ $bytes ] & $mask );
	}

	/** The directory entry a user-agent string claims to be, or null. */
	public static function claimed( $ua ) {
		$ua   = strtolower( trim( (string) $ua ) );
		$best = null;
		if ( '' === $ua ) {
			return null;
		}
		$all = Hubbub_AI_Hub::data()['crawlers'];
		$all[] = array( 'token' => 'bingbot', 'company' => 'Microsoft', 'ip_list' => self::EXTRA_LISTS['bingbot']['url'] );
		foreach ( $all as $c ) {
			$t = strtolower( $c['token'] );
			if ( false !== strpos( $ua, $t ) && ( ! $best || strlen( $t ) > strlen( strtolower( $best['token'] ) ) ) ) {
				$best = $c;
			}
		}
		return $best;
	}

	/** Reverse DNS, confirmed by a forward lookup. Returns [ host, company ] or null. */
	private static function rdns( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) || ! function_exists( 'gethostbyaddr' ) ) {
			return null;
		}
		$host = @gethostbyaddr( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $host || $host === $ip ) {
			return null;
		}
		foreach ( self::RDNS as $company => $suffixes ) {
			foreach ( $suffixes as $sfx ) {
				if ( substr( strtolower( $host ), -strlen( $sfx ) ) === $sfx ) {
					$fwd = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					if ( $fwd && in_array( $ip, $fwd, true ) ) {
						return array( 'host' => $host, 'company' => $company );
					}
				}
			}
		}
		return array( 'host' => $host, 'company' => '' );
	}

	/** Verify an IP, optionally against the bot its user agent claims to be. */
	public static function verify( $ip, $ua = '' ) {
		$ip = trim( (string) $ip );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new WP_Error( 'bad_ip', 'Enter a valid IPv4 or IPv6 address.', array( 'status' => 400 ) );
		}
		$lists   = self::lists();
		$claim   = self::claimed( $ua );
		$urls    = array_keys( $lists );
		if ( $claim && ! empty( $claim['ip_list'] ) && isset( $lists[ $claim['ip_list'] ] ) ) {
			$urls = array( $claim['ip_list'] );
		}
		$loaded  = self::load( $urls );
		$matches = array();
		$checked = 0;
		foreach ( $loaded as $url => $l ) {
			if ( $l['prefixes'] ) {
				$checked++;
			}
			foreach ( $l['prefixes'] as $cidr ) {
				if ( self::in_cidr( $ip, $cidr ) ) {
					$matches[] = array( 'tokens' => $lists[ $url ]['tokens'], 'company' => $lists[ $url ]['company'], 'range' => $cidr, 'list' => $url, 'list_updated' => $l['updated'] );
					break;
				}
			}
		}
		$rdns = null;
		if ( ! $matches && ( ! $claim || isset( self::RDNS[ $claim['company'] ] ) ) ) {
			$rdns = self::rdns( $ip );
		}
		$rdns_ok = $rdns && $rdns['company'] && ( ! $claim || $rdns['company'] === $claim['company'] );

		if ( $claim ) {
			$name = $claim['token'];
			if ( $matches ) {
				$verdict = 'verified';
				$msg     = 'Genuine. ' . $ip . ' is in ' . $claim['company'] . '\'s published ' . $name . ' range (' . $matches[0]['range'] . ').';
			} elseif ( $rdns_ok ) {
				$verdict = 'verified';
				$msg     = 'Genuine. ' . $ip . ' resolves to ' . $rdns['host'] . ', a ' . $claim['company'] . ' crawler host, and back again.';
			} elseif ( empty( $claim['ip_list'] ) && ! isset( self::RDNS[ $claim['company'] ] ) ) {
				$verdict = 'unknown';
				$msg     = $claim['company'] . ' does not publish IP addresses for ' . $name . ', so it cannot be verified this way.';
			} elseif ( ! $checked && ! $rdns ) {
				$verdict = 'unknown';
				$msg     = 'We could not load ' . $claim['company'] . '\'s IP list just now. Try again in a few minutes.';
			} else {
				$verdict = 'fake';
				$msg     = 'Not verified. ' . $ip . ' is not in ' . $claim['company'] . '\'s published ranges for ' . $name . '. Treat it as a fake ' . $name . '.';
			}
		} elseif ( $matches ) {
			$verdict = 'verified';
			$msg     = $ip . ' belongs to ' . $matches[0]['company'] . ': it is in the published range for ' . implode( ', ', $matches[0]['tokens'] ) . ' (' . $matches[0]['range'] . ').';
		} elseif ( $rdns_ok ) {
			$verdict = 'verified';
			$msg     = $ip . ' resolves to ' . $rdns['host'] . ', a ' . $rdns['company'] . ' crawler host.';
		} else {
			$verdict = 'none';
			$msg     = $ip . ' is not in any of the ' . $checked . ' AI crawler IP lists we check.';
		}
		return array(
			'ip'            => $ip,
			'claimed'       => $claim ? $claim['token'] : null,
			'verdict'       => $verdict,
			'message'       => $msg,
			'matches'       => $matches,
			'reverse_dns'   => $rdns,
			'lists_checked' => $checked,
			'source'        => 'IP lists published by each company, cached for up to ' . self::CACHE_HOURS . ' hours.',
		);
	}

	/** A light per-visitor limit for the public endpoint. */
	public static function rate_ok() {
		$key = 'hubbub_vrl_' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
		$n   = (int) get_transient( $key );
		if ( $n >= 30 ) {
			return false;
		}
		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
		return true;
	}

	public static function rest( $req ) {
		if ( ! self::rate_ok() ) {
			return new WP_Error( 'slow_down', 'Too many checks. Try again in a minute.', array( 'status' => 429 ) );
		}
		$r = self::verify( $req->get_param( 'ip' ), (string) $req->get_param( 'user_agent' ) );
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}
}
