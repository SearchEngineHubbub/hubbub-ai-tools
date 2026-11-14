<?php
/**
 * Self-updater: checks the plugin's GitHub releases and lets WordPress install new versions
 * (and auto-update them) like any plugin from WordPress.org.
 *
 * Each release must carry an asset named hubbub-ai-hub.zip with a top-level hubbub-ai-hub/ folder.
 * For a private repository, define HUBBUB_AI_HUB_GITHUB_TOKEN in wp-config.php (a read-only token).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hubbub_AI_Hub_Updater {

	const REPO      = 'SearchEngineHubbub/hubbub-ai-tools';
	const ASSET     = 'hubbub-ai-hub.zip';
	const CACHE_KEY = 'hubbub_ai_hub_release';

	private static $basename;

	public static function init( $plugin_file ) {
		self::$basename = plugin_basename( $plugin_file );
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'http_request_args', array( __CLASS__, 'auth_download' ), 10, 2 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
	}

	private static function token() {
		return defined( 'HUBBUB_AI_HUB_GITHUB_TOKEN' ) ? (string) HUBBUB_AI_HUB_GITHUB_TOKEN : '';
	}

	/** @return array|null { version, package, notes, url, published } */
	public static function latest() {
		// Dashboard > Updates > "Check again" adds force-check=1: skip the cache so a new release shows at once.
		$forced = is_admin() && ! empty( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$cached = $forced ? false : get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached['version'] ? $cached : null;
		}
		$headers = array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'hubbub-ai-hub-updater' );
		if ( self::token() ) {
			$headers['Authorization'] = 'Bearer ' . self::token();
		}
		$res  = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', array( 'headers' => $headers, 'timeout' => 10 ) );
		$out  = array( 'version' => '' );
		$body = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		if ( is_array( $body ) && ! empty( $body['tag_name'] ) && ! empty( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( self::ASSET === $asset['name'] ) {
					$out = array(
						'version'   => ltrim( $body['tag_name'], 'vV' ),
						'package'   => $asset['url'],
						'notes'     => (string) $body['body'],
						'url'       => $body['html_url'],
						'published' => $body['published_at'],
					);
				}
			}
		}
		// Cache for an hour so GitHub is not asked on every admin page load.
		set_site_transient( self::CACHE_KEY, $out, HOUR_IN_SECONDS );
		return $out['version'] ? $out : null;
	}

	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		$rel  = self::latest();
		$item = (object) array(
			'id'          => self::REPO,
			'slug'        => 'hubbub-ai-hub',
			'plugin'      => self::$basename,
			'new_version' => $rel ? $rel['version'] : HUBBUB_AI_HUB_VERSION,
			'url'         => 'https://searchenginehubbub.com/tools/',
			'package'     => $rel ? $rel['package'] : '',
		);
		if ( $rel && version_compare( $rel['version'], HUBBUB_AI_HUB_VERSION, '>' ) ) {
			$transient->response[ self::$basename ] = $item;
		} else {
			// Listing it under no_update makes the auto-update toggle appear on the Plugins screen.
			$transient->no_update[ self::$basename ] = $item;
		}
		return $transient;
	}

	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'hubbub-ai-hub' !== $args->slug ) {
			return $result;
		}
		$rel = self::latest();
		return (object) array(
			'name'          => 'Hubbub AI Hub',
			'slug'          => 'hubbub-ai-hub',
			'version'       => $rel ? $rel['version'] : HUBBUB_AI_HUB_VERSION,
			'author'        => 'Search Engine Hubbub',
			'homepage'      => 'https://searchenginehubbub.com/tools/',
			'last_updated'  => $rel ? $rel['published'] : '',
			'download_link' => $rel ? $rel['package'] : '',
			'sections'      => array( 'changelog' => $rel ? wpautop( esc_html( $rel['notes'] ) ) : '' ),
		);
	}

	/** GitHub release assets download through the API with an octet-stream Accept header. */
	public static function auth_download( $args, $url ) {
		if ( 0 !== strpos( $url, 'https://api.github.com/repos/' . self::REPO . '/releases/assets/' ) ) {
			return $args;
		}
		$args['headers']           = isset( $args['headers'] ) ? (array) $args['headers'] : array();
		$args['headers']['Accept'] = 'application/octet-stream';
		$args['headers']['User-Agent'] = 'hubbub-ai-hub-updater';
		if ( self::token() ) {
			$args['headers']['Authorization'] = 'Bearer ' . self::token();
		}
		return $args;
	}

	/** Always auto-update this plugin: releases come only from its own repository. */
	public static function auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && self::$basename === $item->plugin ) {
			return true;
		}
		return $update;
	}

	public static function flush() {
		delete_site_transient( self::CACHE_KEY );
	}
}
