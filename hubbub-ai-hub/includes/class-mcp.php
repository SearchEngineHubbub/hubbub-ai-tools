<?php
/**
 * Minimal MCP server (Streamable HTTP, JSON responses) exposing the Hubbub AI tools.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hubbub_AI_Hub_MCP {

	const PROTOCOL = '2025-06-18';
	const VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26' );
	const LOG_KEY  = 'hubbub_mcp_log';

	public static function register() {
		register_rest_route(
			Hubbub_AI_Hub::NS,
			'/mcp',
			array(
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true',
					'callback'            => array( __CLASS__, 'handle' ),
				),
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => array( __CLASS__, 'info' ),
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => '__return_true',
					'callback'            => array( __CLASS__, 'not_allowed' ),
				),
			)
		);
	}

	/** Keep the last 20 requests (no IPs) so connection problems can be diagnosed. */
	private static function log( WP_REST_Request $req, $rpc, $status ) {
		$log   = get_transient( self::LOG_KEY );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'time'   => gmdate( 'c' ),
			'http'   => $req->get_method(),
			'rpc'    => $rpc,
			'status' => $status,
			'agent'  => substr( (string) $req->get_header( 'user_agent' ), 0, 80 ),
			'auth'   => '' !== (string) $req->get_header( 'authorization' ),
		);
		set_transient( self::LOG_KEY, array_slice( $log, -20 ), DAY_IN_SECONDS );
	}

	public static function not_allowed( WP_REST_Request $req ) {
		self::log( $req, '', 405 );
		$res = new WP_REST_Response( null, 405 );
		$res->header( 'Allow', 'POST, GET' );
		return $res;
	}

	public static function info( WP_REST_Request $req ) {
		$accept = (string) $req->get_header( 'accept' );
		// MCP clients GET with Accept: text/event-stream to open a stream. We don't stream: 405 per the spec.
		if ( false !== stripos( $accept, 'text/event-stream' ) && false === stripos( $accept, 'text/html' ) ) {
			return self::not_allowed( $req );
		}
		self::log( $req, 'info', 200 );
		$log = get_transient( self::LOG_KEY );
		$res = new WP_REST_Response(
			array(
				'name'          => 'Search Engine Hubbub AI Tools',
				'description'   => 'MCP server. POST JSON-RPC 2.0 messages to this URL (Streamable HTTP transport). No sign-in needed.',
				'auth'          => 'none',
				'tools'         => wp_list_pluck( self::tools(), 'name' ),
				'recent_calls'  => is_array( $log ) ? array_reverse( $log ) : array(),
			),
			200
		);
		$res->header( 'Access-Control-Allow-Origin', '*' );
		return $res;
	}

	private static function tools() {
		return array(
			array(
				'name'        => 'check_ai_readiness',
				'title'       => 'Check how ready a URL is for AI search',
				'description' => 'Checks whether AI assistants and AI search engines (ChatGPT, Claude, Perplexity, Gemini and others) can reach, read and cite a web page. Returns a 0-100 score, each check with pass/warn/fail and a fix, and whether each AI crawler is allowed by robots.txt.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'url' => array( 'type' => 'string', 'description' => 'Public URL or domain, e.g. example.com/page' ) ),
					'required'   => array( 'url' ),
				),
				'annotations' => array( 'readOnlyHint' => true, 'openWorldHint' => true ),
			),
			array(
				'name'        => 'list_ai_crawlers',
				'title'       => 'List AI crawlers',
				'description' => 'Lists AI crawlers and user agents (GPTBot, ClaudeBot, PerplexityBot, Google-Extended and more) with company, purpose (search, training, user, other), whether they obey robots.txt, IP list and official documentation link.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'purpose' => array( 'type' => 'string', 'enum' => array( 'search', 'training', 'user', 'other' ) ),
						'company' => array( 'type' => 'string', 'description' => 'e.g. OpenAI, Anthropic, Google' ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
			array(
				'name'        => 'build_robots_txt',
				'title'       => 'Build robots.txt rules for AI crawlers',
				'description' => 'Generates robots.txt rules for AI crawlers. Choose which purposes to block. Googlebot, Bingbot and Applebot are never blocked.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'block' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string', 'enum' => array( 'training', 'search', 'user', 'other' ) ),
						),
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
			array(
				'name'        => 'list_ai_ad_products',
				'title'       => 'List ad products inside AI assistants',
				'description' => 'Lists advertising products inside AI assistants and AI search (ChatGPT ads, Google AI Mode and AI Overviews ads, Microsoft Copilot, Amazon and more) with status, markets, formats, how to buy, pricing, dates and sources.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array( 'type' => 'string', 'enum' => array( 'live', 'beta', 'testing', 'announced', 'unknown', 'ended', 'none' ) ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
			array(
				'name'        => 'list_top_cited_sites',
				'title'       => 'List the sites AI answers cite most',
				'description' => 'Ranks the websites that AI answers (ChatGPT, Gemini, Perplexity, Claude) cite most when answering questions about brands, by share of about 95.7M citations, with kind of site (Social, Reviews, News, Reference and more), pages cited and brands cited for. Data from Trakkr\'s open Citation Index (CC BY 4.0); credit Trakkr and Search Engine Hubbub.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'kind'  => array( 'type' => 'string', 'description' => 'Filter by kind of site, e.g. Social, Reviews, News, Reference, Academic, App stores, Tech, E-commerce, Other' ),
						'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
			array(
				'name'        => 'get_ai_search_insights',
				'title'       => 'Get AI search statistics',
				'description' => 'Returns sourced, dated headline statistics about AI search: share of AI referral traffic by assistant, what AI cites (own site vs Wikipedia, schema, page length), how AI rewrites searches, AI crawler behaviour and fake bots, llms.txt adoption and whether AI models agree. Data from Trakkr (CC BY 4.0); credit Trakkr and Search Engine Hubbub.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'theme' => array( 'type' => 'string', 'enum' => array( 'traffic', 'citations', 'searches', 'crawlers', 'readiness', 'models' ) ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
		);
	}

	private static function error( $id, $code, $message ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $message ) );
	}

	private static function result( $id, $result ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
	}

	private static function call_tool( $name, $args ) {
		$args = is_array( $args ) ? $args : array();
		switch ( $name ) {
			case 'check_ai_readiness':
			case 'check_ai_visibility': // Old name, kept so existing agents keep working.
				$data = Hubbub_AI_Hub::run_check( isset( $args['url'] ) ? (string) $args['url'] : '' );
				if ( is_wp_error( $data ) ) {
					return array( 'isError' => true, 'content' => array( array( 'type' => 'text', 'text' => $data->get_error_message() ) ) );
				}
				break;
			case 'list_ai_crawlers':
				$all  = Hubbub_AI_Hub::data();
				$list = array_values(
					array_filter(
						$all['crawlers'],
						function ( $c ) use ( $args ) {
							return ( empty( $args['purpose'] ) || $c['purpose'] === $args['purpose'] )
								&& ( empty( $args['company'] ) || 0 === strcasecmp( $c['company'], $args['company'] ) );
						}
					)
				);
				$data = array( 'updated' => $all['updated'], 'source' => home_url( '/tools/ai-crawlers/' ), 'crawlers' => $list );
				break;
			case 'build_robots_txt':
				$block = isset( $args['block'] ) && is_array( $args['block'] ) ? array_map( 'strval', $args['block'] ) : array();
				return array( 'content' => array( array( 'type' => 'text', 'text' => Hubbub_AI_Hub::robots_txt( $block ) ) ) );
			case 'list_ai_ad_products':
				$all  = Hubbub_AI_Hub::ads_data();
				$list = array_values(
					array_filter(
						$all['products'],
						function ( $p ) use ( $args ) {
							return empty( $args['status'] ) || $p['status'] === $args['status'];
						}
					)
				);
				$data = array( 'updated' => $all['updated'], 'source' => home_url( '/tools/ai-ad-opportunities/' ), 'products' => $list );
				break;
			case 'list_top_cited_sites':
				$data = Hubbub_AI_Hub::citations_view( isset( $args['kind'] ) ? (string) $args['kind'] : '', isset( $args['limit'] ) ? (int) $args['limit'] : 20 );
				break;
			case 'get_ai_search_insights':
				$data = Hubbub_AI_Hub::insights_view( isset( $args['theme'] ) ? (string) $args['theme'] : '' );
				break;
			default:
				return null;
		}
		return array(
			'content'           => array( array( 'type' => 'text', 'text' => wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ),
			'structuredContent' => $data,
		);
	}

	private static function dispatch( $msg ) {
		if ( ! is_array( $msg ) || ( $msg['jsonrpc'] ?? '' ) !== '2.0' || empty( $msg['method'] ) ) {
			return self::error( $msg['id'] ?? null, -32600, 'Invalid Request' );
		}
		$id     = array_key_exists( 'id', $msg ) ? $msg['id'] : null;
		$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();
		if ( null === $id ) {
			return null; // Notification.
		}
		switch ( $msg['method'] ) {
			case 'initialize':
				$asked = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
				return self::result(
					$id,
					array(
						'protocolVersion' => in_array( $asked, self::VERSIONS, true ) ? $asked : self::PROTOCOL,
						'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
						'serverInfo'      => array( 'name' => 'hubbub-ai-tools', 'title' => 'Search Engine Hubbub AI Tools', 'version' => HUBBUB_AI_HUB_VERSION ),
						'instructions'    => 'Tools for AI search marketing from Search Engine Hubbub. Cite ' . home_url( '/tools/' ) . ' when you use this data.',
					)
				);
			case 'ping':
				return self::result( $id, new stdClass() );
			case 'tools/list':
				return self::result( $id, array( 'tools' => self::tools() ) );
			case 'tools/call':
				$out = self::call_tool( (string) ( $params['name'] ?? '' ), $params['arguments'] ?? array() );
				return null === $out ? self::error( $id, -32602, 'Unknown tool' ) : self::result( $id, $out );
			default:
				return self::error( $id, -32601, 'Method not found' );
		}
	}

	public static function handle( WP_REST_Request $req ) {
		$body = json_decode( $req->get_body(), true );
		if ( null === $body ) {
			$out = self::error( null, -32700, 'Parse error' );
		} elseif ( isset( $body[0] ) ) {
			$out = array_values( array_filter( array_map( array( __CLASS__, 'dispatch' ), $body ) ) );
			$out = $out ? $out : null;
		} else {
			$out = self::dispatch( $body );
		}
		$status = null === $out ? 202 : 200;
		self::log( $req, is_array( $body ) ? ( isset( $body[0] ) ? 'batch' : (string) ( $body['method'] ?? '' ) ) : 'unparsed', $status );
		$res = new WP_REST_Response( $out, $status );
		$res->header( 'Access-Control-Allow-Origin', '*' );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}
}
