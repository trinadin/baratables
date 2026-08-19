=== BaraTables ===
Contributors: nathannoom
Tags: tables, datatables, charts, csv, shortcode
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create searchable, sortable WordPress tables and charts from CSV files, manual rows, posts, or external databases.

== Description ==

BaraTables builds interactive tables and charts from manual rows, CSV uploads, WordPress content, or an external MySQL/MariaDB database, then publishes them with a shortcode or a block.

Import a JSON, XML, HTML, CSV, TXT, or ZIP export from another table plugin or spreadsheet to rebuild a table.

Tables can include search, sorting, pagination, filters, export buttons, responsive stacking, and column visibility controls. Charts draw from any BaraTables table and embed with their own shortcode.

**Features:**

* Add search, sorting, pagination, and dropdown, multi-select, checkbox, or radio filters, including filtering by category or tag
* Export table data to CSV, Excel, or PDF, copy it, or print it
* Reorder columns and control column visibility
* Collapse wide tables into stacked, expandable rows on small screens
* Match your theme automatically: colors follow your theme's palette, with an optional custom accent color
* Restrict WordPress-content, CSV, and external-database rows by user role or user metadata
* Remember table state between visits, and control scrolling, column sizing, and row limits
* Create bar, horizontal bar, line, area, radar, pie, donut, treemap, scatter, bubble, heatmap, funnel, and Gantt charts with ECharts
* Light frontend styles that are easy to override with CSS

== Installation ==

1. Upload the `baratables` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to Tables in the admin menu and create your first table.
4. Use the shortcode `[bara_table id="your-table-slug"]` to embed it.

== Frequently Asked Questions ==

= What data sources are supported? =

CSV files, manual data entry, WordPress content, and external MySQL/MariaDB databases.

= Which table plugin exports can I import? =

Use a TablePress single-table JSON export. Ninja Tables classic and drag-and-drop JSON exports work; export an externally connected Ninja table as CSV. WP Table Builder XML or CSV, Visualizer CSV, Supsystic JSON or CSV, League Table XML, and wpDataTables or Tablesome data-only CSV also import, as do HTML tables. A ZIP imports the first supported table and reports how many were found.

Imports rebuild the table data and compatible display settings, not plugin-specific styling, formulas, shortcodes, charts, or external data connections. Export XLS or XLSX files as CSV first.

Uploads and pasted imports can be up to 5 MB. A ZIP can hold 50 entries of up to 5 MB each, and up to 20 MB expanded.

= How many rows can a table load? =

Each table loads up to 1,000 rows by default. The Options tab sets a limit from 1 to 10,000 rows for tables and charts on every data source.

= Can I link to a filtered view of a table? =

Yes. Filtering or searching updates the page address, so you can share that exact view. You can also build the link yourself with `btbl_filter[column-slug]`, `btbl_search`, and `btbl_search_cols`.

= How do I add a chart? =

Create a table first, then go to Charts and create a new chart linked to that table. Use `[bara_chart id="your-chart-slug"]` to embed it.

= Can I customize the table appearance? =

Yes. The Options tab provides controls for striping, hover, borders, compact mode, pagination style, button labels, search text, and info display, and layout zones control where controls appear.

= Can I style BaraTables with custom CSS? =

Yes. BaraTables ships minimal frontend styles so your theme stays in control; adjust colors, spacing, typography, and buttons with your theme, the Site Editor, or custom CSS.

= Where can I see examples? =

Visit https://ktisisweb.com/baratables/ for screenshots, feature notes, and styling guidance, or use Live Preview on the WordPress.org page for an interactive demo.

== Screenshots ==

1. Frontend chart generated from a BaraTables table.
2. Frontend table with search, filters, CSV, Excel, and PDF export, column visibility, sorting, and pagination.
3. The Options tab: per-table overrides, control toggles, layout builder, styling, and export buttons.
4. Chart builder and chart-type gallery with all supported chart types.
5. Responsive table on a phone: collapsed columns expand into a stacked row.

== Changelog ==

= 1.3.1 =
Fixes:

