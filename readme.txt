=== BaraTables ===
Contributors: nathannoom
Tags: tables, datatables, charts, csv, shortcode
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create searchable, sortable WordPress tables and charts from CSV files, manual rows, posts, or external databases.

== Description ==

BaraTables builds interactive tables and charts from your site data. Use manual rows, CSV uploads, WordPress content, or an external MySQL/MariaDB database, then publish with a shortcode or a block.

Import a JSON, XML, HTML, CSV, TXT, or ZIP export from another table plugin or spreadsheet to rebuild a table.

Tables can include search, sorting, pagination, filters, export buttons, responsive stacking, column visibility controls, and custom control layouts. Charts draw from any BaraTables table and embed with their own shortcode.

**Features:**

* Build tables from WordPress content, CSV files, manual data, or external MySQL/MariaDB databases
* Embed with a shortcode or a block in the block editor
* Add search, sorting, pagination, and dropdown, multi-select, checkbox, or radio filters, including filtering by category or tag
* Export table data to CSV, Excel, or PDF, copy it, or print it
* Reorder columns and control column visibility
* Collapse wide tables into stacked, expandable rows on small screens
* Match your theme automatically: colors follow your theme's palette, with an optional custom accent color
* Restrict WordPress-content, CSV, and external-database rows by user role or user metadata
* Remember table state between visits, and control scrolling, column sizing, and row limits
* Create bar, horizontal bar, line, area, radar, pie, donut, treemap, scatter, bubble, heatmap, funnel, and Gantt charts with ECharts
* Light frontend styles that are easy to override with CSS
* Import an existing table from another plugin or a spreadsheet (JSON, XML, HTML, CSV, TXT, or ZIP)

== Installation ==

1. Upload the `baratables` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to Tables in the admin menu and create your first table.
4. Use the shortcode `[bara_table id="your-table-slug"]` to embed it.

== Frequently Asked Questions ==

= What data sources are supported? =

CSV files, manual data entry, WordPress content, and external MySQL/MariaDB databases.

= Which table plugin exports can I import? =

For TablePress, use a single-table JSON export. Ninja Tables classic and drag-and-drop JSON exports are supported; export an externally connected table as CSV. WP Table Builder XML or CSV, Visualizer CSV, Supsystic JSON or CSV, League Table XML, and data-only CSV from wpDataTables and Tablesome also import, as do HTML tables. A ZIP with multiple tables imports the first supported table and reports how many were found.

Imports rebuild the table data and compatible display settings. Plugin-specific styling, formulas, shortcodes, charts, and external data connections are not carried over. Export XLS or XLSX files as CSV first.

An upload or pasted import can be up to 5 MB. ZIP files can contain up to 50 entries, with each entry up to 5 MB and up to 20 MB total when expanded.

= How many rows can a table load? =

Each table loads up to 1,000 rows by default. The Options tab lets you choose a limit from 1 to 10,000 rows, applying to tables and charts on every data source.

= Can I link to a filtered view of a table? =

Yes. Filtering or searching updates the page address as you go, so you can copy and share that exact view. You can also build the link yourself with `btbl_filter[column-slug]`, `btbl_search`, and `btbl_search_cols`.

= How do I add a chart? =

Create a table first, then go to Charts and create a new chart linked to that table. Use `[bara_chart id="your-chart-slug"]` to embed it.

= Can I customize the table appearance? =

Yes. The Options tab provides controls for striping, hover, borders, compact mode, pagination style, button labels, search text, and info display, and layout zones control where controls appear.

= Can I style BaraTables with custom CSS? =

Yes. BaraTables ships minimal frontend styles so your theme stays in control. Adjust colors, spacing, typography, and buttons with your theme stylesheet, the Site Editor, or custom CSS.

= Where can I see examples? =

Visit https://ktisisweb.com/baratables/ for screenshots, feature notes, and styling guidance, or use the Live Preview button on the WordPress.org plugin page for a temporary interactive demo.

== Screenshots ==

1. Frontend chart generated from a BaraTables table.
2. Frontend table with search, filters, CSV, Excel, and PDF export, column visibility, sorting, and pagination.
3. The Options tab: per-table overrides, control toggles, layout builder, styling, and export buttons.
4. Chart builder and chart-type gallery with all supported chart types.
5. Responsive table on a phone: collapsed columns expand into a stacked row.

== Changelog ==

= 1.3.0 =
New:

* New block editor blocks: add any table or chart from the BaraTables block picker instead of pasting a shortcode.
* Wide tables can collapse into stacked, expandable rows on small screens.
* Export any table to PDF with a new Export PDF button.
* Tables match your theme automatically: colors follow your theme's palette, with an optional custom accent color per table.

Improvements:

