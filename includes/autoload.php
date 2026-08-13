<?php

if (!defined('ABSPATH')) {
	exit;
}

spl_autoload_register(static function (string $class): void {
	$groups = [
		'class-baratables.php' => ['BaraTables'],
		'core.php' => ['BaraTables_Asset_Utils', 'BaraTables_Crypto', 'BaraTables_Id_Generator', 'BaraTables_Source_Type', 'BaraTables_Taxonomy_Filters'],
		'repositories.php' => ['BaraTables_Abstract_CPT_Repository', 'BaraTables_Base_Repository', 'BaraTables_Chart_Repository', 'BaraTables_Entity_Descriptor', 'BaraTables_Persistence_Guard', 'BaraTables_Repository'],
		'row-result.php' => ['BaraTables_Row_Result'],
		'service-query-sanitize.php' => ['BaraTables_Query_Sanitizer'],
		'service-filter-options.php' => ['BaraTables_Filter_Options_Trait'],
		'service-value-format.php' => ['BaraTables_Value_Format_Trait'],
		'service-fields-discovery.php' => ['BaraTables_Fields_Discovery'],
		'service-column-state.php' => ['BaraTables_Column_State_Trait'],
		'services.php' => ['BaraTables_Service'],
		'table-presentation.php' => ['BaraTables_Table_Presentation'],
		'chart-types.php' => ['BaraTables_Chart_Types'],
		'chart-service.php' => ['BaraTables_Chart_Service'],
		'frontend.php' => ['BaraTables_Frontend'],
		'admin/support.php' => [
			'BaraTables_Admin_Action_Guard', 'BaraTables_Admin_Ajax_Guard', 'BaraTables_Admin_Assets',
			'BaraTables_Admin_Definition_Loader', 'BaraTables_Admin_Duplicator', 'BaraTables_Admin_List_Columns',
			'BaraTables_Admin_List_Columns_Base', 'BaraTables_Admin_List_Renderer', 'BaraTables_Admin_Notice',
			'BaraTables_Admin_Page_Utils', 'BaraTables_Admin_Slug_Manager', 'BaraTables_Base_Slug_Manager',
			'BaraTables_Chart_List_Columns', 'BaraTables_Chart_Slug_Manager', 'BaraTables_Entity_Persistence',
			'BaraTables_Help', 'BaraTables_Post_Cleanup', 'BaraTables_Post_Input', 'BaraTables_Pre_Update_State',
		],
		'admin/form-context.php' => ['BaraTables_Admin_Form_Context'],
		'admin/ui.php' => ['BaraTables_Admin_Tab_Advanced', 'BaraTables_Admin_Tab_Chart', 'BaraTables_Admin_Tab_Columns', 'BaraTables_Admin_Tab_General', 'BaraTables_Admin_Tab_Table'],
		'admin/actions.php' => ['BaraTables_Admin_Action_Handler'],
		'admin/preview.php' => ['BaraTables_Admin_Preview_Renderer'],
		'admin/pages.php' => ['BaraTables_Admin_Pages'],
		'admin/options.php' => ['BaraTables_Admin_Options'],
		'admin/admin.php' => ['BaraTables_Admin', 'BaraTables_Chart_Admin'],
	];

	if ($class === 'BaraTables_Importer' || strpos($class, 'BaraTables_Import_') === 0) {
		require_once __DIR__ . '/admin/import.php';
		return;
	}
	foreach ($groups as $file => $classes) {
		if (in_array($class, $classes, true)) {
			require_once __DIR__ . '/' . $file;
			return;
		}
	}
});
