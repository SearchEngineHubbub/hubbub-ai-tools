<?php
/**
 * AI search news: the latest headlines from official changelogs and blogs.
 *
 * Only headlines, dates and links are shown, each linking to the original.
 * Feeds are fetched by the site with fetch_feed() and cached for six hours;
 * the section hides itself if none can be read.
 *
 * @package Hubbub_AI_Hub
 */

defined( 'ABSPATH' ) || exit;

class Hubbub_AI_Hub_News {

	/** Source name => [feed URL, whether to keep only AI-search items]. */
	const FEEDS = array(
		'Google Search Central' => array( 'https://feeds.feedburner.com/blogspot/amDG', false ),
		'Bing Webmaster'        => array( 'https://blogs.bing.com/webmaster/feed', false ),
		'Microsoft Advertising' => array( 'https://about.ads.microsoft.com/en/blog/rss', false ),
		'OpenAI'                => array( 'https://openai.com/news/rss.xml', true ),
		'Google AI'             => array( 'https://blog.google/technology/ai/rss/', true ),
	);

	/** Words that mark a general AI item as relevant to AI search marketing. */
	const KEYWORDS = '/\b(search|chatgpt|ads?|advertis\w*|shopping|agent\w*|gemini|ai mode|overviews?|crawl\w*|bing|copilot|perplexity|citations?|publishers?|atlas|browser|answers?|discover)\b/i';

	public static function lifetime( $seconds, $url ) {
		foreach ( self::FEEDS as $f ) {
			if ( $f[0] === $url ) {
				return 6 * HOUR_IN_SECONDS;
			}
		}
		return $seconds;
	}

	/** Latest items across all feeds: [title, url, timestamp, source], newest first. */
	public static function items( $limit = 8 ) {
		$cached = get_transient( 'hubbub_news_v2' );
		if ( is_array( $cached ) ) {
			return array_slice( $cached, 0, $limit );
		}
		include_once ABSPATH . WPINC . '/feed.php';
		add_filter( 'wp_feed_cache_transient_lifetime', array( __CLASS__, 'lifetime' ), 10, 2 );
		$out = array();
		foreach ( apply_filters( 'hubbub_ai_hub_news_feeds', self::FEEDS ) as $source => $f ) {
			$feed = fetch_feed( $f[0] );
			if ( is_wp_error( $feed ) ) {
				continue;
			}
			foreach ( $feed->get_items( 0, 15 ) as $it ) {
				$title = trim( wp_strip_all_tags( html_entity_decode( (string) $it->get_title(), ENT_QUOTES, 'UTF-8' ) ) );
				$link  = (string) $it->get_permalink();
				$time  = (int) $it->get_date( 'U' );
				if ( '' === $title || ! preg_match( '#^https?://#i', $link ) || ! $time ) {
					continue;
				}
				if ( $f[1] && ! preg_match( self::KEYWORDS, $title . ' ' . wp_strip_all_tags( (string) $it->get_description() ) ) ) {
					continue;
				}
				$out[] = array( $title, $link, $time, $source );
			}
		}
		remove_filter( 'wp_feed_cache_transient_lifetime', array( __CLASS__, 'lifetime' ), 10 );
		usort(
			$out,
			function ( $a, $b ) {
				return $b[2] <=> $a[2];
			}
		);
		$out = array_slice( $out, 0, 20 );
		set_transient( 'hubbub_news_v2', $out, $out ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return array_slice( $out, 0, $limit );
	}
}