* Dragging a column no longer leaves a group search on the wrong columns.
* Merge tags like {{meta:Price_USD}} now resolve mixed-case keys.
* A chart-only page no longer flashes unstyled content.
* Switching data sources no longer keeps old columns selected.
* A meta column missing from recent posts is no longer dropped on save.
* A remembered search is now visible and clearable.
* Custom Query date filters now apply as written.
* Ninja Tables custom filter values now import as dropdown options.
* The chart preview shows draft charts instead of Chart not found.

Developer:

* Removed the PHP adapters deprecated in 1.2.5; use the column-record API instead.

= 1.3.0 =
New:

* New block editor blocks: add any table or chart from the block picker instead of pasting a shortcode.
* Wide tables can collapse into stacked, expandable rows on small screens.
* Export any table to PDF with a new Export PDF button.
* Tables match your theme automatically: colors follow its palette, with an optional custom accent color per table.

Improvements:

* Table pages no longer load jQuery, and dropdown filters use a lighter picker, so they load faster.
* The table engine is now DataTables 3; pages with export buttons load fewer files.
* Auto-built dropdown and checkbox filters cap at 250 options, so a near-unique column cannot make the page unresponsive.
* The editor's Options tab is reorganized, and every field shows its default until you change it.
* Tables and charts work better with screen readers: proper column headers, an announced optional caption, labelled charts, and a readable fallback when JavaScript is off.
* Publishing a table for the first time shows its shortcode and block.

Fixes:

* Day-first dates such as 25/12/2026 now format correctly with "Format as date".
* Chart values written with a decimal comma (1.234,56) plot at the correct magnitude.
* Tab-delimited files import as separate columns instead of one long column.
* An invalid external database edit no longer silently keeps the previous connection, and clearing every field removes it.
* Search limited to chosen columns follows columns you move by dragging.
* The settings gear no longer appears on a switched-off control when its inner option is still checked.
* Layout builder drop zones sit where their labels say.

= 1.2.5 =
New:

* Imports WP Table Builder XML, Ninja Tables drag-and-drop JSON, Visualizer CSV, Supsystic JSON or CSV, and League Table XML exports.
* Reads table exports from HTML and ZIP files.

Improvements:

* Chart-only pages load faster and large-table searches stay more responsive.
* The chart editor searches table choices as you type, so it stays fast with many tables.

Fixes:

* Empty or incomplete Ninja Tables exports no longer become a live table of WordPress posts.
* Quoted commas and multiline headings no longer break semicolon-delimited imports.
* Hidden columns, footer values, intentional blank rows, and nested cell values are preserved.
* A failed import no longer leaves an incomplete published table behind.
* The plugin activates normally on PHP 7.4 through PHP 8.1.
* A failed table or chart save keeps its data, ID, and linked charts.
* Duplicating a table or chart more than once gives every copy its own ID.
* Missing CSV files and external database failures show a clear error instead of an empty table.
* If an interactive table or chart script fails to load, the loading screen clears to show the table or an error.
* Renaming a Table ID no longer removes backslashes from linked chart names.
* Manual column headings saved by early versions follow the site language after a direct upgrade.

Security:

* Validates the contents of table import uploads before processing them.

= 1.2.4 =

* Adds radar, heatmap, and treemap charts.

= 1.2.3 =
Improvements:

* Fixed scroll height is now a Table controls option, with its height and collapse settings inside. Existing heights carry over.
* Table wording follows your site's language: the search label, the "Show ... entries" selector, the filters heading, and the result summary. Saved tables are included, and wording you typed yourself is kept.
* Tables load without a flash of unstyled content.
* Keyboard and screen reader support: labelled filter controls and option groups, Enter or Space to open the settings gear, focus outlines on taxonomy chips, and arrow keys to arrange the layout.
* Pages with a table and its chart, or the same table twice, load faster on large tables.
* The "Clear filters" and "Edit Table" buttons are styled consistently on any theme.

Security:

* Password-protected post content is withheld from visitors who have not entered the password.
* Post passwords cannot be displayed as a column, including through an imported file.

