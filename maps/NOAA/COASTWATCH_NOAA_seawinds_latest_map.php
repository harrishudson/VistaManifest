<?php
 require('../../common/common.php');
 $nonce = page_top('NOAA Coastwatch Global 10 km Blended Daily Seawinds - Latest', 'no');
?>

<script nonce="<?php echo $nonce;?>" type="module">
import { CFUtils, CFRender } 
 from '../../common/CFRender.js'
import { TDSCatalogParser, TDSMetadataParser } 
 from '../../common/THREDDS_utils.js'
import { createColorLegendImg, tile_providers, 
         getOneMonthPrior, formatDateToYYYYMMDD } 
 from '../../common/map_helpers.js'
import { setCache, getCache } 
 from '../../common/offline_storage_helpers.js'
import { SphericalProjection } 
 from '../../common/projection_helpers.js'
import { windOmitArrow, windArrowOpacity, windScaleArrow,
         windAnimateArrow, windArrowFill, windCellFill, windOmitCell,
         wind_magnitude_cell_stops, wind_magnitude_arrow_stops }
 from '../../common/map_styles.js'

// Primary Map metadata
const gMAP_METADATA = {
 "map_title": "NOAA Coastwatch Global 10 km Blended Daily Seawinds - Latest",
 "proxy": "COASTWATCH_thredds_proxy.php?url=",
 "catalog_endpoint": 
  "/catalog/uvcompNCEIBlendedGlobalNRTDailyWW00/catalog.xml",
 "subsetting_endpoint_prefix": 
  "/ncss/grid/uvcompNCEIBlendedGlobalNRTDailyWW00/",
 "subsetting_query_string_suffix": 
  //"&disableProjSubset=on&addLatLon=true&accept=netcdf&format=netcdf3",
  "&addLatLon=true&accept=netcdf&format=netcdf3",
 "author_comment": 
  "Click for Wind Magnitude information.  Click Arrows for Average Vector information.",
 "related_links": 
  [{"label":"THREDDS Endpoint",
    "href":"https://coastwatch.noaa.gov/thredds/catalog/uvcompNCEIBlendedGlobalNRTDailyWW00/catalog.html"}],
 "variable": "windspeed",
 "cell_color_stops": wind_magnitude_cell_stops,
 "arrow_color_stops": wind_magnitude_arrow_stops,
 "map_attribution": "Data NOAA Coastwatch",
 "layer_starting_opacity": 0.4,
 "cell_omit_value": function(val) {
  if (((!val) && (val != 0)) || (!isFinite(val)))
   return true
  return false
 },
 "cell_opacity": 1,
}

let cfu = new CFUtils()

// Main script

var CFR;  // CFR is the main CFRender object
var map
var gBASE_LAYER
var gARROW_OVERLAY
var gCELL_OVERLAY
var gLATEST = null
var gNETCDF_TDS = null
var gOVERLAY_OPACITY = gMAP_METADATA["layer_starting_opacity"]
var gNETCDF_SUBSET_ENDPOINT = null
var gNETCDF_PROJECTION_CACHE = null
var gDATASET_TIME_MESSAGE = null
var gMAP_FITTED = false
var gMAP_LATEST_REQUEST = 0
var gMAP_CURRENT_REQUEST = null

function fetch_catalog_index() {
 let proxy = gMAP_METADATA["proxy"]
 let endpoint = gMAP_METADATA["catalog_endpoint"]
 let endpoint_url = proxy + encodeURIComponent(endpoint)

 queue_throbber()

 let cached_endpoint = getCache(endpoint_url)
 if (cached_endpoint) {
  process_catalog_index(cached_endpoint)
  return
 }
 
 fetch(endpoint_url)
  .then(response => response.text())
  .then(function(str) {
    setCache(endpoint_url, str)
    process_catalog_index(str)
   })
  .catch(error => { 
    dequeue_throbber(); 
    remove_overlay()
    status_msg(error) 
   })
}

function process_catalog_index(data) {
 dequeue_throbber()

 if (!data) {
  remove_overlay()
  status_msg('No Data Found.')
  return
 }

 let parser = new DOMParser()
 var xmlDoc = parser.parseFromString(data,"text/xml")
 var TDS = new TDSCatalogParser(xmlDoc)
 populate_dates(TDS)
}

function populate_dates(TDS) {
 let datasets = TDS.catalog_dataset_datasets
 gLATEST = datasets[datasets.length - 1].name

 fetch_file_metadata()
}

