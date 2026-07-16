# Wiki pages

Reference copies of on-wiki pages whose content lives in the MediaWiki
database (not in this repo's PHP/config). They are version-controlled here so
the custom home page, styles, and category browse pages aren't lost if the
database is rebuilt.

These files are **not** loaded automatically — they are backups/source. To
apply a change, edit the page in the wiki (or import via `edit.php`).

| File | Wiki page | What it is |
| --- | --- | --- |
| `Main_Page.wikitext` | `Main Page` | Home page: hero banner, feature cards, and a live `<deadlockbuildindex>` of current meta builds. |
| `MediaWiki_Common.css` | `MediaWiki:Common.css` | Site-wide CSS: Citizen green accent + the `.vw-*` home-page hero/card/button styles. |
| `Category_Builds.wikitext` | `Category:Builds` | All builds — uses `<deadlockbuildindex category="Builds"/>`. |
| `Category_Meta_builds.wikitext` | `Category:Meta builds` | Current meta builds — uses `<deadlockbuildindex category="Meta builds"/>`. |

## Applying a page from here

Copy the file to the MediaWiki container and import it with `edit.php`:

```sh
docker compose cp Main_Page.wikitext mediawiki:/tmp/page.txt
docker compose exec -T mediawiki \
  php maintenance/run.php edit --user Admin --summary "Update from repo" "Main Page" < /tmp/page.txt
```

## The `<deadlockbuildindex>` tag

Provided by the `DeadlockBuilds` extension. Attributes:

- `category` — category to list (default `Builds`).
- `limit` — max cards to show; when set, shows the newest builds first and
  adds a "View all N builds →" link (the home page uses `limit="6"`).
- `order` — manual order, a list of build ids, e.g. `order="583665, 1102"`.
  Listed ids come first in that sequence; anything unlisted follows
  alphabetically. Combine with `limit` to curate exactly which builds show
  and in what order.

Builds that also have a `Builds/meta/<id>` page get a pulsing gold star.

The grids embedding this tag (home page, `Category:Builds`,
`Category:Meta builds`) refresh automatically when a build page is created,
edited, or deleted.
