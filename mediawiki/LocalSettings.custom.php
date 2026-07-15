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
// Keep the message cache in the DB (shared between the web server and CLI
// maintenance scripts). With the default APCu message cache, edits made via
// maintenance scripts (e.g. edit.php) don't invalidate the web server's copy,
// so things like MediaWiki:Sidebar silently keep serving the old/default value.
$wgMessageCacheType = CACHE_DB;

// ---- Behind the Cloudflare tunnel (TLS terminated at the edge) -----------
$wgServer = 'https://viscous.wiki';
