<?php
 require('../../common/common.php');
 $nonce = page_top("WaveWatch III Global Wave Model - Latest Wind Analysis", "no");
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
import { openDatabase, storeData, getData, clearAllData, fetchData }
 from '../../common/offline_storage_helpers.js'
import { waveOmitArrow, waveArrowOpacity, waveScaleArrow,
         waveAnimateArrow, waveArrowFill, waveCellFill, waveOmitCell,
         wave_magnitude_cell_stops, wave_magnitude_arrow_stops }
 from '../../common/map_styles.js'

// Primary Map metadata
const gMAP_METADATA = {
 "proxy": "PACIOOS_thredds_proxy.php?url=",
 "latest_endpoint": "/catalog/ww3_global/runs/catalog.xml",
 "subsetting_endpoint_prefix": "/ncss/ww3_global/runs/",
 "subsetting_query_string_suffix": 
  "&disableLLSubset=on&disableProjSubset=on&addLatLon=true&accept=netcdf&format=netcdf3",
 "map_title": 
  "WaveWatch III Global Wave Model - Latest Wave Analysis", 
 "author_comment": 
  "Click to Arrows to View average wave data.  Click Map to show wave Magnitude.",
 "related_links": 
  [{"label": "THREDDS Endpoint",
    "href": "https://pae-paha.pacioos.hawaii.edu/thredds/catalog/ww3_global/catalog.html" }],
 "map_attribution": 'Data &copy; PacIOOS',
 "map_type": 'grid',
 "variable": 'whgt',
 "variable2": 'wdir',
 "cell_color_stops": wave_magnitude_cell_stops,
 "arrow_color_stops": wave_magnitude_arrow_stops,
 "layer_starting_opacity": 0.4,
 "cell_omit_value": function(val) {
  if (((!val) && (val != 0)) || (!isFinite(val)))
   return true
  return false
 }
}

// Main script

let cfu = new CFUtils()

var CFR
var gCELL_OVERLAY = null
var gARROW_OVERLAY = null
var gOVERLAY_OPACITY = gMAP_METADATA["layer_starting_opacity"]
var map
var gBASE_LAYER
var gNETCDF_TDS = null
var gNETCDF_SUBSET_ENDPOINT = null
var gNETCDF_PROJECTION_CACHE = null
var gMAP_FITTED = false
var gDATASET_TIME_MESSAGE = null
var gDOCUMENT_HIDDEN = false
var gLATEST = null
var gIMAGES = []
var gARROWS = []
var gIMAGE_OFFSET = null 
var gRENDER_WORKER = null
var gRENDER_BOUNDS = null
var gPROJECTION_CACHE = null
var gGLOBAL_ATTRIBUTES = null
var gVARIABLE_ATTRIBUTES = null
var gMAP_LATEST_REQUEST = 0
var gMAP_CURRENT_REQUEST = null
const dbNAME = 'timeseries_grid'
const dbSTORE = 'timeseries_grid'

function fetch_catalog_index() {
 if (!gDOCUMENT_HIDDEN) {
  let latest_endpoint = gMAP_METADATA["latest_endpoint"]
  let proxy = gMAP_METADATA["proxy"]
  let endpoint_url = proxy + encodeURIComponent(latest_endpoint)

  queue_throbber()

  fetch(endpoint_url)
   .then(response => response.text())
   .then(str => find_latest_index(str))
   .catch(error => { dequeue_throbber(); status_msg(error) })
 }
}

function find_latest_index(data) {
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

 let latest = TDS.catalog_dataset_datasets[0].name
 if (gLATEST != latest) {
  gLATEST = latest
  fetch_metadata()
 }
}

function fetch_metadata() {
 if (!gLATEST)
  return

 let endpoint = 
  gMAP_METADATA["subsetting_endpoint_prefix"] +
  gLATEST

 let dataset_endpoint = endpoint + '/dataset.xml'
 let proxy = gMAP_METADATA["proxy"]
 let endpoint_url = proxy + encodeURIComponent(dataset_endpoint)
 queue_throbber()
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
  gNETCDF_TDS = new TDSMetadataParser(xmlDoc)
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

 remove_overlay()
 document.getElementById('map_info').innerText = null
 document.getElementById('map_progress').style.visibility = 'hidden'

 let reftime_units = gNETCDF_TDS.Axes['time']['units']
 let time_values = gNETCDF_TDS.Axes['time']['values']
 // Scan through slices to look for future date
 let now = new Date()
 var time_start_offset = 0, time_start, time_end
 if (time_values.length > 4) {
  for (let i=0; i<time_values.length; i++) {
   let time_slice = cfu.getTimeISOString(time_values[i], reftime_units)
   let time_slice_date = new Date(time_slice)
   if (time_slice_date > now) {
    time_start_offset = Math.max(0, i - 4) 
    time_start = cfu.getTimeISOString(time_values[time_start_offset], reftime_units)
    break;
   }
  }
 }

 if (!time_start) {
  time_start = cfu.getTimeISOString(time_values[0], reftime_units)
  time_start_offset = 0
 }

 // Get up to next 12 timeslices
 let max_slice = time_start_offset + Math.min(((time_values.length - 1) - time_start_offset), 12)
 time_end = cfu.getTimeISOString(time_values[max_slice], reftime_units)

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

 var query_string = `?var=${gMAP_METADATA['variable']}&var=${gMAP_METADATA['variable2']}`

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
 var image_size = {x: img_x_range, y: img_y_range}
 let HorizStride = gNETCDF_TDS.getHorizStride(image_size, queryBounds)
 
 query_string += "&horizStride="+encodeURIComponent(HorizStride)

 query_string += gMAP_METADATA['subsetting_query_string_suffix']
 
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
         process_netcdf(buf, gMAP_LATEST_REQUEST)
       })
 .catch(error => { 
         dequeue_throbber();
         if (error.name !== 'AbortError') 
          status_msg(error) 
        })
}

