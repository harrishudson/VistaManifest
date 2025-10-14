<?php
 require('../../common/common.php');
 $nonce = page_top("BOM Earth3 Projected. Hourly Maximum Near-Surface Wind Speed", "no");
?>

<script nonce="<?php echo $nonce;?>" type="module">
import { CFUtils, CFRender } 
 from '../../common/CFRender.js'
import { TDSCatalogParser, TDSMetadataParser } 
 from '../../common/THREDDS_utils.js'
import { createColorLegendImg, tile_providers, empty_image, formatDateToYYYYMMDD } 
 from '../../common/map_helpers.js'
import { getCache, setCache, cleanCache, openDatabase, 
         storeData, getData, clearAllData, fetchData } 
 from '../../common/offline_storage_helpers.js'
import { SphericalProjection }
 from '../../common/projection_helpers.js'
import { wind_magnitude_cell_stops }
 from '../../common/map_styles.js'

// Primary Map metadata
const gMAP_METADATA = {
 "latest_endpoint": "https://thredds.nci.org.au/thredds/catalog/py18/BARPA/output/CMIP6/DD/AUS-15/BOM/EC-Earth3/ssp585/r1i1p1f1/BARPA-R/v1-r1/1hr/sfcWind/latest/catalog.xml",
 "subsetting_endpoint_prefix": 
  "https://thredds.nci.org.au/thredds/ncss/grid/py18/BARPA/output/CMIP6/DD/AUS-15/BOM/EC-Earth3/ssp585/r1i1p1f1/BARPA-R/v1-r1/1hr/sfcWind/latest/",
 "subsetting_query_string_suffix": 
  "&addLatLon=true&accept=netcdf&format=netcdf3",
 "variable": 'sfcWind',
 "map_title": 
  "BOM Earth3 Projected. Hourly Maximum Near-Surface Wind Speed",
 "author_comment": 
  "Select a Date. Click to view Wind Speed.",
 "catalog_filter_string":
  "sfcWind_AUS-15_EC-Earth3_ssp585_r1i1p1f1_BOM_BARPA-R_v1-r1_1hr_",
 "related_links": 
  [{"label": "THREDDS Endpoint",
    "href": "https://thredds.nci.org.au/thredds/catalog/py18/BARPA/output/CMIP6/DD/AUS-15/BOM/EC-Earth3/ssp585/r1i1p1f1/BARPA-R/v1-r1/1hr/sfcWind/latest/catalog.html"}],
 "map_attribution": 'Data &copy; ANU',
 "map_type": 'grid',
 "layer_starting_opacity": 0.45,
 "cell_color_stops": wind_magnitude_cell_stops,
 "cell_omit_value": function(val) {
  if (((!val) && (val != 0)) || (!isFinite(val)))
   return true
  return false
 },
 "cell_opacity": 1,
 "bound_dimension_filters": ["height"]
}

// Main script

var gOVERLAY = null
var gOVERLAY_OPACITY = gMAP_METADATA["layer_starting_opacity"]
var map
var gBASE_LAYER
var gNETCDF_TDS = null
var gNETCDF_SUBSET_ENDPOINT = null
var gCHOSEN_DAY = null
var gMAP_FITTED = false
var gDATASET_TIME_MESSAGE = null
var gDOCUMENT_HIDDEN = false
var gLATEST = null
var gIMAGES = []
var gIMAGE_OFFSET = null 
var gSHADOW_IMAGES = []
var gRENDER_WORKER = null
var gRENDER_BOUNDS = null
var gGLOBAL_ATTRIBUTES = null
var gVARIABLE_ATTRIBUTES = null
var gMAP_LATEST_REQUEST = 0
var gMAP_CURRENT_REQUEST = null
const dbNAME = 'timeseries_grid'
const dbSTORE = 'timeseries_grid'

function chosen_day_change() {
 fetch_catalog_index()
}

function fetch_catalog_index() {
 if (!gDOCUMENT_HIDDEN) {
  gCHOSEN_DAY = null

  let latest_endpoint = gMAP_METADATA["latest_endpoint"]

  queue_throbber()

  let cached_endpoint = getCache(latest_endpoint)
  if (cached_endpoint) {
   process_latest_index(cached_endpoint)
   return
  }

  fetch(latest_endpoint, {
   method: 'GET',
   headers: {'Content-Type': 'application/xml'},
   mode: 'cors'
  })
   .then(response => response.text())
   .then(str => {
     setCache(latest_endpoint, str)
     process_latest_index(str)
   })
   .catch(error => { dequeue_throbber(); status_msg(error) })
 }
}

