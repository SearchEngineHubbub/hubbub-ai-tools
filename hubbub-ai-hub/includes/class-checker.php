<?php
/**
 * AI Visibility Checker: how readable and citable a URL is for AI crawlers and answer engines.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'HUBBUB_AI_HUB_TEST' ) ) {
	exit;
}

class Hubbub_AI_Hub_Checker {

	const UA = 'HubbubAIVisibilityChecker/1.0 (+https://searchenginehubbub.com/tools/ai-visibility-checker/)';

	/** @var callable(string): array{code:int, body:string, headers:array, url:string, error:string} */
	private $fetch;

	/** @var array crawler records from data/crawlers.json */
	private $crawlers;

	public function __construct( $crawlers, $fetch = null ) {
		$this->crawlers = $crawlers;
		$this->fetch    = $fetch ? $fetch : array( $this, 'wp_fetch' );
	}

	public function wp_fetch( $url ) {
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 8,
				'redirection'         => 5,
				'user-agent'          => self::UA,
				'limit_response_size' => 2 * MB_IN_BYTES,
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( 'code' => 0, 'body' => '', 'headers' => array(), 'url' => $url, 'error' => $res->get_error_message() );
		}
		$headers = array();
		foreach ( wp_remote_retrieve_headers( $res ) as $k => $v ) {
			$headers[ strtolower( $k ) ] = is_array( $v ) ? implode( ', ', $v ) : $v;
		}
		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $res ),
			'body'    => (string) wp_remote_retrieve_body( $res ),
			'headers' => $headers,
			'url'     => $url,
			'error'   => '',
		);
	}

	public static function normalise_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$parts = parse_url( $url );
		if ( empty( $parts['host'] ) || ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/i', $parts['host'] ) ) {
			return '';
		}
		return $url;
	}

	private function add( &$checks, $group, $id, $label, $status, $detail, $fix = '' ) {
		$checks[] = compact( 'group', 'id', 'label', 'status', 'detail', 'fix' );
	}

	public function run( $url ) {
		$url = self::normalise_url( $url );
		if ( '' === $url ) {
			return array( 'error' => 'Please enter a valid public web address, such as example.com/page.' );
		}
		$parts  = parse_url( $url );
		$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$path   = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

		$checks = array();
		$page   = call_user_func( $this->fetch, $url );

		if ( 0 === $page['code'] ) {
			return array( 'error' => 'We could not reach that page: ' . $page['error'] );
		}

		// 1. Page reachable.
		if ( $page['code'] >= 200 && $page['code'] < 300 ) {
			$this->add( $checks, 'access', 'status', 'Page loads for bots', 'pass', 'The page returned HTTP ' . $page['code'] . '.' );
		} else {
			$this->add( $checks, 'access', 'status', 'Page loads for bots', 'fail', 'The page returned HTTP ' . $page['code'] . ' to a bot request.', 'Make sure the page returns 200 to crawlers and is not behind a firewall challenge.' );
		}

		// 2. robots.txt per crawler.
		$robots_res = call_user_func( $this->fetch, $origin . '/robots.txt' );
		$robots     = new Hubbub_AI_Hub_Robots( 200 === $robots_res['code'] ? $robots_res['body'] : '' );
		$bots       = array();
		foreach ( $this->crawlers as $c ) {
			if ( empty( $c['token'] ) ) {
				continue;
			}
			$r      = $robots->check( $c['token'], $path );
			$bots[] = array(
				'name'     => $c['name'],
				'company'  => $c['company'],
				'purpose'  => $c['purpose'],
				'allowed'  => $r['allowed'],
				'specific' => $r['specific'],
				'rule'     => $r['rule'],
			);
		}
		$blocked_search = array();
		$blocked_train  = array();
		foreach ( $bots as $b ) {
			if ( ! $b['allowed'] && in_array( $b['purpose'], array( 'search', 'user' ), true ) ) {
				$blocked_search[] = $b['name'];
			}
			if ( ! $b['allowed'] && 'training' === $b['purpose'] ) {
				$blocked_train[] = $b['name'];
			}
		}
		if ( 200 !== $robots_res['code'] ) {
			$this->add( $checks, 'access', 'robots', 'robots.txt', 'warn', 'No robots.txt found (HTTP ' . $robots_res['code'] . '), so all crawlers are allowed by default.', 'Add a robots.txt with a Sitemap line so crawlers find your pages.' );
		}
		$search_total = count( array_filter( $bots, function ( $b ) { return in_array( $b['purpose'], array( 'search', 'user' ), true ); } ) );
		$major        = array_intersect( $blocked_search, array( 'Googlebot', 'OAI-SearchBot', 'Bingbot' ) );
		if ( $blocked_search && ! $major && count( $blocked_search ) * 2 < $search_total ) {
			$this->add( $checks, 'access', 'ai-search-bots', 'AI search and answer bots', 'warn', 'Blocked: ' . implode( ', ', $blocked_search ) . '. You will not be cited by these AI assistants.', 'Allow these user agents in robots.txt if you want to be cited in their answers.' );
		} elseif ( $blocked_search ) {
			$this->add( $checks, 'access', 'ai-search-bots', 'AI search and answer bots', 'fail', 'Blocked: ' . implode( ', ', $blocked_search ) . '. These bots fetch pages for AI answers and citations.', 'Allow these user agents in robots.txt if you want to be cited in AI answers.' );
		} else {
			$this->add( $checks, 'access', 'ai-search-bots', 'AI search and answer bots', 'pass', 'Every AI search and user-triggered bot we track can fetch this page.' );
		}
		if ( $blocked_train ) {
			$this->add( $checks, 'access', 'ai-training-bots', 'AI training bots', 'info', 'Blocked: ' . implode( ', ', $blocked_train ) . '. This keeps content out of model training and is a business choice.', '' );
		} else {
			$this->add( $checks, 'access', 'ai-training-bots', 'AI training bots', 'info', 'Training crawlers are allowed. Block them in robots.txt if you do not want your content used to train models.', '' );
		}

		$html    = 200 <= $page['code'] && $page['code'] < 300 ? $page['body'] : '';
		$headers = $page['headers'];

		// 3. Indexing directives.
		$robots_meta = strtolower( self::meta( $html, 'robots' ) . ' ' . ( isset( $headers['x-robots-tag'] ) ? $headers['x-robots-tag'] : '' ) );
		if ( preg_match( '/noindex|none/', $robots_meta ) ) {
			$this->add( $checks, 'access', 'noindex', 'Indexing allowed', 'fail', 'The page has a noindex directive.', 'Remove noindex if you want this page found in search and AI answers.' );
		} elseif ( preg_match( '/nosnippet|max-snippet:0/', $robots_meta ) ) {
			$this->add( $checks, 'access', 'noindex', 'Indexing allowed', 'warn', 'The page blocks snippets, which also limits use in Google AI Overviews and AI Mode.', 'Remove nosnippet unless you mean to keep the text out of AI features.' );
		} else {
			$this->add( $checks, 'access', 'noindex', 'Indexing allowed', 'pass', 'No noindex or nosnippet directive found.' );
		}

		// 4. llms.txt.
		$llms = call_user_func( $this->fetch, $origin . '/llms.txt' );
		$body = ltrim( $llms['body'] );
		if ( 200 === $llms['code'] && 0 === strpos( $body, '# ' ) && false === stripos( substr( $body, 0, 200 ), '<html' ) ) {
			$links = preg_match_all( '/\[[^\]]+\]\(https?:[^)]+\)/', $body );
			$this->add( $checks, 'guidance', 'llms-txt', 'llms.txt', 'pass', 'Found a valid llms.txt with ' . $links . ' links.' );
		} elseif ( 200 === $llms['code'] ) {
			$this->add( $checks, 'guidance', 'llms-txt', 'llms.txt', 'warn', 'An llms.txt exists but does not start with a "# Site name" heading.', 'Follow the llms.txt format: an H1 title, a > summary, then sections of Markdown links.' );
		} else {
			$this->add( $checks, 'guidance', 'llms-txt', 'llms.txt', 'warn', 'No llms.txt found.', 'Add /llms.txt listing your most useful pages for AI tools.' );
		}

		if ( '' === $html ) {
			return $this->result( $url, $checks, $bots );
		}

		// 5. Content in the HTML (AI crawlers mostly do not run JavaScript).
		$text  = self::visible_text( $html );
		$words = str_word_count( $text );
		if ( $words >= 300 ) {
			$this->add( $checks, 'content', 'server-html', 'Content in the HTML', 'pass', number_format( $words ) . ' words are readable without JavaScript.' );
		} elseif ( $words >= 100 ) {
			$this->add( $checks, 'content', 'server-html', 'Content in the HTML', 'warn', 'Only ' . $words . ' words are readable without JavaScript.', 'Most AI crawlers do not run JavaScript. Render the main content on the server.' );
		} else {
			$this->add( $checks, 'content', 'server-html', 'Content in the HTML', 'fail', 'Only ' . $words . ' words are readable without JavaScript.', 'Most AI crawlers do not run JavaScript. Render the main content on the server.' );
		}

		// 6. Title and description.
		$title = self::tag_text( $html, 'title' );
		$desc  = self::meta( $html, 'description' );
		if ( '' !== $title && '' !== $desc ) {
			$this->add( $checks, 'content', 'title-desc', 'Title and description', 'pass', 'Title: "' . self::clip( $title ) . '"' );
		} else {
			$this->add( $checks, 'content', 'title-desc', 'Title and description', 'warn', '' === $title ? 'No <title> found.' : 'No meta description found.', 'Write a specific title and a one-sentence description that answers what the page is.' );
		}

		// 7. Headings.
		$h1 = preg_match_all( '#<h1[\s>]#i', $html );
		$h2 = preg_match_all( '#<h2[\s>]#i', $html );
		if ( 1 === $h1 && $h2 >= 2 ) {
			$this->add( $checks, 'content', 'headings', 'Clear heading structure', 'pass', 'One H1 and ' . $h2 . ' H2 sections.' );
		} else {
			$this->add( $checks, 'content', 'headings', 'Clear heading structure', 'warn', $h1 . ' H1 and ' . $h2 . ' H2 headings found.', 'Use one H1 and break the page into H2 sections that each answer a question.' );
		}

		// 8. Structured data.
		$types = self::schema_types( $html );
		if ( $types ) {
			$this->add( $checks, 'structure', 'schema', 'Structured data', 'pass', 'JSON-LD types: ' . implode( ', ', array_slice( array_unique( $types ), 0, 8 ) ) . '.' );
		} else {
			$this->add( $checks, 'structure', 'schema', 'Structured data', 'warn', 'No JSON-LD structured data found.', 'Add Schema.org JSON-LD (Article, Organization, Product, DefinedTerm) so machines know what the page is.' );
		}

		// 9. Canonical.
		if ( preg_match( '#<link[^>]+rel=["\']canonical["\'][^>]*>#i', $html ) ) {
			$this->add( $checks, 'structure', 'canonical', 'Canonical URL', 'pass', 'A canonical link is set.' );
		} else {
			$this->add( $checks, 'structure', 'canonical', 'Canonical URL', 'warn', 'No canonical link found.', 'Add rel="canonical" so AI tools cite one version of the page.' );
		}

		// 10. Freshness.
		$has_date = '' !== self::meta( $html, 'article:modified_time', 'property' ) || preg_match( '/"date(Modified|Published)"\s*:/', $html ) || preg_match( '#<time[^>]+datetime=#i', $html );
		if ( $has_date ) {
			$this->add( $checks, 'trust', 'dates', 'Published or updated date', 'pass', 'The page states when it was published or updated.' );
		} else {
			$this->add( $checks, 'trust', 'dates', 'Published or updated date', 'warn', 'No machine-readable date found.', 'Show a visible "Updated" date and include dateModified in your schema.' );
		}

		// 11. Author.
		$has_author = '' !== self::meta( $html, 'author' ) || preg_match( '/"author"\s*:/', $html ) || preg_match( '#rel=["\']author["\']#i', $html );
		if ( $has_author ) {
			$this->add( $checks, 'trust', 'author', 'Named author', 'pass', 'An author is declared.' );
		} else {
			$this->add( $checks, 'trust', 'author', 'Named author', 'warn', 'No author found.', 'Name the author and link to a bio page; answer engines weigh who wrote it.' );
		}

		return $this->result( $url, $checks, $bots );
	}

	private function result( $url, $checks, $bots ) {
		$weights = array( 'pass' => 1, 'warn' => 0.5, 'fail' => 0 );
		$total   = 0;
		$got     = 0;
		foreach ( $checks as $c ) {
			if ( 'info' === $c['status'] ) {
				continue;
			}
			$w      = in_array( $c['id'], array( 'status', 'ai-search-bots', 'noindex', 'server-html' ), true ) ? 3 : 1;
			$total += $w;
			$got   += $w * $weights[ $c['status'] ];
		}
		$score = $total ? (int) round( 100 * $got / $total ) : 0;
		foreach ( $checks as $c ) {
			if ( 'fail' === $c['status'] && in_array( $c['id'], array( 'status', 'ai-search-bots', 'noindex' ), true ) ) {
				$score = min( $score, 40 );
			}
		}
		return array(
			'url'        => $url,
			'checked_at' => gmdate( 'c' ),
			'score'      => $score,
			'grade'      => $score >= 85 ? 'Ready for AI search' : ( $score >= 60 ? 'Partly ready' : 'Hard for AI to use' ),
			'checks'     => $checks,
			'crawlers'   => $bots,
		);
	}

	private static function clip( $s, $n = 80 ) {
		return strlen( $s ) > $n ? substr( $s, 0, $n - 1 ) . '…' : $s;
	}

	public static function meta( $html, $name, $attr = 'name' ) {
		$q = preg_quote( $name, '#' );
		if ( preg_match( '#<meta[^>]+' . $attr . '=["\']' . $q . '["\'][^>]*content=["\']([^"\']*)["\']#i', $html, $m ) ) {
			return trim( html_entity_decode( $m[1], ENT_QUOTES ) );
		}
		if ( preg_match( '#<meta[^>]+content=["\']([^"\']*)["\'][^>]*' . $attr . '=["\']' . $q . '["\']#i', $html, $m ) ) {
			return trim( html_entity_decode( $m[1], ENT_QUOTES ) );
		}
		return '';
	}

	private static function tag_text( $html, $tag ) {
		if ( preg_match( '#<' . $tag . '[^>]*>(.*?)</' . $tag . '>#is', $html, $m ) ) {
			return trim( html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES ) );
		}
		return '';
	}

	public static function visible_text( $html ) {
		$html = preg_replace( '#<(script|style|noscript|svg|template)[^>]*>.*?</\1>#is', ' ', $html );
		if ( preg_match( '#<body[^>]*>(.*)</body>#is', $html, $m ) ) {
			$html = $m[1];
		}
		return trim( preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( $html ), ENT_QUOTES ) ) );
	}

	public static function schema_types( $html ) {
		$types = array();
		if ( ! preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
			return $types;
		}
		foreach ( $m[1] as $json ) {
			$data = json_decode( trim( $json ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			array_walk_recursive(
				$data,
				function ( $v, $k ) use ( &$types ) {
					if ( '@type' === $k && is_string( $v ) ) {
						$types[] = $v;
					}
				}
			);
		}
		return $types;
	}
}