function process_netcdf(barray, request_counter) {
 if (!barray) {
  dequeue_throbber()
  status_msg('No Data Found.')
  return
 }
 prepare_images(barray, request_counter)
}

function prepare_images(barray, request_counter) {
 CFR = new CFRender(barray, null, gPROJECTION_CACHE)

 let bounds = CFR.getXYbbox().bbox
 let global_attributes = CFR.netCDF.headers['globalAttributes']

 let time_var = CFR.Axes['T']['axis']
 let time_values = CFR.netCDF.getDataVariable(time_var)
 let timeUnits = CFR.getVariableUnits(time_var)

 let time_start = time_values[0]
 let time_end = time_values[time_values.length -1]
 let time1Value = cfu.getTimeISOString(time_start, timeUnits)
 let time2Value = cfu.getTimeISOString(time_end, timeUnits)
 let local1Time = cfu.zuluToLocalTime(time1Value)
 let local2Time = cfu.zuluToLocalTime(time2Value)
 gDATASET_TIME_MESSAGE = `${local1Time} to ${local2Time}`

 gGLOBAL_ATTRIBUTES = CFR.netCDF.headers['globalAttributes']
 let NetCDF_variable = CFR.netCDF.headers.variables.find((val) => {
   return val.name === gMAP_METADATA['variable']
  })
 if ((NetCDF_variable) && (NetCDF_variable.attributes))
  gVARIABLE_ATTRIBUTES = NetCDF_variable.attributes

 hide_page_progress()

 remove_overlay()
 document.getElementById('map_info').innerText = null
 document.getElementById('map_progress').style.visibility = 'hidden'
 render_loop(request_counter, 0, time_values, time_var, timeUnits)
 return
}

async function render_loop(request_counter, offset, time_values, time_var, timeUnits) {
 if ((request_counter != gMAP_LATEST_REQUEST) || (!CFR)) {
  hide_page_progress()
  dequeue_throbber()
  return
 }

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

 let dimensionFilter = {}
 dimensionFilter[time_var] = time_values[offset]

 if (time_values.length > 6)
  show_page_progress(offset, time_values.length, 'Rendering')

 let cellImg = await CFR.draw2DbasicGrid(gMAP_METADATA['variable'],
                                         dimensionFilter,
                                         SphericalProjection, 
                                         'url',
                                         {"fill": waveCellFill,
                                          "opacity": gOVERLAY_OPACITY,
                                          "stroke": "none",
                                          "strokeWidth": 0,
                                          "omit": waveOmitCell
                                         })

 let arrowImg = CFR.draw2DbasicVector(gMAP_METADATA['variable'],
                                      gMAP_METADATA['variable2'],
                                      1,
                                      dimensionFilter,
                                      imgWidth,
                                      imgHeight,
                                      SphericalProjection, 
                                      'svg',
                                      {"omit": waveOmitArrow,
                                       "fill": waveArrowFill,
                                       "opacity": waveArrowOpacity,
                                       "symbol": 'arrow',
                                       "symbolSize": waveScaleArrow,
                                       "idealArrowSize": 22,
                                       "animateDuration": waveAnimateArrow,
                                       "eventListeners": [
                                        ['click', show_arrow_data, null],
                                       ]
                                     })
  
 let timeZulu = cfu.getTimeISOString(time_values[offset], timeUnits)
 let timeLocal = cfu.zuluToLocalTime(timeZulu)
 gARROWS[offset] = arrowImg
 gIMAGES.push({'index': offset,
               'timeVariable': time_var,
               'timeValue': time_values[offset],
               'timeUnits': timeUnits,
               'bounds': bounds,
               'timeValueZulu': timeZulu,
               'timeValueLocal': timeLocal})

 let key1 = `cellImage_${time_var}_${time_values[offset]}`
 storeData(dbNAME, dbSTORE, key1, cellImg)

 let key2 = `time_slice_${time_var}_${time_values[offset]}`
 storeData(dbNAME, dbSTORE, key2, JSON.stringify(CFR.getTransferableData2DGrid()))

 offset++
 if (offset >= time_values.length) {
  post_process()
  return;
 }

 window.setTimeout(function() { render_loop(request_counter, offset, time_values, time_var, timeUnits); }, 80);
}

