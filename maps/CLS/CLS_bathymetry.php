<?php
 $PAGE_TITLE = "Vista Manifest - CLS Bathymetry";
 $METADATA_JS = <<<'JS'
const METADATA = {
  mapTitle: "CLS NOAA Bathymetry etopo2v2",
  datasetName: "CLS NOAA Bathymetry etopo2v2",
  datasetDescription: "CLS NOAA Bathymetry etopo2v2. NetCDF variable: grid_001.",
  sourceNote: "Elevation source: CLS NOAA Bathymetry etopo2v2.",
  providerName: "CLS THREDDS NCSS",
  proxy: 'CLS_thredds_proxy.php?url=',
  subsetEndpoint: "/ncss/dataset-noaa-global-bathymetry-etopo2v2",
  metadataUrl: "/ncss/dataset-noaa-global-bathymetry-etopo2v2/dataset.xml",
  datasetPageUrl: "https://tds-datastore.cls.fr/thredds/ncss/dataset-noaa-global-bathymetry-etopo2v2/dataset.html",
  variable: "Grid_0001",
  longitudeWrap: false,
  querySuffix: "&accept=netcdf",
  initialView: {lat: 0, lon: 0, zoom: 1},
  defaultStrideScale: 2,
  verticalScale: 1,
  stopContext: "bathymetry",
  cellOmitValue: function(val) {
   if ((val >= 0) || (!isFinite(val)))
   return true;
  return false
  }
};
JS;

require_once __DIR__ . '/../../common/elevation_renderer.php';