function fetch_file_metadata() {
 let endpoint = gMAP_METADATA["subsetting_endpoint_prefix"] + gLATEST 

 let dataset_endpoint = endpoint + '/dataset.xml'

 let proxy = gMAP_METADATA["proxy"]
 let dataset_proxy_url = proxy + encodeURIComponent(dataset_endpoint)

 queue_throbber()

 fetch(dataset_proxy_url)
  .then(response => response.text())
  .then(str => process_netcdf_metadata(str, endpoint))
  .catch(error => { 
   dequeue_throbber()
   remove_overlay()
   status_msg(error) 
  })
}

function process_netcdf_metadata(data, endpoint) {
 dequeue_throbber()
 if (!data) {
  status_msg('No Data Found.')
  return
 }
 try {
  let parser = new DOMParser()
  var xmlDoc = parser.parseFromString(data,"text/xml")
  gNETCDF_TDS = new TDSMetadataParser(xmlDoc, true)
  gNETCDF_SUBSET_ENDPOINT = endpoint
 } catch(e) {
  status_msg('No Data Found.')
  console.log(e)
  return
 }

 fit_map_bounds()
 window.setTimeout(redraw_map_layer, 100)
}

function fit_map_bounds() {
 if (gMAP_FITTED)
  return
 let LatLonBox = gNETCDF_TDS.getLatLonBox()
 let NetCDFBounds = [ [LatLonBox['south'], LatLonBox['west']],
                      [LatLonBox['north'], LatLonBox['east']] ]
 map.fitBounds(NetCDFBounds)
 gMAP_FITTED = true
}

function redraw_map_layer() {
 if (!gNETCDF_SUBSET_ENDPOINT)
  return

 // Grid Bounds
 let LatLonBox = gNETCDF_TDS.getLatLonBox()
 //Map Bounds
 let bounds = map.getBounds()
 let east = Math.min(bounds.getEast(), LatLonBox['east'])
 let west = Math.max(bounds.getWest(), LatLonBox['west'])
 let north = Math.min(bounds.getNorth(), LatLonBox['north'])
 let south = Math.max(bounds.getSouth(), LatLonBox['south'])
 // Clipped bounds
 let queryBounds = [[west, south],[east, north]]

 if ((east <= west) || (north <= south)) {
  remove_overlay()
  return
 }

 // Add Variables (hard coded)
 var query_string = `?var=u_wind&var=v_wind&var=${gMAP_METADATA['variable']}`

 query_string += "&spatial=bb"+
                 "&east="+encodeURIComponent(east)+
                 "&west="+encodeURIComponent(west)+
                 "&north="+encodeURIComponent(north)+
                 "&south="+encodeURIComponent(south)

 //HorizStride
 let sw_pixel = map.latLngToContainerPoint([south, west])
 let ne_pixel = map.latLngToContainerPoint([north, east])
 let img_x_range = Math.abs(sw_pixel.x - ne_pixel.x)
 let img_y_range = Math.abs(sw_pixel.y - ne_pixel.y)
 var image_size = {x: img_x_range, y: img_y_range}
 let HorizStride = gNETCDF_TDS.getHorizStride(image_size, queryBounds)
 
 query_string += "&horizStride="+encodeURIComponent(HorizStride)
 
 query_string += gMAP_METADATA['subsetting_query_string_suffix']
 
 // Commit to fetch

 CFR = null  

 fetch_netcdf_for_render(query_string)
}

function fetch_netcdf_for_render(query_string) {
 let netcdf_subset_url = gNETCDF_SUBSET_ENDPOINT + query_string
 let proxy = gMAP_METADATA["proxy"]
 let endpoint_proxy_subset_url = proxy + encodeURIComponent(netcdf_subset_url)

 queue_throbber()

 gMAP_LATEST_REQUEST++
 const requestId = gMAP_LATEST_REQUEST

 if (gMAP_CURRENT_REQUEST) 
  gMAP_CURRENT_REQUEST.abort()

 const controller = new AbortController()
 gMAP_CURRENT_REQUEST = controller;

 fetch(endpoint_proxy_subset_url,  { signal: controller.signal })
 .then(response => {
        if (!response.ok) 
         throw new Error("Network response was not ok")
        return response.arrayBuffer() 
       })
 .then(buf => {
        if (requestId === gMAP_LATEST_REQUEST)
         process_netcdf(buf)
        else
         dequeue_throbber()
       })
 .catch(error => { 
         dequeue_throbber();
         if (error.name !== 'AbortError') 
          status_msg(error) 
        })
}