function process_latest_index(data) {
 dequeue_throbber()
 if (!data) {
  status_msg('No Data Found.')
  return
 }
 let parser = new DOMParser()
 var xmlDoc = parser.parseFromString(data,"text/xml")
 var TDS = new TDSCatalogParser(xmlDoc)

 check_latest(TDS)
}

function check_latest(TDS) {
 let datasets = TDS.catalog_dataset_datasets
 let the_date = document.getElementById('chosen_day').value
 let the_year = the_date.split('-')[0] 

 let reg = new RegExp(the_year)
 let year_datasets =
 datasets.filter(function(obj) {return reg.test(obj.name)});

 if ((!year_datasets) || (year_datasets.length == 0)) {
  status_msg('No Data Found for that Year.') 
  return;
 }

 // Work out available date ranges
 var start_year = Infinity
 var end_year = -Infinity
 for (let i = 0; i < datasets.length; i++) {
  let year_part = datasets[i].name.replaceAll(gMAP_METADATA["catalog_filter_string"],'').substring(0,4)
  let thisYear = parseInt(year_part)
  if (thisYear < start_year)
   start_year = thisYear
  if (thisYear > end_year)
   end_year = thisYear
 }

 if (!gDATASET_TIME_MESSAGE) {
  gDATASET_TIME_MESSAGE = 'Dates available; '+ start_year.toString() + ' to ' + end_year.toString()
  status_msg(gDATASET_TIME_MESSAGE)
 }

 fetch_metadata(year_datasets[0].name, the_date)
}

function fetch_metadata(theFile, theDate) {
 let endpoint = 
  gMAP_METADATA["subsetting_endpoint_prefix"] +
  theFile 

 let dataset_endpoint = endpoint + '/dataset.xml'

 queue_throbber()

 let cached_endpoint = getCache(dataset_endpoint)
 if (cached_endpoint) {
  process_netcdf_metadata(cached_endpoint, endpoint, theDate)
  return
 }

 fetch(dataset_endpoint, {
  method: 'GET',
  headers: {'Content-Type': 'application/xml'},
  mode: 'cors'
 })
  .then(response => response.text())
  .then(str => {
    setCache(dataset_endpoint, str)
    process_netcdf_metadata(str, endpoint, theDate)
  })
  .catch(error => { 
   dequeue_throbber()
   remove_overlay()
   status_msg(error) 
  })
}

function process_netcdf_metadata(data, endpoint, theDate) {
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
  gCHOSEN_DAY = theDate
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

 if (!gCHOSEN_DAY)
  return

 let theDay = new Date(gCHOSEN_DAY)
 let nextDay = new Date(theDay.getTime() + (24 * 60 * 60 * 1000))
 let time_start = theDay.toISOString()
 let time_end = nextDay.toISOString()

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
 
 //HorizStride
 let sw_pixel = map.latLngToContainerPoint([south, west])
 let ne_pixel = map.latLngToContainerPoint([north, east])
 let img_x_range = Math.abs(sw_pixel.x - ne_pixel.x)
 let img_y_range = Math.abs(sw_pixel.y - ne_pixel.y)
 // Animated visualizations need to cap/limit the image resolution
 // or reponses will come too large and rendering too jerky
 var image_size = {x: img_x_range, y: img_y_range}
 if (img_x_range + img_y_range > 1500)
  image_size = {x: 750, y: 750}
 let HorizStride = gNETCDF_TDS.getHorizStride(image_size, queryBounds)
 
 query_string += "&horizStride="+encodeURIComponent(HorizStride)

 query_string += gMAP_METADATA['subsetting_query_string_suffix']
 
 // Commit to fetch

 fetch_netcdf_for_render(query_string)
}

