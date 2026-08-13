// Rebuild from the repository root with ECharts 6.1.0 and esbuild installed:
// npx esbuild tools/echarts-entry.js --bundle --minify --format=iife --platform=browser --legal-comments=eof --outfile=assets/vendor/echarts/echarts.min.js
// perl -pi -e 's/[ \t]+$//' assets/vendor/echarts/echarts.min.js
import * as echarts from 'echarts/core';
import {
	BarChart,
	CustomChart,
	FunnelChart,
	HeatmapChart,
	LineChart,
	PieChart,
	RadarChart,
	ScatterChart,
	TreemapChart
} from 'echarts/charts';
import {
	GridComponent,
	LegendComponent,
	RadarComponent,
	TooltipComponent,
	VisualMapComponent
} from 'echarts/components';
import {LabelLayout, UniversalTransition} from 'echarts/features';
import {CanvasRenderer} from 'echarts/renderers';

echarts.use([
	BarChart,
	CustomChart,
	FunnelChart,
	HeatmapChart,
	LineChart,
	PieChart,
	RadarChart,
	ScatterChart,
	TreemapChart,
	GridComponent,
	LegendComponent,
	RadarComponent,
	TooltipComponent,
	VisualMapComponent,
	LabelLayout,
	UniversalTransition,
	CanvasRenderer
]);

window.echarts = echarts;
