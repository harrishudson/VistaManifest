<?php
 require('../../common/common.php');
 $nonce = page_top("CMORPH-2 High Resolution Global Precipitation Estimates (Latest)", "no");
?>

<script nonce="<?php echo $nonce;?>" type="module">
import { CFUtils, CFRender } 
 from '../../common/CFRender.js'
import { TDSCatalogParser, TDSMetadataParser } 
 from '../../common/THREDDS_utils.js'
import { createColorLegendImg, tile_providers } 
from '../../common/map_helpers.js'
import { SphericalProjection } 
from '../../common/projection_helpers.js'
import { daily_precipitation_totals_mm_stops } 
 from '../../common/map_styles.js'

// Primary Map metadata
const gMAP_METADATA = {
 "proxy": "COASTWATCH_thredds_proxy.php?url=",
 "year_endpoint_prefix": "/catalog/cmorph/",
 "subsetting_endpoint_prefix": "/ncss/grid/cmorph/",
 "subsetting_query_string_suffix": 
  "&addLatLon=true&accept=netcdf&format=netcdf3",
 "variable": 'cmorph',
 "map_title": 
  "CMORPH-2 High Resolution Global Precipitation Estimates (Latest)",
 "author_comment": 
  "Click to view precipitation value.",
 "related_links": 
  [{"label": "THREDDS Endpoint",
    "href": "https://coastwatch.noaa.gov/thredds/catalog/cmorph/catalog.html"}],
 "map_attribution": 'Data &copy; COASTWATCH NOAA',
 "map_type": 'grid',
 "layer_starting_opacity": 0.7,
 "cell_color_stops": daily_precipitation_totals_mm_stops,
 "cell_omit_value": function(val) {
  if (((!val) && (val != 0)) || (!isFinite(val)) || (val <= 0))
   return true
  return false
 }
}

let cfu = new CFUtils()

function fillCell(cellData) {
 return cfu.steppedHexColor(cellData.value, gMAP_METADATA['cell_color_stops'])
}

function omitCell(cellData) {
 return (gMAP_METADATA['cell_omit_value'](cellData.value))
}

function opacityCell(cellData) {
 return gMAP_METADATA['cell_color_stops']
}

// Main script

var CFR 
var gOVERLAY_OPACITY = gMAP_METADATA["layer_starting_opacity"]
var map
var gOVERLAY = null
var gBASE_LAYER
var gNETCDF_TDS = null
var gNETCDF_SUBSET_ENDPOINT = null
var gNETCDF_PROJECTION_CACHE = null
var gMAP_FITTED = false
var gDATASET_TIME_MESSAGE = null
var gDOCUMENT_HIDDEN = false
var gLATEST_YEAR = null
var gLATEST_DAY = null
var gLATEST_FILE = null
var gMAP_LATEST_REQUEST = 0
var gMAP_CURRENT_REQUEST = null
var gLAST_CATALOG_FETCH_TIME = null

function fetch_catalog_index() {
 if (!gDOCUMENT_HIDDEN) {
  let latest_endpoint = gMAP_METADATA["year_endpoint_prefix"] + '/catalog.xml'
  let proxy = gMAP_METADATA["proxy"]
  let endpoint_url = proxy + encodeURIComponent(latest_endpoint)
  gLAST_CATALOG_FETCH_TIME = new Date()

  gLATEST_YEAR = null
  gLATEST_DAY = null

  queue_throbber()

  fetch(endpoint_url)
   .then(response => response.text())
   .then(str => process_year_index(str))
   .catch(error => { dequeue_throbber(); status_msg(error) })
 }
}

function process_year_index(data) {
 if (!data) {
  dequeue_throbber()
  status_msg('No Data Found.')
  return
 }
 let parser = new DOMParser()
 var xmlDoc = parser.parseFromString(data,"text/xml")
 var TDS = new TDSCatalogParser(xmlDoc)
 check_latest_year(TDS)
}

function check_latest_year(TDS) {
 let years = TDS.catalog_dataset_refs;

 if ((!years) || (years.length == 0)) {
  dequeue_throber()
  status_msg('No Data Found.')
  return;
 }

 let years_rev_sorted = years.sort(function(a,b) { return (b.title - a.title) })
 let latest_year = years_rev_sorted[0].title

 fetch_latest_year_catalog_index(latest_year)
}

