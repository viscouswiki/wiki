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
		$parser->setHook( 'deadlockbuildindex', [ self::class, 'renderBuildIndex' ] );
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
			// Title the page after the build's own name rather than the numeric
			// id in the page name; the id stays visible in the build header/footer.
			$name = trim( (string)( $build['name'] ?? '' ) );
			if ( $name !== '' ) {
				$parser->getOutput()->setDisplayTitle( htmlspecialchars( $name ) );
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
	 * In category listings, show a build page (Builds/<id> or Builds/meta/<id>)
	 * by its human build name rather than the bare numeric page title. Falls
	 * through to the default link if the build name can't be resolved.
	 */
	public static function onCategoryViewerGenerateLink( $type, $title, $html, &$link ) {
		try {
			if ( $type !== 'page' || !( $title instanceof \Title )
				|| $title->getNamespace() !== NS_MAIN
				|| !preg_match( '#^Builds/(?:meta/)?(\d+)$#', $title->getText(), $m )
			) {
				return true;
			}
			$build = self::fetchBuild( $m[1] );
			$name = $build ? trim( (string)( $build['name'] ?? '' ) ) : '';
			if ( $name === '' ) {
				return true;
			}
			$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
			$link = $linkRenderer->makeKnownLink( $title, $name );
		} catch ( \Throwable $e ) {
			// Leave $link untouched so the default title-based link is used.
		}
		return true;
	}

	/**
	 * <deadlockbuildindex category="Builds"/> — a browse grid of the build
	 * pages in a category, shown by build name (with hero + tags) instead of
	 * the numeric page title. Reads names from the cached build API.
	 */
	public static function renderBuildIndex( $input, array $args, Parser $parser, PPFrame $frame ) {
		$parser->getOutput()->addModuleStyles( [ 'ext.deadlockBuilds' ] );
		$category = isset( $args['category'] ) ? trim( (string)$args['category'] ) : 'Builds';
		try {
			$members = self::categoryBuildIds( $category );
			if ( !$members ) {
				return self::notice( 'notice', 'No builds are listed in this category yet.' );
			}
			$heroes = self::assetMap( 'heroes' );
			$tags   = self::assetMap( 'build-tags' );

			// A meta build lives in both Category:Builds and Category:Meta builds
			// and shares its build id with the canonical Builds/<id> page, so the
			// same build can appear twice. Keep one entry per build id, preferring
			// the canonical (non-/meta/) page.
			$chosen = [];
			$hasMeta = [];
			foreach ( $members as $member ) {
				$id = $member['id'];
				$isMeta = strpos( $member['title']->getText(), '/meta/' ) !== false;
				if ( $isMeta ) {
					$hasMeta[$id] = true;
				}
				if ( !isset( $chosen[$id] ) || ( !$isMeta && $chosen[$id]['meta'] ) ) {
					$chosen[$id] = [ 'title' => $member['title'], 'id' => $id, 'meta' => $isMeta ];
				}
			}

			$entries = [];
			foreach ( $chosen as $member ) {
				$build = self::fetchBuild( $member['id'] );
				$name = $build ? trim( (string)( $build['name'] ?? '' ) ) : '';
				if ( $name === '' ) {
					$name = 'Build ' . $member['id'];
				}
				$entries[] = [
					'title'   => $member['title'],
					'id'      => $member['id'],
					'name'    => $name,
					'build'   => is_array( $build ) ? $build : [],
					'hasMeta' => !empty( $hasMeta[$member['id']] ),
				];
			}
			usort( $entries, static fn ( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );

			$cards = '';
			foreach ( $entries as $e ) {
				$cards .= self::buildIndexCard( $e, $heroes, $tags );
			}
			return '<div class="dlb-index">' . $cards . '</div>';
		} catch ( \Throwable $ex ) {
			return self::notice( 'error', 'Could not load the build index right now.' );
		}
	}

	/** Titles + build ids of the Builds/<id> pages in a category. */
	private static function categoryBuildIds( string $category ): array {
		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
		$cat = str_replace( ' ', '_', trim( $category ) );
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'page_namespace', 'page_title' ] )
			->from( 'categorylinks' )
			->join( 'page', null, 'cl_from=page_id' )
			->where( [ 'cl_to' => $cat, 'page_namespace' => NS_MAIN ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$out = [];
		foreach ( $res as $row ) {
			if ( preg_match( '#^Builds/(?:meta/)?(\d+)$#', $row->page_title, $m ) ) {
				$out[] = [
					'title' => \Title::makeTitle( (int)$row->page_namespace, $row->page_title ),
					'id'    => $m[1],
				];
			}
		}
		return $out;
	}

	/** One build tile in the browse index. */
	private static function buildIndexCard( array $e, array $heroes, array $tags ): string {
		$build  = $e['build'];
		$heroId = $build['hero_id'] ?? null;
		$hero   = ( $heroId !== null && isset( $heroes[$heroId] ) ) ? $heroes[$heroId] : null;
		$url    = $e['title']->getLocalURL();

		$cardCls = 'dlb-index-card' . ( !empty( $e['hasMeta'] ) ? ' dlb-index-card-meta' : '' );
		$h  = '<a class="' . $cardCls . '" href="' . htmlspecialchars( $url ) . '">';
		if ( !empty( $e['hasMeta'] ) ) {
			$h .= '<span class="dlb-index-star" title="Current meta build" aria-label="Current meta build">&#9733;</span>';
		}
		if ( $hero && !empty( $hero['image'] ) ) {
			$h .= '<img class="dlb-index-img" src="' . htmlspecialchars( $hero['image'] )
				. '" alt="" width="46" height="46" loading="lazy">';
		} else {
			$h .= '<span class="dlb-index-img dlb-index-img-blank"></span>';
		}
		$h .= '<span class="dlb-index-body">';
		$h .= '<span class="dlb-index-name">' . htmlspecialchars( $e['name'] ) . '</span>';
		$meta = $hero ? htmlspecialchars( $hero['name'] ) . ' · ' : '';
		$meta .= '#' . htmlspecialchars( $e['id'] );
		$h .= '<span class="dlb-index-meta">' . $meta . '</span>';

		$btags = is_array( $build['tags'] ?? null ) ? $build['tags'] : [];
		$tagHtml = '';
		foreach ( array_slice( $btags, 0, 3 ) as $tid ) {
			if ( isset( $tags[$tid]['name'] ) ) {
				$tagHtml .= '<span class="dlb-index-tag">' . htmlspecialchars( $tags[$tid]['name'] ) . '</span>';
			}
		}
		if ( $tagHtml !== '' ) {
			$h .= '<span class="dlb-index-tags">' . $tagHtml . '</span>';
		}
		$h .= '</span></a>';
		return $h;
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
		$h .= self::soulDefs();

		// Header: hero portrait + title + hero name.
		$h .= '<div class="dlb-head">';
		if ( $hero && !empty( $hero['image'] ) ) {
			$h .= '<img class="dlb-hero-img" src="' . htmlspecialchars( $hero['image'] )
				. '" alt="' . htmlspecialchars( $hero['name'] ) . '" width="48" height="48" loading="lazy">';
		}
		$h .= '<div class="dlb-head-text">';
		$h .= '<div class="dlb-title">' . htmlspecialchars( $name ) . '</div>';
		$h .= '<div class="dlb-hero">'
			. ( $hero ? htmlspecialchars( $hero['name'] ) . ' · ' : '' )
			. '<span class="dlb-buildid">Build #' . htmlspecialchars( $id ) . '</span>'
			. '</div>';
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
			$tileCount = 0;
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
				$tiles .= self::itemTile( $it, $mod['annotation'] ?? null );
				$tileCount++;
			}
			if ( $tiles === '' ) {
				continue;
			}
			$catNum++;
			$label = self::categoryLabel( (string)( $cat['name'] ?? '' ), $catNum );
			$catDesc = self::noteText( (string)( $cat['description'] ?? '' ) );
			$countLabel = $tileCount . ' item' . ( $tileCount === 1 ? '' : 's' );
			$catHtml .= '<div class="dlb-cat"><div class="dlb-cat-head">'
				. '<div class="dlb-cat-name">' . htmlspecialchars( $label ) . '</div>'
				. '<div class="dlb-cat-count">' . htmlspecialchars( $countLabel ) . '</div></div>'
				. ( $catDesc !== '' ? '<div class="dlb-cat-desc">' . htmlspecialchars( $catDesc ) . '</div>' : '' )
				. '<div class="dlb-grid">' . $tiles . '</div></div>';
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
			$slug = preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string)( $ab['name'] ?? '' ) ) );
			$slug = trim( (string)$slug, '-' );
			$abilClass = 'dlb-abil' . ( $slug !== '' ? ' dlb-abil-' . $slug : '' );
			$steps .= '<div class="' . htmlspecialchars( $abilClass ) . '" title="' . self::attr( 'Point ' . $step . ': ' . $ab['name'] ) . '">';
			if ( !empty( $ab['image'] ) ) {
				$steps .= '<img class="dlb-abil-icon" src="' . htmlspecialchars( $ab['image'] )
					. '" alt="' . htmlspecialchars( $ab['name'] ) . '" width="34" height="34" loading="lazy">';
			}
			$steps .= '<span class="dlb-abil-step">' . $step . '</span></div>';
		}
		if ( $steps === '' ) {
			return '';
		}
		$countLabel = $step . ' point' . ( $step === 1 ? '' : 's' );
		return '<div class="dlb-cat dlb-ability-order"><div class="dlb-cat-head">'
			. '<div class="dlb-cat-name">Ability Leveling Order</div>'
			. '<div class="dlb-cat-count">' . htmlspecialchars( $countLabel ) . '</div></div>'
			. '<div class="dlb-abil-seq">' . $steps . '</div></div>';
	}

	/**
	 * The in-game "souls" currency glyph, defined once per build as an SVG
	 * <symbol> so every cost can reference it with a tiny <use>. Traced from
	 * Deadlock's own gold.svg shop asset; recoloured via CSS `currentColor`.
	 */
	private static function soulDefs(): string {
		return '<svg class="dlb-svgdefs" width="0" height="0" aria-hidden="true" '
			. 'style="position:absolute;width:0;height:0;overflow:hidden">'
			. '<symbol id="dlb-soul" viewBox="0 0 128 128" fill="currentColor">'
			. '<path d="M64,10.6c29.4,0,53.4,23.9,53.4,53.4S93.4,117.4,64,117.4S10.6,93.4,10.6,64'
			. 'S34.6,10.6,64,10.6z M64,0C28.6,0,0,28.6,0,64s28.6,64,64,64s64-28.6,64-64S99.4,0,64,0z"/>'
			. '<path d="M91.4,79.9l-11.2-3c2.9-3.6,4.6-8,4.6-13c0-0.1,0-0.2,0-0.2l6.6-1.8c2.8-0.7,4.4-3.6,'
			. '3.7-6.4c-0.5-1.8-2-3.1-3.7-3.6V52l-13.8-3.8c-3.7-3.1-8.4-5.1-13.6-5.1c-1.4,0-2.7,0.2-4,0.4'
			. 'L58.1,43l33.4-9L88.7,24L36.6,38c-2.3,0.6-3.8,2.6-3.8,5c0,2.4,1.6,4.4,3.8,5l11.2,3c-2.9,3.6-'
			. '4.6,8.1-4.6,13c0,0.1,0,0.2,0,0.2l-6.6,1.8c-2.8,0.7-4.4,3.6-3.7,6.4c0.5,1.8,2,3.2,3.7,3.7v0.1'
			. 'l13.9,3.8C54.2,83,58.8,85,64,85c1.4,0,2.7-0.2,4-0.4l1.9,0.5l-33.4,9l2.7,10l52.2-14c2.3-0.6,'
			. '3.8-2.6,3.8-5S93.8,80.6,91.4,79.9z M64,45.3c6.9,0,12.8,3.8,16.1,9.3l-34.8,9.3C45.4,53.6,'
			. '53.7,45.3,64,45.3z M47.9,73.5l34.8-9.4C82.6,74.4,74.3,82.7,64,82.7C57.1,82.7,51.2,79,47.9,73.5z"/>'
			. '</symbol></svg>';
	}

	/** A single inline souls glyph that references the per-build <symbol>. */
	private static function soul(): string {
		return '<svg class="dlb-soul" aria-hidden="true"><use href="#dlb-soul"></use></svg>';
	}

	/** Format a soul cost with the leading currency glyph. */
	private static function costHtml( int $cost ): string {
		return self::soul() . '<span class="dlb-soul-n">' . number_format( $cost ) . '</span>';
	}

	/**
	 * Render one item as an icon tile with tier badge, cost and a rich hover
	 * card. $annotation is the build creator's per-item note, if any.
	 */
	private static function itemTile( array $it, ?string $annotation = null ): string {
		$slot   = self::slotClass( $it['slot'] ?? '' );
		$tier   = $it['tier'] ?? null;
		$cost   = $it['cost'] ?? null;
		$active = !empty( $it['active'] );
		$stats  = is_array( $it['stats'] ?? null ) ? $it['stats'] : [];
		$desc   = (string)( $it['desc'] ?? '' );
		$note   = self::noteText( (string)( $annotation ?? '' ) );

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
		if ( $note !== '' ) {
			$t .= '<span class="dlb-note" title="Build note — hover for details">&#9998;</span>';
		}
		$t .= '</div>';
		$t .= '<div class="dlb-tname">' . htmlspecialchars( $it['name'] ) . '</div>';
		if ( $cost !== null ) {
			$t .= '<div class="dlb-tcost">' . self::costHtml( (int)$cost ) . '</div>';
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
			$card .= '<div class="dlb-pop-cost">' . self::costHtml( (int)$cost ) . ' souls</div>';
		}
		if ( $note !== '' ) {
			$card .= '<div class="dlb-pop-note">' . htmlspecialchars( $note ) . '</div>';
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
		$h .= self::soulDefs();
		$h .= '<div class="dlb-pop-head">';
		if ( !empty( $it['image'] ) ) {
			$h .= '<img class="dlb-pop-icon dlb-slot-' . $slot . '" src="' . htmlspecialchars( $it['image'] )
				. '" alt="' . htmlspecialchars( $it['name'] ) . '" width="56" height="56" loading="lazy">';
		}
		$h .= '<div><div class="dlb-pop-name">' . htmlspecialchars( $it['name'] ) . '</div>'
			. '<div class="dlb-pop-meta">' . htmlspecialchars( implode( ' · ', $meta ) )
			. ( $cost !== null ? ' · ' . self::costHtml( (int)$cost ) . ' souls' : '' ) . '</div></div></div>';
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

	/** Clean a creator note/description; returns '' if empty or purely decorative. */
	private static function noteText( string $s ): string {
		$s = trim( $s );
		return ( $s !== '' && preg_match( '/\p{L}/u', $s ) ) ? $s : '';
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
			$cache->makeKey( 'deadlockbuilds-assets', $kind, 'v5' ),
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
				// The API ships each description as HTML with inline value spans.
				// Strip the tags, decode entities, and collapse the leftover
				// whitespace/newlines to single spaces so the text reads as one
				// clean sentence (loose newlines otherwise become <p> gaps).
				$text = html_entity_decode( strip_tags( (string)$dd[$k] ), ENT_QUOTES | ENT_HTML5 );
				$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
				if ( $text !== '' ) {
					$parts[] = $text;
				}
			}
		}
		return trim( implode( ' ', $parts ) );
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
