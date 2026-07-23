=== BaraTables ===
Contributors: nathannoom
Tags: tables, datatables, charts, csv, shortcode
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create searchable, sortable WordPress tables and charts from CSV files, manual rows, posts, or external databases.

== Description ==

BaraTables builds interactive tables and charts from your site data. Use manual rows, CSV uploads, WordPress content, or an external MySQL/MariaDB database, then publish with a shortcode.

Import a JSON, XML, CSV, or TXT export from another table plugin or a spreadsheet to rebuild an existing table.

Tables can include search, sorting, pagination, filters, export buttons, column visibility controls, and custom control layouts. Charts draw from any BaraTables table and embed separately with their own shortcode.

**Features:**

* Build tables from WordPress content, CSV files, manual data, or external MySQL/MariaDB databases
* Add search, sorting, pagination, and dropdown, multi-select, checkbox, or radio filters, including filtering by category or tag
* Export table data to CSV or Excel, copy it, or print it
* Reorder columns and control column visibility
* Restrict rows by user role or user metadata with row-level access control
* Remember table state between visits, and control scrolling, column sizing, and row loading limits
* Create bar, horizontal bar, line, area, pie, donut, scatter, bubble, funnel, and Gantt charts with ECharts
* Customize table controls, labels, layout, and display options
* Light frontend styles that are easy to override with CSS
* Import an existing table from another plugin or a spreadsheet (JSON, XML, CSV, or TXT)

== Installation ==

1. Upload the `baratables` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to Tables in the admin menu and create your first table.
4. Use the shortcode `[bara_table id="your-table-slug"]` to embed it.

== Frequently Asked Questions ==

= What data sources are supported? =

CSV files from the media library, manual data entry via the admin editor, WordPress content, and external MySQL/MariaDB databases.

= How many rows can a table load? =

Each table loads up to 1,000 rows by default. The Options tab lets administrators choose a limit from 1 to 10,000 rows. The limit applies to tables and charts across every data source.

= Can I link to a filtered view of a table? =

Yes. Filtering or searching a table updates the page address as you go, so you can copy it and share that exact view. You can also build the link yourself using `btbl_filter[column-slug]`, `btbl_search`, and `btbl_search_cols`.

= How do I add a chart? =

Create a table first, then go to Charts and create a new chart linked to that table. Use `[bara_chart id="your-chart-slug"]` to embed it.

= Can I customize the table appearance? =

Yes. The Options tab provides controls for striping, hover effects, borders, compact mode, pagination style, button labels, search text, and info display. You can also arrange layout zones for full control over where controls appear.

= Can I style BaraTables with custom CSS? =

Yes. BaraTables ships minimal frontend styles so your theme stays in control. Use your theme stylesheet, the Site Editor, or additional custom CSS to adjust colors, spacing, typography, borders, and button styles.

= Where can I see examples? =

Visit https://ktisisweb.com/baratables/ for screenshots, feature notes, and styling guidance, or use the Live Preview button on the WordPress.org plugin page for a temporary interactive demo.

== Screenshots ==

1. Frontend chart generated from a BaraTables table.
2. Frontend table with search, filters, CSV and Excel export, column visibility, sorting, and pagination.
3. Table Options tab with saved state, scrolling, row limits, layout, styling, and export controls.
4. Chart builder and chart-type gallery with all supported chart types.

== Changelog ==

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

* [DataTables](https://datatables.net/) v2.3.8 - MIT License
* DataTables Buttons v3.2.6 - MIT License
* DataTables ColReorder v2.1.2 - MIT License
* [Select2](https://select2.org/) v4.1.0-rc.0 - MIT License
* [JSZip](https://stuk.github.io/jszip/) v3.10.1 - MIT License
* [ECharts](https://echarts.apache.org/) v6.1.0 - Apache License 2.0
* FileSaver.js v1.3.3 - MIT License (bundled inside DataTables Buttons)
* pako - MIT License (bundled inside JSZip)

Source code and uncompressed distribution files for the bundled compressed assets are available here:

* DataTables v2.3.8 source: https://github.com/DataTables/DataTablesSrc/tree/2.3.8
* DataTables v2.3.8 distribution files: https://cdn.datatables.net/2.3.8/
* DataTables Buttons v3.2.6 source: https://github.com/DataTables/Buttons/tree/3.2.6
* DataTables Buttons v3.2.6 distribution files: https://cdn.datatables.net/buttons/3.2.6/
* DataTables ColReorder v2.1.2 source: https://github.com/DataTables/ColReorder/tree/2.1.2
* DataTables ColReorder v2.1.2 distribution files: https://cdn.datatables.net/colreorder/2.1.2/
* Select2 v4.1.0-rc.0 source: https://github.com/select2/select2/tree/4.1.0-rc.0
* Select2 v4.1.0-rc.0 uncompressed JavaScript: https://raw.githubusercontent.com/select2/select2/4.1.0-rc.0/dist/js/select2.js
* Select2 v4.1.0-rc.0 uncompressed CSS: https://raw.githubusercontent.com/select2/select2/4.1.0-rc.0/dist/css/select2.css
* JSZip v3.10.1 source: https://github.com/Stuk/jszip/tree/v3.10.1
* JSZip v3.10.1 uncompressed JavaScript: https://raw.githubusercontent.com/Stuk/jszip/v3.10.1/dist/jszip.js
* FileSaver.js v1.3.3 source: https://github.com/eligrey/FileSaver.js/tree/1.3.3
* pako source: https://github.com/nodeca/pako
* ECharts v6.1.0 source: https://github.com/apache/echarts/tree/6.1.0
* ECharts v6.1.0 uncompressed JavaScript: https://raw.githubusercontent.com/apache/echarts/6.1.0/dist/echarts.js
* Apache ECharts example thumbnail source files: https://echarts.apache.org/examples/data/thumb/
* Apache ECharts examples source: https://github.com/apache/echarts-examples