function process_netcdf(barray) {
 if (!barray) {
  dequeue_throbber()
  status_msg('No Data Found.')
  return
 }

 CFR = new CFRender(barray, null, gNETCDF_PROJECTION_CACHE, true)

 render_image()
}

async function render_image() {
 let LatLonBox = CFR.getXYbbox().bbox
 let bounds = LatLonBox
 let east = LatLonBox[1][0]
 let west = LatLonBox[0][0]
 let north = LatLonBox[1][1]
 let south = LatLonBox[0][1]
 let sw_pixel = map.latLngToContainerPoint([south, west])
 let ne_pixel = map.latLngToContainerPoint([north, east])
 let imgWidth = Math.abs(sw_pixel.x - ne_pixel.x)
 let imgHeight = Math.abs(sw_pixel.y - ne_pixel.y)
 let imageBounds = [[ south, west ], [ north, east ]]

 let img1 = await CFR.draw2DbasicGrid(gMAP_METADATA['variable'],
                                      {},
                                      SphericalProjection, 
                                      'url',
                                      {"fill": windCellFill,
                                       "opacity": 1,
                                       "stroke": "none",
                                       "strokeWidth": 0 ,
                                       "omit": windOmitCell
                                      })


 if (!CFR) {
  dequeue_throbber()
  return
 }

 if (gCELL_OVERLAY) 
  map.removeLayer(gCELL_OVERLAY)

 gCELL_OVERLAY = L.imageOverlay(img1, imageBounds) 
 gCELL_OVERLAY.addTo(map)
 update_layer_opacity() 

 let img = CFR.draw2DbasicVector('u_wind',
                                 'v_wind',
                                 0,
                                 {},
                                 imgWidth,
                                 imgHeight,
                                 SphericalProjection, 
                                 'svg',
                                 {"omit": windOmitArrow,
                                  "fill": windArrowFill,
                                  "opacity": windArrowOpacity,
                                  "symbol": 'vane',
                                  "symbolSize": windScaleArrow,
                                  "idealArrowSize": 20,
                                  "animateDuration": windAnimateArrow,
                                  "eventListeners": [
                                   ['click', show_arrow_data, null],
                                  ]
                                })

 if (gARROW_OVERLAY) 
  map.removeLayer(gARROW_OVERLAY)

 gARROW_OVERLAY = L.svgOverlay(img, imageBounds, {interactive: true}) 
 gARROW_OVERLAY.addTo(map)

 gNETCDF_PROJECTION_CACHE = CFR.projectionCache

 let theTime = CFR.netCDF.getDataVariable('time')[0]
 let timeUnits = CFR.getVariableUnits('time')
 let timeValue = cfu.getTimeISOString(theTime, timeUnits)
 let localTime = cfu.zuluToLocalTime(timeValue)
 document.getElementById('map_info').textContent = localTime
 gDATASET_TIME_MESSAGE = localTime

 dequeue_throbber()
}

function remove_overlay(no_reset) {
 if (gARROW_OVERLAY) 
  map.removeLayer(gARROW_OVERLAY)
 gARROW_OVERLAY = null
 if (gCELL_OVERLAY) 
  map.removeLayer(gCELL_OVERLAY)
 gCELL_OVERLAY = null
}

