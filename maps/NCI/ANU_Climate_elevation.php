<?php
 $PAGE_TITLE = "Vista Manifest - ANUClimate DEM 1.0km Elevation";
 $METADATA_JS = <<<'JS'
const METADATA = {
  mapTitle: "ANUClimate DEM 1.0km Elevation",
  datasetName: "ANUClimate 1.0 DEM",
  datasetDescription: "ANUClimate 1.0 digital elevation model served by NCI THREDDS NCSS. NetCDF variable: altitude.",
  sourceNote: "Elevation source: ANUClimate 1.0 DEM (~1 km cells).",
  providerName: "NCI THREDDS NCSS",
  proxy: null,
  subsetEndpoint: "https://thredds.nci.org.au/thredds/ncss/grid/rr9/eMAST_data/ANUClimate/ANUClimate_v1-0_digital-elevation-model_terrain_0-01deg",
  metadataUrl: "https://thredds.nci.org.au/thredds/ncss/grid/rr9/eMAST_data/ANUClimate/ANUClimate_v1-0_digital-elevation-model_terrain_0-01deg/dataset.xml",
  datasetPageUrl: "https://thredds.nci.org.au/thredds/ncss/grid/rr9/eMAST_data/ANUClimate/ANUClimate_v1-0_digital-elevation-model_terrain_0-01deg/dataset.html",
  variable: "altitude",
  longitudeWrap: false,
  querySuffix: "&accept=netcdf",
  initialView: {lat: -35.275515, lon: 149.097771, zoom: 8},
  defaultStrideScale: 2,
  defaultVerticalExaggeration: 10,
  verticalScale: 1
};
JS;

require_once __DIR__ . '/../../common/elevation_renderer.php';
