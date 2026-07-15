<?php
/**
 * DeadlockBuilds — <deadlockbuild id="..."/> parser tag.
 *
 * Renders a Deadlock build (hero portrait, ability leveling order, and the item
 * build order as an icon grid with rich hover cards) fetched from the
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

	/** Register the parser tags. */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setHook( 'deadlockbuild', [ self::class, 'renderTag' ] );
		$parser->setHook( 'deadlockitem', [ self::class, 'renderItemTagEntry' ] );
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

	/**
	 * Auto-create a build page when a logged-in user visits a missing
	 * Builds/<id> or Builds/meta/<id> title for a real Deadlock build. Guards:
	 * only real builds (API-validated), only registered users who can create,
	 * and it never resurrects a page that was deleted.
	 */
	public static function onShowMissingArticle( $article ) {
		try {
			$services = MediaWikiServices::getInstance();
			if ( !self::config( 'DeadlockBuildsAutocreate', true ) ) {
				return true;
			}
			if ( $services->getReadOnlyMode()->isReadOnly() ) {
				return true;
			}

			$title = $article->getTitle();
			if ( $title->getNamespace() !== NS_MAIN
				|| !preg_match( '#^Builds/(meta/)?(\d+)$#', $title->getText(), $m )
			) {
				return true;
			}
			$isMeta = $m[1] !== '';
			$buildId = $m[2];

			// Respect a prior deletion — don't resurrect what someone removed.
			if ( $title->isDeletedQuick() ) {
				return true;
			}

			$ctx = $article->getContext();
			$user = $ctx->getUser();
			// Only admins (or a group granted this right) may auto-create.
			if ( !$user->isAllowed( 'deadlock-autocreatebuild' ) ) {
				return true;
			}
			$pm = $services->getPermissionManager();
			if ( !$pm->userCan( 'edit', $user, $title ) || !$pm->userCan( 'create', $user, $title ) ) {
				return true;
			}

			// Only create a page for a build that actually exists. If the build
			// isn't in the community API, say so instead of a blank missing page.
			if ( !self::fetchBuild( $buildId ) ) {
				$out = $ctx->getOutput();
				$out->addModuleStyles( [ 'ext.deadlockBuilds' ] );
				$out->addHTML( self::notice( 'warn',
					'No Deadlock build with ID <strong>' . htmlspecialchars( $buildId )
					. '</strong> was found in the deadlock-api.com database, so this page was not '
					. 'auto-created. The build may be unpublished, too new to be indexed yet, or the '
					. 'ID may be incorrect. Double-check the build\'s share link.' ) );
				return true;
			}

			$sysUser = \User::newSystemUser( 'DeadlockBuilds', [ 'steal' => true ] ) ?: $user;
			$base = "<deadlockbuild id=\"$buildId\" />\n\n[[Category:Builds]]";

			$visitedCreated = false;
			if ( $isMeta ) {
				// Keep a permanent canonical page at Builds/<id>. Meta pages are
				// disposable as the game's meta shifts, but the build record stays.
				$canon = \Title::newFromText( "Builds/$buildId" );
				if ( $canon && !$canon->exists() && !$canon->isDeletedQuick() ) {
					self::createBuildPage( $services, $canon, $sysUser, $base );
				}
				$visitedCreated = self::createBuildPage(
					$services, $title, $sysUser, $base . "\n[[Category:Meta builds]]" );
			} else {
				// The visited title is itself the canonical Builds/<id> page.
				$visitedCreated = self::createBuildPage( $services, $title, $sysUser, $base );
			}

			if ( $visitedCreated ) {
				$ctx->getOutput()->redirect( $title->getFullURL() );
				return false; // suppress the default "no such page" output
			}
		} catch ( \Throwable $e ) {
			// Fall through to the normal missing-page behaviour.
		}
		return true;
	}

	/** Create a build page with the given wikitext; returns whether it saved. */
	private static function createBuildPage( $services, \Title $title, $performer, string $text ): bool {
		try {
			if ( $title->exists() ) {
				return false;
			}
			$page = $services->getWikiPageFactory()->newFromTitle( $title );
			$updater = $page->newPageUpdater( $performer );
			$updater->setContent(
				\MediaWiki\Revision\SlotRecord::MAIN,
				\ContentHandler::makeContent( $text, $title )
			);
			$updater->saveRevision(
				\CommentStoreComment::newUnsavedComment( 'Auto-create Deadlock build page' ),
				EDIT_NEW
			);
			return $updater->wasSuccessful();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * <deadlockitem id="123"/> or <deadlockitem name="Extra Spirit"/> — render a
	 * single item as a standalone card. Defaults to the page name so an item
	 * page can simply contain <deadlockitem/>.
	 */
	public static function renderItemTagEntry( $input, array $args, Parser $parser, PPFrame $frame ) {
		$parser->getOutput()->addModuleStyles( [ 'ext.deadlockBuilds' ] );
		try {
			$items = self::assetMap( 'items' );
			$it = null;
			if ( isset( $args['id'] ) && preg_replace( '/[^0-9]/', '', (string)$args['id'] ) !== '' ) {
				$id = preg_replace( '/[^0-9]/', '', (string)$args['id'] );
				$it = $items[$id] ?? null;
			} else {
				$name = isset( $args['name'] ) ? (string)$args['name'] : (string)$parser->getTitle()->getText();
				$id = self::nameIndex()[strtolower( trim( $name ) )] ?? null;
				$it = $id !== null ? ( $items[$id] ?? null ) : null;
			}
			if ( !$it ) {
				return self::notice( 'error', 'Deadlock item not found.' );
			}
			return self::renderItemCard( $it );
		} catch ( \Throwable $e ) {
			return self::notice( 'error', 'Could not load that Deadlock item right now.' );
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
			$descHtml = str_replace( "\n", '', nl2br( htmlspecialchars( mb_strimwidth( $desc, 0, 400, '…' ) ) ) );
			$h .= '<div class="dlb-desc">' . $descHtml . '</div>';
		}

		// Ability leveling order.
		$h .= self::renderAbilityOrder( $build, $items );

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
					continue;
				}
				$it = $items[$aid];
				if ( ( $it['type'] ?? '' ) === 'ability' ) {
					// A hero ability, not a shop item — belongs in the ability row.
					continue;
				}
				$tiles .= self::itemTile( $it );
			}
			if ( $tiles === '' ) {
				continue;
			}
			$catNum++;
			$label = self::categoryLabel( (string)( $cat['name'] ?? '' ), $catNum );
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

	/** Render the ability leveling order as an ordered row of ability icons. */
	private static function renderAbilityOrder( array $build, array $items ): string {
		$changes = $build['details']['ability_order']['currency_changes'] ?? null;
		if ( !is_array( $changes ) || !count( $changes ) ) {
			return '';
		}
		$steps = '';
		$step = 0;
		foreach ( $changes as $c ) {
			$aid = $c['ability_id'] ?? null;
			if ( $aid === null || !isset( $items[$aid] ) ) {
				continue;
			}
			$ab = $items[$aid];
			$step++;
			$steps .= '<div class="dlb-abil" title="' . self::attr( 'Point ' . $step . ': ' . $ab['name'] ) . '">';
			if ( !empty( $ab['image'] ) ) {
				$steps .= '<img class="dlb-abil-icon" src="' . htmlspecialchars( $ab['image'] )
					. '" alt="' . htmlspecialchars( $ab['name'] ) . '" width="34" height="34" loading="lazy">';
			}
			$steps .= '<span class="dlb-abil-step">' . $step . '</span></div>';
		}
		if ( $steps === '' ) {
			return '';
		}
		return '<div class="dlb-cat dlb-ability-order"><div class="dlb-cat-name">Ability Leveling Order</div>'
			. '<div class="dlb-abil-seq">' . $steps . '</div></div>';
	}

	/** Render one item as an icon tile with tier badge, cost and a rich hover card. */
	private static function itemTile( array $it ): string {
		$slot   = self::slotClass( $it['slot'] ?? '' );
		$tier   = $it['tier'] ?? null;
		$cost   = $it['cost'] ?? null;
		$active = !empty( $it['active'] );
		$stats  = is_array( $it['stats'] ?? null ) ? $it['stats'] : [];
		$desc   = (string)( $it['desc'] ?? '' );

		// Link the tile to the item's wiki page when the Item namespace exists.
		$url = self::itemPageUrl( (string)( $it['name'] ?? '' ) );
		$cls = 'dlb-tile dlb-slot-' . $slot . ( $active ? ' dlb-active' : '' );
		$t  = $url !== null
			? '<a class="' . $cls . '" href="' . htmlspecialchars( $url ) . '">'
			: '<div class="' . $cls . '">';

		// Visible tile: icon + tier/active badges + name + cost.
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

		// Hover card (shown via CSS on tile hover).
		$meta = [];
		if ( $tier !== null ) {
			$meta[] = 'Tier ' . $tier;
		}
		if ( $slot !== 'other' ) {
			$meta[] = ucfirst( $slot );
		}
		$meta[] = $active ? 'Active' : 'Passive';

		$card  = '<div class="dlb-pop">';
		$card .= '<div class="dlb-pop-head">';
		if ( !empty( $it['image'] ) ) {
			$card .= '<img class="dlb-pop-icon dlb-slot-' . $slot . '" src="' . htmlspecialchars( $it['image'] )
				. '" alt="" width="40" height="40" loading="lazy">';
		}
		$card .= '<div><div class="dlb-pop-name">' . htmlspecialchars( $it['name'] ) . '</div>'
			. '<div class="dlb-pop-meta">' . htmlspecialchars( implode( ' · ', $meta ) ) . '</div></div></div>';
		if ( $cost !== null ) {
			$card .= '<div class="dlb-pop-cost">' . number_format( (int)$cost ) . ' souls</div>';
		}
		$card .= self::statList( $stats );
		if ( $desc !== '' ) {
			$card .= '<div class="dlb-pop-desc">' . htmlspecialchars( mb_strimwidth( $desc, 0, 260, '…' ) ) . '</div>';
		}
		$card .= '</div>';

		$t .= $card . ( $url !== null ? '</a>' : '</div>' );
		return $t;
	}

	/** Render a single item as a standalone card (used by the <deadlockitem> tag). */
	private static function renderItemCard( array $it ): string {
		$slot   = self::slotClass( $it['slot'] ?? '' );
		$tier   = $it['tier'] ?? null;
		$cost   = $it['cost'] ?? null;
		$active = !empty( $it['active'] );
		$stats  = is_array( $it['stats'] ?? null ) ? $it['stats'] : [];
		$desc   = (string)( $it['desc'] ?? '' );

		$meta = [];
		if ( $tier !== null ) {
			$meta[] = 'Tier ' . $tier;
		}
		if ( $slot !== 'other' ) {
			$meta[] = ucfirst( $slot );
		}
		$meta[] = $active ? 'Active' : 'Passive';

		$h  = '<div class="deadlock-build dlb-item-card dlb-slot-' . $slot . '">';
		$h .= '<div class="dlb-pop-head">';
		if ( !empty( $it['image'] ) ) {
			$h .= '<img class="dlb-pop-icon dlb-slot-' . $slot . '" src="' . htmlspecialchars( $it['image'] )
				. '" alt="' . htmlspecialchars( $it['name'] ) . '" width="56" height="56" loading="lazy">';
		}
		$h .= '<div><div class="dlb-pop-name">' . htmlspecialchars( $it['name'] ) . '</div>'
			. '<div class="dlb-pop-meta">' . htmlspecialchars( implode( ' · ', $meta ) )
			. ( $cost !== null ? ' · ' . number_format( (int)$cost ) . ' souls' : '' ) . '</div></div></div>';
		$h .= self::statList( $stats );
		if ( $desc !== '' ) {
			$h .= '<div class="dlb-pop-desc">' . htmlspecialchars( $desc ) . '</div>';
		}
		$h .= '</div>';
		return $h;
	}

	/** Local URL of the wiki page for an item, or null if the Item namespace
	 * isn't configured. */
	private static function itemPageUrl( string $name ): ?string {
		$ns = defined( 'NS_ITEM' ) ? NS_ITEM : (int)self::config( 'DeadlockBuildsItemNamespace', 0 );
		if ( $ns <= 0 ) {
			return null;
		}
		$title = \Title::makeTitleSafe( $ns, $name );
		return $title ? $title->getLinkURL() : null;
	}

	/**
	 * Friendly label for an author's item-category name. Default game sections
	 * arrive as localisation tokens like "#Citadel_HeroBuilds_EarlyGame"; turn
	 * those into "Early Game". Custom names are used as-is.
	 */
	private static function categoryLabel( string $raw, int $n ): string {
		$s = trim( $raw );
		if ( $s === '' ) {
			return 'Group ' . $n;
		}
		if ( $s[0] === '#' ) {
			$s = preg_replace( '/^#(citadel_)?(herobuilds?_)?/i', '', $s );
			$s = str_replace( '_', ' ', $s );
			$s = preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', ' ', $s );
			$s = trim( $s );
			if ( $s === '' ) {
				return 'Group ' . $n;
			}
		}
		return $s;
	}

	/** Render a stat list with the label left and the value right-aligned. */
	private static function statList( array $stats ): string {
		if ( !$stats ) {
			return '';
		}
		$li = '';
		foreach ( $stats as $s ) {
			list( $label, $value ) = self::splitStat( (string)$s );
			$li .= '<li><span class="dlb-stat-label">' . htmlspecialchars( $label ) . '</span>'
				. ( $value !== '' ? '<span class="dlb-stat-val">' . htmlspecialchars( $value ) . '</span>' : '' )
				. '</li>';
		}
		return '<ul class="dlb-pop-stats">' . $li . '</ul>';
	}

	/** Split "Spirit Power +10" into [ "Spirit Power", "+10" ]. */
	private static function splitStat( string $s ): array {
		$pos = strrpos( $s, ' ' );
		if ( $pos !== false ) {
			$value = substr( $s, $pos + 1 );
			if ( $value !== '' && ( $value[0] === '+' || $value[0] === '-' ) ) {
				return [ substr( $s, 0, $pos ), $value ];
			}
		}
		return [ $s, '' ];
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
	 *   items      => id => {name, cost, slot, tier, active, image, stats[], desc, type}
	 *                 (includes shop items AND abilities from the same fetch)
	 *   heroes     => id => {name, image}
	 *   build-tags => id => {name}
	 */
	private static function assetMap( string $kind ): array {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$ttl = (int)self::config( 'DeadlockBuildsAssetTtl', 86400 );
		$value = $cache->getWithSetCallback(
			$cache->makeKey( 'deadlockbuilds-assets', $kind, 'v4' ),
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
						// Shop items use shop_image; abilities use image.
						$img = $row['shop_image_webp'] ?? $row['shop_image']
							?? $row['image_webp'] ?? $row['image'] ?? '';
						$map[$row['id']] = [
							'name'   => (string)( $row['name'] ?? ( '#' . $row['id'] ) ),
							'cost'   => $row['cost'] ?? null,
							'slot'   => (string)( $row['item_slot_type'] ?? '' ),
							'tier'   => $row['item_tier'] ?? null,
							'active' => !empty( $row['is_active_item'] ),
							'image'  => (string)$img,
							'stats'  => self::itemStats( $row ),
							'desc'   => self::itemDesc( $row ),
							'type'   => (string)( $row['type'] ?? '' ),
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

	/** Cached lowercase-name => id index for shoppable items (for <deadlockitem name=>). */
	private static function nameIndex(): array {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$ttl = (int)self::config( 'DeadlockBuildsAssetTtl', 86400 );
		$value = $cache->getWithSetCallback(
			$cache->makeKey( 'deadlockbuilds-nameindex', 'v4' ),
			$ttl,
			static function ( $old, &$setTtl ) {
				$items = self::assetMap( 'items' );
				if ( !$items ) {
					$setTtl = 60;
					return [];
				}
				$idx = [];
				foreach ( $items as $id => $it ) {
					if ( ( $it['type'] ?? '' ) === 'upgrade' && !empty( $it['name'] ) ) {
						$idx[strtolower( trim( $it['name'] ) )] = $id;
					}
				}
				return $idx;
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
				$suffix = '';
				if ( $bonus !== null && $bonus !== '' ) {
					$b = (string)$bonus;
					// Negative values keep their sign; positive get a leading +.
					$suffix = ' ' . ( $b[0] === '-' ? $b : '+' . $b );
				}
				$out[] = self::statLabel( (string)$name ) . $suffix;
			}
		}
		return $out;
	}

	/** Plain-text description from an item's structured description dict. */
	private static function itemDesc( array $row ): string {
		$dd = $row['description'] ?? null;
		if ( !is_array( $dd ) ) {
			return '';
		}
		$parts = [];
		foreach ( [ 'passive', 'active', 'innate' ] as $k ) {
			if ( !empty( $dd[$k] ) ) {
				$parts[] = trim( strip_tags( (string)$dd[$k] ) );
			}
		}
		return trim( implode( ' ', array_filter( $parts ) ) );
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
		// Fallback: de-camelCase and tidy ("BonusClipSizePercent" -> "Clip Size %").
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