Fixes:

* Hiding a column's heading keeps the custom heading you typed.
* Sticky posts are excluded, so a table stays within its row limit.
* Column filters follow their column when a visitor reorders columns.
* Date columns sort by date, even with empty cells.
* The Column visibility button reveals columns hidden in the table setup.
* Custom meta keys with capital letters or dots (such as Price_USD) match your data.
* Media tables list your attachments.

= 1.2.2 =
New:

* Row-level access control on Custom WP Query tables.

Fixes:

* Filtering by category or tag.
* Pages using Advanced Custom Fields Post Object, Relationship, Taxonomy, or Repeater columns load correctly; the columns show their title, name, or contents.
* Manual data grids hold up to 25,000 cells, and the editor warns when your server's form-field limit is too low for a grid that size.
* Two tables on the same page keep their own filters in the shareable link.
* An import that fails to save can be retried without uploading the file again.
* Importing a large table keeps more of its rows and columns.
* Ninja Tables exports apply their saved search, sorting, and pagination settings.
* Editing an external database connection keeps your column and filter setup.
* Saved table state keeps sorting, search, page, and page length between visits.
* A restored search respects the columns you set as searchable.
* "Value overrides" apply to CSV and external database tables.
* "Format as date" is available on CSV, external database, manual, and custom field columns.
* Date columns show the time of day, and timestamps use your site's timezone.
* Blank lines in a CSV file are skipped.
* Custom WP Query tables load up to the row limit you set.
* On CSV and external database tables, row-level access control shows every row a visitor may see, even beyond the row limit.
* Shareable filter links work for a value of 0.
* Unnamed columns from a headerless CSV follow the site language.
* The editor preview uses the same export button labels as the published table.
* Creating a table from an import opens its editor.

Improvements:

* A table loads only the export, column-reorder, and dropdown-filter libraries it uses, cutting about 265KB per page.
* Large tables sort, page, and filter faster.
* The term picker lists the first 200 terms of a taxonomy, plus any already selected.
* The "Strict matching" filter option is removed; filters match whole values exactly.
* A column order set by dragging applies to the current visit only.

Security:

* A column heading taken from a CSV file's header row could run script in the table editor. Column headings are now escaped everywhere they appear.
* Table and chart posts are no longer listed by the REST API. Anonymous requests could retrieve their ids, titles, and slugs.
* An external database table with no columns selected no longer publishes the column holding its access tokens.
* Row-level access control was accepted but not applied on manual-data tables. The setting has been removed there.
* Changing a table's data source could leave row-level access control switched on but unenforced, publishing rows it was set to hide. It now applies only to the source it was set up for, and a table whose saved settings cannot be enforced shows no rows until you re-save it.
* New tables default to hiding restricted rows from logged-out visitors. Existing tables keep their saved setting.

= 1.2.1 =
* Export a table to Excel with a new table button option.
* Five new chart types: horizontal bar, donut, scatter, bubble, and funnel, each with a preview in the chart-type gallery.
* Remember table state: a table can keep its sorting, search, page, and page length.
* New table controls for horizontal scrolling, vertical scroll height, and column auto-sizing.
* Configurable row loading limit per table (default 1,000 rows, up to 10,000).

= 1.1.1 =
Fixes:

* "Format as date" displays the formatted date (e.g. "Mar 18, 2026") on manual data columns.
* Plain numbers such as a year or a count are not read as dates.
* A [bara_table] or [bara_chart] shortcode used without an id shows a "not found" message on WordPress 6.2 through 6.4.
* Importing a file with only a header row, or no rows, creates an empty table.
* Front-end table controls (export buttons, column-visibility menu, "Search in") and the CSV file picker follow the site language.

Security:

* Hardened the table editor's "Column heading" field against script injection (XSS).

= 1.1.0 =
New:

* Import a table from another table plugin or a spreadsheet: upload a JSON, XML, or CSV export and BaraTables creates a matching table.
* Editable Table ID and Chart ID: rename a table's or chart's shortcode ID after creation. Linked charts update automatically, and a notice reminds you to update shortcodes already placed in your content.
* Reorder manual-data rows in the editor with up and down controls.
* Manual-table column headers are translation-ready and follow the site language.

