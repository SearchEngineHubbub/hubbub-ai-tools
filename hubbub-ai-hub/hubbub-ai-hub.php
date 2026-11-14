<?php
/**
 * Plugin Name: Hubbub AI Hub
 * Description: AI Visibility Checker, AI Crawler Directory and AI Ads Tracker for the Search Engine Hubbub Knowledge Hub, with a public JSON API for people and AI agents.
 * Version: 0.8.0
 * Author: Search Engine Hubbub
 * License: GPL-2.0-or-later
 * Requires PHP: 7.4
 * Update URI: https://github.com/SearchEngineHubbub/hubbub-ai-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HUBBUB_AI_HUB_VERSION', '0.8.0' );
define( 'HUBBUB_AI_HUB_DIR', plugin_dir_path( __FILE__ ) );
define( 'HUBBUB_AI_HUB_URL', plugin_dir_url( __FILE__ ) );

require_once HUBBUB_AI_HUB_DIR . 'includes/class-robots.php';
require_once HUBBUB_AI_HUB_DIR . 'includes/class-checker.php';
require_once HUBBUB_AI_HUB_DIR . 'includes/class-mcp.php';
require_once HUBBUB_AI_HUB_DIR . 'includes/class-updater.php';

Hubbub_AI_Hub_Updater::init( __FILE__ );

final class Hubbub_AI_Hub {

	const NS            = 'hubbub/v1';
	const RATE_PER_HOUR = 20;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'rest_api_init', array( 'Hubbub_AI_Hub_MCP', 'register' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_shortcode( 'hubbub_ai_checker', array( __CLASS__, 'checker_shortcode' ) );
		add_shortcode( 'hubbub_ai_crawlers', array( __CLASS__, 'crawlers_shortcode' ) );
		add_shortcode( 'hubbub_ai_ads', array( __CLASS__, 'ads_shortcode' ) );
		add_shortcode( 'hubbub_ai_tools', array( __CLASS__, 'tools_shortcode' ) );
		add_action( 'wp_head', array( __CLASS__, 'api_link' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_markdown' ), 10, 3 );
	}

	/**
	 * Load a dataset. A private page with the slug hubbub-data-{name} holding the JSON
	 * overrides the copy shipped in the plugin, so the data can be refreshed without a new release.
	 */
	private static function load_data( $name ) {
		$file = json_decode( file_get_contents( HUBBUB_AI_HUB_DIR . 'data/' . $name . '.json' ), true );
		$page = get_page_by_path( 'hubbub-data-' . $name, OBJECT, 'page' );
		if ( $page && in_array( $page->post_status, array( 'private', 'draft', 'publish' ), true ) ) {
			$raw  = html_entity_decode( wp_strip_all_tags( preg_replace( '/<!--.*?-->/s', '', $page->post_content ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$live = json_decode( trim( $raw ), true );
			$key  = 'crawlers' === $name ? 'crawlers' : 'products';
			if ( is_array( $live ) && ! empty( $live[ $key ] ) && ! empty( $live['updated'] ) && strcmp( $live['updated'], $file['updated'] ) >= 0 ) {
				return $live;
			}
		}
		return $file;
	}

	public static function data() {
		static $data = null;
		if ( null === $data ) {
			$data = self::load_data( 'crawlers' );
		}
		return $data;
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/crawlers',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_crawlers' ),
				'args'                => array(
					'purpose' => array( 'type' => 'string', 'enum' => array( 'search', 'training', 'user', 'other' ) ),
					'company' => array( 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/check',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_check' ),
				'args'                => array(
					'url' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/robots',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_robots' ),
				'args'                => array(
					'block' => array( 'type' => 'string', 'description' => 'Comma-separated purposes to block: training, search, user, other.' ),
				),
			)
		);
		register_rest_route( self::NS, '/about', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'rest_about' ) ) );
		register_rest_route( self::NS, '/llms', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'rest_llms' ) ) );
		register_rest_route(
			self::NS,
			'/ads',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_ads' ),
				'args'                => array(
					'status' => array( 'type' => 'string', 'enum' => array( 'live', 'beta', 'testing', 'announced', 'unknown', 'ended', 'none' ) ),
				),
			)
		);
	}

	public static function rest_crawlers( WP_REST_Request $req ) {
		$data = self::data();
		$out  = array_values(
			array_filter(
				$data['crawlers'],
				function ( $c ) use ( $req ) {
					if ( $req['purpose'] && $c['purpose'] !== $req['purpose'] ) {
						return false;
					}
					if ( $req['company'] && 0 !== strcasecmp( $c['company'], $req['company'] ) ) {
						return false;
					}
					return true;
				}
			)
		);
		$res = rest_ensure_response(
			array(
				'source'   => 'Search Engine Hubbub AI Crawler Directory',
				'page'     => home_url( '/tools/ai-crawlers/' ),
				'license'  => 'Free to use with attribution and a link to the page above.',
				'updated'  => $data['updated'],
				'purposes' => $data['purposes'],
				'count'    => count( $out ),
				'crawlers' => $out,
			)
		);
		$res->header( 'Access-Control-Allow-Origin', '*' );
		$res->header( 'Cache-Control', 'public, max-age=3600' );
		return $res;
	}

	public static function robots_txt( array $block ) {
		$data  = self::data();
		$lines = array( '# AI crawler rules generated by Search Engine Hubbub', '# ' . home_url( '/tools/ai-crawlers/' ) . ' (data updated ' . $data['updated'] . ')' );
		foreach ( array( 'training', 'search', 'user', 'other' ) as $purpose ) {
			$tokens = array();
			foreach ( $data['crawlers'] as $c ) {
				if ( $c['purpose'] === $purpose && '' !== $c['token'] && empty( $c['web_search'] ) ) {
					$tokens[] = $c['token'];
				}
			}
			if ( ! $tokens ) {
				continue;
			}
			$lines[] = '';
			$lines[] = '# ' . $data['purposes'][ $purpose ];
			foreach ( $tokens as $t ) {
				$lines[] = 'User-agent: ' . $t;
			}
			$lines[] = in_array( $purpose, $block, true ) ? 'Disallow: /' : 'Allow: /';
		}
		$lines[] = '';
		$lines[] = '# Googlebot, bingbot and Applebot are left out on purpose: blocking them removes you from';
		$lines[] = '# Google, Bing and Apple search as well as their AI answers. Use nosnippet to limit AI features instead.';
		return implode( "\n", $lines ) . "\n";
	}

	public static function rest_robots( WP_REST_Request $req ) {
		$block = array_filter( array_map( 'trim', explode( ',', (string) $req['block'] ) ) );
		$res   = new WP_REST_Response( array( 'block' => array_values( $block ), 'robots_txt' => self::robots_txt( $block ) ) );
		$res->header( 'Access-Control-Allow-Origin', '*' );
		return $res;
	}

	private static function client_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return 'hubbub_rl_' . md5( $ip . gmdate( 'YmdH' ) );
	}

	/** @return array|WP_Error */
	public static function run_check( $raw_url ) {
		$url = Hubbub_AI_Hub_Checker::normalise_url( $raw_url );
		if ( '' === $url ) {
			return new WP_Error( 'hubbub_bad_url', 'Please enter a valid public web address, such as example.com/page.', array( 'status' => 400 ) );
		}
		$cache_key = 'hubbub_chk_' . md5( strtolower( $url ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		$rl    = self::client_key();
		$count = (int) get_transient( $rl );
		if ( $count >= self::RATE_PER_HOUR ) {
			return new WP_Error( 'hubbub_rate_limited', 'You have reached the limit of ' . self::RATE_PER_HOUR . ' checks an hour. Please try again later.', array( 'status' => 429 ) );
		}
		set_transient( $rl, $count + 1, HOUR_IN_SECONDS );
		$checker = new Hubbub_AI_Hub_Checker( self::data()['crawlers'] );
		$result  = $checker->run( $url );
		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'hubbub_unreachable', $result['error'], array( 'status' => 422 ) );
		}
		$result['report'] = add_query_arg( 'url', rawurlencode( $url ), home_url( '/tools/ai-visibility-checker/' ) );
		set_transient( $cache_key, $result, HOUR_IN_SECONDS );
		return $result;
	}

	public static function rest_check( WP_REST_Request $req ) {
		$out = self::run_check( $req['url'] );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$res = rest_ensure_response( $out );
		$res->header( 'Access-Control-Allow-Origin', '*' );
		return $res;
	}

	public static function register_assets() {
		wp_register_style( 'hubbub-ai-hub', HUBBUB_AI_HUB_URL . 'assets/hub.css', array(), HUBBUB_AI_HUB_VERSION );
		wp_register_script( 'hubbub-ai-hub', HUBBUB_AI_HUB_URL . 'assets/hub.js', array(), HUBBUB_AI_HUB_VERSION, true );
		wp_localize_script(
			'hubbub-ai-hub',
			'HubbubAIHub',
			array( 'api' => esc_url_raw( rest_url( self::NS ) ) )
		);
	}

	private static function enqueue() {
		wp_enqueue_style( 'hubbub-ai-hub' );
		wp_enqueue_script( 'hubbub-ai-hub' );
	}

	private static function icon( $name ) {
		$paths = array(
			'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
			'training' => '<path d="M12 3a4 4 0 0 0-4 4v1a3 3 0 0 0-2 5 3 3 0 0 0 2 5 4 4 0 0 0 8 0 3 3 0 0 0 2-5 3 3 0 0 0-2-5V7a4 4 0 0 0-4-4z"/><path d="M12 3v18"/>',
			'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
			'other'    => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/>',
			'bot'      => '<rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 4v4M9 13h.01M15 13h.01"/>',
			'spark'    => '<path d="M12 3v4m0 10v4M3 12h4m10 0h4M6 6l2.5 2.5m7 7L18 18M6 18l2.5-2.5m7-7L18 6"/>',
			'plug'     => '<path d="M9 2v6m6-6v6M6 8h12v4a6 6 0 0 1-12 0V8zm6 10v4"/>',
			'arrow'    => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
			'copy'     => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
			'shield'   => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6l-8-3z"/>',
			'eye'      => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
			'layers'   => '<path d="m12 3 9 5-9 5-9-5 9-5z"/><path d="m3 13 9 5 9-5"/>',
			'doc'      => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6z"/><path d="M14 3v6h6M8 13h8M8 17h5"/>',
			'chart'    => '<path d="M4 20V10m6 10V4m6 16v-7m4 7H2"/>',
		);
		return '<svg class="hb-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[ $name ] . '</svg>';
	}

	/** Tool registry used by the nav, landing page, schema and the machine-readable index. */
	public static function tools() {
		return array(
			'checker'  => array( 'slug' => 'ai-visibility-checker', 'icon' => 'search', 'short' => 'Visibility Checker', 'name' => 'AI Visibility Checker', 'desc' => 'Check whether ChatGPT, Claude, Perplexity and Gemini can reach, read and cite a page. Score, fixes and a crawler-by-crawler breakdown.', 'api' => '/check?url=example.com', 'mcp' => 'check_ai_visibility' ),
			'crawlers' => array( 'slug' => 'ai-crawlers', 'icon' => 'bot', 'short' => 'Crawler Directory', 'name' => 'AI Crawler Directory', 'desc' => 'Every AI crawler, who runs it, what it does with your content and whether it obeys robots.txt. With a robots.txt builder.', 'api' => '/crawlers', 'mcp' => 'list_ai_crawlers' ),
			'ads'      => array( 'slug' => 'ai-ads-tracker', 'icon' => 'spark', 'short' => 'AI Ads Tracker', 'name' => 'AI Ads Tracker', 'desc' => 'Every ad product inside AI assistants and AI search: status, markets, formats and how to buy. Sourced and dated.', 'api' => '/ads?status=live', 'mcp' => 'list_ai_ad_products' ),
		);
	}

	private static function page_url( $key = '' ) {
		$t = self::tools();
		return home_url( '/tools/' . ( $key ? $t[ $key ]['slug'] . '/' : '' ) );
	}

	private static function nav( $active ) {
		$links = '<a href="' . esc_url( self::page_url() ) . '"' . ( 'tools' === $active ? ' class="is-on" aria-current="page"' : '' ) . '>' . self::icon( 'layers' ) . '<span>All tools</span></a>';
		foreach ( self::tools() as $key => $t ) {
			$links .= '<a href="' . esc_url( self::page_url( $key ) ) . '"' . ( $key === $active ? ' class="is-on" aria-current="page"' : '' ) . '>' . self::icon( $t['icon'] ) . '<span>' . esc_html( $t['short'] ) . '</span></a>';
		}
		$links .= '<a href="#hb-agents" class="hb-nav__agents">' . self::icon( 'plug' ) . '<span>Use with AI</span></a>';
		return '<nav class="hb-nav" aria-label="Hubbub AI tools"><a class="hb-nav__brand" href="' . esc_url( self::page_url() ) . '"><span class="hb-nav__mark">' . self::icon( 'spark' ) . '</span>Hubbub <b>AI Tools</b></a><div class="hb-nav__links">' . $links . '</div></nav>';
	}

	/**
	 * Hero block. $title may hold one <em>…</em> for the highlighted words.
	 */
	private static function hero( $eyebrow, $title, $sub, $extra = '', $class = '' ) {
		$title = wp_kses( $title, array( 'em' => array(), 'br' => array() ) );
		return '<header class="hb-hero ' . esc_attr( $class ) . '"><div class="hb-aurora" aria-hidden="true"><i></i><i></i><i></i></div><div class="hb-gridbg" aria-hidden="true"></div>'
			. '<div class="hb-hero__in"><span class="hb-eyebrow"><span class="hb-live"></span>' . esc_html( $eyebrow ) . '</span>'
			. '<h2 class="hb-hero__title">' . $title . '</h2><p class="hb-hero__sub">' . esc_html( $sub ) . '</p>' . $extra . '</div></header>';
	}

	private static function stat( $num, $label, $key = null ) {
		$tag  = null === $key ? 'div' : 'button';
		$attr = null === $key ? '' : ' type="button" data-filter="' . esc_attr( $key ) . '"';
		return '<' . $tag . ' class="hb-stat"' . $attr . '><span class="hb-stat__num" data-count="' . (int) $num . '">' . (int) $num . '</span><span class="hb-stat__label">' . esc_html( $label ) . '</span></' . $tag . '>';
	}

	private static function copy_code( $text, $label = '' ) {
		return '<div class="hb-code">' . ( $label ? '<span class="hb-code__label">' . esc_html( $label ) . '</span>' : '' ) . '<code>' . esc_html( $text ) . '</code><button type="button" class="hb-copy" data-copy="' . esc_attr( $text ) . '" aria-label="Copy">' . self::icon( 'copy' ) . '<span>Copy</span></button></div>';
	}

	/** "Use with AI" panel: how to plug the tools into assistants, agents and code. */
	private static function agents( $focus = '' ) {
		$mcp   = rest_url( self::NS . '/mcp' );
		$t     = self::tools();
		$tool  = $focus && isset( $t[ $focus ] ) ? $t[ $focus ] : null;
		$api   = rest_url( self::NS . ( $tool ? $tool['api'] : '/crawlers' ) );
		$cfg   = wp_json_encode( array( 'mcpServers' => array( 'hubbub' => array( 'url' => $mcp ) ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$tabs  = array(
			'claude'  => array( 'Claude', '<ol><li>Open <b>Settings → Connectors</b> and choose <b>Add custom connector</b>.</li><li>Name it <b>Search Engine Hubbub</b> and paste this URL. No sign-in needed.</li></ol>' . self::copy_code( $mcp, 'MCP URL' ) ),
			'chatgpt' => array( 'ChatGPT', '<ol><li>Turn on <b>Developer mode</b> in ChatGPT settings, then create a new connector.</li><li>Paste this URL and choose <b>No authentication</b>.</li></ol>' . self::copy_code( $mcp, 'MCP URL' ) ),
			'code'    => array( 'Claude Code', '<p>Add the server from your terminal:</p>' . self::copy_code( 'claude mcp add --transport http hubbub ' . $mcp ) ),
			'cursor'  => array( 'Cursor', '<p>Add this to <code>~/.cursor/mcp.json</code>:</p><div class="hb-code hb-code--block"><pre>' . esc_html( $cfg ) . '</pre><button type="button" class="hb-copy" data-copy="' . esc_attr( $cfg ) . '" aria-label="Copy">' . self::icon( 'copy' ) . '<span>Copy</span></button></div>' ),
			'api'     => array( 'JSON API', '<p>Open, free and CORS-enabled. Please credit and link to Search Engine Hubbub.</p>' . self::copy_code( 'curl "' . $api . '"' ) . self::copy_code( rest_url( self::NS . '/llms' ), 'Markdown guide for LLMs' ) ),
		);
		$btns  = '';
		$pans  = '';
		$i     = 0;
		foreach ( $tabs as $k => $tab ) {
			$on    = 0 === $i++;
			$btns .= '<button type="button" role="tab" id="hb-tab-' . $k . '" aria-controls="hb-pan-' . $k . '" aria-selected="' . ( $on ? 'true' : 'false' ) . '"' . ( $on ? ' class="is-on"' : '' ) . '>' . esc_html( $tab[0] ) . '</button>';
			$pans .= '<div role="tabpanel" id="hb-pan-' . $k . '" aria-labelledby="hb-tab-' . $k . '"' . ( $on ? '' : ' hidden' ) . '>' . $tab[1] . '</div>';
		}
		$toolchips = '';
		foreach ( $t as $k => $x ) {
			$toolchips .= '<li><code>' . esc_html( $x['mcp'] ) . '</code></li>';
		}
		$toolchips .= '<li><code>build_robots_txt</code></li>';
		$prompt = $tool && 'checker' === $focus ? 'Use Search Engine Hubbub to check how visible example.com is to AI search, then list the fixes in priority order.'
			: ( 'ads' === $focus ? 'Use Search Engine Hubbub to list every ad product that is live inside AI assistants right now, with how to buy each one.'
			: 'Use Search Engine Hubbub to tell me which AI crawlers my robots.txt should allow if I want to be cited but not used for training.' );
		return '<section class="hb-agents" id="hb-agents"><div class="hb-agents__intro"><span class="hb-eyebrow">' . self::icon( 'plug' ) . 'Built for people and AI agents</span>'
			. '<h2>Plug these tools into your AI</h2><p>Every tool runs as an MCP server and an open JSON API. Add it once and your assistant can run checks, look up crawlers and track AI ads for you.</p>'
			. '<ul class="hb-mcptools">' . $toolchips . '</ul>'
			. '<div class="hb-try"><span>Then try asking:</span><p>“' . esc_html( $prompt ) . '”</p><button type="button" class="hb-copy hb-copy--text" data-copy="' . esc_attr( $prompt ) . '">' . self::icon( 'copy' ) . '<span>Copy prompt</span></button></div></div>'
			. '<div class="hb-agents__setup"><div class="hb-tabs" role="tablist" aria-label="Set up with">' . $btns . '</div><div class="hb-tabpans">' . $pans . '</div></div></section>';
	}

	private static function shell( $key, $inner ) {
		$crawl = self::data();
		$ads   = self::ads_data();
		$foot  = '<footer class="hb-footer"><span>Crawler data updated ' . esc_html( self::fmt_date( $crawl['updated'] ) ) . ' · Ads data updated ' . esc_html( self::fmt_date( $ads['updated'] ) ) . '</span>'
			. '<span>Free to use with attribution. <a href="' . esc_url( rest_url( self::NS . '/llms' ) ) . '">LLM guide</a> · <a href="' . esc_url( rest_url( self::NS . '/about' ) ) . '">JSON index</a> · <a href="' . esc_url( home_url( '/contact-us/' ) ) . '">Report a change</a></span></footer>';
		return '<div class="hubbub-hub hb-app hubbub-' . esc_attr( $key ) . '"' . ( 'checker' === $key ? self::$checker_attr : '' ) . '>' . self::nav( $key ) . $inner . self::agents( $key ) . $foot . '</div>';
	}

	private static $checker_attr = '';

	public static function checker_shortcode() {
		self::enqueue();
		$prefill            = isset( $_GET['url'] ) ? sanitize_text_field( wp_unslash( $_GET['url'] ) ) : '';
		self::$checker_attr = ' data-prefill="' . esc_attr( $prefill ) . '"';
		$n                  = count( self::data()['crawlers'] );
		$form               = '<form class="hb-search hb-search--xl" novalidate role="search">'
			. '<label class="screen-reader-text" for="hubbub-url">Page to check</label>'
			. self::icon( 'search' )
			. '<input id="hubbub-url" name="url" type="text" inputmode="url" autocomplete="url" placeholder="yoursite.com/page" value="' . esc_attr( $prefill ) . '" required>'
			. '<button type="submit" class="hb-btn">Check visibility' . self::icon( 'arrow' ) . '</button></form>'
			. '<div class="hb-tryrow"><span>Try:</span><button type="button" class="hb-example" data-url="searchenginehubbub.com">searchenginehubbub.com</button><button type="button" class="hb-example" data-url="en.wikipedia.org/wiki/Search_engine_optimization">wikipedia.org/…/SEO</button></div>';
		$groups = array(
			array( 'eye', 'Reach', 'Status, robots.txt for ' . $n . ' AI bots, noindex' ),
			array( 'doc', 'Guidance', 'llms.txt and how it is written' ),
			array( 'search', 'Readability', 'Content visible without JavaScript' ),
			array( 'layers', 'Structure', 'Schema, headings, canonical' ),
			array( 'shield', 'Trust', 'Author and dates answer engines weigh' ),
		);
		$how = '';
		foreach ( $groups as $g ) {
			$how .= '<li class="hb-card">' . self::icon( $g[0] ) . '<strong>' . esc_html( $g[1] ) . '</strong><span>' . esc_html( $g[2] ) . '</span></li>';
		}
		$inner = self::hero( 'Free · no sign-up · ' . $n . ' AI crawlers', 'Can AI assistants <em>find and cite</em> your page?', 'We load your page the way ChatGPT, Claude, Perplexity and Gemini do, then score it and show you exactly what to fix.', $form, 'hb-hero--center' )
			. '<div class="hubbub-checker__out" aria-live="polite"></div>'
			. '<section class="hb-section hb-how"><h2 class="hb-h2">What we check</h2><ul class="hb-howgrid">' . $how . '</ul></section>';
		return self::shell( 'checker', $inner );
	}

	public static function crawlers_shortcode() {
		self::enqueue();
		$data   = self::data();
		$counts = array( 'search' => 0, 'training' => 0, 'user' => 0, 'other' => 0 );
		$cards  = '';
		foreach ( $data['crawlers'] as $c ) {
			$counts[ $c['purpose'] ]++;
			$robots = strtolower( $c['respects_robots'] );
			$rclass = 'yes' === $robots ? 'ok' : ( 'no' === $robots ? 'no' : 'meh' );
			$cards .= sprintf(
				'<article class="hb-bot hb-card hb-bot--%1$s" data-purpose="%1$s" data-company="%2$s">'
				. '<header><span class="hb-mono hb-mono--%1$s" aria-hidden="true">%11$s</span><div><code class="hb-bot__token">%3$s</code><span class="hb-bot__co">%2$s</span></div><span class="hb-chip hb-chip--%1$s">%9$s%4$s</span></header>'
				. '<p>%5$s</p>'
				. '<footer><span class="hb-robots hb-robots--%10$s"><i></i>robots.txt: %6$s</span><span class="hb-links"><a href="%7$s" rel="noopener" target="_blank">Docs ↗</a>%8$s</span></footer></article>',
				esc_attr( $c['purpose'] ),
				esc_html( $c['company'] ),
				esc_html( $c['token'] ),
				esc_html( ucfirst( $c['purpose'] ) ),
				esc_html( $c['note'] ),
				esc_html( ucfirst( $c['respects_robots'] ) ),
				esc_url( $c['source'] ),
				$c['ip_list'] ? '<a href="' . esc_url( $c['ip_list'] ) . '" rel="noopener" target="_blank">IPs ↗</a>' : '',
				self::icon( $c['purpose'] ),
				$rclass,
				esc_html( mb_strtoupper( mb_substr( $c['company'], 0, 1 ) ) )
			);
		}
		$companies = array_unique( wp_list_pluck( $data['crawlers'], 'company' ) );
		sort( $companies );
		$opts = '<option value="">All companies</option>';
		foreach ( $companies as $co ) {
			$opts .= '<option>' . esc_html( $co ) . '</option>';
		}
		$toggle = function ( $value, $label, $hint ) {
			return '<label class="hb-toggle"><input type="checkbox" value="' . esc_attr( $value ) . '"><span class="hb-toggle__track" aria-hidden="true"></span><span><strong>' . esc_html( $label ) . '</strong><small>' . esc_html( $hint ) . '</small></span></label>';
		};
		$stats = '<div class="hb-stats">' . self::stat( count( $data['crawlers'] ), 'AI bots tracked', '' ) . self::stat( $counts['search'], 'Search & citations', 'search' ) . self::stat( $counts['training'], 'Model training', 'training' ) . self::stat( $counts['user'], 'User-triggered', 'user' ) . '</div>';
		$inner = self::hero( 'Live directory · updated ' . self::fmt_date( $data['updated'] ), 'Every AI crawler, <em>decoded</em>', 'Who runs each bot, what it does with your content, and whether it obeys robots.txt. Checked against each company\'s own documentation.', $stats )
			. '<section class="hb-section"><div class="hb-toolbar"><div class="hb-search hb-search--sm">' . self::icon( 'search' ) . '<input type="search" class="hubbub-crawlers__q" placeholder="Search bots or companies" aria-label="Search bots"></div>'
			. '<div class="hb-seg" role="group" aria-label="Purpose"><button type="button" class="is-on" data-p="">All</button><button type="button" data-p="search">Search</button><button type="button" data-p="training">Training</button><button type="button" data-p="user">User</button><button type="button" data-p="other">Other</button></div>'
			. '<select class="hubbub-crawlers__company hb-select" aria-label="Company">' . $opts . '</select></div>'
			. '<p class="hb-count" aria-live="polite"></p>'
			. '<div class="hb-grid">' . $cards . '</div></section>'
			. '<section class="hb-builder"><div class="hb-builder__controls"><span class="hb-eyebrow">' . self::icon( 'bot' ) . 'robots.txt builder</span><h2 class="hb-h2">Choose who gets in</h2><p>Search and user bots decide whether AI assistants can cite you. Googlebot, Bingbot and Applebot are never blocked, so you stay in normal search.</p>'
			. $toggle( 'training', 'Block training bots', 'Keeps your content out of AI model training' )
			. $toggle( 'search', 'Block AI search bots', 'You will not be cited in ChatGPT search, Perplexity, Claude' )
			. $toggle( 'user', 'Block user-triggered fetchers', 'Many of these ignore robots.txt anyway' )
			. '</div><div class="hb-term"><div class="hb-term__bar"><span></span><span></span><span></span><em>robots.txt</em><button type="button" class="hubbub-builder__copy">Copy</button></div><pre class="hubbub-builder__out"></pre></div></section>';
		return self::shell( 'crawlers', $inner );
	}

	public static function ads_data() {
		static $data = null;
		if ( null === $data ) {
			$data = self::load_data( 'ai-ads' );
			$rank = array( 'live' => 0, 'beta' => 1, 'testing' => 1, 'announced' => 2, 'unknown' => 3, 'ended' => 4, 'none' => 5 );
			usort(
				$data['products'],
				function ( $a, $b ) use ( $rank ) {
					return $rank[ $a['status'] ] <=> $rank[ $b['status'] ] ?: strcmp( $b['updated'], $a['updated'] );
				}
			);
		}
		return $data;
	}

	public static function rest_ads( WP_REST_Request $req ) {
		$data = self::ads_data();
		$out  = array_values(
			array_filter(
				$data['products'],
				function ( $p ) use ( $req ) {
					return ! $req['status'] || $p['status'] === $req['status'];
				}
			)
		);
		$res = rest_ensure_response(
			array(
				'source'   => 'Search Engine Hubbub AI Ads Tracker',
				'page'     => home_url( '/tools/ai-ads-tracker/' ),
				'license'  => 'Free to use with attribution and a link to the page above.',
				'updated'  => $data['updated'],
				'count'    => count( $out ),
				'products' => $out,
			)
		);
		$res->header( 'Access-Control-Allow-Origin', '*' );
		$res->header( 'Cache-Control', 'public, max-age=3600' );
		return $res;
	}

	private static function fmt_date( $d ) {
		if ( preg_match( '/^\d{4}-\d{2}$/', $d ) ) {
			return gmdate( 'M Y', strtotime( $d . '-01 00:00:00 UTC' ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
			return $d;
		}
		return gmdate( 'j M Y', strtotime( $d . ' 00:00:00 UTC' ) );
	}

	private static function ad_counts() {
		$counts = array( 'live' => 0, 'test' => 0, 'ended' => 0, 'none' => 0 );
		foreach ( self::ads_data()['products'] as $p ) {
			$counts[ self::ad_group( $p['status'] ) ]++;
		}
		return $counts;
	}

	private static function ad_group( $status ) {
		return in_array( $status, array( 'beta', 'testing', 'announced', 'unknown' ), true ) ? 'test' : $status;
	}

	public static function ads_shortcode() {
		self::enqueue();
		$data   = self::ads_data();
		$counts = self::ad_counts();
		$cards  = '';
		foreach ( $data['products'] as $p ) {
			$group = self::ad_group( $p['status'] );
			$facts = '';
			foreach ( array( 'markets' => 'Markets', 'formats' => 'Formats', 'buying' => 'How to buy', 'pricing' => 'Pricing' ) as $k => $label ) {
				if ( '-' === $p[ $k ] ) {
					continue;
				}
				$facts .= '<div class="hb-fact"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $p[ $k ] ) . '</dd></div>';
			}
			$links = '';
			foreach ( $p['sources'] as $src ) {
				$host   = preg_replace( '/^www\./', '', (string) wp_parse_url( $src, PHP_URL_HOST ) );
				$links .= '<a href="' . esc_url( $src ) . '" rel="noopener" target="_blank">' . esc_html( $host ) . ' ↗</a>';
			}
			$cards .= '<article class="hb-ad hb-card hb-ad--' . esc_attr( $p['status'] ) . '" data-group="' . esc_attr( $group ) . '">'
				. '<header><span class="hb-mono hb-mono--' . esc_attr( $group ) . '" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( $p['company'], 0, 1 ) ) ) . '</span>'
				. '<div><h3>' . esc_html( $p['product'] ) . '</h3><span class="hb-ad__meta">' . esc_html( $p['company'] . ' · ' . $p['surface'] ) . '</span></div>'
				. '<span class="hb-status hb-status--' . esc_attr( $p['status'] ) . '">' . esc_html( $data['statuses'][ $p['status'] ] ) . '</span></header>'
				. ( $facts ? '<dl class="hb-facts">' . $facts . '</dl>' : '' )
				. ( $p['notes'] ? '<p class="hb-ad__note">' . esc_html( $p['notes'] ) . '</p>' : '' )
				. '<footer><span>Updated ' . esc_html( self::fmt_date( $p['updated'] ) ) . ( $p['announced'] ? ' · First announced ' . esc_html( self::fmt_date( $p['announced'] ) ) : '' ) . '</span>'
				. '<span class="hb-src"><span class="hb-src__tag hb-src__tag--' . ( $p['official_source'] ? 'official">Official source' : 'press">Trade press' ) . '</span>' . $links . '</span></footer>'
				. '</article>';
		}
		$stats = '<div class="hb-stats">' . self::stat( $counts['live'], 'Live now', 'live' ) . self::stat( $counts['test'], 'Testing or announced', 'test' ) . self::stat( $counts['ended'], 'Ended', 'ended' ) . self::stat( $counts['none'], 'Ad-free (for now)', 'none' ) . '</div>';
		$inner = self::hero( 'Live tracker · updated ' . self::fmt_date( $data['updated'] ), 'Where you can buy ads <em>inside AI answers</em>', 'Every ad product inside AI assistants and AI search, from ChatGPT to AI Mode: status, markets, formats and how to buy. Sourced and dated.', $stats )
			. '<section class="hb-section"><div class="hb-toolbar"><div class="hb-seg" role="group" aria-label="Status"><button type="button" class="is-on" data-p="">All</button><button type="button" data-p="live">Live</button><button type="button" data-p="test">Testing</button><button type="button" data-p="ended">Ended</button><button type="button" data-p="none">No ads</button></div></div>'
			. '<p class="hb-count" aria-live="polite"></p>'
			. '<div class="hb-adlist">' . $cards . '</div>'
			. '<p class="hb-foot">Spotted a new AI ad format or a change? <a href="' . esc_url( home_url( '/contact-us/' ) ) . '">Tell us</a> and we\'ll verify it.</p></section>';
		return self::shell( 'ads', $inner );
	}

	public static function tools_shortcode() {
		self::enqueue();
		$crawl  = self::data();
		$ads    = self::ads_data();
		$counts = self::ad_counts();
		$nbots  = count( $crawl['crawlers'] );

		$form = '<form class="hb-search hb-search--lg" role="search" method="get" action="' . esc_url( self::page_url( 'checker' ) ) . '">'
			. '<label class="screen-reader-text" for="hubbub-home-url">Page to check</label>' . self::icon( 'search' )
			. '<input id="hubbub-home-url" name="url" type="text" inputmode="url" placeholder="Check any page, e.g. yoursite.com" required>'
			. '<button type="submit" class="hb-btn">Check' . self::icon( 'arrow' ) . '</button></form>';
		$stats = '<div class="hb-stats hb-stats--inline">' . self::stat( $nbots, 'AI crawlers tracked' ) . self::stat( count( $ads['products'] ), 'AI ad products' ) . self::stat( 12, 'Visibility checks' ) . self::stat( 4, 'MCP tools' ) . '</div>';

		$console = '<div class="hb-console" aria-label="Example: an AI assistant using the Hubbub MCP server" role="img">'
			. '<div class="hb-term__bar"><span></span><span></span><span></span><em>AI assistant · Search Engine Hubbub MCP</em></div><div class="hb-console__body">'
			. '<p class="l1"><b class="you">You</b>Is my site ready for AI search?</p>'
			. '<p class="l2"><b class="tool">tool</b><code>check_ai_visibility("yoursite.com")</code></p>'
			. '<p class="l3 ok">✓ Page loads for AI bots</p>'
			. '<p class="l4 ok">✓ ' . (int) $nbots . ' AI crawlers allowed</p>'
			. '<p class="l5 warn">! llms.txt is missing a # title</p>'
			. '<p class="l6 ok">✓ Schema: Article, Organization</p>'
			. '<p class="l7 score"><span>92</span>/100 · Ready for AI search</p>'
			. '</div></div>';

		$hero = '<header class="hb-hero hb-hero--home"><div class="hb-aurora" aria-hidden="true"><i></i><i></i><i></i></div><div class="hb-gridbg" aria-hidden="true"></div>'
			. '<div class="hb-hero__in"><div class="hb-hero__copy"><span class="hb-eyebrow"><span class="hb-live"></span>Free tools · live data · MCP</span>'
			. '<h2 class="hb-hero__title">The toolkit for <em>AI search</em> marketers</h2>'
			. '<p class="hb-hero__sub">See how ChatGPT, Claude, Perplexity and Gemini find, read, cite and sell. Use it in your browser, or plug it straight into your AI.</p>'
			. $form . $stats . '</div>' . $console . '</div></header>';

		$tokens = '';
		foreach ( $crawl['crawlers'] as $c ) {
			$tokens .= '<span class="hb-chip hb-chip--' . esc_attr( $c['purpose'] ) . '">' . esc_html( $c['token'] ) . '</span>';
		}
		$marquee = '<div class="hb-marquee" aria-hidden="true"><div class="hb-marquee__track">' . $tokens . $tokens . '</div></div>';

		$live = '';
		$i    = 0;
		foreach ( $ads['products'] as $p ) {
			if ( 'live' !== $p['status'] || $i++ >= 4 ) {
				continue;
			}
			$live .= '<li><span class="hb-pulse"></span>' . esc_html( $p['product'] ) . '<small>' . esc_html( $p['company'] ) . '</small></li>';
		}
		$chips = '';
		foreach ( array_slice( $crawl['crawlers'], 0, 9 ) as $c ) {
			$chips .= '<span class="hb-chip hb-chip--' . esc_attr( $c['purpose'] ) . '">' . esc_html( $c['token'] ) . '</span>';
		}
		$t     = self::tools();
		$cta   = function ( $text ) {
			return '<span class="hb-cta">' . esc_html( $text ) . self::icon( 'arrow' ) . '</span>';
		};
		$head  = function ( $key, $badge ) use ( $t ) {
			return '<div class="hb-bento__head"><span class="hb-bento__ico">' . self::icon( $t[ $key ]['icon'] ) . '</span><span class="hb-badge">' . esc_html( $badge ) . '</span></div><h2>' . esc_html( $t[ $key ]['name'] ) . '</h2><p>' . esc_html( $t[ $key ]['desc'] ) . '</p>';
		};
		$gauge = '<div class="hb-mini"><svg viewBox="0 0 120 120" class="hb-mini__g" aria-hidden="true"><circle cx="60" cy="60" r="50"/><circle cx="60" cy="60" r="50" class="fg" stroke-dasharray="314" stroke-dashoffset="25"/></svg><strong>92</strong>'
			. '<ul><li class="ok">Reach <i style="--w:100%"></i></li><li class="ok">Readability <i style="--w:100%"></i></li><li class="mid">Guidance <i style="--w:50%"></i></li><li class="ok">Structure <i style="--w:100%"></i></li></ul></div>';
		$bento = '<section class="hb-bento">'
			. '<a class="hb-card hb-bento__item hb-bento--checker" href="' . esc_url( self::page_url( 'checker' ) ) . '">' . $head( 'checker', 'Most used' ) . $gauge . $cta( 'Check a page' ) . '</a>'
			. '<a class="hb-card hb-bento__item hb-bento--crawlers" href="' . esc_url( self::page_url( 'crawlers' ) ) . '">' . $head( 'crawlers', $nbots . ' bots' ) . '<div class="hb-chipcloud">' . $chips . '</div>' . $cta( 'Browse the directory' ) . '</a>'
			. '<a class="hb-card hb-bento__item hb-bento--ads" href="' . esc_url( self::page_url( 'ads' ) ) . '">' . $head( 'ads', $counts['live'] . ' live' ) . '<ul class="hb-livelist">' . $live . '</ul>' . $cta( 'See what is live' ) . '</a>'
			. '<a class="hb-card hb-bento__item hb-bento--mcp" href="#hb-agents"><div class="hb-bento__head"><span class="hb-bento__ico">' . self::icon( 'plug' ) . '</span><span class="hb-badge">New</span></div><h2>MCP server</h2><p>Give Claude, ChatGPT or Cursor all of these tools. One URL, no sign-in.</p><code class="hb-bento__code">' . esc_html( rest_url( self::NS . '/mcp' ) ) . '</code>' . $cta( 'Connect your AI' ) . '</a>'
			. '<div class="hb-card hb-bento__item hb-bento--soon"><div class="hb-bento__head"><span class="hb-bento__ico">' . self::icon( 'chart' ) . '</span><span class="hb-badge hb-badge--soon">Coming soon</span></div><h2>AI Citation Index</h2><p>Each month we ask the leading AI assistants the same marketing questions and publish who they cite.</p>'
			. '<div class="hb-fakebars" aria-hidden="true"><i style="--h:80%"></i><i style="--h:62%"></i><i style="--h:48%"></i><i style="--h:35%"></i><i style="--h:22%"></i></div></div>'
			. '</section>';

		return '<div class="hubbub-hub hb-app hubbub-tools">' . self::nav( 'tools' ) . $hero . $marquee . $bento . self::agents() 
			. '<footer class="hb-footer"><span>Crawler data updated ' . esc_html( self::fmt_date( $crawl['updated'] ) ) . ' · Ads data updated ' . esc_html( self::fmt_date( $ads['updated'] ) ) . '</span>'
			. '<span>Free to use with attribution. <a href="' . esc_url( rest_url( self::NS . '/llms' ) ) . '">LLM guide</a> · <a href="' . esc_url( rest_url( self::NS . '/about' ) ) . '">JSON index</a></span></footer></div>';
	}

	/** Which tool (if any) the current singular page shows. */
	private static function current_tool() {
		if ( ! is_singular() ) {
			return '';
		}
		$post = get_queried_object();
		if ( ! $post || empty( $post->post_content ) ) {
			return '';
		}
		foreach ( array( 'hubbub_ai_checker' => 'checker', 'hubbub_ai_crawlers' => 'crawlers', 'hubbub_ai_ads' => 'ads', 'hubbub_ai_tools' => 'tools' ) as $sc => $key ) {
			if ( has_shortcode( $post->post_content, $sc ) ) {
				return $key;
			}
		}
		return '';
	}

	public static function body_class( $classes ) {
		if ( self::current_tool() ) {
			$classes[] = 'hubbub-tool-page';
		}
		return $classes;
	}

	/** The tool hero carries the H1, so drop the theme's page title on tool pages. */
	public static function hide_title( $content, $block, $instance = null ) {
		if ( ! self::current_tool() || is_admin() ) {
			return $content;
		}
		$id = $instance && isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : 0;
		return $id === (int) get_queried_object_id() ? '' : $content;
	}

	public static function api_link() {
		$key = self::current_tool();
		echo '<link rel="alternate" type="application/json" title="Hubbub AI Crawler Directory" href="' . esc_url( rest_url( self::NS . '/crawlers' ) ) . '">' . "\n";
		if ( ! $key ) {
			return;
		}
		echo '<link rel="alternate" type="text/markdown" title="Hubbub AI tools guide for LLMs" href="' . esc_url( rest_url( self::NS . '/llms' ) ) . '">' . "\n";
		if ( 'ads' === $key ) {
			echo '<link rel="alternate" type="application/json" title="Hubbub AI Ads Tracker" href="' . esc_url( rest_url( self::NS . '/ads' ) ) . '">' . "\n";
		}
		echo '<script type="application/ld+json">' . wp_json_encode( self::schema( $key ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}

	private static function schema( $key ) {
		$org   = array( '@type' => 'Organization', 'name' => 'Search Engine Hubbub', 'url' => home_url( '/' ) );
		$t     = self::tools();
		$graph = array();
		if ( 'tools' === $key ) {
			$items = array();
			$pos   = 1;
			foreach ( $t as $k => $x ) {
				$items[] = array( '@type' => 'ListItem', 'position' => $pos++, 'name' => $x['name'], 'url' => self::page_url( $k ) );
			}
			$graph[] = array( '@type' => 'CollectionPage', 'name' => 'Hubbub AI Marketing Tools', 'url' => self::page_url(), 'description' => 'Free tools and live data for marketers working in AI search, with an MCP server and JSON API for AI agents.', 'publisher' => $org, 'mainEntity' => array( '@type' => 'ItemList', 'itemListElement' => $items ) );
			return array( '@context' => 'https://schema.org', '@graph' => $graph );
		}
		$x       = $t[ $key ];
		$graph[] = array(
			'@type'               => 'WebApplication',
			'name'                => $x['name'],
			'url'                 => self::page_url( $key ),
			'description'         => $x['desc'],
			'applicationCategory' => 'BusinessApplication',
			'operatingSystem'     => 'Any',
			'isAccessibleForFree' => true,
			'offers'              => array( '@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'GBP' ),
			'publisher'           => $org,
			'potentialAction'     => 'checker' === $key ? array( '@type' => 'SearchAction', 'target' => self::page_url( 'checker' ) . '?url={url}', 'query-input' => 'required name=url' ) : null,
		);
		if ( 'checker' !== $key ) {
			$data    = 'ads' === $key ? self::ads_data() : self::data();
			$graph[] = array(
				'@type'               => 'Dataset',
				'name'                => $x['name'],
				'description'         => $x['desc'],
				'url'                 => self::page_url( $key ),
				'dateModified'        => $data['updated'],
				'creator'             => $org,
				'isAccessibleForFree' => true,
				'license'             => 'https://creativecommons.org/licenses/by/4.0/',
				'distribution'        => array( '@type' => 'DataDownload', 'encodingFormat' => 'application/json', 'contentUrl' => rest_url( self::NS . ( 'ads' === $key ? '/ads' : '/crawlers' ) ) ),
			);
		}
		$graph[0] = array_filter( $graph[0], function ( $v ) { return null !== $v; } );
		return array( '@context' => 'https://schema.org', '@graph' => $graph );
	}

	/** Machine-readable index of the tools for agents. */
	public static function rest_about() {
		$t     = self::tools();
		$tools = array();
		foreach ( $t as $k => $x ) {
			$tools[] = array( 'name' => $x['name'], 'page' => self::page_url( $k ), 'description' => $x['desc'], 'api' => rest_url( self::NS . $x['api'] ), 'mcp_tool' => $x['mcp'] );
		}
		$res = rest_ensure_response(
			array(
				'name'        => 'Search Engine Hubbub AI Tools',
				'description' => 'Free tools and live data for marketers working in AI search.',
				'page'        => self::page_url(),
				'mcp'         => array( 'url' => rest_url( self::NS . '/mcp' ), 'transport' => 'streamable-http', 'auth' => 'none' ),
				'llms_guide'  => rest_url( self::NS . '/llms' ),
				'license'     => 'Free to use with attribution and a link to the tool page.',
				'tools'       => $tools,
			)
		);
		$res->header( 'Access-Control-Allow-Origin', '*' );
		return $res;
	}

	public static function llms_markdown() {
		$t   = self::tools();
		$md  = "# Search Engine Hubbub AI Tools\n\n> Free tools and live data for marketers working in AI search: an AI visibility checker, a directory of AI crawlers and a tracker of ad products inside AI assistants. Free to use with attribution.\n\n";
		$md .= "## Connect an AI assistant (MCP)\n\n- MCP server (Streamable HTTP, no auth): " . rest_url( self::NS . '/mcp' ) . "\n- MCP tools: check_ai_visibility(url), list_ai_crawlers(purpose?, company?), build_robots_txt(block[]), list_ai_ad_products(status?)\n\n";
		$md .= "## Tools\n\n";
		foreach ( $t as $k => $x ) {
			$md .= '- [' . $x['name'] . '](' . self::page_url( $k ) . '): ' . $x['desc'] . ' JSON: ' . rest_url( self::NS . $x['api'] ) . "\n";
		}
		$md .= "- robots.txt rules for AI crawlers: " . rest_url( self::NS . '/robots?block=training' ) . " (block = training, search, user, other)\n\n";
		$md .= "## Data\n\n- Crawler data updated " . self::data()['updated'] . ', ' . count( self::data()['crawlers'] ) . " crawlers, each checked against the operator's own documentation.\n- Ads data updated " . self::ads_data()['updated'] . ', ' . count( self::ads_data()['products'] ) . " products, each with sources.\n- Please cite Search Engine Hubbub and link to the tool page when you use this data.\n";
		return $md;
	}

	public static function rest_llms() {
		$res = new WP_REST_Response( self::llms_markdown() );
		$res->header( 'Content-Type', 'text/markdown; charset=utf-8' );
		$res->header( 'Access-Control-Allow-Origin', '*' );
		return $res;
	}

	/** Serve /llms as raw Markdown rather than a JSON string. */
	public static function serve_markdown( $served, $result, $request ) {
		if ( '/' . self::NS . '/llms' !== $request->get_route() || $served ) {
			return $served;
		}
		foreach ( $result->get_headers() as $k => $v ) {
			header( $k . ': ' . $v );
		}
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput -- plain Markdown.
		return true;
	}
}

Hubbub_AI_Hub::init();
