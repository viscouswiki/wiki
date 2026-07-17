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
		$parser->setHook( 'deadlockitemindex', [ self::class, 'renderItemIndex' ] );
		$parser->setHook( 'deadlockitemrow', [ self::class, 'renderItemRow' ] );
		$parser->setHook( 'deadlockability', [ self::class, 'renderAbilityTagEntry' ] );
		$parser->setHook( 'deadlockabilityindex', [ self::class, 'renderAbilityIndex' ] );
		$parser->setHook( 'deadlockrecent', [ self::class, 'renderRecent' ] );
		$parser->setHook( 'deadlocksearch', [ self::class, 'renderSearch' ] );
	}

	/**
	 * Tag handler. Output is raw HTML (placed in the parser strip state, so it
	 * is not re-parsed); every dynamic value is escaped with htmlspecialchars.
	 */
	public static function renderTag( $input, array $args, Parser $parser, PPFrame $frame ) {
		$parser->getOutput()->addModuleStyles( [ 'ext.deadlockBuilds' ] );
		$parser->getOutput()->addModules( [ 'ext.deadlockBuilds.share' ] );

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

			// A meta page is disposable: visiting Builds/meta/<id> again should
			// re-add it as a meta build even after it was deleted. The permanent
			// canonical Builds/<id> page, by contrast, still respects deletion so
			// a build can be removed for good.
			if ( !$isMeta && $title->isDeletedQuick() ) {
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
	 * When a build page (Builds/<id> or Builds/meta/<id>) is created, edited or
	 * deleted, purge the pages that embed a <deadlockbuildindex> so the browse
	 * grids reflect the change immediately rather than serving a stale cache.
	 */
	public static function onPageDeleteComplete( $page, $deleter, $reason, $pageID, $deletedRev, $logEntry, $archivedRevisionCount ) {
		self::purgeBuildIndexHosts( \Title::castFromPageIdentity( $page ) );
	}

	public static function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		self::purgeBuildIndexHosts( $wikiPage->getTitle() );
	}

	/** Purge the known <deadlockbuildindex> host pages for a build-page change. */
	private static function purgeBuildIndexHosts( $changed ): void {
		try {
			if ( !$changed || $changed->getNamespace() !== NS_MAIN
				|| !preg_match( '#^Builds/(?:meta/)?\d+$#', $changed->getText() )
			) {
				return;
			}
			$wpf = MediaWikiServices::getInstance()->getWikiPageFactory();
			$hosts = [
				\Title::newMainPage(),
				\Title::makeTitle( NS_CATEGORY, 'Builds' ),
				\Title::makeTitle( NS_CATEGORY, 'Meta builds' ),
			];
			foreach ( $hosts as $host ) {
				if ( $host && $host->exists() ) {
					$wpf->newFromTitle( $host )->doPurge();
				}
			}
		} catch ( \Throwable $e ) {
			// A failed purge must never break a save or delete.
		}
	}

	/**
	 * Add OpenGraph / Twitter-card meta so shared links (Discord, Twitter, etc.)
	 * get a rich preview. Build and item pages use their real name, description,
	 * and art; everything else falls back to the wiki title + logo.
	 */
	public static function onBeforePageDisplay( $out, $skin ): void {
		try {
			$title = $out->getTitle();
			if ( !$title ) {
				return;
			}
			$ogType  = 'website';
			$ogTitle = $title->getPrefixedText();
			$ogDesc  = "The community guide to Deadlock's hero Viscous — abilities, "
				. "item builds, and the current meta.";
			$ogImage = 'https://media.viscous.wiki/branding/viscous-logo.png';

			if ( $title->getNamespace() === NS_MAIN
				&& preg_match( '#^Builds/(?:meta/)?(\d+)$#', $title->getText(), $m )
			) {
				$b = self::fetchBuild( $m[1] );
				if ( $b ) {
					$name = trim( (string)( $b['name'] ?? '' ) );
					if ( $name !== '' ) {
						$ogTitle = $name;
					}
					$d = trim( (string)( $b['description'] ?? '' ) );
					$ogDesc = $d !== ''
						? mb_strimwidth( preg_replace( '/\s+/', ' ', $d ), 0, 200, '…' )
						: 'A Viscous build for Deadlock — items, ability order, and playstyle.';
					$heroes = self::assetMap( 'heroes' );
					$hid = $b['hero_id'] ?? null;
					if ( $hid !== null && !empty( $heroes[$hid]['image'] ) ) {
						$ogImage = $heroes[$hid]['image'];
					}
					$ogType = 'article';
				}
			} elseif ( defined( 'NS_ITEM' ) && $title->getNamespace() === NS_ITEM ) {
				$items = self::assetMap( 'items' );
				$id = self::nameIndex()[strtolower( trim( $title->getText() ) )] ?? null;
				$it = $id !== null ? ( $items[$id] ?? null ) : null;
				if ( $it ) {
					$ogTitle = (string)$it['name'];
					$d = trim( (string)( $it['desc'] ?? '' ) );
					$ogDesc = $d !== ''
						? mb_strimwidth( preg_replace( '/\s+/', ' ', $d ), 0, 200, '…' )
						: 'A Deadlock item for Viscous.';
					if ( !empty( $it['image'] ) ) {
						$ogImage = (string)$it['image'];
					}
					$ogType = 'article';
				}
			}

			$tags = [
				'og:title'       => $ogTitle,
				'og:description' => $ogDesc,
				'og:image'       => $ogImage,
				'og:type'        => $ogType,
				'og:url'         => $title->getFullURL(),
				'og:site_name'   => 'The Viscous Wiki',
				'twitter:card'   => 'summary_large_image',
				'twitter:title'  => $ogTitle,
				'twitter:description' => $ogDesc,
				'twitter:image'  => $ogImage,
			];
			foreach ( $tags as $prop => $content ) {
				$attr = str_starts_with( $prop, 'twitter:' ) ? 'name' : 'property';
				$out->addHeadItem( 'dlb-meta-' . $prop,
					'<meta ' . $attr . '="' . htmlspecialchars( $prop, ENT_QUOTES )
					. '" content="' . htmlspecialchars( (string)$content, ENT_QUOTES ) . '">' );
			}
		} catch ( \Throwable $e ) {
			// Never let preview metadata break page rendering.
		}
	}

	/**
	 * <deadlockbuildindex category="Builds"/> — a browse grid of the build
	 * pages in a category, shown by build name (with hero + tags) instead of
	 * the numeric page title. Reads names from the cached build API.
	 */
	public static function renderBuildIndex( $input, array $args, Parser $parser, PPFrame $frame ) {
		$parser->getOutput()->addModuleStyles( [ 'ext.deadlockBuilds' ] );
		$category = isset( $args['category'] ) ? trim( (string)$args['category'] ) : 'Builds';
		$limit = isset( $args['limit'] ) ? max( 0, (int)$args['limit'] ) : 0;
		try {
			$members = self::categoryBuildIds( $category );
			if ( !$members ) {
				return self::notice( 'notice', 'No builds are listed in this category yet.' );
			}

			// Keep the index fresh as builds enter/leave the category without a
			// manual purge: register each listed page as a link so deleting or
			// editing one purges this page through the backlink cache, and cap
			// the parser-cache lifetime so newly added builds appear on their own.
			$out = $parser->getOutput();
			$out->updateCacheExpiry( 900 );
			foreach ( $members as $member ) {
				$out->addLink( $member['title'] );
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
			$total = count( $entries );
			$order = self::parseOrderArg( $args['order'] ?? '' );
			if ( $order ) {
				// Manual order: build ids listed in `order` come first, in that
				// sequence; anything unlisted follows alphabetically.
				$last = count( $order );
				usort( $entries, static function ( $a, $b ) use ( $order, $last ) {
					$pa = $order[$a['id']] ?? $last;
					$pb = $order[$b['id']] ?? $last;
					return ( $pa <=> $pb ) ?: strcasecmp( $a['name'], $b['name'] );
				} );
			} elseif ( $limit > 0 ) {
				// Limited views (e.g. the home page) default to the freshest
				// builds first — a higher page id is a more recently created page.
				usort( $entries, static fn ( $a, $b ) =>
					$b['title']->getArticleID() <=> $a['title']->getArticleID() );
			} else {
				usort( $entries, static fn ( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
			}
			if ( $limit > 0 ) {
				$entries = array_slice( $entries, 0, $limit );
			}

			$cards = '';
			foreach ( $entries as $e ) {
				$cards .= self::buildIndexCard( $e, $heroes, $tags );
			}
			$more = '';
			if ( $limit > 0 && $total > $limit ) {
				$catTitle = \Title::makeTitle( NS_CATEGORY, $category );
				$more = '<a class="dlb-index-more" href="' . htmlspecialchars( $catTitle->getLocalURL() )
					. '">View all ' . $total . ' builds &rarr;</a>';
			}
			return '<div class="dlb-index">' . $cards . '</div>' . $more;
		} catch ( \Throwable $ex ) {
			return self::notice( 'error', 'Could not load the build index right now.' );
		}
	}

	/** Parse an `order="583083, 1102, ..."` attribute into id => position. */
	private static function parseOrderArg( string $raw ): array {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return [];
		}
		$order = [];
		$pos = 0;
		foreach ( preg_split( '/[\s,]+/', $raw ) as $tok ) {
			$id = preg_replace( '/\D/', '', (string)$tok );
			if ( $id !== '' && !isset( $order[$id] ) ) {
				$order[$id] = $pos++;
			}
		}
		return $order;
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
		if ( isset( $build['num_favorites'] ) && $build['num_favorites'] !== null ) {
			$meta .= ' · ' . self::favHtml( (int)$build['num_favorites'] );
		}
		$h .= '<span class="dlb-index-meta">' . $meta . '</span>';

		$btags = is_array( $build['tags'] ?? null ) ? $build['tags'] : [];
		$tagHtml = '';
		if ( !empty( $build['num_weekly_favorites'] ) ) {
			$tagHtml .= '<span class="dlb-index-tag dlb-trending" title="Popular this week">&#128293; Trending</span>';
		}
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

	/** Compact human count: 431 -> "431", 12300 -> "12.3k", 1500000 -> "1.5M". */
	private static function fmtCount( int $n ): string {
		if ( $n >= 1000000 ) {
			return rtrim( rtrim( number_format( $n / 1000000, 1 ), '0' ), '.' ) . 'M';
		}
		if ( $n >= 1000 ) {
			return rtrim( rtrim( number_format( $n / 1000, 1 ), '0' ), '.' ) . 'k';
		}
		return (string)$n;
	}

	/** A heart + favorite-count badge. */
	private static function favHtml( int $n ): string {
		return '<span class="dlb-fav" title="' . number_format( $n ) . ' favorites">'
			. '<span class="dlb-fav-heart">&#9829;</span>' . self::fmtCount( $n ) . '</span>';
	}

	/**
	 * <deadlockitemindex/> — a browsable gallery of every shop item, grouped by
	 * slot (Weapon / Vitality / Spirit) and sorted by tier, cost, then name.
	 * Optional slot="weapon|vitality|spirit" limits it to one column type.
	 */
	public static function renderItemIndex( $input, array $args, Parser $parser, PPFrame $frame ) {
		$parser->getOutput()->addModuleStyles( [ 'ext.deadlockBuilds' ] );
		try {
			$items = self::assetMap( 'items' );
			$labels = [ 'weapon' => 'Weapon', 'vitality' => 'Vitality', 'spirit' => 'Spirit' ];
			$wantSlot = isset( $args['slot'] ) ? strtolower( trim( (string)$args['slot'] ) ) : '';
			$groups = [ 'weapon' => [], 'vitality' => [], 'spirit' => [] ];
			foreach ( $items as $it ) {
				$slot = strtolower( (string)( $it['slot'] ?? '' ) );
				if ( !isset( $groups[$slot] ) ) {
					continue; // abilities and non-shop entries
				}
				if ( $wantSlot !== '' && $slot !== $wantSlot ) {
					continue;
				}
				$groups[$slot][] = $it;
			}
			$out = '<div class="deadlock-build dlb-itemindex">' . self::soulDefs();
			$any = false;
			foreach ( $labels as $slotKey => $slotLabel ) {
				$list = $groups[$slotKey];
				if ( !$list ) {
					continue;
				}
				$any = true;
				usort( $list, static function ( $a, $b ) {
					$ta = (int)( $a['tier'] ?? 0 );
					$tb = (int)( $b['tier'] ?? 0 );
					if ( $ta !== $tb ) {
						return $ta <=> $tb;
					}
					$ca = (int)( $a['cost'] ?? 0 );
					$cb = (int)( $b['cost'] ?? 0 );
					return ( $ca <=> $cb ) ?: strcasecmp( (string)$a['name'], (string)$b['name'] );
				} );
				$tiles = '';
				foreach ( $list as $it ) {
					$tiles .= self::itemGalleryTile( $it );
				}
				$out .= '<div class="dlb-cat dlb-slot-group dlb-slot-' . $slotKey . '">'
					. '<div class="dlb-cat-head"><div class="dlb-cat-name">' . htmlspecialchars( $slotLabel )
					. '</div><div class="dlb-cat-count">' . count( $list ) . ' items</div></div>'
					. '<div class="dlb-grid">' . $tiles . '</div></div>';
			}
			$out .= '</div>';
			return $any ? $out : self::notice( 'notice', 'No items are available right now.' );
		} catch ( \Throwable $e ) {
			return self::notice( 'error', 'Could not load the item list right now.' );
		}
	}

	/** A compact gallery tile (icon + name + cost) linking to the item's page. */
	private static function itemGalleryTile( array $it ): string {
		$slot   = self::slotClass( $it['slot'] ?? '' );
		$tier   = $it['tier'] ?? null;
		$cost   = $it['cost'] ?? null;
		$active = !empty( $it['active'] );
		$url    = self::itemPageUrl( (string)( $it['name'] ?? '' ) );
		$cls    = 'dlb-tile dlb-slot-' . $slot . ( $active ? ' dlb-active' : '' );
		$t = $url !== null
			? '<a class="' . $cls . '" href="' . htmlspecialchars( $url ) . '">'
			: '<div class="' . $cls . '">';
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
			$t .= '<div class="dlb-tcost">' . self::costHtml( (int)$cost ) . '</div>';
		}
		$t .= $url !== null ? '</a>' : '</div>';
		return $t;
	}

	/**
	 * <deadlockrecent limit="6"/> — a compact list of the most recently edited
	 * content pages (main, Item and Category namespaces) with relative times.
	 */
	public static function renderRecent( $input, array $args, Parser $parser, PPFrame $frame ) {
		$out = $parser->getOutput();
		$out->addModuleStyles( [ 'ext.deadlockBuilds' ] );
		$out->updateCacheExpiry( 300 );
		$limit = isset( $args['limit'] ) ? max( 1, min( 20, (int)$args['limit'] ) ) : 6;
		try {
			$services = MediaWikiServices::getInstance();
			$dbr = $services->getConnectionProvider()->getReplicaDatabase();
			$ns = [ NS_MAIN ];
			if ( defined( 'NS_ITEM' ) ) {
				$ns[] = NS_ITEM;
			}
			$ns[] = NS_CATEGORY;
			$res = $dbr->newSelectQueryBuilder()
				->select( [ 'rc_namespace', 'rc_title', 'rc_timestamp', 'rc_this_oldid', 'rc_new' ] )
				->distinct()
				->from( 'recentchanges' )
				->where( [
					'rc_namespace' => $ns,
					'rc_type' => [ RC_EDIT, RC_NEW ],
					$dbr->expr( 'rc_bot', '=', 0 ),
				] )
				->orderBy( 'rc_timestamp', 'DESC' )
				->limit( 60 )
				->caller( __METHOD__ )
				->fetchResultSet();

			$lang = $services->getContentLanguage();
			$seen = [];
			$rows = '';
			$count = 0;
			foreach ( $res as $r ) {
				$title = \Title::makeTitle( (int)$r->rc_namespace, $r->rc_title );
				$key = $title->getPrefixedDBkey();
				if ( isset( $seen[$key] ) || !$title->exists() ) {
					continue;
				}
				$seen[$key] = true;
				$label = $title->getText();
				// Prefer a build's display name over the numeric page title.
				if ( $title->getNamespace() === NS_MAIN
					&& preg_match( '#^Builds/(?:meta/)?(\d+)$#', $title->getText(), $m )
				) {
					$b = self::fetchBuild( $m[1] );
					if ( $b && trim( (string)( $b['name'] ?? '' ) ) !== '' ) {
						$label = trim( (string)$b['name'] );
					}
				}
				$when = $lang->getHumanTimestamp(
					\MWTimestamp::getInstance( $r->rc_timestamp ) );
				$rows .= '<li class="dlb-recent-item"><a class="dlb-recent-link" href="'
					. htmlspecialchars( $title->getLocalURL() ) . '">' . htmlspecialchars( $label ) . '</a>'
					. ( $r->rc_new ? '<span class="dlb-recent-new">new</span>' : '' )
					. '<span class="dlb-recent-when">' . htmlspecialchars( $when ) . '</span></li>';
				$out->addLink( $title );
				if ( ++$count >= $limit ) {
					break;
				}
			}
			if ( $rows === '' ) {
				return '';
			}
			return '<ul class="dlb-recent">' . $rows . '</ul>';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * <deadlocksearch placeholder="..."/> — a styled search box that submits to
	 * Special:Search. Output is raw HTML (a real <form>, which wikitext strips).
	 */
	public static function renderSearch( $input, array $args, Parser $parser, PPFrame $frame ) {
		$parser->getOutput()->addModuleStyles( [ 'ext.deadlockBuilds' ] );
		$placeholder = isset( $args['placeholder'] )
			? (string)$args['placeholder'] : 'Search builds, items, abilities…';
		$action = \SpecialPage::getTitleFor( 'Search' )->getLocalURL();
		return '<form class="dlb-search" action="' . htmlspecialchars( $action ) . '" method="get" role="search">'
			. '<input type="hidden" name="title" value="' . htmlspecialchars( \SpecialPage::getTitleFor( 'Search' )->getPrefixedText() ) . '">'
			. '<input class="dlb-search-input" type="search" name="search" autocomplete="off"'
			. ' placeholder="' . htmlspecialchars( $placeholder ) . '" aria-label="Search the wiki">'
			. '<button class="dlb-search-btn" type="submit">Search</button>'
			. '</form>';
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

	/**
	 * <deadlockitemrow items="Mystic Burst, Superior Cooldown, ..."/> — a compact
	 * row of item tiles (each linking to its Item page), resolved by name. Names
	 * that don't match a shop item are silently skipped. Used to show an
	 * ability's recommended/synergy items.
	 */
	public static function renderItemRow( $input, array $args, Parser $parser, PPFrame $frame ) {
		$parser->getOutput()->addModuleStyles( [ 'ext.deadlockBuilds' ] );
		try {
			$raw = isset( $args['items'] ) ? (string)$args['items'] : (string)$input;
			$names = array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' );
			if ( !$names ) {
				return '';
			}
			$items = self::assetMap( 'items' );
			$idx = self::nameIndex();
			$tiles = '';
			foreach ( $names as $n ) {
				$id = $idx[strtolower( $n )] ?? null;
				$it = $id !== null ? ( $items[$id] ?? null ) : null;
				if ( $it ) {
					// itemTile (not the compact gallery tile) gives the same rich
					// hover card as the build page.
					$tiles .= self::itemTile( $it );
				}
			}
			if ( $tiles === '' ) {
				return '';
			}
			return '<div class="deadlock-build dlb-itemrow">' . self::soulDefs()
				. '<div class="dlb-grid">' . $tiles . '</div></div>';
		} catch ( \Throwable $e ) {
			return self::notice( 'error', 'Could not load those items right now.' );
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
			. ( isset( $build['num_favorites'] ) && $build['num_favorites'] !== null
				? ' · ' . self::favHtml( (int)$build['num_favorites'] ) : '' )
			. ( !empty( $build['num_weekly_favorites'] )
				? ' · <span class="dlb-trending" title="Popular this week">&#128293; Trending</span>' : '' )
			. '</div>';
		$h .= '</div>';
		// Share (copy canonical build link); wired up by ext.deadlockBuilds.share.
		$canonTitle = \Title::newFromText( "Builds/$id" );
		$canonAttr = $canonTitle ? ' data-dlb-url="' . htmlspecialchars( $canonTitle->getFullURL() ) . '"' : '';
		$h .= '<button type="button" class="dlb-share" data-dlb-share' . $canonAttr
			. ' title="Copy a link to this build">'
			. '<span class="dlb-share-ico">&#128279;</span>'
			. '<span class="dlb-share-label">Share</span></button>';
		$h .= '</div>';

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
		$updatedTs = isset( $build['last_updated_timestamp'] ) ? (int)$build['last_updated_timestamp'] : 0;
		$updatedStr = $updatedTs > 0 ? gmdate( 'j M Y', $updatedTs ) : '';
		$h .= '<div class="dlb-foot">'
			. '<span class="dlb-legend"><span class="dlb-key dlb-slot-weapon"></span>Weapon '
			. '<span class="dlb-key dlb-slot-vitality"></span>Vitality '
			. '<span class="dlb-key dlb-slot-spirit"></span>Spirit</span>'
			. '<span class="dlb-src">'
			. ( $updatedStr !== '' ? 'Updated ' . htmlspecialchars( $updatedStr ) . ' · ' : '' )
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
		// Ability ids that have their own wiki page, so we can link the icons.
		$abilPages = self::abilityMap();
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
			// Link to the ability's page when one exists.
			$url = isset( $abilPages[(string)$aid] ) ? self::abilityPageUrl( (string)$ab['name'] ) : null;
			$tag = $url !== null ? 'a' : 'div';
			$href = $url !== null ? ' href="' . htmlspecialchars( $url ) . '"' : '';
			$steps .= '<' . $tag . ' class="' . htmlspecialchars( $abilClass ) . '"' . $href
				. ' title="' . self::attr( 'Point ' . $step . ': ' . $ab['name'] ) . '">';
			if ( !empty( $ab['image'] ) ) {
				$steps .= '<img class="dlb-abil-icon" src="' . htmlspecialchars( $ab['image'] )
					. '" alt="' . htmlspecialchars( $ab['name'] ) . '" width="34" height="34" loading="lazy">';
			}
			$steps .= '<span class="dlb-abil-step">' . $step . '</span></' . $tag . '>';
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

	/**
	 * Some items ship without a localised display name — the API returns the raw
	 * class identifier ("upgrade_stabilizer") instead. Turn those into a readable
	 * title ("Stabilizer"); leave real names (which have spaces/capitals) alone.
	 */
	private static function prettyItemName( string $name ): string {
		if ( !preg_match( '/^[a-z0-9_]+$/', $name ) ) {
			return $name;
		}
		$s = preg_replace( '/^upgrade_/', '', $name );
		$s = trim( ucwords( str_replace( '_', ' ', $s ) ) );
		return $s !== '' ? $s : $name;
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
			$cache->makeKey( 'deadlockbuilds-build', $id, 'v2' ),
			$ttl,
			static function ( $old, &$setTtl ) use ( $id ) {
				$base = rtrim( self::config( 'DeadlockBuildsApiBase', self::DEFAULT_API ), '/' );
				$json = self::httpGet( $base . '/v1/builds?only_latest=true&build_id=' . rawurlencode( $id ) );
				$data = $json !== null ? json_decode( $json, true ) : null;
				if ( !is_array( $data ) || !count( $data ) || empty( $data[0]['hero_build'] ) ) {
					$setTtl = 60; // don't cache a miss/outage for long
					return [ 'ok' => false ];
				}
				// The community-facing popularity counters live one level up from
				// hero_build; fold them in so callers see them on the build.
				$build = $data[0]['hero_build'];
				$build['num_favorites'] = $data[0]['num_favorites'] ?? null;
				$build['num_weekly_favorites'] = $data[0]['num_weekly_favorites'] ?? null;
				return [ 'ok' => true, 'build' => $build ];
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
			$cache->makeKey( 'deadlockbuilds-assets', $kind, 'v6' ),
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
							'name'   => self::prettyItemName( (string)( $row['name'] ?? ( '#' . $row['id'] ) ) ),
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
			$cache->makeKey( 'deadlockbuilds-nameindex', 'v5' ),
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

	// ---- Abilities -------------------------------------------------------

	/** Viscous's hero id and the canonical order of his four abilities. */
	private const VISCOUS_HERO_ID = 35;
	private const ABILITY_ORDER = [
		'viscous_goo_grenade',      // Splatter
		'viscous_restorative_goo',  // The Cube
		'viscous_telepunch',        // Puddle Punch
		'viscous_goo_bowling_ball', // Goo Ball
	];

	/**
	 * <deadlockability name="Splatter"/> (or class="..." / id="123") — render a
	 * full ability card. Defaults to the page name, so an ability page can simply
	 * contain <deadlockability/>.
	 */
	public static function renderAbilityTagEntry( $input, array $args, Parser $parser, PPFrame $frame ) {
		$parser->getOutput()->addModuleStyles( [ 'ext.deadlockBuilds' ] );
		try {
			$map = self::abilityMap();
			if ( !$map ) {
				return self::notice( 'error', 'Could not load Viscous\'s abilities right now.' );
			}
			$a = null;
			if ( isset( $args['id'] ) && preg_replace( '/[^0-9]/', '', (string)$args['id'] ) !== '' ) {
				$a = $map[preg_replace( '/[^0-9]/', '', (string)$args['id'] )] ?? null;
			} elseif ( isset( $args['class'] ) ) {
				$a = self::abilityByClass( $map, (string)$args['class'] );
			} else {
				$name = isset( $args['name'] ) ? (string)$args['name'] : (string)$parser->getTitle()->getText();
				$a = self::abilityByName( $map, $name );
			}
			if ( !$a ) {
				return self::notice( 'error', 'That Viscous ability was not found.' );
			}
			return self::renderAbilityCard( $a );
		} catch ( \Throwable $e ) {
			return self::notice( 'error', 'Could not load that ability right now.' );
		}
	}

	/** <deadlockabilityindex/> — a grid of Viscous's abilities linking to each page. */
	public static function renderAbilityIndex( $input, array $args, Parser $parser, PPFrame $frame ) {
		$parser->getOutput()->addModuleStyles( [ 'ext.deadlockBuilds' ] );
		try {
			$map = self::abilityMap();
			if ( !$map ) {
				return self::notice( 'error', 'Could not load Viscous\'s abilities right now.' );
			}
			$tiles = '';
			foreach ( self::abilitiesInOrder( $map ) as $a ) {
				$slug = self::abilitySlug( $a['name'] );
				$typeLabel = $a['type'] === 'ultimate' ? 'Ultimate' : 'Signature';
				$url = self::abilityPageUrl( $a['name'] );
				$open = $url !== null ? '<a class="dlb-ab-tile dlb-abil-' . $slug . '" href="' . htmlspecialchars( $url ) . '">'
					: '<div class="dlb-ab-tile dlb-abil-' . $slug . '">';
				$close = $url !== null ? '</a>' : '</div>';
				$tiles .= $open;
				if ( !empty( $a['image'] ) ) {
					$tiles .= '<span class="dlb-ab-tile-plate"><img class="dlb-ab-tile-icon" src="' . htmlspecialchars( $a['image'] )
						. '" alt="' . htmlspecialchars( $a['name'] ) . '" width="56" height="56" loading="lazy"></span>';
				}
				$tiles .= '<span class="dlb-ab-tile-name">' . htmlspecialchars( $a['name'] ) . '</span>';
				$tiles .= '<span class="dlb-ab-tile-type dlb-ab-type-' . htmlspecialchars( $a['type'] ) . '">'
					. $typeLabel . '</span>';
				if ( !empty( $a['quip'] ) ) {
					$tiles .= '<span class="dlb-ab-tile-quip">' . htmlspecialchars( $a['quip'] ) . '</span>';
				}
				$tiles .= $close;
			}
			if ( $tiles === '' ) {
				return self::notice( 'info', 'No abilities to show.' );
			}
			return '<div class="deadlock-build dlb-ability-index"><div class="dlb-ab-grid">' . $tiles . '</div></div>';
		} catch ( \Throwable $e ) {
			return self::notice( 'error', 'Could not load abilities right now.' );
		}
	}

	/** Render one ability as a full card: icon, type, description, key stats,
	 * upgrade tiers, and a looping gameplay clip. */
	private static function renderAbilityCard( array $a ): string {
		$slug = self::abilitySlug( $a['name'] );
		$typeLabel = $a['type'] === 'ultimate' ? 'Ultimate' : 'Signature';

		$h  = '<div class="deadlock-build dlb-ability-card dlb-abil-' . $slug . '">';
		$h .= '<div class="dlb-ab-head">';
		if ( !empty( $a['image'] ) ) {
			// Plate on the wrapper, not the img, so the Splatter invert filter
			// only recolours the icon art — never the plate behind it.
			$h .= '<span class="dlb-ab-icon-plate"><img class="dlb-ab-icon" src="' . htmlspecialchars( $a['image'] )
				. '" alt="' . htmlspecialchars( $a['name'] ) . '" width="72" height="72" loading="lazy"></span>';
		}
		$h .= '<div class="dlb-ab-headtext">';
		$h .= '<div class="dlb-ab-name">' . htmlspecialchars( $a['name'] ) . '</div>';
		$h .= '<div class="dlb-ab-type dlb-ab-type-' . htmlspecialchars( $a['type'] ) . '">'
			. $typeLabel . ' Ability</div>';
		if ( !empty( $a['quip'] ) ) {
			$h .= '<div class="dlb-ab-quip">' . htmlspecialchars( $a['quip'] ) . '</div>';
		}
		$h .= '</div></div>';

		if ( !empty( $a['desc'] ) ) {
			// Raw HTML (parser strip state): only <span> survives self::cleanAbilityHtml().
			$h .= '<div class="dlb-ab-desc">' . $a['desc'] . '</div>';
		}

		if ( !empty( $a['stats'] ) ) {
			$h .= '<div class="dlb-ab-stats">';
			foreach ( $a['stats'] as $s ) {
				$h .= '<div class="dlb-ab-stat">'
					. '<span class="dlb-ab-stat-val">' . htmlspecialchars( $s['value'] ) . '</span>'
					. '<span class="dlb-ab-stat-key">' . htmlspecialchars( $s['label'] ) . '</span></div>';
			}
			$h .= '</div>';
		}

		$tiers = is_array( $a['tiers'] ?? null ) ? $a['tiers'] : [];
		$costs = [ 1, 2, 5 ];
		$tierHtml = '';
		foreach ( $tiers as $i => $text ) {
			if ( trim( (string)$text ) === '' ) {
				continue;
			}
			$n = $i + 1;
			$cost = $costs[$i] ?? ( $n );
			$tierHtml .= '<div class="dlb-ab-tier">'
				. '<span class="dlb-ab-tier-badge">T' . $n . '</span>'
				. '<span class="dlb-ab-tier-desc">' . $text . '</span>'
				. '<span class="dlb-ab-tier-cost" title="Ability points">' . $cost
				. ' <span class="dlb-ab-tier-cost-lbl">pt' . ( $cost === 1 ? '' : 's' ) . '</span></span>'
				. '</div>';
		}
		if ( $tierHtml !== '' ) {
			$h .= '<div class="dlb-ab-tiers"><div class="dlb-ab-tiers-head">Upgrades</div>' . $tierHtml . '</div>';
		}

		if ( !empty( $a['video'] ) ) {
			$poster = !empty( $a['image'] ) ? ' poster="' . htmlspecialchars( $a['image'] ) . '"' : '';
			$h .= '<video class="dlb-ab-video" src="' . htmlspecialchars( $a['video'] ) . '"'
				. $poster . ' autoplay muted loop playsinline preload="metadata"></video>';
		}

		$h .= '</div>';
		return $h;
	}

	/** Cached id => ability record for Viscous's signature/ultimate abilities. */
	private static function abilityMap(): array {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$ttl = (int)self::config( 'DeadlockBuildsAssetTtl', 86400 );
		$value = $cache->getWithSetCallback(
			$cache->makeKey( 'deadlockbuilds-abilities', 'v3' ),
			$ttl,
			static function ( $old, &$setTtl ) {
				$base = rtrim( self::config( 'DeadlockBuildsApiBase', self::DEFAULT_API ), '/' );
				$json = self::httpGet( $base . '/v1/assets/items' );
				$arr = $json !== null ? json_decode( $json, true ) : null;
				if ( !is_array( $arr ) ) {
					$setTtl = 60;
					return [];
				}
				$map = [];
				foreach ( $arr as $row ) {
					if ( ( $row['type'] ?? '' ) !== 'ability'
						|| ( $row['hero'] ?? null ) !== self::VISCOUS_HERO_ID
						|| !in_array( $row['ability_type'] ?? '', [ 'signature', 'ultimate' ], true )
					) {
						continue;
					}
					if ( !isset( $row['id'] ) ) {
						continue;
					}
					$desc = is_array( $row['description'] ?? null ) ? $row['description'] : [];
					$vids = is_array( $row['videos'] ?? null ) ? $row['videos'] : [];
					$map[(string)$row['id']] = [
						'id'    => (string)$row['id'],
						'class' => (string)( $row['class_name'] ?? '' ),
						'name'  => (string)( $row['name'] ?? ( '#' . $row['id'] ) ),
						'type'  => (string)( $row['ability_type'] ?? 'signature' ),
						'image' => (string)( $row['image_webp'] ?? $row['image'] ?? '' ),
						'desc'  => self::linkifyItems( self::cleanAbilityHtml( (string)( $desc['desc'] ?? '' ) ) ),
						'quip'  => trim( (string)( $desc['quip'] ?? '' ) ),
						'stats' => self::abilityStats( $row ),
						'tiers' => self::abilityTiers( $row ),
						'video' => (string)( $vids['mp4'] ?? '' ),
					];
				}
				if ( !$map ) {
					$setTtl = 60;
				}
				return $map;
			}
		);
		return is_array( $value ) ? $value : [];
	}

	/** Abilities in canonical in-game order (Splatter, Cube, Punch, Goo Ball). */
	private static function abilitiesInOrder( array $map ): array {
		$order = array_flip( self::ABILITY_ORDER );
		$list = array_values( $map );
		usort( $list, static function ( $a, $b ) use ( $order ) {
			$ai = $order[$a['class']] ?? 99;
			$bi = $order[$b['class']] ?? 99;
			return $ai <=> $bi ?: strcmp( $a['name'], $b['name'] );
		} );
		return $list;
	}

	private static function abilityByName( array $map, string $name ): ?array {
		$name = strtolower( trim( $name ) );
		foreach ( $map as $a ) {
			if ( strtolower( $a['name'] ) === $name ) {
				return $a;
			}
		}
		return null;
	}

	private static function abilityByClass( array $map, string $class ): ?array {
		$class = strtolower( trim( $class ) );
		foreach ( $map as $a ) {
			if ( strtolower( $a['class'] ) === $class ) {
				return $a;
			}
		}
		return null;
	}

	/** Local URL of the (main-namespace) wiki page for an ability, or null. */
	private static function abilityPageUrl( string $name ): ?string {
		$title = \Title::newFromText( $name );
		return $title ? $title->getLinkURL() : null;
	}

	private static function abilitySlug( string $name ): string {
		$slug = preg_replace( '/[^a-z0-9]+/', '-', strtolower( $name ) );
		return trim( (string)$slug, '-' );
	}

	/** Curated key stats (label => value) for an ability's card. */
	private static function abilityStats( array $row ): array {
		$props = is_array( $row['properties'] ?? null ) ? $row['properties'] : [];
		// Cast-timing noise that clutters a wiki card without adding value.
		$deny = [
			'AbilityCastDelay' => 1, 'AbilityChannelTime' => 1,
			'AbilityPostCastDuration' => 1, 'AbilityChargeDelay' => 1,
		];
		$out = [];
		foreach ( $props as $key => $p ) {
			if ( !is_array( $p ) || isset( $deny[$key] ) || empty( $p['label'] ) ) {
				continue;
			}
			$val = self::fmtAbilityValue( $p );
			if ( $val === null ) {
				continue;
			}
			$out[] = [ 'label' => (string)$p['label'], 'value' => $val ];
			if ( count( $out ) >= 8 ) {
				break;
			}
		}
		return $out;
	}

	/** The three upgrade-tier descriptions, preferring the game's prose and
	 * falling back to synthesising from the raw property upgrades. */
	private static function abilityTiers( array $row ): array {
		$desc = is_array( $row['description'] ?? null ) ? $row['description'] : [];
		$upg  = is_array( $row['upgrades'] ?? null ) ? $row['upgrades'] : [];
		$props = is_array( $row['properties'] ?? null ) ? $row['properties'] : [];
		$tiers = [];
		for ( $i = 1; $i <= 3; $i++ ) {
			$text = self::cleanAbilityHtml( (string)( $desc["t{$i}_desc"] ?? '' ) );
			if ( $text === '' && isset( $upg[$i - 1]['property_upgrades'] ) ) {
				$text = self::synthTier( $upg[$i - 1]['property_upgrades'], $props );
			}
			$tiers[] = self::linkifyItems( $text );
		}
		return $tiers;
	}

	/** Build a "+bonus Label, …" string from an upgrade tier's property list. */
	private static function synthTier( array $pus, array $props ): string {
		$parts = [];
		foreach ( $pus as $pu ) {
			if ( !is_array( $pu ) ) {
				continue;
			}
			$name = (string)( $pu['name'] ?? '' );
			$label = !empty( $props[$name]['label'] ) ? (string)$props[$name]['label']
				: self::prettyPropName( $name );
			$bonus = $pu['bonus'] ?? '';
			if ( is_numeric( $bonus ) ) {
				$f = (float)$bonus;
				$num = ( $f == floor( $f ) ) ? (string)(int)$f
					: rtrim( rtrim( number_format( $f, 2, '.', '' ), '0' ), '.' );
				$bstr = ( $f >= 0 ? '+' : '' ) . $num;
			} else {
				$bstr = (string)$bonus;
			}
			$parts[] = trim( $bstr . ' ' . $label );
		}
		return implode( ', ', array_filter( $parts ) );
	}

	/** Turn an internal property key like "AbilityCooldown" into "Cooldown". */
	private static function prettyPropName( string $key ): string {
		$s = preg_replace( '/^Ability/', '', $key );
		$s = preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', ' ', $s );
		return trim( $s );
	}

	/**
	 * Format a property's display value: normalise trailing ".0", append the
	 * unit (postfix or display_units), skip zero / disabled values, and avoid
	 * doubling a unit the value already carries (e.g. "5m").
	 */
	private static function fmtAbilityValue( array $p ): ?string {
		if ( !array_key_exists( 'value', $p ) ) {
			return null;
		}
		$raw = $p['value'];
		$valStr = is_string( $raw ) ? trim( $raw ) : (string)$raw;
		if ( $valStr === '' ) {
			return null;
		}
		if ( isset( $p['disable_value'] ) && $valStr === (string)$p['disable_value'] ) {
			return null;
		}
		// Zero-ish ("0", "0m", "-0.0s") carries no information on a card.
		if ( preg_match( '/^-?0(\.0+)?[a-z%]*$/i', $valStr ) ) {
			return null;
		}
		if ( is_numeric( $valStr ) ) {
			$f = (float)$valStr;
			// A negative base stat is a "disabled" sentinel (e.g. -1 = no charge
			// system), not a real magnitude — omit it.
			if ( $f < 0 ) {
				return null;
			}
			$valStr = ( $f == floor( $f ) ) ? (string)(int)$f
				: rtrim( rtrim( number_format( $f, 2, '.', '' ), '0' ), '.' );
		}
		$postfix = '';
		if ( !empty( $p['postfix'] ) ) {
			$postfix = (string)$p['postfix'];
		} elseif ( !empty( $p['display_units'] ) ) {
			$u = strtolower( (string)$p['display_units'] );
			if ( strpos( $u, 'percent' ) !== false ) {
				$postfix = '%';
			} elseif ( strpos( $u, 'meters' ) !== false ) {
				$postfix = 'm';
			} elseif ( strpos( $u, 'second' ) !== false ) {
				$postfix = 's';
			}
		}
		if ( $postfix !== '' && substr( $valStr, -strlen( $postfix ) ) === $postfix ) {
			$postfix = '';
		}
		return $valStr . $postfix;
	}

	/** Sanitise an ability description/tier snippet: drop everything except the
	 * game's <span class="highlight"> emphasis, and collapse whitespace. */
	private static function cleanAbilityHtml( string $s ): string {
		if ( $s === '' ) {
			return '';
		}
		$s = strip_tags( $s, '<span>' );
		$s = preg_replace( '/\s+/u', ' ', $s );
		return trim( $s );
	}

	/**
	 * Link any shop-item names that appear in an ability's prose to their Item
	 * pages. Restricted to distinctive multi-word names (e.g. "Mystic Burst") to
	 * avoid turning common words like "Damage" or "Slow" into links, matched as
	 * whole phrases outside any existing tag, at most one link per text segment.
	 */
	private static function linkifyItems( string $html ): string {
		if ( $html === '' ) {
			return $html;
		}
		$phrases = self::itemLinkPhrases();
		if ( !$phrases ) {
			return $html;
		}
		// Split on tags so we only rewrite visible text, never tag internals.
		$parts = preg_split( '/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $parts as $i => $seg ) {
			if ( $seg === '' || $seg[0] === '<' ) {
				continue;
			}
			foreach ( $phrases as $name => $url ) {
				$new = preg_replace(
					'/\b' . preg_quote( $name, '/' ) . '\b/u',
					'<a href="' . htmlspecialchars( $url ) . '">$0</a>',
					$seg,
					1,
					$count
				);
				if ( $count > 0 ) {
					// One link per segment keeps things tidy and avoids nesting.
					$parts[$i] = $new;
					break;
				}
			}
		}
		return implode( '', $parts );
	}

	/** Cached "Multi Word Item Name" => Item-page URL map for linkifyItems(). */
	private static function itemLinkPhrases(): array {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$ttl = (int)self::config( 'DeadlockBuildsAssetTtl', 86400 );
		$value = $cache->getWithSetCallback(
			$cache->makeKey( 'deadlockbuilds-itemphrases', 'v1' ),
			$ttl,
			static function ( $old, &$setTtl ) {
				$items = self::assetMap( 'items' );
				$idx = self::nameIndex();
				if ( !$idx ) {
					$setTtl = 60;
					return [];
				}
				$out = [];
				foreach ( $idx as $lname => $id ) {
					// Only distinctive multi-word names are safe to auto-link.
					if ( strpos( $lname, ' ' ) === false ) {
						continue;
					}
					$it = $items[$id] ?? null;
					if ( !$it ) {
						continue;
					}
					$url = self::itemPageUrl( (string)$it['name'] );
					if ( $url !== null ) {
						$out[(string)$it['name']] = $url;
					}
				}
				// Longest names first so "Superior Cooldown" wins over "Cooldown".
				uksort( $out, static function ( $a, $b ) {
					return mb_strlen( $b ) <=> mb_strlen( $a );
				} );
				return $out;
			}
		);
		return is_array( $value ) ? $value : [];
	}
}
