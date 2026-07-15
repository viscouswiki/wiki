<?php
/**
 * DeadlockBuilds — <deadlockbuild id="..."/> parser tag.
 *
 * Renders a Deadlock build (hero portrait + item build order as an icon grid
 * with tiers, costs and stat tooltips) fetched from the deadlock-api.com
 * community API. All network results are cached in the main WAN object cache so
 * page views don't hammer the API, and every failure mode degrades to a small
 * inline notice rather than a fatal — a broken build must never take the wiki
 * down.
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
		$hero    = ( $heroId !== null && isset( $heroes[$heroId] ) ) ? $heroes[$heroId] : null;
		$desc    = trim( (string)( $build['description'] ?? '' ) );
		$version = $build['version'] ?? null;

		$h  = '<div class="deadlock-build">';

		// Header: hero portrait + title + hero name.
		$h .= '<div class="dlb-head">';
		if ( $hero && !empty( $hero['image'] ) ) {
			$h .= '<img class="dlb-hero-img" src="' . htmlspecialchars( $hero['image'] )
				. '" alt="' . htmlspecialchars( $hero['name'] ) . '" width="48" height="48" loading="lazy">';
		}
		$h .= '<div class="dlb-head-text">';
		$h .= '<div class="dlb-title">' . htmlspecialchars( $name ) . '</div>';
		if ( $hero ) {
			$h .= '<div class="dlb-hero">' . htmlspecialchars( $hero['name'] ) . '</div>';
		}
		$h .= '</div></div>';

		// Tags.
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
			// Strip literal newlines (keep the <br>) so MediaWiki's block parser
			// doesn't wrap fragments of our output in <p> tags.
			$descHtml = str_replace( "\n", '', nl2br( htmlspecialchars( mb_strimwidth( $desc, 0, 400, '…' ) ) ) );
			$h .= '<div class="dlb-desc">' . $descHtml . '</div>';
		}

		// Item build order — the author's own categories, in order, as icon tiles.
		$cats = $build['details']['mod_categories'] ?? [];
		$catHtml = '';
		$catNum = 0;
		foreach ( $cats as $cat ) {
			$mods = is_array( $cat['mods'] ?? null ) ? $cat['mods'] : [];
			$tiles = '';
			foreach ( $mods as $mod ) {
				$aid = $mod['ability_id'] ?? null;
				if ( $aid === null || !isset( $items[$aid] ) ) {
					// Not a shop item (e.g. a hero ability point) — skip in v1.
					continue;
				}
				$tiles .= self::itemTile( $items[$aid] );
			}
			if ( $tiles === '' ) {
				continue;
			}
			$catNum++;
			$label = trim( (string)( $cat['name'] ?? '' ) );
			if ( $label === '' ) {
				$label = 'Group ' . $catNum;
			}
			$catHtml .= '<div class="dlb-cat"><div class="dlb-cat-name">' . htmlspecialchars( $label )
				. '</div><div class="dlb-grid">' . $tiles . '</div></div>';
		}

		if ( $catHtml !== '' ) {
			$h .= '<div class="dlb-build-order">' . $catHtml . '</div>';
		} else {
			$h .= self::notice( 'warn', 'No item data available for this build.' );
		}

		// Footer + legend.
		$apiBase = rtrim( self::config( 'DeadlockBuildsApiBase', self::DEFAULT_API ), '/' );
		$src = $apiBase . '/v1/builds?build_id=' . rawurlencode( $id );
		$h .= '<div class="dlb-foot">'
			. '<span class="dlb-legend"><span class="dlb-key dlb-slot-weapon"></span>Weapon '
			. '<span class="dlb-key dlb-slot-vitality"></span>Vitality '
			. '<span class="dlb-key dlb-slot-spirit"></span>Spirit</span>'
			. '<span class="dlb-src">'
			. ( $version !== null ? 'v' . htmlspecialchars( (string)$version ) . ' · ' : '' )
			. 'Data: <a href="' . htmlspecialchars( $src ) . '" rel="nofollow noopener" target="_blank">deadlock-api.com ' . htmlspecialchars( $id ) . '</a>'
			. '</span></div>';

		$h .= '</div>';
		return $h;
	}

	/** Render one item as an icon tile with tier badge, cost and a stat tooltip. */
	private static function itemTile( array $it ): string {
		$slot   = self::slotClass( $it['slot'] ?? '' );
		$tier   = $it['tier'] ?? null;
		$cost   = $it['cost'] ?? null;
		$active = !empty( $it['active'] );
		$stats  = is_array( $it['stats'] ?? null ) ? $it['stats'] : [];

		$tip = $it['name'];
		if ( $tier !== null ) {
			$tip .= "\nTier $tier" . ( $active ? ' · Active' : '' );
		}
		if ( $stats ) {
			$tip .= "\n" . implode( "\n", $stats );
		}

		$t  = '<div class="dlb-tile dlb-slot-' . $slot . ( $active ? ' dlb-active' : '' )
			. '" title="' . self::attr( $tip ) . '">';
		$t .= '<div class="dlb-icon-wrap">';
		if ( !empty( $it['image'] ) ) {
			$t .= '<img class="dlb-icon" src="' . htmlspecialchars( $it['image'] )
				. '" alt="' . htmlspecialchars( $it['name'] ) . '" width="46" height="46" loading="lazy">';
		}
		if ( $tier !== null ) {
			$t .= '<span class="dlb-tier">' . htmlspecialchars( (string)$tier ) . '</span>';
		}
		if ( $active ) {
			$t .= '<span class="dlb-act" title="Active item">A</span>';
		}
		$t .= '</div>';
		$t .= '<div class="dlb-tname">' . htmlspecialchars( $it['name'] ) . '</div>';
		if ( $cost !== null ) {
			$t .= '<div class="dlb-tcost">' . number_format( (int)$cost ) . '</div>';
		}
		$t .= '</div>';
		return $t;
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

	/** Escape a string for an HTML attribute, encoding newlines as &#10; so
	 * multi-line title tooltips survive without tripping the block parser. */
	private static function attr( string $text ): string {
		return str_replace( "\n", '&#10;', htmlspecialchars( $text ) );
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
	 * Build a trimmed id => data map for an asset kind, cached for a day:
	 *   items      => id => {name, cost, slot, tier, active, image, stats[]}
	 *   heroes     => id => {name, image}
	 *   build-tags => id => {name}
	 */
	private static function assetMap( string $kind ): array {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$ttl = (int)self::config( 'DeadlockBuildsAssetTtl', 86400 );
		$value = $cache->getWithSetCallback(
			$cache->makeKey( 'deadlockbuilds-assets', $kind, 'v3' ),
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
					if ( $kind === 'items' ) {
						$map[$row['id']] = [
							'name'   => (string)( $row['name'] ?? ( '#' . $row['id'] ) ),
							'cost'   => $row['cost'] ?? null,
							'slot'   => (string)( $row['item_slot_type'] ?? '' ),
							'tier'   => $row['item_tier'] ?? null,
							'active' => !empty( $row['is_active_item'] ),
							'image'  => (string)( $row['shop_image_webp'] ?? $row['shop_image'] ?? '' ),
							'stats'  => self::itemStats( $row ),
						];
					} elseif ( $kind === 'heroes' ) {
						$imgs = is_array( $row['images'] ?? null ) ? $row['images'] : [];
						$map[$row['id']] = [
							'name'  => (string)( $row['name'] ?? ( '#' . $row['id'] ) ),
							'image' => (string)( $imgs['icon_image_small_webp'] ?? $imgs['icon_image_small'] ?? '' ),
						];
					} else {
						// build-tags use "label" (e.g. "For New Players"), not "name".
						$map[$row['id']] = [ 'name' => (string)( $row['label'] ?? $row['name'] ?? ( '#' . $row['id'] ) ) ];
					}
				}
				return $map;
			}
		);
		return is_array( $value ) ? $value : [];
	}

	/** Extract "Label +bonus" stat strings from an item's upgrades. */
	private static function itemStats( array $row ): array {
		$out = [];
		foreach ( $row['upgrades'] ?? [] as $u ) {
			foreach ( $u['property_upgrades'] ?? [] as $p ) {
				$name = $p['name'] ?? null;
				if ( $name === null ) {
					continue;
				}
				$bonus = $p['bonus'] ?? null;
				$out[] = self::statLabel( (string)$name ) . ( $bonus !== null && $bonus !== '' ? ' +' . $bonus : '' );
			}
		}
		return $out;
	}

	/** Friendly label for a Deadlock stat property name. */
	private static function statLabel( string $name ): string {
		static $map = [
			'TechPower'              => 'Spirit Power',
			'TechRange'              => 'Spirit Range',
			'TechDuration'           => 'Spirit Duration',
			'TechCooldown'           => 'Cooldown Reduction',
			'BonusHealth'            => 'Bonus Health',
			'BonusHealthRegen'       => 'Health Regen',
			'BulletDamage'           => 'Weapon Damage',
			'BaseWeaponDamageIncrease' => 'Weapon Damage',
			'BulletResist'           => 'Bullet Resist',
			'SpiritResist'           => 'Spirit Resist',
			'BonusClipSizePercent'   => 'Ammo',
			'BonusFireRate'          => 'Fire Rate',
			'BonusMoveSpeed'         => 'Move Speed',
			'ProcBonusSpirit'        => 'Spirit Power',
		];
		if ( isset( $map[$name] ) ) {
			return $map[$name];
		}
		// Fallback: de-camelCase and tidy ("BonusClipSizePercent" -> "Bonus Clip Size %").
		$s = preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', ' ', $name );
		$s = str_replace( [ 'Percent', 'Bonus ' ], [ '%', '' ], $s );
		return trim( $s );
	}

	// ---- Helpers ---------------------------------------------------------

	private static function httpGet( string $url ) {
		$res = MediaWikiServices::getInstance()->getHttpRequestFactory()->get(
			$url,
			[ 'timeout' => 8, 'connectTimeout' => 3 ],
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
