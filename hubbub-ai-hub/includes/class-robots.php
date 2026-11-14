<?php
/**
 * Minimal robots.txt parser following RFC 9309 matching rules.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'HUBBUB_AI_HUB_TEST' ) ) {
	exit;
}

class Hubbub_AI_Hub_Robots {

	/** @var array<int, array{agents: string[], rules: array<int, array{type: string, path: string}>}> */
	private $groups = array();

	public function __construct( $body ) {
		$this->parse( (string) $body );
	}

	private function parse( $body ) {
		$lines        = preg_split( '/\r\n|\r|\n/', $body );
		$current      = null;
		$last_was_ua  = false;

		foreach ( $lines as $line ) {
			$line = preg_replace( '/#.*$/', '', $line );
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $key, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$key = strtolower( $key );

			if ( 'user-agent' === $key ) {
				if ( ! $last_was_ua || null === $current ) {
					$this->groups[] = array( 'agents' => array(), 'rules' => array() );
					$current        = count( $this->groups ) - 1;
				}
				$this->groups[ $current ]['agents'][] = strtolower( $value );
				$last_was_ua                          = true;
				continue;
			}

			$last_was_ua = false;
			if ( null === $current ) {
				continue;
			}
			if ( 'allow' === $key || 'disallow' === $key ) {
				$this->groups[ $current ]['rules'][] = array( 'type' => $key, 'path' => $value );
			}
		}
	}

	/**
	 * Rules that apply to a product token, merging every group that names it,
	 * falling back to the * group(s).
	 */
	private function rules_for( $token ) {
		$token   = strtolower( $token );
		$matched = array();
		$star    = array();
		foreach ( $this->groups as $group ) {
			foreach ( $group['agents'] as $agent ) {
				if ( '*' === $agent ) {
					$star = array_merge( $star, $group['rules'] );
				} elseif ( $agent === $token ) {
					$matched = array_merge( $matched, $group['rules'] );
				}
			}
		}
		if ( $this->names( $token ) ) {
			return array( 'rules' => $matched, 'specific' => true );
		}
		return array( 'rules' => $star, 'specific' => false );
	}

	/** Whether any group names this token explicitly. */
	public function names( $token ) {
		$token = strtolower( $token );
		foreach ( $this->groups as $group ) {
			if ( in_array( $token, $group['agents'], true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function rule_matches( $pattern, $path ) {
		if ( '' === $pattern ) {
			return false;
		}
		$anchored = '$' === substr( $pattern, -1 );
		if ( $anchored ) {
			$pattern = substr( $pattern, 0, -1 );
		}
		$regex = '';
		foreach ( explode( '*', $pattern ) as $i => $part ) {
			$regex .= ( $i > 0 ? '.*' : '' ) . preg_quote( $part, '#' );
		}
		$regex = '#^' . $regex . ( $anchored ? '$' : '' ) . '#';
		return 1 === preg_match( $regex, $path );
	}

	/**
	 * @return array{allowed: bool, specific: bool, rule: string}
	 */
	public function check( $token, $path = '/' ) {
		$set     = $this->rules_for( $token );
		$best    = null;
		$bestlen = -1;
		foreach ( $set['rules'] as $rule ) {
			if ( ! self::rule_matches( $rule['path'], $path ) ) {
				continue;
			}
			$len = strlen( $rule['path'] );
			if ( $len > $bestlen || ( $len === $bestlen && 'allow' === $rule['type'] ) ) {
				$best    = $rule;
				$bestlen = $len;
			}
		}
		return array(
			'allowed'  => null === $best || 'allow' === $best['type'],
			'specific' => $set['specific'],
			'rule'     => null === $best ? '' : ucfirst( $best['type'] ) . ': ' . $best['path'],
		);
	}
}