Improvements:

* Wide manual-data tables scroll horizontally while keeping the row number and controls in view.
* Paste tabular data straight from a spreadsheet into the manual-data grid.
* Smoother admin: one-click copy for shortcodes and IDs, a Show/Hide help text preference, and fewer page reloads while configuring a source.

Fixes:

* Numeric columns sort numerically (e.g. 3.15, 3.2, 3.9).
* Far-future dates display correctly.

Security:

* Hardened admin request handling and input validation.

= 1.0.1 =
* Improved date formatting controls for WordPress date columns, including support for the site's default date format.
* Security: hardened frontend table and chart configuration output.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.3.1 =
Fixes search after column reordering, remembered searches, and meta columns dropped on save. Developers: PHP methods deprecated in 1.2.5 are removed.

= 1.3.0 =
New table and chart blocks, responsive stacking on small screens, PDF export, theme-matched colors with a custom accent, and faster table pages without jQuery. Fixes day-first dates, decimal-comma chart values, and silent external database edits.

= 1.2.5 =
Broader and more accurate table imports, faster tables and chart-only pages, reliable saves, clear load errors, PHP 7.4 compatibility, and secure import validation.

= 1.2.4 =
Adds radar, heatmap, and treemap charts.

= 1.2.3 =
Security fixes: password-protected post content and post passwords could be displayed in a table. Also fixes date sorting, sticky posts, and Media tables.

= 1.2.2 =
Includes a security fix for the table editor. Fixes category and tag filtering, Advanced Custom Fields columns, and settings lost when editing an external database connection. If you use row-level access control, open and re-save those tables: some show no rows until you do.

= 1.2.1 =
Adds Excel export, five new chart types, saved table state, and scrolling and row-limit controls.

= 1.1.1 =
"Format as date" works on every data source, front-end controls follow the site language, and shortcodes without an id no longer error on older WordPress. Includes a security fix for the table editor.

= 1.1.0 =
Import tables from other plugins or spreadsheets, rename Table and Chart IDs, reorder manual rows, and use translation-ready headers. Includes fixes and security hardening.

= 1.0.1 =
Maintenance release with improved date formatting controls and safer frontend output.

= 1.0.0 =
Initial release.

== Third-Party Libraries ==

This plugin bundles these libraries and admin thumbnail images, all under GPL-compatible licenses. Full license notices ship in assets/vendor/THIRD-PARTY-LICENSES.txt; each link is that library's source.

* DataTables v3.0.2 - MIT License - https://github.com/DataTables/DataTablesSrc/tree/3.0.2
* DataTables Buttons v4.0.2 - MIT License - https://github.com/DataTables/Buttons/tree/4.0.2 (bundles FileSaver.js - MIT License - https://github.com/eligrey/FileSaver.js/tree/1.3.3)
* DataTables ColReorder v3.0.1 - MIT License - https://github.com/DataTables/ColReorder/tree/3.0.1
* DataTables Responsive v4.0.2 - MIT License - https://github.com/DataTables/Responsive/tree/4.0.2
* Tom Select v2.6.2 - Apache License 2.0 - https://github.com/orchidjs/tom-select/tree/v2.6.2
* JSZip v3.10.1 - MIT License - https://github.com/Stuk/jszip/tree/v3.10.1 (bundles pako - MIT License - https://github.com/nodeca/pako)
* pdfmake v0.2.23 - MIT License - https://github.com/bpampuch/pdfmake/tree/0.2.23 (bundles the Roboto fonts - Apache License 2.0)
* ECharts v6.1.0 - Apache License 2.0 - https://github.com/apache/echarts/tree/6.1.0
* Chart-type gallery thumbnails from the Apache ECharts examples, Apache License 2.0 - https://github.com/apache/echarts-examples
* BaraTables' custom ECharts bundle entry and build command - https://github.com/trinadin/baratables/blob/main/tools/echarts-entry.js
