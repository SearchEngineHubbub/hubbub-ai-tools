<?php
/**
 * Interest in AI assistants, from English Wikipedia monthly pageviews.
 *
 * Wikimedia publishes pageview data under CC0. The site fetches it server-side
 * (one request per article, cached for a day) and the section hides itself if
 * the API cannot be reached.
 *
 * @package Hubbub_AI_Hub
 */

defined( 'ABSPATH' ) || exit;

class Hubbub_AI_Hub_Interest {

	/** Assistant name => English Wikipedia article title. */
	const ARTICLES = array(
		'ChatGPT'    => 'ChatGPT',
		'Gemini'     => 'Google_Gemini',
		'Claude'     => 'Claude_(language_model)',
		'Perplexity' => 'Perplexity_AI',
		'Copilot'    => 'Microsoft_Copilot',
	);

	const API = 'https://wikimedia.org/api/rest_v1/metrics/pageviews/per-article/en.wikipedia/all-access/user/%s/monthly/%s/%s';

	/** The last 12 complete months, as series: [name, title, [[YYYYMM, views], ...]]. */
	public static function series() {
		$articles = apply_filters( 'hubbub_ai_hub_interest_articles', self::ARTICLES );
		$end      = gmdate( 'Ym01', strtotime( 'first day of last month' ) );
		$start    = gmdate( 'Ym01', strtotime( 'first day of -12 months' ) );
		$key      = 'hubbub_interest_' . md5( wp_json_encode( $articles ) . $start . $end );
		$cached   = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out = array();
		foreach ( $articles as $name => $title ) {
			$url = sprintf( self::API, rawurlencode( $title ), $start . '00', $end . '00' );
			$res = wp_remote_get(
				$url,
				array(
					'timeout'    => 8,
					'user-agent' => 'SearchEngineHubbubAIHub/' . HUBBUB_AI_HUB_VERSION . ' (+' . home_url( '/tools/' ) . ')',
				)
			);
			$points = self::parse( is_wp_error( $res ) ? '' : wp_remote_retrieve_body( $res ) );
			if ( count( $points ) >= 3 ) {
				$out[] = array( $name, $title, $points );
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return end( $b[2] )[1] <=> end( $a[2] )[1];
			}
		);
		set_transient( $key, $out, $out ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
		return $out;
	}

	/** Parses a pageviews API response into [[YYYYMM, views], ...]. */
	public static function parse( $body ) {
		$j = json_decode( (string) $body, true );
		if ( empty( $j['items'] ) || ! is_array( $j['items'] ) ) {
			return array();
		}
		$points = array();
		foreach ( $j['items'] as $it ) {
			if ( isset( $it['timestamp'], $it['views'] ) ) {
				$points[] = array( substr( $it['timestamp'], 0, 6 ), (int) $it['views'] );
			}
		}
		return $points;
	}
}
