<?php
/**
 * Builds hubbub-ai-hub/assets/hub.css from tools/hub.src.css.
 *
 * Themes such as Recap style page content with selectors like
 * ".cs-main-content .entry-content ul:not(...)", which beat the plugin's own rules
 * (bulleted nav, theme link colours, small <mark>). The build prefixes every rule with
 * HUBBUB_SCOPE so the plugin's styles win inside its own pages and nowhere else.
 *
 * Edit tools/hub.src.css, then run: php tools/build-css.php
 * tests/test.php fails if the built file is out of date.
 */

const HUBBUB_SCOPE = 'html body .hubbub-hub.hubbub-hub.hb-app';

function hubbub_scope_selector( $sel ) {
	$sel = trim( $sel );
	if ( '' === $sel ) {
		return $sel;
	}
	if ( 0 === strpos( $sel, '.hubbub-hub' ) && ! preg_match( '/^\.hubbub-hub[\w-]/', $sel ) ) {
		return HUBBUB_SCOPE . substr( $sel, strlen( '.hubbub-hub' ) );
	}
	return HUBBUB_SCOPE . ' ' . $sel;
}

function hubbub_split_selectors( $list ) {
	$out   = array();
	$depth = 0;
	$cur   = '';
	foreach ( str_split( $list ) as $ch ) {
		if ( '(' === $ch || '[' === $ch ) {
			$depth++;
		} elseif ( ')' === $ch || ']' === $ch ) {
			$depth--;
		}
		if ( ',' === $ch && 0 === $depth ) {
			$out[] = $cur;
			$cur   = '';
			continue;
		}
		$cur .= $ch;
	}
	$out[] = $cur;
	return $out;
}

/** Index of the "}" that closes the block opening at $open. */
function hubbub_block_end( $css, $open ) {
	$depth = 0;
	$n     = strlen( $css );
	for ( $i = $open; $i < $n; $i++ ) {
		if ( '/' === $css[ $i ] && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
			$i = strpos( $css, '*/', $i + 2 ) + 1;
			continue;
		}
		if ( '"' === $css[ $i ] || "'" === $css[ $i ] ) {
			$i = strpos( $css, $css[ $i ], $i + 1 );
			continue;
		}
		if ( '{' === $css[ $i ] ) {
			$depth++;
		} elseif ( '}' === $css[ $i ] && 0 === --$depth ) {
			return $i;
		}
	}
	throw new RuntimeException( 'Unbalanced braces in CSS' );
}

function hubbub_scope_css( $css ) {
	$out = '';
	$i   = 0;
	$n   = strlen( $css );
	while ( $i < $n ) {
		if ( preg_match( '/\G(\s+|\/\*.*?\*\/)/s', $css, $m, 0, $i ) ) {
			$out .= $m[0];
			$i   += strlen( $m[0] );
			continue;
		}
		$brace = strpos( $css, '{', $i );
		$semi  = strpos( $css, ';', $i );
		if ( false !== $semi && ( false === $brace || $semi < $brace ) ) {
			$out .= substr( $css, $i, $semi - $i + 1 );
			$i    = $semi + 1;
			continue;
		}
		if ( false === $brace ) {
			$out .= substr( $css, $i );
			break;
		}
		$prelude = substr( $css, $i, $brace - $i );
		$end     = hubbub_block_end( $css, $brace );
		$body    = substr( $css, $brace + 1, $end - $brace - 1 );
		$p       = trim( $prelude );
		if ( 0 === strpos( $p, '@media' ) || 0 === strpos( $p, '@supports' ) || 0 === strpos( $p, '@container' ) ) {
			$out .= $prelude . '{' . hubbub_scope_css( $body ) . '}';
		} elseif ( '@' === ( $p[0] ?? '' ) ) {
			$out .= $prelude . '{' . $body . '}';
		} else {
			$lead = substr( $prelude, 0, strlen( $prelude ) - strlen( ltrim( $prelude ) ) );
			$out .= $lead . implode( ', ', array_map( 'hubbub_scope_selector', hubbub_split_selectors( $p ) ) ) . ' {' . $body . '}';
		}
		$i = $end + 1;
	}
	return $out;
}

function hubbub_build_css() {
	$src = file_get_contents( __DIR__ . '/hub.src.css' );
	return "/* Built from tools/hub.src.css by tools/build-css.php. Do not edit by hand. */\n" . hubbub_scope_css( $src );
}

if ( PHP_SAPI === 'cli' && realpath( $argv[0] ?? '' ) === __FILE__ ) {
	file_put_contents( __DIR__ . '/../hubbub-ai-hub/assets/hub.css', hubbub_build_css() );
	echo "Built hubbub-ai-hub/assets/hub.css\n";
}