function post_process() {
 document.getElementById('map_progress').style.visibility = 'visible'
 hide_page_progress()
 dequeue_throbber()
 gIMAGE_OFFSET = 0
 shuffle_images()
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

 set_overlay_images(gIMAGES[gIMAGE_OFFSET])

 document.getElementById('map_info').textContent = gIMAGES[gIMAGE_OFFSET].timeValueLocal

 let p = document.getElementById('map_progress')
 p.min = 1
 p.max = gIMAGES.length
 p.value = gIMAGE_OFFSET + 1
}

function set_overlay_images(img_rec) {
 let bounds = img_rec['bounds']
 //let imageBounds = [[ bounds[0][1], bounds[0][0] % 360  ], [ bounds[1][1], bounds[1][0] % 360 ]]
 let imageBounds = [[ bounds[0][1], bounds[0][0] ], [ bounds[1][1], bounds[1][0]  ]]

 let key1 = `cellImage_${img_rec.timeVariable}_${img_rec.timeValue}`
 getData(dbNAME, dbSTORE, key1).then(
  (img) => { 
   if (!gCELL_OVERLAY) {
    gCELL_OVERLAY = L.imageOverlay(img, imageBounds) 
    gCELL_OVERLAY.addTo(map)
   } else
    gCELL_OVERLAY.setUrl(img)
   update_layer_opacity()
  })

 let offset = img_rec['index']
 if (gARROW_OVERLAY) 
  map.removeLayer(gARROW_OVERLAY)
 gARROW_OVERLAY = null
 gARROW_OVERLAY = L.svgOverlay(gARROWS[offset], imageBounds, {interactive: true}) 
 gARROW_OVERLAY.addTo(map)
}

function remove_overlay(no_reset) {
 if (gCELL_OVERLAY) 
  map.removeLayer(gCELL_OVERLAY)
 if (gARROW_OVERLAY) 
  map.removeLayer(gARROW_OVERLAY)
 gCELL_OVERLAY = null
 gARROW_OVERLAY = null
 gIMAGES = []
 gARROWS = []
 gIMAGE_OFFSET = null 
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
 });

 // Map attribution
 map.attributionControl.setPrefix(false)
 map.attributionControl.addAttribution(gMAP_METADATA["map_attribution"])

 // Open Offline Image Db and Reset it
 openDatabase(dbNAME, dbSTORE)
 clearAllData(dbNAME, dbSTORE)

 fetch_catalog_index() 
 window.setInterval(fetch_catalog_index, 150000)
 window.setInterval(shuffle_images, 3000)

 status_msg(gMAP_METADATA['author_comment'])
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
 status_msg(`Avg Arrow Magitude: ${arrow_data['value_magnitude']},\n Avg Arrow Direction: ${arrow_data['value_direction']}`)
}

async function map_tap(evt) {
 close_all_dialogs()

 if (!map) 
  return

 if ((!gIMAGE_OFFSET) && (gIMAGE_OFFSET != 0))
  return

 if (gIMAGES.length == 0)
  return

 let image = gIMAGES[gIMAGE_OFFSET]
 if (!image)
  return

 let timeVariable = image.timeVariable
 let timeValue = image.timeValue
 let timeZulu = image.timeValueZulu

 let key = `time_slice_${timeVariable}_${timeValue}`
 let grid_enc = await fetchData(dbNAME, dbSTORE, key)
 if (!grid_enc)
  return

 let grid = JSON.parse(grid_enc)

 //Need to setup projection function as it cannot be passed back (serialized) in web worker
 grid["XYprojectionFunction"] = SphericalProjection
 let CFR = new CFRender()
 CFR.setdata2DGrid(grid) 

 let x = CFR.getCellValue(gMAP_METADATA['variable'],
                          grid['DimensionFilter'],
                          evt.latlng.lng,
                          evt.latlng.lat,
                          gMAP_METADATA["cell_omit_value"])

 if ((!x) && (x !=0))
  return

 let msg = 'Value: '+ x.toString() + '\n'+
           'Date: '+ timeZulu.toString() + '\n'+
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
  <h4>Map Layer Legend</h4>
  <p>
   <img alt="Legend" id="info_legend" class="information" 
    src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==">
  </p>
  <h4>Map Arrow Legend</h4>
  <p>
   <img alt="Legend" id="arrow_legend" class="information" 
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
