<?php
/**
 * DeadlockBuilds — <deadlockbuild id="..."/> parser tag.
 *
 * Renders a Deadlock build (item build order + hero + tags) fetched from the
 * deadlock-api.com community API. All network results are cached in the main
 * WAN object cache so page views don't hammer the API, and every failure mode
 * degrades to a small inline notice rather than a fatal — a broken build must
 * never take the wiki down.
 */

namespace MediaWiki\Extension\DeadlockBuilds;

use MediaWiki\MediaWikiServices;
use Parser;
use PPFrame;

class Hooks {

	private const DEFAULT_API = 'https://api.deadlock-api.com';

	/** Register the <deadlockbuild> tag. */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setHook( 'deadlockbuild', [ self::class, 'renderTag' ] );
	}

	/**
	 * Tag handler. Output is raw HTML (placed in the parser strip state, so it
	 * is not re-parsed); every dynamic value is escaped with htmlspecialchars.
	 */
	public static function renderTag( $input, array $args, Parser $parser, PPFrame $frame ) {
		$parser->getOutput()->addModuleStyles( [ 'ext.deadlockBuilds' ] );

		$id = isset( $args['id'] ) ? preg_replace( '/[^0-9]/', '', (string)$args['id'] ) : '';
		if ( $id === '' ) {
			return self::notice( 'error', 'Missing build id — use <code>&lt;deadlockbuild id="583083" /&gt;</code>.' );
		}

		try {
			$build = self::fetchBuild( $id );
			if ( !$build ) {
				return self::notice( 'error', 'Deadlock build ' . htmlspecialchars( $id ) . ' could not be found.' );
			}
			return self::renderBuild( $id, $build );
		} catch ( \Throwable $e ) {
			return self::notice( 'error', 'Could not load Deadlock build ' . htmlspecialchars( $id ) . ' right now.' );
		}
	}

	// ---- Rendering -------------------------------------------------------

	private static function renderBuild( string $id, array $build ): string {
		$items  = self::assetMap( 'items' );
		$heroes = self::assetMap( 'heroes' );
		$tags   = self::assetMap( 'build-tags' );

		$name    = (string)( $build['name'] ?? "Build $id" );
		$heroId  = $build['hero_id'] ?? null;
		$hero    = ( $heroId !== null && isset( $heroes[$heroId] ) ) ? $heroes[$heroId]['name'] : null;
		$desc    = trim( (string)( $build['description'] ?? '' ) );
		$version = $build['version'] ?? null;

		$h  = '<div class="deadlock-build">';
		$h .= '<div class="dlb-head">';
		$h .= '<span class="dlb-title">' . htmlspecialchars( $name ) . '</span>';
		if ( $hero !== null ) {
			$h .= '<span class="dlb-hero">' . htmlspecialchars( $hero ) . '</span>';
		}
		$h .= '</div>';

		// Tags
		$btags = is_array( $build['tags'] ?? null ) ? $build['tags'] : [];
		$tagHtml = '';
		foreach ( $btags as $tid ) {
			if ( isset( $tags[$tid]['name'] ) ) {
				$tagHtml .= '<span class="dlb-tag">' . htmlspecialchars( $tags[$tid]['name'] ) . '</span>';
			}
		}
		if ( $tagHtml !== '' ) {
			$h .= '<div class="dlb-tags">' . $tagHtml . '</div>';
		}

		if ( $desc !== '' ) {
			$h .= '<div class="dlb-desc">' . nl2br( htmlspecialchars( mb_strimwidth( $desc, 0, 400, '…' ) ) ) . '</div>';
		}

		// Item build order — the author's own categories, in order.
		$cats = $build['details']['mod_categories'] ?? [];
		$catHtml = '';
		$catNum = 0;
		foreach ( $cats as $cat ) {
			$mods = is_array( $cat['mods'] ?? null ) ? $cat['mods'] : [];
			$itemsHtml = '';
			foreach ( $mods as $mod ) {
				$aid = $mod['ability_id'] ?? null;
				if ( $aid === null || !isset( $items[$aid] ) ) {
					// Not an item (e.g. a hero ability point) — skip in v1.
					continue;
				}
				$it   = $items[$aid];
				$slot = self::slotClass( $it['slot'] ?? '' );
				$cost = $it['cost'] ?? null;
				$itemsHtml .= '<li class="dlb-item dlb-slot-' . $slot . '">'
					. '<span class="dlb-item-name">' . htmlspecialchars( $it['name'] ) . '</span>'
					. ( $cost !== null ? '<span class="dlb-item-cost">' . number_format( (int)$cost ) . '</span>' : '' )
					. '</li>';
			}
			if ( $itemsHtml === '' ) {
				continue;
			}
			$catNum++;
			$label = trim( (string)( $cat['name'] ?? '' ) );
			if ( $label === '' ) {
				$label = 'Group ' . $catNum;
			}
			$catHtml .= '<div class="dlb-cat"><div class="dlb-cat-name">' . htmlspecialchars( $label )
				. '</div><ul class="dlb-items">' . $itemsHtml . '</ul></div>';
		}

		if ( $catHtml !== '' ) {
			$h .= '<div class="dlb-build-order">' . $catHtml . '</div>';
		} else {
			$h .= self::notice( 'warn', 'No item data available for this build.' );
		}

		$apiBase = self::config( 'DeadlockBuildsApiBase', self::DEFAULT_API );
		$src = rtrim( $apiBase, '/' ) . '/v1/builds?build_id=' . rawurlencode( $id );
		$h .= '<div class="dlb-foot">'
			. ( $version !== null ? 'v' . htmlspecialchars( (string)$version ) . ' · ' : '' )
			. 'Data: <a href="' . htmlspecialchars( $src ) . '" rel="nofollow noopener" target="_blank">deadlock-api.com build ' . htmlspecialchars( $id ) . '</a>'
			. '</div>';

		$h .= '</div>';
		return $h;
	}

	private static function slotClass( string $slot ): string {
		$slot = strtolower( $slot );
		if ( $slot === 'weapon' || $slot === 'vitality' || $slot === 'spirit' ) {
			return $slot;
		}
		return 'other';
	}

	private static function notice( string $kind, string $htmlMsg ): string {
		return '<div class="deadlock-build dlb-notice dlb-' . htmlspecialchars( $kind ) . '">' . $htmlMsg . '</div>';
	}

	// ---- Data access (cached) -------------------------------------------

	/** Fetch a single build's hero_build object (cached), or null. */
	private static function fetchBuild( string $id ) {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$ttl = (int)self::config( 'DeadlockBuildsBuildTtl', 3600 );
		$value = $cache->getWithSetCallback(
			$cache->makeKey( 'deadlockbuilds-build', $id ),
			$ttl,
			static function ( $old, &$setTtl ) use ( $id ) {
				$base = rtrim( self::config( 'DeadlockBuildsApiBase', self::DEFAULT_API ), '/' );
				$json = self::httpGet( $base . '/v1/builds?only_latest=true&build_id=' . rawurlencode( $id ) );
				$data = $json !== null ? json_decode( $json, true ) : null;
				if ( !is_array( $data ) || !count( $data ) || empty( $data[0]['hero_build'] ) ) {
					$setTtl = 60; // don't cache a miss/outage for long
					return [ 'ok' => false ];
				}
				return [ 'ok' => true, 'build' => $data[0]['hero_build'] ];
			}
		);
		return ( is_array( $value ) && ( $value['ok'] ?? false ) ) ? $value['build'] : null;
	}

	/**
	 * Build an id => {name, cost, slot} map for an asset kind
	 * ('items' | 'heroes' | 'build-tags'), cached for a day.
	 */
	private static function assetMap( string $kind ): array {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$ttl = (int)self::config( 'DeadlockBuildsAssetTtl', 86400 );
		$value = $cache->getWithSetCallback(
			$cache->makeKey( 'deadlockbuilds-assets', $kind ),
			$ttl,
			static function ( $old, &$setTtl ) use ( $kind ) {
				$base = rtrim( self::config( 'DeadlockBuildsApiBase', self::DEFAULT_API ), '/' );
				$json = self::httpGet( $base . '/v1/assets/' . $kind );
				$arr = $json !== null ? json_decode( $json, true ) : null;
				if ( !is_array( $arr ) ) {
					$setTtl = 60;
					return [];
				}
				$map = [];
				foreach ( $arr as $row ) {
					if ( !isset( $row['id'] ) ) {
						continue;
					}
					$map[$row['id']] = [
						'name' => (string)( $row['name'] ?? ( '#' . $row['id'] ) ),
						'cost' => $row['cost'] ?? null,
						'slot' => (string)( $row['item_slot_type'] ?? '' ),
					];
				}
				return $map;
			}
		);
		return is_array( $value ) ? $value : [];
	}

	// ---- Helpers ---------------------------------------------------------

	private static function httpGet( string $url ) {
		$res = MediaWikiServices::getInstance()->getHttpRequestFactory()->get(
			$url,
			[ 'timeout' => 5, 'connectTimeout' => 3 ],
			__METHOD__
		);
		return $res; // string on success, null on failure
	}

	private static function config( string $key, $default ) {
		try {
			$val = MediaWikiServices::getInstance()->getMainConfig()->get( $key );
			return $val !== null && $val !== '' ? $val : $default;
		} catch ( \Throwable $e ) {
			return $default;
		}
	}
}