function fetch_netcdf_for_render(query_string) {
 let endpoint_subset_url = gNETCDF_SUBSET_ENDPOINT + query_string

 queue_throbber()

 gMAP_LATEST_REQUEST++
 const requestId = gMAP_LATEST_REQUEST

 if (gMAP_CURRENT_REQUEST) {
  gMAP_CURRENT_REQUEST.abort()
  if (gRENDER_WORKER) 
   terminate_worker()
 }

 const controller = new AbortController()
 gMAP_CURRENT_REQUEST = controller;

 fetch(endpoint_subset_url,  { signal: controller.signal })
 .then(response => {
        if (!response.ok) 
         throw new Error("Network response was not ok")
        return response.arrayBuffer() 
       })
 .then(buf => {
        if (requestId === gMAP_LATEST_REQUEST)
         process_netcdf(buf)
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
 prepare_images(barray)
}

function prepare_images(barray) {
 if (gRENDER_WORKER) 
  terminate_worker()

 gRENDER_WORKER = new Worker('../../common/spherical_timeseries_grid_worker.js', {type: 'module'})

 gRENDER_WORKER.onmessage = handle_imagery

 gRENDER_WORKER.onerror = (error) => {
   console.error('Worker error:', error);
  }

 let payload = {"barray": barray,
                "variable": gMAP_METADATA['variable'],
                "stops": gMAP_METADATA['cell_color_stops'],
                //"idealCellSize": gMAP_METADATA['idealCellSize'],
                "longitudeWrap": true,
                "omitZeroValues": true,
                "returnGrid": true,
                "returnCaches": false,
                "returnCaches": false,
                "extentCache": null,
                "projectionCache": null}

 gRENDER_WORKER.postMessage(payload, [barray])

 dequeue_throbber()
}

function terminate_worker() {
 gRENDER_WORKER.terminate()
 hide_page_progress()
}

function handle_imagery(e) {
 let payload = e.data

 if (payload.msg == 'begin') {
  gRENDER_BOUNDS = payload.bounds
  gGLOBAL_ATTRIBUTES = payload.globalAttributes
  gVARIABLE_ATTRIBUTES = payload.variableAttributes
  remove_overlay()
  gIMAGE_OFFSET = 0
  gIMAGES = []
  document.getElementById('map_progress').style.visibility = 'hidden'
  return
 }

 if (payload.msg == 'row') {
  let d = payload.data
  gIMAGES.push({'timeVariable': d.timeVariable, 
                'timeValue': d.timeValue,
                'timeUnits': d.timeUnits,
                'timeValueZulu': d.timeValueZulu,
                'timeValueLocal': d.timeValueLocal})
  let key1 = `image_${d.timeVariable}_${d.timeValue}`
  storeData(dbNAME, dbSTORE, key1, d.img)
  let key2 = `time_slice_${d.timeVariable}_${d.timeValue}`
  storeData(dbNAME, dbSTORE, key2, JSON.stringify(d.grid))
  if (payload.total > 6)
   show_page_progress(payload.sofar, payload.total, 'Rendering')
  if (!gOVERLAY) {
   let bounds = gRENDER_BOUNDS
   let imageBounds = [[ bounds[0][1], bounds[0][0] % 360  ], [ bounds[1][1], bounds[1][0] % 360 ]]
   gOVERLAY = L.imageOverlay(empty_image, imageBounds, {opacity: 0}) 
   gOVERLAY.addTo(map)
  }
  return
 }

 if (payload.msg == 'end') {
  hide_page_progress()
  gIMAGE_OFFSET = 0
  document.getElementById('map_progress').style.visibility = 'visible'
  return
 }
}

function shuffle_images() {
 if (gDOCUMENT_HIDDEN)
  return

 if ((!gIMAGE_OFFSET) && (gIMAGE_OFFSET != 0))
  return

 if ((!gIMAGES) || (gIMAGES.length == 0))
  return

 // All good, shuffle in next image
 if (gIMAGE_OFFSET >= gIMAGES.length - 1)
  gIMAGE_OFFSET = 0 
 else gIMAGE_OFFSET++ 

 set_overlay_image(gIMAGES[gIMAGE_OFFSET])

 document.getElementById('map_info').textContent = gIMAGES[gIMAGE_OFFSET].timeValueLocal

 let p = document.getElementById('map_progress')
 p.min = 1
 p.max = gIMAGES.length
 p.value = gIMAGE_OFFSET + 1
}

function set_overlay_image(img_rec) {
 let key1 = `image_${img_rec.timeVariable}_${img_rec.timeValue}`
 getData(dbNAME, dbSTORE, key1).then(
  (img) => { if (gOVERLAY) { gOVERLAY.setUrl(img); update_layer_opacity(); } })
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

 document.getElementById('chosen_day').addEventListener('change', chosen_day_change)
 document.getElementById('chosen_day').addEventListener('click', 
  function(e) {
   e.stopPropagation()
   return false 
  })

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
 });

 // Map attribution
 map.attributionControl.setPrefix(false)
 map.attributionControl.addAttribution(gMAP_METADATA["map_attribution"])

 let now = new Date()
 document.getElementById('chosen_day').value = formatDateToYYYYMMDD(now)

 fetch_catalog_index() 
 window.setInterval(shuffle_images, 600)
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

async function map_tap(evt) {
 close_all_dialogs()

 if (!map) 
  return

 if (gIMAGES.length == 0)
  return

 if ((!gIMAGE_OFFSET) && (gIMAGE_OFFSET != 0))
  return

 let image = gIMAGES[gIMAGE_OFFSET]

 let key = `time_slice_${image.timeVariable}_${image.timeValue}`
 let grid_enc = await fetchData(dbNAME, dbSTORE, key)
 if (!grid_enc)
  return

 let grid = JSON.parse(grid_enc)

 //Need to setup projection function as it cannot be passed back (serialized) in web worker
 grid["XYprojectionFunction"] = SphericalProjection
 let CFR = new CFRender(null,null,null,true)
 CFR.setdata2DGrid(grid) 

 let binds = grid.DimensionFilter
 var extra = ''
 for (let key in binds) {
  if (key != image.timeVariable) {
   extra+= key+': '+binds[key].toString()+'\n'
  }
 }

 let x = CFR.getCellValue(gMAP_METADATA['variable'],
                          grid.DimensionFilter,
                          evt.latlng.lng,
                          evt.latlng.lat,
                          gMAP_METADATA["cell_omit_value"])

 if ((!x) && (x !=0))
  return

 let msg = 'Value: '+ x.toString() + '\n'+
           extra +
           'Date: '+ image.timeValueZulu + '\n'+
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
 if (gGLOBAL_ATTRIBUTES) {
  let globals = gGLOBAL_ATTRIBUTES
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
 if (gVARIABLE_ATTRIBUTES) {
  let attribs = gVARIABLE_ATTRIBUTES
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
   <input id="chosen_day" type="date"><br>
   <span id="map_info" class="map_info"></span><br>
   <progress id="map_progress" class="map_progress"></progress>
  </div>
  <div class="map_buttons">
   <button id="button_zoom_in" title="Zoom In"><i class="fa fa-plus"></i></button><br>
   <button id="button_zoom_out" title="Zoom Out"><i class="fa fa-minus"></i></button><br>
   <button id="button_info" title="Information"><i class="fa fa-info-circle"></i></button><br>
   <button id="button_settings" title="Settings"><i class="fa fa-cog"></i></button><br>
  </div>
 </div>

 <dialog id="map_info_dialog" class="map_dialog">
  <h3>Information</h3>
  <h4>Map Legend</h4>
  <p>
   <img alt="Legend" id="info_legend" class="information" 
    src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==">
  </p>
  <h4>Map Instructions</h4>
  <p>
   <span id="info_author_comment" class="information"></span>
  </p>
  <h4>Possible dataset date/time ranges</h4>
  <p>
   <span id="info_dataset_dates" class="information"></span>
  </p>
  <h4>Related Links</h4>
  <p>
   <span id="info_related_links" class="information"></span>
  </p>
  <h4>Dataset Global Attributes</h4>
  <p>
   <span id="info_global_attributes" class="information"></span>
  </p>
  <h4>Dataset Variable Attributes (<span id="info_variable" class="information"></span>)</h4>
  <p>
   <span id="info_variable_attributes" class="information"></span>
  </p>
  <button id="map_info_dialog_close" title="Close">Close</button>
 </dialog>
 <dialog id="map_settings_dialog" class="map_dialog">
  <h3>Settings</h3>
  <h4>Layer Opacity</h4>
  <p>
   <input type="range" min="20" max="100" id="opacity_range">
   <span id="opacity_range_label"></span>
  </p>
  <h4>Base Layer</h4>
  <p>
   <select id="base_layer_selector"></select>
  </p>
  <button id="map_settings_dialog_close" title="Close">Close</button>
 </dialog>
</div>

<?php page_bottom(); ?>