function map_startup() {
 map = L.map('map',{"zoomControl": false}).setView([0, 0], 1)
 map.on('click', map_tap)
 map.on('moveend', redraw_map_layer)

 // Map buttons & Dialog controls eventListeners
 document.getElementById("button_zoom_in").addEventListener('click', 
  function(e) { 
   map.zoomIn()
   e.stopPropagation()
   return false 
  })
 document.getElementById("button_zoom_out").addEventListener('click', 
  function(e) { 
   map.zoomOut()
   e.stopPropagation()
   return false
 })

 document.getElementById("button_info").addEventListener('click', 
  function(e) { 
   close_all_dialogs()
   make_info_dialog()
   let d = document.getElementById('map_info_dialog')
   d.show()
   document.body.appendChild(d)
   d.scrollTo(0,0)
   e.stopPropagation() 
   return false 
  })
 document.getElementById('map_info_dialog').addEventListener('click', 
  function(e) {
   e.stopPropagation()
   return false
  })
 document.getElementById('map_info_dialog_close').addEventListener('click', 
  function(e) { 
   document.getElementById('map_info_dialog').close()
   e.stopPropagation()
   return false
  })

 document.getElementById("button_settings").addEventListener('click', 
  function(e) { 
   close_all_dialogs()
   let d = document.getElementById('map_settings_dialog')
   d.show()
   document.body.appendChild(d)
   e.stopPropagation() 
   return false 
  })
 document.getElementById('map_settings_dialog').addEventListener('click', 
  function(e) {
   e.stopPropagation()
   return false
  })
 document.getElementById('map_settings_dialog_close').addEventListener('click', 
  function(e) { 
   document.getElementById('map_settings_dialog').close()
   e.stopPropagation()
   return false
  })

 document.getElementById('opacity_range').addEventListener('input',
  function(e) { 
   document.getElementById('opacity_range_label').textContent = this.value+'%' 
   gOVERLAY_OPACITY = this.value / 100
   update_layer_opacity()
  })
 document.getElementById('opacity_range').value = gOVERLAY_OPACITY * 100
 document.getElementById('opacity_range_label').textContent = (gOVERLAY_OPACITY * 100).toString()+'%'

 // Map Title
 if (gMAP_METADATA.map_title)
  document.getElementById('map_title').textContent = gMAP_METADATA.map_title

 // Base Layer
 let base_layer_selector = document.getElementById('base_layer_selector')
 for (let i = 0; i < tile_providers.length; i++) {
  let opt = document.createElement('option')
  opt.value = tile_providers[i].title
  opt.textContent = tile_providers[i].title
  base_layer_selector.appendChild(opt)
 }
 if (localStorage['base_layer'])
  base_layer_selector.value = localStorage['base_layer']
 base_layer_selector.addEventListener('change', set_map_base_layer)
 set_map_base_layer()

 // Container resize
 window.addEventListener("resize", function() {
  try { map.invalidateSize() } catch(e) {}
 });

 // Map attribution
 map.attributionControl.setPrefix(false)
 map.attributionControl.addAttribution(gMAP_METADATA["map_attribution"])

 status_msg(gMAP_METADATA['author_comment']) 

 fetch_catalog_index()
}

function set_map_base_layer() {
 let base_layer = document.getElementById('base_layer_selector').value
 localStorage['base_layer'] = base_layer
 let layer = tile_providers.find((val) => {
  return val.title === base_layer
 })
 try { map.removeLayer(gBASE_LAYER) } catch(e) {} 
 gBASE_LAYER = L.tileLayer(layer.url, {attribution: layer.attrib})
 if (map)
  gBASE_LAYER.addTo(map)
}

function update_layer_opacity() {
 if (gCELL_OVERLAY) 
  gCELL_OVERLAY.setOpacity(gOVERLAY_OPACITY)
}

function show_arrow_data(e) {
 let arrow_data = e.srcElement.dataset
 status_msg(`Avg Arrow Magnitude:\n ${arrow_data['value_magnitude']}\nAvg Arrow Direction:\n ${arrow_data['value_direction']}`)
}

function map_tap(evt) {
 close_all_dialogs()

 if (!map) 
  return

 if (!CFR)
  return

 let theTime = CFR.netCDF.getDataVariable('time')[0]
 let timeUnits = CFR.getVariableUnits('time')
 let timeValue = cfu.getTimeISOString(theTime, timeUnits)
 //let DimensionFilter = {}
 //DimensionFilter['time'] = theTime

 let DimensionFilter = CFR.getdata2DGrid().DimensionFilter

 let x = CFR.getCellValue(gMAP_METADATA['variable'],
                          DimensionFilter,
                          evt.latlng.lng,
                          evt.latlng.lat,
                          null) //gMAP_METADATA["cell_omit_value"],

 if ((!x) && (x !=0))
  return

 let msg = 'Value: '+ x.toString() + '\n'+
           'Date: '+ timeValue.toString() + '\n'+
           'Lat: '+ evt.latlng.lat.toString() + '\n'+
           'Lon: '+ evt.latlng.lng.toString()

 status_msg(msg)
}

