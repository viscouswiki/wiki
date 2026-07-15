<?php
/**
 * Viscous Wiki — version-controlled MediaWiki configuration (NO secrets here).
 *
 * The generated LocalSettings.php (gitignored) holds the DB password, secret
 * keys, and $wgServer. At the end of it we append:
 *     require_once "$IP/LocalSettings.custom.php";
 * so everything below is applied on top of the install defaults.
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	exit;
}

// ---- Pretty URLs: https://viscous.wiki/wiki/Page_Name --------------------
// Paired with the Apache `Alias /wiki -> index.php` in mediawiki/shorturl.conf.
$wgArticlePath = '/wiki/$1';
$wgUsePathInfo = true;

// ---- Skins ---------------------------------------------------------------
wfLoadSkin( 'Citizen' );   // default; themed Viscous-green via MediaWiki:Common.css
wfLoadSkin( 'Fluent' );    // alternative; users switch it in Preferences → Appearance
$wgDefaultSkin = 'citizen';

// ---- Extensions ----------------------------------------------------------
// CategoryTree (bundled with MediaWiki core) — collapsible, AJAX-expandable
// category trees. Adds the <categorytree> parser tag and expand arrows on
// category pages / Special:Categories. No download or DB tables required.
wfLoadExtension( 'CategoryTree' );

// ParserFunctions (bundled) — #if / #ifeq / #switch etc. Needed by templates
// like Template:Build to hide empty infobox rows. No DB tables required.
wfLoadExtension( 'ParserFunctions' );

// DeadlockBuilds (first-party, in ./extensions) — <deadlockbuild id="..."/> tag
// that renders a Deadlock build from the deadlock-api.com API. Results are
// cached in the object cache (see $wgMainCacheType below); pages are also
// parser-cached, so the API is not called on normal page views.
wfLoadExtension( 'DeadlockBuilds' );

// Dedicated "Item:" namespace for Deadlock item pages (Item:Extra Spirit),
// rendered by the <deadlockitem> tag. NS_ITEM is read by DeadlockBuilds to link
// item tiles on build pages to these pages.
define( 'NS_ITEM', 3000 );
define( 'NS_ITEM_TALK', 3001 );
$wgExtraNamespaces[NS_ITEM]      = 'Item';
$wgExtraNamespaces[NS_ITEM_TALK] = 'Item_talk';
$wgContentNamespaces[]           = NS_ITEM;

// ---- "Video Uploaders" group (gates the R2 uploader at /upload) ----------
// Members may use the media uploader. Assign people via Special:UserRights
// (you, as a bureaucrat, can grant it). No special on-wiki permissions are
// granted — the group simply exists so the uploader can check for it.
$wgGroupPermissions['videouploader']['read']  = true;
$wgAddGroups['bureaucrat'][]    = 'videouploader';
$wgRemoveGroups['bureaucrat'][] = 'videouploader';

// ---- Logo ----------------------------------------------------------------
// Viscous's helmet, served from R2. Swap these URLs to change the logo.
$wgLogos = [
	'1x'   => 'https://media.viscous.wiki/branding/viscous-logo.png',
	'icon' => 'https://media.viscous.wiki/branding/viscous-logo.png',
];

// ---- Uploads -------------------------------------------------------------
// Native MediaWiki file uploads (images/small files). Large media/video go to
// R2 via the separate uploader at /upload.
$wgEnableUploads    = true;
$wgUseInstantCommons = false;

// ---- Caching -------------------------------------------------------------
// The install set $wgMainCacheType = CACHE_ACCEL (APCu), but APCu isn't built
// into the mediawiki image — so the object cache silently became a no-op. Pin
// it to the database instead: a real, shared, persistent backend with no extra
// services. This makes WANObjectCache actually cache (e.g. the DeadlockBuilds
// extension's API responses) and fixes the same root cause behind the earlier
// sidebar/message-cache issue.
$wgMainCacheType = CACHE_DB;

// Keep the message cache in the DB too (shared between the web server and CLI
// maintenance scripts) so edits via maintenance scripts invalidate correctly.
$wgMessageCacheType = CACHE_DB;

// ---- Behind the Cloudflare tunnel (TLS terminated at the edge) -----------
$wgServer = 'https://viscous.wiki';