* Table pages no longer load jQuery, and dropdown filters use a lighter picker, so pages with tables load faster.
* The table engine is now DataTables 3; pages with export buttons load fewer files.
* Auto-built dropdown and checkbox filters cap at 250 options, so a near-unique column can no longer make the page unresponsive.
* The editor's Options tab is reorganized with clearer wording, and every field shows its default until you change it.
* Tables and charts work better with screen readers: proper column headers, an announced optional caption, labelled charts, and a readable fallback when JavaScript is off.
* Publishing a table for the first time shows the shortcode and block to embed it with.

Fixes:

* Day-first dates such as 25/12/2026 now format correctly with "Format as date".
* Chart values written with a decimal comma (1.234,56) plot at the correct magnitude.
* Tab-delimited files import as separate columns instead of one long column.
* An invalid external database edit no longer silently keeps the previous connection, and clearing every field now removes the connection.
* Search limited to chosen columns follows columns you move by dragging.
* The settings gear no longer appears on a switched-off control, such as Fixed scroll height, when its inner option is still checked.
* In the layout builder, drop zones sit where their labels say instead of spreading across one row.

= 1.2.5 =
New:

* Imports WP Table Builder XML, Ninja Tables drag-and-drop JSON, Visualizer CSV, Supsystic JSON or CSV, and League Table XML exports.
* Reads table exports from HTML and ZIP files.

Improvements:

* Chart-only pages load faster and large-table searches stay more responsive.
* The chart editor searches table choices as you type, keeping it fast on sites with many tables.

Fixes:

* Empty or incomplete Ninja Tables exports no longer become a live table of WordPress posts.
* Quoted commas and multiline headings no longer break semicolon-delimited imports.
* Hidden columns, footer values, intentional blank rows, and nested cell values are preserved instead of discarded.
* A failed import no longer leaves an incomplete published table behind.
* The plugin activates normally on PHP 7.4 through PHP 8.1.
* A failed table or chart save keeps its previous data, ID, and linked charts together.
* Duplicating the same table or chart more than once gives every copy its own ID.
* Missing CSV files and external database failures show a clear error instead of looking like an empty table.
* If an interactive table or chart script fails to load, the loading screen clears and shows the available table or an error.
* Renaming a Table ID no longer removes backslashes from linked chart names.
* Manual column headings saved by early versions follow the site language after a direct upgrade.

Security:

* Validates the contents of table import uploads before processing them.

= 1.2.4 =

* Adds radar, heatmap, and treemap charts.

= 1.2.3 =
Improvements:

* Fixed scroll height is now a Table controls option, with its height and collapse settings inside. Existing scroll heights carry over.
* Table wording follows your site's language: the search label, the "Show ... entries" selector, the filters heading, and the result summary. This includes tables you have already saved, and keeps any wording you typed yourself.
* Tables load without a flash of unstyled content.
* Keyboard and screen reader support: labelled filter controls and option groups, Enter or Space to open the settings gear, focus outlines on taxonomy chips, and arrow keys to arrange the table layout.
* Pages with a table and its chart, or the same table twice, load faster on large tables.
* The "Clear filters" and "Edit Table" buttons are styled consistently on any theme.

Security:

* Password-protected post content is withheld from visitors who have not entered the password.
* Post passwords cannot be displayed as a column, including through an imported file.

Fixes:

* Hiding a column's heading keeps the custom heading you typed.
* Sticky posts are excluded, so a table stays within its row limit.
* Column filters follow their column when a visitor reorders columns.
* Date columns sort by date, including columns with empty cells.
* The Column visibility button reveals columns hidden in the table setup.
* Custom meta keys with capital letters or dots (such as Price_USD) match your data.
* Media tables list your attachments.

= 1.2.2 =
New:

* Row-level access control on Custom WP Query tables.

Fixes:

* Filtering by category or tag.
* Pages using Advanced Custom Fields Post Object, Relationship, Taxonomy, or Repeater columns load correctly, and those columns show their title, name, or contents.
* Manual data grids hold up to 25,000 cells, and the editor tells you when your server's form-field limit is too low for a grid that size.
* Two tables on the same page each keep their own filters in the shareable link.
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
* On CSV and external database tables, row-level access control shows every row a visitor may see, even when the table has more rows than the limit.
* Shareable filter links work for a value of 0.
* Unnamed columns from a headerless CSV follow the site language.
* The editor preview uses the same export button labels as the published table.
* Creating a table from an import opens that table's editor.

Improvements:

* A table loads only the export, column-reorder, and dropdown-filter libraries it uses, cutting about 265KB from a typical page.
* Large tables sort, page, and filter faster.
* The term picker lists the first 200 terms of a taxonomy, plus any you have already selected.
* The "Strict matching" filter option has been removed. Filters match whole values exactly.
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

