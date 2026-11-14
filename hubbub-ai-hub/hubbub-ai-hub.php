<?php
/**
 * Plugin Name: Hubbub AI Hub
 * Description: AI Visibility Checker, AI Crawler Directory and AI Ads Tracker for the Search Engine Hubbub Knowledge Hub, with a public JSON API for people and AI agents.
 * Version: 0.11.1
 * Author: Search Engine Hubbub
 * License: GPL-2.0-or-later
 * Requires PHP: 7.4
 * Update URI: https://github.com/SearchEngineHubbub/hubbub-ai-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HUBBUB_AI_HUB_VERSION', '0.11.1' );
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
		$links = '<li><a href="' . esc_url( self::page_url() ) . '"' . ( 'tools' === $active ? ' aria-current="page"' : '' ) . '>All tools</a></li>';
		foreach ( self::tools() as $key => $t ) {
			$links .= '<li><a href="' . esc_url( self::page_url( $key ) ) . '"' . ( $key === $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $t['short'] ) . '</a></li>';
		}
		$links .= '<li class="hb-nav__end"><a href="#hb-agents">API &amp; MCP</a></li>';
		return '<nav class="hb-nav" aria-label="AI tools"><ul>' . $links . '</ul></nav>';
	}

	/**
	 * Hero block. $title may hold one <em>…</em> for the highlighted words.
	 */
	private static function hero( $eyebrow, $title, $sub, $extra = '', $class = '' ) {
		$title = wp_strip_all_tags( $title );
		return '<header class="hb-intro ' . esc_attr( $class ) . '">'
			. ( $eyebrow ? '<p class="hb-kicker">' . esc_html( $eyebrow ) . '</p>' : '' )
			. '<h2 class="hb-intro__title">' . esc_html( $title ) . '</h2><p class="hb-intro__sub">' . esc_html( $sub ) . '</p>' . $extra . '</header>';
	}

	private static function stat( $num, $label, $key = null ) {
		$tag  = null === $key ? 'div' : 'button';
		$attr = null === $key ? '' : ' type="button" data-filter="' . esc_attr( $key ) . '"';
		return '<' . $tag . ' class="hb-stat"' . $attr . '><span class="hb-stat__num" data-count="' . (int) $num . '">' . (int) $num . '</span><span class="hb-stat__label">' . esc_html( $label ) . '</span></' . $tag . '>';
	}

	private static function copy_code( $text, $label = '' ) {
		return '<div class="hb-code"><code>' . esc_html( $text ) . '</code><button type="button" class="hb-copy" data-copy="' . esc_attr( $text ) . '">Copy</button></div>';
	}

	/** "Use with AI" panel: how to plug the tools into assistants, agents and code. */
	private static function agents( $focus = '' ) {
		$mcp  = rest_url( self::NS . '/mcp' );
		$t    = self::tools();
		$tool = $focus && isset( $t[ $focus ] ) ? $t[ $focus ] : null;
		$api  = rest_url( self::NS . ( $tool ? $tool['api'] : '/crawlers' ) );
		$cfg  = wp_json_encode( array( 'mcpServers' => array( 'hubbub' => array( 'url' => $mcp ) ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$tabs = array(
			'claude'  => array( 'Claude', '<p>In Claude, open Settings, then Connectors, and add a custom connector with this URL. No sign-in is needed.</p>' . self::copy_code( $mcp ) ),
			'chatgpt' => array( 'ChatGPT', '<p>In ChatGPT, turn on developer mode, create a connector with this URL and choose no authentication.</p>' . self::copy_code( $mcp ) ),
			'code'    => array( 'Claude Code', '<p>Run this in your terminal:</p>' . self::copy_code( 'claude mcp add --transport http hubbub ' . $mcp ) ),
			'cursor'  => array( 'Cursor', '<p>Add this to <code>~/.cursor/mcp.json</code>:</p><div class="hb-code"><pre>' . esc_html( $cfg ) . '</pre><button type="button" class="hb-copy" data-copy="' . esc_attr( $cfg ) . '">Copy</button></div>' ),
			'api'     => array( 'JSON API', '<p>Open, free and CORS-enabled. Please credit Search Engine Hubbub with a link.</p>' . self::copy_code( $api ) . '<p>A plain-text guide for language models: <a href="' . esc_url( rest_url( self::NS . '/llms' ) ) . '">' . esc_html( rest_url( self::NS . '/llms' ) ) . '</a></p>' ),
		);
		$btns = '';
		$pans = '';
		$i    = 0;
		foreach ( $tabs as $k => $tab ) {
			$on    = 0 === $i++;
			$btns .= '<button type="button" role="tab" id="hb-tab-' . $k . '" aria-controls="hb-pan-' . $k . '" aria-selected="' . ( $on ? 'true' : 'false' ) . '"' . ( $on ? ' class="is-on"' : '' ) . '>' . esc_html( $tab[0] ) . '</button>';
			$pans .= '<div role="tabpanel" id="hb-pan-' . $k . '" aria-labelledby="hb-tab-' . $k . '"' . ( $on ? '' : ' hidden' ) . '>' . $tab[1] . '</div>';
		}
		return '<section class="hb-agents" id="hb-agents"><h2 class="hb-h2">Use these tools in your AI assistant</h2>'
			. '<p>Every tool is available through an MCP server and an open JSON API, so Claude, ChatGPT, Cursor or your own agents can run checks and pull the data directly. The server offers four tools: <code>check_ai_visibility</code>, <code>list_ai_crawlers</code>, <code>build_robots_txt</code> and <code>list_ai_ad_products</code>.</p>'
			. '<div class="hb-tabs" role="tablist" aria-label="Set up with">' . $btns . '</div><div class="hb-tabpans">' . $pans . '</div></section>';
	}

	private static function shell( $key, $inner ) {
		$crawl = self::data();
		$ads   = self::ads_data();
		$foot  = '<footer class="hb-footer"><p>Crawler data checked ' . esc_html( self::fmt_date( $crawl['updated'] ) ) . '. Ads data checked ' . esc_html( self::fmt_date( $ads['updated'] ) ) . '. Free to use with attribution. Spotted something out of date? <a href="' . esc_url( home_url( '/contact-us/' ) ) . '">Let us know</a>.</p></footer>';
		return '<div class="hubbub-hub hb-app hubbub-' . esc_attr( $key ) . '"' . ( 'checker' === $key ? self::$checker_attr : '' ) . '>' . self::nav( $key ) . $inner . self::agents( 'tools' === $key ? '' : $key ) . $foot . '</div>';
	}

	private static $checker_attr = '';

	public static function checker_shortcode() {
		self::enqueue();
		$prefill            = isset( $_GET['url'] ) ? sanitize_text_field( wp_unslash( $_GET['url'] ) ) : '';
		self::$checker_attr = ' data-prefill="' . esc_attr( $prefill ) . '"';
		$n                  = count( self::data()['crawlers'] );
		$form               = '<form class="hb-search" novalidate role="search">'
			. '<label class="screen-reader-text" for="hubbub-url">Page to check</label>'
			. '<input id="hubbub-url" name="url" type="text" inputmode="url" autocomplete="url" placeholder="yoursite.com/page" value="' . esc_attr( $prefill ) . '" required>'
			. '<button type="submit" class="hb-btn">Check page</button></form>'
			. '<p class="hb-hint">Free, no sign-up. Try <button type="button" class="hb-example" data-url="searchenginehubbub.com">searchenginehubbub.com</button></p>';
		$groups = array(
			array( 'Reach', 'Whether the page loads for bots, and whether robots.txt lets each of the ' . $n . ' AI crawlers we track fetch it.' ),
			array( 'Guidance', 'Whether the site has an llms.txt file, and whether it follows the format.' ),
			array( 'Readability', 'How much of the content is in the HTML, without JavaScript, plus the title and headings.' ),
			array( 'Structure', 'Schema markup and a canonical URL, so AI tools understand and cite one version of the page.' ),
			array( 'Trust', 'A visible author and a machine-readable published or updated date.' ),
		);
		$how = '';
		foreach ( $groups as $g ) {
			$how .= '<div><dt>' . esc_html( $g[0] ) . '</dt><dd>' . esc_html( $g[1] ) . '</dd></div>';
		}
		$inner = self::hero( '', 'Can AI assistants find and cite your page?', 'Enter a URL and we will load it the way ChatGPT, Claude, Perplexity and Gemini do, score it out of 100 and list what to fix.', $form )
			. '<div class="hubbub-checker__out" aria-live="polite"></div>'
			. '<section class="hb-section"><h2 class="hb-h2">What the checker looks at</h2><dl class="hb-deflist">' . $how . '</dl></section>';
		return self::shell( 'checker', $inner );
	}

	public static function crawlers_shortcode() {
		self::enqueue();
		$data   = self::data();
		$counts = array( 'search' => 0, 'training' => 0, 'user' => 0, 'other' => 0 );
		$labels = array( 'search' => 'Search', 'training' => 'Training', 'user' => 'User request', 'other' => 'Other' );
		$rows   = '';
		foreach ( $data['crawlers'] as $c ) {
			$counts[ $c['purpose'] ]++;
			$robots = strtolower( $c['respects_robots'] );
			$rclass = 'yes' === $robots ? 'ok' : ( 'no' === $robots ? 'no' : 'meh' );
			$rows  .= '<tr class="hb-bot" data-purpose="' . esc_attr( $c['purpose'] ) . '" data-company="' . esc_attr( $c['company'] ) . '">'
				. '<th scope="row"><code>' . esc_html( $c['token'] ) . '</code><span class="hb-bot__note">' . esc_html( $c['note'] ) . '</span></th>'
				. '<td data-label="Operator">' . esc_html( $c['company'] ) . '</td>'
				. '<td data-label="Purpose"><span class="hb-tag hb-tag--' . esc_attr( $c['purpose'] ) . '">' . esc_html( $labels[ $c['purpose'] ] ) . '</span></td>'
				. '<td data-label="Obeys robots.txt" class="hb-robots hb-robots--' . $rclass . '">' . esc_html( ucfirst( $c['respects_robots'] ) ) . '</td>'
				. '<td data-label="Sources" class="hb-links"><a href="' . esc_url( $c['source'] ) . '" rel="noopener" target="_blank">Docs</a>' . ( $c['ip_list'] ? ' <a href="' . esc_url( $c['ip_list'] ) . '" rel="noopener" target="_blank">IPs</a>' : '' ) . '</td></tr>';
		}
		$companies = array_unique( wp_list_pluck( $data['crawlers'], 'company' ) );
		sort( $companies );
		$opts = '<option value="">All operators</option>';
		foreach ( $companies as $co ) {
			$opts .= '<option>' . esc_html( $co ) . '</option>';
		}
		$toggle = function ( $value, $label, $hint ) {
			return '<label class="hb-check-opt"><input type="checkbox" value="' . esc_attr( $value ) . '"><span><strong>' . esc_html( $label ) . '</strong> ' . esc_html( $hint ) . '</span></label>';
		};
		$summary = '<p class="hb-summary">' . count( $data['crawlers'] ) . ' crawlers from ' . count( $companies ) . ' companies: ' . $counts['search'] . ' for search, ' . $counts['training'] . ' for model training, ' . $counts['user'] . ' fetching pages on a user\'s request and ' . $counts['other'] . ' other. Checked against each company\'s documentation on ' . esc_html( self::fmt_date( $data['updated'] ) ) . '.</p>';
		$inner = self::hero( '', 'Every AI crawler, and what it does with your content', 'Who runs each bot, why it visits and whether it follows robots.txt.', $summary )
			. '<section class="hb-section"><div class="hb-toolbar"><input type="search" class="hubbub-crawlers__q hb-input" placeholder="Search bots" aria-label="Search bots">'
			. '<div class="hb-seg" role="group" aria-label="Purpose"><button type="button" class="is-on" data-p="">All</button><button type="button" data-p="search">Search</button><button type="button" data-p="training">Training</button><button type="button" data-p="user">User request</button><button type="button" data-p="other">Other</button></div>'
			. '<select class="hubbub-crawlers__company hb-input" aria-label="Operator">' . $opts . '</select></div>'
			. '<p class="hb-count" aria-live="polite"></p>'
			. '<div class="hb-tablewrap"><table class="hb-table hb-table--bots"><thead><tr><th scope="col">User agent</th><th scope="col">Operator</th><th scope="col">Purpose</th><th scope="col">Obeys robots.txt</th><th scope="col">Sources</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>'
			. '<section class="hb-section hb-builder"><h2 class="hb-h2">Build your robots.txt rules</h2><p>Choose what to block and copy the rules into your robots.txt. Search and user-request bots decide whether AI assistants can cite you. Googlebot, Bingbot and Applebot are left out, so your normal search rankings are not affected.</p>'
			. '<div class="hb-builder__opts">' . $toggle( 'training', 'Block training bots.', 'Keeps your content out of AI model training.' )
			. $toggle( 'search', 'Block AI search bots.', 'You will not be cited in ChatGPT search, Perplexity or Claude.' )
			. $toggle( 'user', 'Block user-request fetchers.', 'Several of these ignore robots.txt anyway.' ) . '</div>'
			. '<div class="hb-code hb-code--out"><pre class="hubbub-builder__out"></pre><button type="button" class="hb-copy hubbub-builder__copy">Copy</button></div></section>';
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
				$facts .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $p[ $k ] ) . '</dd></div>';
			}
			$links = array();
			foreach ( $p['sources'] as $src ) {
				$host    = preg_replace( '/^www\./', '', (string) wp_parse_url( $src, PHP_URL_HOST ) );
				$links[] = '<a href="' . esc_url( $src ) . '" rel="noopener" target="_blank">' . esc_html( $host ) . '</a>';
			}
			$cards .= '<article class="hb-ad hb-ad--' . esc_attr( $p['status'] ) . '" data-group="' . esc_attr( $group ) . '">'
				. '<header><div><h3>' . esc_html( $p['product'] ) . '</h3><p class="hb-ad__meta">' . esc_html( $p['company'] . ', ' . $p['surface'] ) . '</p></div>'
				. '<span class="hb-status hb-status--' . esc_attr( $p['status'] ) . '">' . esc_html( $data['statuses'][ $p['status'] ] ) . '</span></header>'
				. ( $p['notes'] ? '<p class="hb-ad__note">' . esc_html( $p['notes'] ) . '</p>' : '' )
				. ( $facts ? '<dl class="hb-facts">' . $facts . '</dl>' : '' )
				. '<p class="hb-ad__src">Updated ' . esc_html( self::fmt_date( $p['updated'] ) ) . ( $p['announced'] ? ', first announced ' . esc_html( self::fmt_date( $p['announced'] ) ) : '' ) . '. ' . ( $p['official_source'] ? 'Official source' : 'Source' ) . ( count( $links ) > 1 ? 's' : '' ) . ': ' . implode( ', ', $links ) . '.</p>'
				. '</article>';
		}
		$summary = '<p class="hb-summary">' . $counts['live'] . ' live, ' . $counts['test'] . ' in testing or announced, ' . $counts['ended'] . ' ended and ' . $counts['none'] . ' assistants with no ads yet. Checked ' . esc_html( self::fmt_date( $data['updated'] ) ) . '.</p>';
		$inner = self::hero( '', 'Where you can buy ads inside AI answers', 'Every ad product inside AI assistants and AI search, with its status, markets, formats and how to buy. Each entry links to its source.', $summary )
			. '<section class="hb-section"><div class="hb-toolbar"><div class="hb-seg" role="group" aria-label="Status"><button type="button" class="is-on" data-p="">All</button><button type="button" data-p="live">Live</button><button type="button" data-p="test">Testing</button><button type="button" data-p="ended">Ended</button><button type="button" data-p="none">No ads</button></div></div>'
			. '<p class="hb-count" aria-live="polite"></p>'
			. '<div class="hb-adlist">' . $cards . '</div></section>';
		return self::shell( 'ads', $inner );
	}

	public static function tools_shortcode() {
		self::enqueue();
		$crawl  = self::data();
		$ads    = self::ads_data();
		$counts = self::ad_counts();
		$nbots  = count( $crawl['crawlers'] );
		$nads   = count( $ads['products'] );
		$t      = self::tools();
		$meta   = array(
			'checker'  => 'Free · 12 checks across ' . $nbots . ' AI crawlers',
			'crawlers' => $nbots . ' crawlers · checked ' . self::fmt_date( $crawl['updated'] ),
			'ads'      => $nads . ' products · checked ' . self::fmt_date( $ads['updated'] ),
		);

		$form = '<form class="hb-search hb-search--hero" role="search" method="get" action="' . esc_url( self::page_url( 'checker' ) ) . '">'
			. '<label class="screen-reader-text" for="hubbub-home-url">Page to check</label>' . self::icon( 'search' )
			. '<input id="hubbub-home-url" name="url" type="text" inputmode="url" placeholder="Check any page, e.g. yoursite.com" required>'
			. '<button type="submit" class="hb-btn">Check page' . self::icon( 'arrow' ) . '</button></form>';
		$stats = '<dl class="hb-figures">'
			. '<div><dt>AI crawlers tracked</dt><dd class="hb-stat__num" data-count="' . (int) $nbots . '">' . (int) $nbots . '</dd></div>'
			. '<div><dt>AI ad products</dt><dd class="hb-stat__num" data-count="' . (int) $nads . '">' . (int) $nads . '</dd></div>'
			. '<div><dt>Live right now</dt><dd class="hb-stat__num" data-count="' . (int) $counts['live'] . '">' . (int) $counts['live'] . '</dd></div>'
			. '<div><dt>MCP tools for agents</dt><dd class="hb-stat__num" data-count="4">4</dd></div></dl>';
		$hero = '<header class="hb-stage">'
			. '<p class="hb-kicker"><span class="hb-live" aria-hidden="true"></span>Free tools · Live data · MCP</p>'
			. '<h2 class="hb-stage__title">The toolkit for <mark>AI search</mark> marketers</h2>'
			. '<p class="hb-stage__sub">See how ChatGPT, Claude, Perplexity and Gemini find, read, cite and sell. Use it here, or plug it straight into your own AI.</p>'
			. $form . $stats . '</header>';

		// Tool cards, each with a small live preview drawn from the data.
		$card = function ( $key, $label, $preview, $cta, $extra_class = '' ) use ( $t, $meta ) {
			return '<a class="hb-tile hb-tile--' . esc_attr( $key ) . ' ' . esc_attr( $extra_class ) . '" href="' . esc_url( self::page_url( $key ) ) . '">'
				. '<span class="hb-label">' . esc_html( $label ) . '</span><h3>' . esc_html( $t[ $key ]['name'] ) . '</h3><p>' . esc_html( $t[ $key ]['desc'] ) . '</p>'
				. $preview . '<span class="hb-tile__foot"><span>' . esc_html( $meta[ $key ] ) . '</span><span class="hb-cta">' . esc_html( $cta ) . self::icon( 'arrow' ) . '</span></span></a>';
		};
		$gauge = '<div class="hb-preview hb-preview--gauge" aria-hidden="true"><div class="hb-ring"><svg viewBox="0 0 120 120"><circle cx="60" cy="60" r="52"/><circle cx="60" cy="60" r="52" class="fg"/></svg><strong>92</strong></div>'
			. '<ul><li><span>Reach</span><i style="--w:100%"></i></li><li><span>Readability</span><i style="--w:100%"></i></li><li class="mid"><span>Guidance</span><i style="--w:55%"></i></li><li><span>Structure</span><i style="--w:90%"></i></li><li class="mid"><span>Trust</span><i style="--w:60%"></i></li></ul></div>';
		$bots = '';
		foreach ( array_slice( $crawl['crawlers'], 0, 12 ) as $c ) {
			$bots .= '<span class="hb-tag hb-tag--' . esc_attr( $c['purpose'] ) . '">' . esc_html( $c['token'] ) . '</span>';
		}
		$bots = '<div class="hb-preview hb-preview--tags" aria-hidden="true">' . $bots . '<span class="hb-tag hb-tag--more">+' . max( 0, $nbots - 12 ) . ' more</span></div>';
		$live = '';
		$i    = 0;
		foreach ( $ads['products'] as $p ) {
			if ( 'live' !== $p['status'] || $i++ >= 4 ) {
				continue;
			}
			$live .= '<li><span class="hb-dot"></span><b>' . esc_html( $p['product'] ) . '</b><small>' . esc_html( $p['company'] ) . '</small></li>';
		}
		$live = '<ul class="hb-preview hb-preview--live" aria-hidden="true">' . $live . '</ul>';

		$tiles = '<section class="hb-tiles" aria-label="Tools">'
			. '<h2 class="hb-rule"><span>The tools</span></h2>'
			. '<div class="hb-tiles__grid">'
			. $card( 'checker', 'Most used', $gauge, 'Check a page', 'is-lead' )
			. $card( 'crawlers', 'Updated weekly', $bots, 'Browse the directory' )
			. $card( 'ads', $counts['live'] . ' live now', $live, 'See what is live' )
			. '<a class="hb-tile hb-tile--mcp" href="#hb-agents"><span class="hb-label">For AI agents</span><h3>MCP server</h3><p>Give Claude, ChatGPT or Cursor every tool here. One URL, no sign-in.</p>'
			. '<code class="hb-tile__code">' . esc_html( rest_url( self::NS . '/mcp' ) ) . '</code><span class="hb-tile__foot"><span>4 tools · JSON API too</span><span class="hb-cta">Connect your AI' . self::icon( 'arrow' ) . '</span></span></a>'
			. '<div class="hb-tile hb-tile--soon"><span class="hb-label">Coming soon</span><h3>AI Citation Index</h3><p>Each month we will ask the leading AI assistants the same marketing questions and publish which sources they cite.</p>'
			. '<div class="hb-preview hb-preview--bars" aria-hidden="true"><i style="--h:84%"></i><i style="--h:66%"></i><i style="--h:52%"></i><i style="--h:40%"></i><i style="--h:28%"></i><i style="--h:18%"></i></div></div>'
			. '</div></section>';

		$inner = $hero . self::graph( $meta ) . $tiles;
		return self::shell( 'tools', $inner );
	}

	/**
	 * The knowledge graph on the Tools page: the hub, each tool and the topics it covers.
	 * Drawn by hub.js. It is decorative for screen readers and agents, who get the same
	 * links in the tool list below it.
	 */
	private static function graph( $meta ) {
		$topics = array(
			'checker'   => array( 'Schema', 'Canonical', 'Authorship', 'llms.txt', 'robots.txt' ),
			'crawlers'  => array( 'GPTBot', 'ClaudeBot', 'PerplexityBot', 'Applebot', 'Google-Extended' ),
			'ads'       => array( 'AI Mode', 'AI Overviews', 'Copilot', 'ChatGPT Ads' ),
			'citations' => array( 'Share of voice', 'Prompts', 'Sources' ),
		);
		$nodes = array();
		$mini  = array( 'checker' => 'Checker', 'crawlers' => 'Crawlers', 'ads' => 'Ads' );
		foreach ( self::tools() as $k => $t ) {
			$nodes[] = array( 'id' => $k, 'label' => $t['short'], 'mini' => $mini[ $k ], 'meta' => $meta[ $k ], 'desc' => $t['desc'], 'url' => self::page_url( $k ), 'topics' => $topics[ $k ] );
		}
		$nodes[] = array( 'id' => 'citations', 'label' => 'Citation Index', 'mini' => 'Citations', 'meta' => 'Coming soon', 'desc' => 'Which sources the leading AI assistants cite for the same marketing questions, tracked monthly.', 'url' => '', 'soon' => true, 'topics' => $topics['citations'] );
		$links = array(
			array( 'robots.txt', 'GPTBot' ), array( 'Google-Extended', 'AI Mode' ), array( 'ChatGPT Ads', 'Share of voice' ), array( 'Sources', 'Schema' ),
		);
		$data = array( 'hub' => 'Knowledge Hub', 'tools' => $nodes, 'links' => $links );
		return '<figure class="hb-graph" aria-hidden="true" data-graph="' . esc_attr( wp_json_encode( $data ) ) . '">'
			. '<div class="hb-graph__head"><span class="hb-label">The Knowledge Hub</span><span class="hb-graph__legend"><i class="is-tool"></i>Tool <i class="is-topic"></i>What it covers <i class="is-link"></i>Related</span></div>'
			. '<div class="hb-graph__stage"></div>'
			. '<figcaption class="hb-graph__cap"><span class="hb-graph__hint">Hover a tool to explore what it covers</span></figcaption></figure>';
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
