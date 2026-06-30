=== BaraTables ===
Contributors: nathannoom
Tags: tables, datatables, charts, csv, shortcode
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create searchable, sortable WordPress tables and charts from CSV files, manual rows, posts, or external databases.

== Description ==

BaraTables helps you turn site data into interactive tables and charts directly in WordPress. Build from manual rows, CSV uploads, WordPress content, or an external MySQL/MariaDB database, then publish the result with a shortcode.

Already have tables elsewhere? Import a JSON, XML, or CSV export from another table plugin or a spreadsheet, and BaraTables rebuilds it for you.

Tables can include search, sorting, pagination, filters, export buttons, column visibility controls, and custom control layouts. Charts can be created from any BaraTables table and displayed separately with their own shortcode.

BaraTables uses clean, theme-friendly frontend styles by default. It is designed to look presentable immediately while staying easy to restyle with your theme or custom CSS.

**Features:**

* Build tables from WordPress content, CSV files, manual data, or external MySQL/MariaDB databases
* Add search, sorting, pagination, and column filters
* Use dropdown, multi-select, checkbox, and radio-style filters
* Export table data to CSV, copy it, or print it
* Reorder columns and control column visibility
* Create bar, line, area, pie, and Gantt charts with ECharts
* Customize table controls, labels, layout, and display options
* Start with light frontend styles that are easy to override with CSS
* Import an existing table from another plugin or a spreadsheet (JSON, XML, or CSV)

== Installation ==

1. Upload the `baratables` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to Tables in the admin menu and create your first table.
4. Use the shortcode `[bara_table id="your-table-slug"]` to embed it.

== Frequently Asked Questions ==

= What data sources are supported? =

CSV files from the media library, manual data entry via the admin editor, WordPress content, and external MySQL/MariaDB databases.

= How do I add a chart? =

Create a table first, then go to Charts and create a new chart linked to that table. Use `[bara_chart id="your-chart-slug"]` to embed it.

= Can I customize the table appearance? =

Yes. The Options tab provides controls for striping, hover effects, borders, compact mode, pagination style, button labels, search text, and info display. You can also arrange layout zones for full control over where controls appear.

= Can I style BaraTables with custom CSS? =

Yes. BaraTables keeps its frontend design intentionally light so your theme can stay in control. You can use your theme stylesheet, the Site Editor, or additional custom CSS to adjust colors, spacing, typography, borders, and button styles.

= Where can I see examples? =

Visit https://ktisisweb.com/baratables/ for screenshots, feature notes, and styling guidance.

== Screenshots ==

1. Frontend chart generated from a BaraTables table.
2. Frontend table with search, filters, export buttons, column visibility, sorting, and pagination.
3. Table builder column and filter controls inside the WordPress admin.
4. Chart builder connected to a BaraTables table with chart type, axis, series, and height settings.

== Changelog ==

= 1.1.1 =
Fixes:
* Columns set to "Format as date" now display the formatted date (e.g. "Mar 18, 2026") on every data source (manual data, CSV, and external database), matching how date columns from a WordPress query already behaved.
* Date columns no longer turn a plain number, such as a year or a count, into a 1970-era date.
* A [bara_table] or [bara_chart] shortcode used without an id no longer causes an error on WordPress 6.2 through 6.4; it shows a "not found" message instead.
* Importing a file that has only a header row, or no rows, now creates an empty table instead of adding blank placeholder rows.
* Front-end table controls (the export buttons, the column-visibility menu, and the "Search in" control) and the CSV file picker now follow the site language instead of always showing English.

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
* Numeric columns now sort numerically instead of being mis-read as dates (e.g. values like 3.15, 3.2, 3.9 sort in numeric order).
* Fixed date columns that mis-displayed far-future dates.
* Security: hardened admin request handling and input validation.

= 1.0.1 =
* Improved date formatting controls for WordPress date columns, including support for the site's default date format.
* Security: hardened frontend table and chart configuration output.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.1 =
"Format as date" now works on every data source, small numbers no longer render as 1970-era dates, front-end controls follow the site language, and attribute-less shortcodes no longer error on older WordPress, plus a security fix for the table editor's column-heading field.

= 1.1.0 =
Feature release: import tables from other plugins or spreadsheets, editable Table and Chart IDs, manual-row reordering, and translation-ready headers, plus admin polish, fixes, and security hardening.

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
* [ECharts](https://echarts.apache.org/) v6.0.0 - Apache License 2.0

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
* ECharts v6.0.0 source: https://github.com/apache/echarts/tree/6.0.0
* ECharts v6.0.0 uncompressed JavaScript: https://raw.githubusercontent.com/apache/echarts/6.0.0/dist/echarts.js
* Apache ECharts example thumbnail source files: https://echarts.apache.org/examples/data/thumb/
* Apache ECharts examples source: https://github.com/apache/echarts-examples

Additional third-party license and notice text is included in assets/vendor/THIRD-PARTY-LICENSES.txt.