* Import a table from another table plugin or a spreadsheet: upload a JSON or XML table export, or a CSV file, and BaraTables creates a matching table for you.
* Editable Table ID and Chart ID: rename a table's or chart's shortcode ID after it is created. Linked charts update automatically, and a notice reminds you to update any [bara_table] / [bara_chart] shortcodes already placed in your content.
* Reorder manual-data rows directly in the editor with up and down controls.
* Manual-table column headers are now translation-ready and follow the site language.

Improvements:

* Wide manual-data tables now scroll horizontally while keeping the row number and row controls in view.
* Paste tabular data straight from a spreadsheet into the manual-data grid.
* Smoother admin experience: one-click copy for shortcodes and IDs, a Show/Hide help text preference, and fewer page reloads while configuring a source.

Fixes:

* Numeric columns sort numerically (e.g. 3.15, 3.2, 3.9 in numeric order).
* Far-future dates display correctly.

Security:

* Hardened admin request handling and input validation.

= 1.0.1 =
* Improved date formatting controls for WordPress date columns, including support for the site's default date format.
* Security: hardened frontend table and chart configuration output.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

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

This plugin bundles the following libraries and admin thumbnail assets:

* [DataTables](https://datatables.net/) v3.0.2 - MIT License
* DataTables Buttons v4.0.2 - MIT License
* DataTables ColReorder v3.0.1 - MIT License
* DataTables Responsive v4.0.2 - MIT License
* [Select2](https://select2.org/) v4.1.0-rc.0 - MIT License (admin editor pickers)
* [Tom Select](https://tom-select.js.org/) v2.6.2 - Apache License 2.0 (front-end dropdown filters)
* [JSZip](https://stuk.github.io/jszip/) v3.10.1 - MIT License
* [pdfmake](http://pdfmake.org/) v0.2.23 - MIT License (bundles the Roboto font family - Apache License 2.0)
* [ECharts](https://echarts.apache.org/) v6.1.0 - Apache License 2.0
* FileSaver.js - MIT License (bundled inside DataTables Buttons)
* pako - MIT License (bundled inside JSZip)

Source code and uncompressed distribution files for the bundled compressed assets are available here:

* DataTables v3.0.2 source: https://github.com/DataTables/DataTablesSrc/tree/3.0.2
* DataTables v3.0.2 distribution files: https://cdn.datatables.net/3.0.2/
* DataTables Buttons v4.0.2 source: https://github.com/DataTables/Buttons/tree/4.0.2
* DataTables Buttons v4.0.2 distribution files: https://cdn.datatables.net/buttons/4.0.2/
* DataTables ColReorder v3.0.1 source: https://github.com/DataTables/ColReorder/tree/3.0.1
* DataTables ColReorder v3.0.1 distribution files: https://cdn.datatables.net/colreorder/3.0.1/
* DataTables Responsive v4.0.2 source: https://github.com/DataTables/Responsive/tree/4.0.2
* DataTables Responsive v4.0.2 distribution files: https://cdn.datatables.net/responsive/4.0.2/
* Select2 v4.1.0-rc.0 source: https://github.com/select2/select2/tree/4.1.0-rc.0
* Select2 v4.1.0-rc.0 uncompressed JavaScript: https://raw.githubusercontent.com/select2/select2/4.1.0-rc.0/dist/js/select2.js
* Select2 v4.1.0-rc.0 uncompressed CSS: https://raw.githubusercontent.com/select2/select2/4.1.0-rc.0/dist/css/select2.css
* Tom Select v2.6.2 source: https://github.com/orchidjs/tom-select/tree/v2.6.2
* Tom Select v2.6.2 distribution files: https://cdn.jsdelivr.net/npm/tom-select@2.6.2/dist/
* JSZip v3.10.1 source: https://github.com/Stuk/jszip/tree/v3.10.1
* JSZip v3.10.1 uncompressed JavaScript: https://raw.githubusercontent.com/Stuk/jszip/v3.10.1/dist/jszip.js
* pdfmake v0.2.23 source: https://github.com/bpampuch/pdfmake/tree/0.2.23
* pdfmake v0.2.23 distribution files: https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.23/
* FileSaver.js v1.3.3 source: https://github.com/eligrey/FileSaver.js/tree/1.3.3
* pako source: https://github.com/nodeca/pako
* ECharts v6.1.0 source: https://github.com/apache/echarts/tree/6.1.0
* ECharts v6.1.0 uncompressed JavaScript: https://raw.githubusercontent.com/apache/echarts/6.1.0/dist/echarts.js
* BaraTables ECharts bundle entry and build command: https://github.com/trinadin/baratables/blob/main/tools/echarts-entry.js
* Apache ECharts example thumbnail source files: https://echarts.apache.org/examples/data/thumb/
* Apache ECharts examples source: https://github.com/apache/echarts-examples