function fetch_latest_year_catalog_index(latest_year) {
 let latest_endpoint = gMAP_METADATA["year_endpoint_prefix"] + 
      '/'+
      latest_year +
      '/catalog.xml'
 let proxy = gMAP_METADATA["proxy"]
 let endpoint_url = proxy + encodeURIComponent(latest_endpoint)

 fetch(endpoint_url)
  .then(response => response.text())
  .then(str => process_latest_year_index(str, latest_year))
  .catch(error => { dequeue_throbber(); status_msg(error) })
}

function process_latest_year_index(data, latest_year) {
 if (!data) {
  dequeue_throbber()
  status_msg('No Data Found.')
  return
 }
 let parser = new DOMParser()
 var xmlDoc = parser.parseFromString(data,"text/xml")
 var TDS = new TDSCatalogParser(xmlDoc)
 check_latest_year_index(TDS, latest_year)
}

function check_latest_year_index(TDS, latest_year) {
 let days = TDS.catalog_dataset_refs;
 if ((!days) || (days.length == 0)) {
  dequeue_throbber()
  status_msg('No Data Found.')
  return;
 }

 let days_rev_sorted = days.sort(function(a,b) { return (b.title - a.title) })
 let latest_day = days_rev_sorted[0].title

 fetch_latest_minute_index(latest_year, latest_day)
}

function fetch_latest_minute_index(latest_year, latest_day) {
 let latest_endpoint = gMAP_METADATA["year_endpoint_prefix"] + 
                       '/' +
                       latest_year +
                       '/'+
                       latest_day +
                       '/catalog.xml'
 let proxy = gMAP_METADATA["proxy"]

 let endpoint_url = proxy + encodeURIComponent(latest_endpoint)

 fetch(endpoint_url)
  .then(response => response.text())
  .then(str => process_latest_minute_index(str, latest_year, latest_day))
  .catch(error => { dequeue_throbber(); status_msg(error) })
}

function process_latest_minute_index(data, latest_year, latest_day) {
 if (!data) {
  dequeue_throbber()
  status_msg('No Data Found.')
  return
 }
 let parser = new DOMParser()
 var xmlDoc = parser.parseFromString(data,"text/xml")
 var TDS = new TDSCatalogParser(xmlDoc)
 check_latest_minute(TDS, latest_year, latest_day)
}

function check_latest_minute(TDS, latest_year, latest_day) {
 let minutes = TDS.catalog_dataset_datasets;
 if ((!minutes) || (minutes.length == 0)) {
  dequeue_throbber()
  status_msg('No Data Found.')
  return;
 }
 let reg = new RegExp('CMORPH2_0.05deg-30min')
 let minutes_filtered =
  minutes.filter(function(obj) {return reg.test(obj.name)});

 let minutes_rev_sorted = minutes_filtered.sort((a, b) => b.name.localeCompare(a.name))
 let latest_file = minutes_rev_sorted[0].name

 if (gLATEST_FILE != latest_file) {
  gLATEST_YEAR = latest_year
  gLATEST_DAY = latest_day
  gLATEST_FILE = latest_file
  fetch_metadata()
 } else {
  // No recent updates
  dequeue_throbber()
 }
}