function make_info_dialog() {
 document.getElementById('info_variable').textContent = 
  gMAP_METADATA['variable']
 document.getElementById('info_legend').src = 
  createColorLegendImg(gMAP_METADATA["cell_color_stops"])
 document.getElementById('arrow_legend').src = 
  createColorLegendImg(gMAP_METADATA["arrow_color_stops"])
 document.getElementById('info_author_comment').textContent = 
  gMAP_METADATA['author_comment']
 document.getElementById('info_dataset_dates').textContent = 
  gDATASET_TIME_MESSAGE

 document.getElementById('info_related_links').innerHTML = null
 let links = gMAP_METADATA['related_links']
 if (links) {
  for (let i=0; i < links.length; i++) {
   let a = document.createElement('a')
   a.setAttribute('href', links[i]['href'])
   a.textContent = links[i]['label']
   let br = document.createElement('br')
   document.getElementById('info_related_links').appendChild(a)
   document.getElementById('info_related_links').appendChild(br)
  } 
 }

 document.getElementById('info_global_attributes').innerHTML = null
 if ((CFR) &&
     (CFR.netCDF) &&
     (CFR.netCDF.headers) &&
     (CFR.netCDF.headers['globalAttributes'])) {
  let globals = CFR.netCDF.headers['globalAttributes']
  let dl = document.createElement('dl')
  for (let a = 0; a < globals.length; a++) {
   let attr = globals[a]
   let dt = document.createElement('dt')
   dt.textContent = `${attr.name} (${attr.type})`
   dl.appendChild(dt)
   let dd = document.createElement('dd')
   dd.textContent = attr.value
   dl.appendChild(dd)
  }
  document.getElementById('info_global_attributes').appendChild(dl)
 }

 document.getElementById('info_variable_attributes').innerHTML = null
 if ((CFR) &&
     (CFR.netCDF) &&
     (CFR.netCDF.headers)) {

  let variable = CFR.netCDF.headers.variables.find((val) => {
   return val.name === gMAP_METADATA['variable']
  })

  if ((variable) && (variable.attributes)) {
   let attribs = variable.attributes
   let dl = document.createElement('dl')
   for (let a = 0; a < attribs.length; a++) {
    let attr = attribs[a]
    let dt = document.createElement('dt')
    dt.textContent = `${attr.name} (${attr.type})`
    dl.appendChild(dt)
    let dd = document.createElement('dd')
    dd.textContent = attr.value
    dl.appendChild(dd)
    }
    document.getElementById('info_variable_attributes').appendChild(dl)
  }
 }
}

function close_all_dialogs() {
 let dialogs = document.querySelectorAll('dialog')
 for (let d = 0; d < dialogs.length; d++) {
  dialogs[d].close()
 }
}

window.onload = map_startup
</script>

<div class="full_screen">
 <div id="map" style="overflow:hidden; width:100%; height:100%;"> 
  <div class="map_headings">
   <span id="map_title" class="map_title"></span><br>
   <span id="map_info" class="map_info"></span><br>
  </div>
  <div class="map_buttons">
   <button id="button_zoom_in" title="Zoom In"><i class="fa fa-plus"></i></button><br>
   <button id="button_zoom_out" title="Zoom Out"><i class="fa fa-minus"></i></button><br>
   <button id="button_info" title="Information"><i class="fa fa-info-circle"></i></button><br>
   <button id="button_settings" title="Settings"><i class="fa fa-cog"></i></button><br>
  </div>
 </div>

 <dialog id="map_info_dialog" class="map_dialog">
  <p>
   <h3>Information</h3>
  </p>
  <p>
    <h4>Layer Map Legend</h4>
    <img alt="Legend" id="info_legend" class="information" src="">
    <h4>Arrow Map Legend</h4>
    <img alt="Legend" id="arrow_legend" class="information" src="">
  </p>
  </p>
  <p>
   <h4>Map Instructions</h4>
    <span id="info_author_comment" class="information"></span>
  </p>
  </p>
   <h4>Possible dataset date/time ranges</h4>
    <span id="info_dataset_dates" class="information"></span>
  </p>
  <p>
   <h4>Related Links</h4>
    <span id="info_related_links" class="information"></span>
  </p>
  <p>
    <h4>Dataset Global Attributes</h4>
    <span id="info_global_attributes" class="information"></span>
  </p>
  <p>
   <h4>Dataset Variable Attributes (<span id="info_variable" class="information"></span>)</h4>
    <span id="info_variable_attributes" class="information"></span>
  </p>
  <button id="map_info_dialog_close" title="Close">Close</button>
 </dialog>
 <dialog id="map_settings_dialog" class="map_dialog">
  <p>
   <h3>Settings</h3>
  </p>
  <p><h4>Layer Opacity</h4>
   <input type="range" min="20" max="100" id="opacity_range">
   <span id="opacity_range_label"></span>
  </p>
  <p><h4>Base Layer</h4>
   <select id="base_layer_selector"></select>
  </p>
  <button id="map_settings_dialog_close" title="Close">Close</button>
 </dialog>
</div>

<?php page_bottom(); ?>
