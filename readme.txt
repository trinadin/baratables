=== BaraTables ===
Contributors: nathannoom
Tags: tables, datatables, charts, csv, shortcode
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create searchable, sortable WordPress tables and charts from CSV files, manual rows, posts, or external databases.

== Description ==

BaraTables helps you turn site data into interactive tables and charts directly in WordPress. Build from manual rows, CSV uploads, WordPress content, or an external MySQL/MariaDB database, then publish the result with a shortcode.

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
* Import compatible table definitions from JSON

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

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

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