function fetch_metadata() {
 if ((!gLATEST_YEAR) || (!gLATEST_DAY) || (!gLATEST_FILE)) {
  dequeue_thobber()
  return
 }

 let endpoint = 
  gMAP_METADATA["subsetting_endpoint_prefix"] +
  '/'+
  gLATEST_YEAR +
  '/'+
  gLATEST_DAY +
  '/'+
  gLATEST_FILE

 let dataset_endpoint = endpoint + '/dataset.xml'
 let proxy = gMAP_METADATA["proxy"]
 let endpoint_url = proxy + encodeURIComponent(dataset_endpoint)
 fetch(endpoint_url)
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

 let reftime_units = gNETCDF_TDS.Axes['time']['units']
 let time_values = gNETCDF_TDS.Axes['time']['values']
 let time_start = cfu.getTimeISOString(time_values[0], reftime_units)
 let time_end = cfu.getTimeISOString(time_values[time_values.length - 1], reftime_units)

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

 var query_string = "?var="+encodeURIComponent(gMAP_METADATA.variable)

 query_string+= '&time_start='+encodeURIComponent(time_start)
 query_string+= '&time_end='+encodeURIComponent(time_end)

 query_string += "&spatial=bb"+
                 "&east="+encodeURIComponent(east)+
                 "&west="+encodeURIComponent(west)+
                 "&north="+encodeURIComponent(north)+
                 "&south="+encodeURIComponent(south)
 
 //HozizStride
 let sw_pixel = map.latLngToContainerPoint([south, west])
 let ne_pixel = map.latLngToContainerPoint([north, east])
 let img_x_range = Math.abs(sw_pixel.x - ne_pixel.x)
 let img_y_range = Math.abs(sw_pixel.y - ne_pixel.y)
 var image_size = {x: img_x_range, y: img_y_range}
 let HorizStride = gNETCDF_TDS.getHorizStride(image_size, queryBounds, 2.5)
 
 query_string += "&horizStride="+encodeURIComponent(HorizStride)

 query_string += gMAP_METADATA['subsetting_query_string_suffix']
 
 // Commit to fetch

 CFR = null  // Remove existing CFR so map_tap won't draw popups during loading

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
 let img = await CFR.draw2DbasicGrid(gMAP_METADATA['variable'], 
                                     null,
                                     SphericalProjection, 
                                     'url',
                                     {"fill": fillCell,
                                      "opacity": opacityCell,
                                      "omit": omitCell,
                                      "stroke": "none",
                                      "strokeWidth": 0
                                     })

 if (!CFR) {
  dequeue_throbber()
  return
 }

 gNETCDF_PROJECTION_CACHE = CFR.projectionCache

 // Draw overlay
 let bounds = CFR.getXYbbox().bbox
 let imageBounds = [[ bounds[0][1], bounds[0][0] % 360  ], [ bounds[1][1], bounds[1][0] % 360 ]]
 remove_overlay()
 gOVERLAY = L.imageOverlay(img, imageBounds)
 gOVERLAY.addTo(map)
 update_layer_opacity()

 // Slightly hacky as 'time' variable missing proper axis definition in NetCDF
 let theTime = CFR.netCDF.getDataVariable('time')[0]
 let timeUnits = CFR.getVariableUnits('time')
 let timeValue = cfu.getTimeISOString(theTime, timeUnits)
 let localTime = cfu.zuluToLocalTime(timeValue)
 document.getElementById('map_info').textContent = localTime
 gDATASET_TIME_MESSAGE = localTime

 dequeue_throbber()
}

function remove_overlay(no_reset) {
 if (gOVERLAY) 
  map.removeLayer(gOVERLAY)
 gOVERLAY = null
}

function map_startup() {
 map = L.map('map',{"zoomControl": false}).setView([0, 0], 1)
 map.on('click', map_tap)
 map.on('moveend',redraw_map_layer)

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

 document.addEventListener('visibilitychange', () => {
  gDOCUMENT_HIDDEN = document.hidden
  if (!gDOCUMENT_HIDDEN) {
   let now = new Date()
   if ((gLAST_CATALOG_FETCH_TIME) && (gLAST_CATALOG_FETCH_TIME < now - 90000)) 
    window.setTimeout(fetch_catalog_index, 100)
  }
 });

 // Map attribution
 map.attributionControl.setPrefix(false)
 map.attributionControl.addAttribution(gMAP_METADATA["map_attribution"])

 fetch_catalog_index()
 window.setInterval(fetch_catalog_index, 60000)
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
 if (gOVERLAY) 
  gOVERLAY.setOpacity(gOVERLAY_OPACITY)
}

function map_tap(evt) {
 close_all_dialogs()

 if (!map) 
  return

 if (!CFR)
  return

 // Slightly hacky as 'time' variable missing proper axis definition in NetCDF
 let theTime = CFR.netCDF.getDataVariable('time')[0]
 let timeUnits = CFR.getVariableUnits('time')
 let timeValue = cfu.getTimeISOString(theTime, timeUnits)
 let DimensionFilter = {}
 DimensionFilter['time'] = theTime

 let x = CFR.getCellValue(gMAP_METADATA['variable'],
                          DimensionFilter,
                          evt.latlng.lng,
                          evt.latlng.lat,
                          null)  //gMAP_METADATA["cell_omit_value"],

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
    <h4>Map Legend</h4>
    <img alt="Legend" id="info_legend" class="information" src="">
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
