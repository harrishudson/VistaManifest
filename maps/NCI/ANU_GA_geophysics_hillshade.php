<?php
 require('../../common/common.php');
 $nonce = page_top('GA Airborne Geophysics (iv65) Hillshade Survey', 'no');
?>

<script nonce="<?php echo $nonce;?>" type="module">
import { CFRender } 
 from '../../common/CFRender.js'
import { TDSCatalogParser, TDSMetadataParser } 
 from '../../common/THREDDS_utils.js'
import { tile_providers, formatDateToYYYYMMDD } 
 from '../../common/map_helpers.js'
import { getCache, setCache, cleanCache, openDatabase, 
         storeData, getData, clearAllData, fetchData } 
 from '../../common/offline_storage_helpers.js'
import { SphericalProjection }
 from '../../common/projection_helpers.js'
import { createHillShader } 
 from '../../common/CF_elevation_helpers.js'

// Primary Map metadata

const gMAP_METADATA = {
 "catalog_endpoint": 
  "https://thredds.nci.org.au/thredds/catalog//catalogs/iv65/airborne_geophysics/airborne_geophysics.xml",
 "data_endpoint_prefix": 
  "https://thredds.nci.org.au/thredds/catalog/iv65/Geoscience_Australia_Geophysics_Reference_Data_Collection/airborne_geophysics/",
 "subsetting_endpoint_prefix": 
  "https://thredds.nci.org.au/thredds/ncss/grid/iv65/Geoscience_Australia_Geophysics_Reference_Data_Collection/airborne_geophysics/",
 "subsetting_query_string_suffix": 
  "&addLatLon=true&accept=netcdf&format=netcdf3",
 "variable": 'Band1',
 "map_title": 
  "GA Airborne Geophysics (iv65) Hillshade Survey",
 "author_comment": 
  "Select a State then select a Survey.  Adjust the sun position and shader colours using Shader Controls.",
 "related_links": 
  [{"label":"THREDDS Endpoint",
    "href":"https://thredds.nci.org.au/thredds/catalog/catalogs/iv65/airborne_geophysics/airborne_geophysics.html"}],
 "map_attribution": 'Data &copy; GA',
 "map_type": 'grid',
 "layer_starting_opacity": 0.7,
 "default_azimuth": 315,
 "default_sun_elevation": 45,
 "default_exaggeration": 10,
 "default_light_color": '#fff2c6',
 "default_shadow_color": '#26384a',
 "default_stride_scale": 2,
 "cell_omit_value": function(val) {
  if ((val < -10) || (!isFinite(val)))
   return true
  return false
 },
}

function finiteNumber(value, fallback) {
 let n = Number(value)
 return Number.isFinite(n) ? n : fallback
}

// Hillshade is built lazily from the CFRender grid currently being drawn 
// and is rebuilt whenever the grid or the light parameters change.

function flatten_grid(gridObject) {
 let cells = []
 function visit(node) {
  if ((!node) || (typeof node !== 'object')) 
   return
  if ((Number.isInteger(node.x_offset)) && (Number.isInteger(node.y_offset))) {
   cells.push(node)
   return
  }
  for (const value of Object.values(node)) 
   visit(value)
 }
 visit(gridObject)
 return cells
}

function initialise_hillshade() {
 if (gHILLSHADE) 
  return gHILLSHADE
 let gridObject = CFR?.data2DGrid?.grid
 if ((!gridObject) || (typeof gridObject !== 'object'))
  throw new Error('CFRender has not built data2DGrid yet.')
 let grid = flatten_grid(gridObject)
 if (!grid.length)
  throw new Error('CFRender data2DGrid contains no cell objects.')
 gHILLSHADE = createHillShader({
  cfr: CFR, 
  grid: grid, 
  azimuth: gAZIMUTH, 
  elevation: gSUN_ELEVATION, 
  exaggeration: gEXAGGERATION, 
  verticalScale: 1,
  shaderStyle: {
   lightColor: document.getElementById('lightColor').value,
   lightOpacity: 0.88,
   shadowColor: document.getElementById('shadowColor').value,
   shadowOpacity: 0.72
  }
 })
 return gHILLSHADE
}

function fillCell(cellData) {
 return initialise_hillshade().fillColor(cellData)
}

function omitCell(cellData) {
 return (gMAP_METADATA['cell_omit_value'](cellData.value))
}

function opacityCell(cellData) {
 return initialise_hillshade().fillOpacity(cellData)
}

// Main script

var CFR  // CFR is the main CFRender object
var gOVERLAY
var gOVERLAY_OPACITY = gMAP_METADATA["layer_starting_opacity"]
var map
var gBASE_LAYER
var gNETCDF_TDS = null
var gNETCDF_SUBSET_ENDPOINT = null
var gNETCDF_PROJECTION_CACHE = null
var gPOPUP = null
var gMAP_LATEST_REQUEST = 0
var gMAP_CURRENT_REQUEST = null
var gHILLSHADE = null
var gAZIMUTH = ((finiteNumber(gMAP_METADATA['default_azimuth'], 315) % 360) + 360) % 360
var gSUN_ELEVATION = Math.max(0, Math.min(90, finiteNumber(gMAP_METADATA['default_sun_elevation'], 45)))
var gEXAGGERATION = Math.max(0, Math.min(100, finiteNumber(gMAP_METADATA['default_exaggeration'], 10)))
var gSTRIDE_SCALE = Math.max(1, Math.min(16, Math.round(finiteNumber(gMAP_METADATA['default_stride_scale'], 2))))
var gSHADER_TIMER = null
var gRENDER_VERSION = 0

function fetch_catalog_index() {
 let sv = document.getElementById('select_survey')
 sv.innerHTML = '<option value="none" selected="selected">Survey</option>'
 sv.disabled = true

 let endpoint_url = gMAP_METADATA["catalog_endpoint"]
 
 queue_throbber()

 fetch(endpoint_url, {
  method: 'GET', 
  headers: {'Content-Type': 'application/xml'},
  mode: 'cors' 
 })
  .then(response => response.text())
  .then(str => process_catalog_index(str))
  .catch(error => { dequeue_throbber(); status_msg(error) })
}

function process_catalog_index(data) {
 dequeue_throbber()

 if (!data) {
  status_msg('No Data Found.')
  return
 }
 let parser = new DOMParser()
 var xmlDoc = parser.parseFromString(data,"text/xml")
 var TDS = new TDSCatalogParser(xmlDoc)
 populate_states(TDS)
}

function populate_states(TDS) {
 let dataset_refs = TDS.catalog_dataset_refs
 let s = document.getElementById('select_state')
 s.innerHTML = '<option value="none" selected="selected">State</option>'

 dataset_refs.forEach(optionData => {
  const option = document.createElement('option')
  option.value = optionData.title
  option.textContent = optionData.title
  s.appendChild(option)
  }) 
}

function select_state_change(e) {
 remove_overlay()
 clear_render()

 let sv = document.getElementById('select_survey')
 sv.innerHTML = '<option value="none" selected="selected">Survey</option>'
 sv.disabled = true

 if (this.value == 'none')
  return

 let endpoint_url = 'https://thredds.nci.org.au/thredds/catalog/iv65/Geoscience_Australia_Geophysics_Reference_Data_Collection/airborne_geophysics/'+encodeURIComponent(this.value)+'/grid/catalog.xml'

 queue_throbber()

 fetch(endpoint_url, {
  method: 'GET', 
  headers: {'Content-Type': 'application/xml'},
  mode: 'cors' 
 })
  .then(response => response.text())
  .then(str => process_survey_index(str))
  .catch(error => { dequeue_throbber(); status_msg(error) })
}

function process_survey_index(data) {
 dequeue_throbber()

 if (!data) {
  status_msg('No Data Found.')
  return
 }
 let parser = new DOMParser()
 var xmlDoc = parser.parseFromString(data,"text/xml")
 var TDS = new TDSCatalogParser(xmlDoc)
 populate_surveys(TDS)
}

function populate_surveys(TDS) {

 let dataset_refs = TDS.catalog_dataset_refs
 let sv = document.getElementById('select_survey')
 sv.innerHTML = '<option value="none" selected="selected">Survey</option>'

 dataset_refs.forEach(optionData => {
  const option = document.createElement('option')
  option.value = optionData.title
  option.textContent = optionData.title
  sv.appendChild(option)
  }) 

 if ((dataset_refs) && (dataset_refs.length)) 
  sv.disabled = false
}

function select_survey_change(e) {
 let survey = this.value
 remove_overlay()
 clear_render()

 if (survey == 'none') 
  return

 fetch_survey_metadata()
}

function fetch_survey_metadata() {
 let s = document.getElementById('select_state').value
 let sv = document.getElementById('select_survey').value

 let prefix = gMAP_METADATA["subsetting_endpoint_prefix"]
 let endpoint = prefix + 
                encodeURIComponent(s) +
                '/grid/' +
                encodeURIComponent(sv) +
                '/' +
                encodeURIComponent(sv) +
                '-grid-dem_geoid.nc'

 let dataset_endpoint = endpoint + '/dataset.xml'

 queue_throbber()

 fetch(dataset_endpoint,{
  method: 'GET', 
  headers: { 'Content-Type': 'application/xml'},
  mode: 'cors' 
 })
  .then(response => response.text())
  .then(str => process_netcdf_metadata(str, endpoint))
  .catch(error => { dequeue_throbber(); status_msg(error) })
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
 let LatLonBox = gNETCDF_TDS.getLatLonBox()
 let NetCDFBounds = [ [LatLonBox['south'], LatLonBox['west']],
                      [LatLonBox['north'], LatLonBox['east']] ]
                      
 map.fitBounds(NetCDFBounds)
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

 var query_string = "?var="+encodeURIComponent(gMAP_METADATA.variable)

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
 let image_size = {x: img_x_range, y: img_y_range}
 let HorizStride = gNETCDF_TDS.getHorizStride(image_size, queryBounds, gSTRIDE_SCALE)
 
 query_string += "&horizStride="+encodeURIComponent(HorizStride)
 
 query_string += gMAP_METADATA['subsetting_query_string_suffix']
 
 // Commit to fetch

 CFR = null  // Remove existing CFR so map_tap won't draw popups during loading

 try { map.removeLayer(gPOPUP) } catch(e) {}
 
 fetch_netcdf_for_render(query_string, bounds)
}

function fetch_netcdf_for_render(query_string) {
 let netcdf_subset_url = gNETCDF_SUBSET_ENDPOINT + query_string
                         
 queue_throbber()

 gMAP_LATEST_REQUEST++
 const requestId = gMAP_LATEST_REQUEST

 if (gMAP_CURRENT_REQUEST) 
  gMAP_CURRENT_REQUEST.abort()

 const controller = new AbortController()
 gMAP_CURRENT_REQUEST = controller;

 fetch(netcdf_subset_url, { 
  signal: controller.signal,
  method: 'GET', 
  mode: 'cors' 
 })
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
 gHILLSHADE = null
 
 render_image()
}

async function render_image() {
 const renderVersion = ++gRENDER_VERSION
 gHILLSHADE = null
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
 if ((!CFR) || (renderVersion !== gRENDER_VERSION)) {
  dequeue_throbber()
  return
 }

 gNETCDF_PROJECTION_CACHE = CFR.projectionCache
 
 // Draw overlay
 let bounds = CFR.getXYbbox().bbox
 let imageBounds = [[ bounds[0][1], bounds[0][0] ], [ bounds[1][1], bounds[1][0] ]]
 remove_overlay()
 gOVERLAY = L.imageOverlay(img, imageBounds)
 gOVERLAY.addTo(map)
 update_layer_opacity()
 dequeue_throbber()
}

function remove_overlay(no_reset) {
 if (gOVERLAY) 
  map.removeLayer(gOVERLAY)
 gOVERLAY = null
}

function clear_render() {
 gNETCDF_SUBSET_ENDPOINT = null
 gNETCDF_PROJECTION_CACHE = null
 gHILLSHADE = null
 CFR = null
}

// Shader controls

function polar_position(az, el) {
 let radius = 88 * (1 - Math.max(0, Math.min(90, el)) / 90)
 let angle = az * Math.PI / 180
 return { x: 110 + radius * Math.sin(angle), y: 110 - radius * Math.cos(angle) }
}

function update_polar() {
 let p = polar_position(gAZIMUTH, gSUN_ELEVATION)
 document.getElementById('sunHandle').setAttribute('cx', p.x)
 document.getElementById('sunHandle').setAttribute('cy', p.y)
 document.getElementById('sunRay').setAttribute('x2', p.x)
 document.getElementById('sunRay').setAttribute('y2', p.y)
 document.getElementById('azimuthInput').value = gAZIMUTH.toFixed(1)
 document.getElementById('elevationInput').value = gSUN_ELEVATION.toFixed(1)
}

function set_polar_from_pointer(evt) {
 let svg = document.getElementById('sunPolar')
 let pt = svg.createSVGPoint()
 pt.x = evt.clientX
 pt.y = evt.clientY
 let p = pt.matrixTransform(svg.getScreenCTM().inverse())
 let dx = p.x - 110
 let dy = 110 - p.y
 let radius = Math.min(88, Math.hypot(dx, dy))
 gAZIMUTH = (Math.atan2(dx, dy) * 180 / Math.PI + 360) % 360
 gSUN_ELEVATION = Math.max(0, Math.min(90, 90 * (1 - radius / 88)))
 update_polar()
 schedule_shader_refresh()
}

function schedule_shader_refresh() {
 gHILLSHADE = null
 clearTimeout(gSHADER_TIMER)
 gSHADER_TIMER = setTimeout(refresh_shading, 100)
}

// Re-shade the grid already downloaded (no new NCSS request)
function refresh_shading() {
 if (!CFR)
  return
 queue_throbber()
 render_image()
}

function bind_shader_controls() {
 let svg = document.getElementById('sunPolar')
 let dragging = false
 for (const eventName of ["pointerdown", "pointermove", "pointerup", "pointercancel", "mousedown", "touchstart", "click", "dblclick"]) {
  svg.addEventListener(eventName, function(e) { e.stopPropagation() })
 }
 svg.addEventListener('pointerdown', function(e) { 
  dragging = true
  svg.setPointerCapture(e.pointerId)
  set_polar_from_pointer(e)
  e.preventDefault()
  e.stopPropagation() 
 })
 svg.addEventListener('pointermove', function(e) { 
  if (dragging) { 
   set_polar_from_pointer(e)
   e.preventDefault() 
  }
  e.stopPropagation() 
 })
 svg.addEventListener('pointerup', function(e) { 
  dragging = false
  e.stopPropagation() 
 })
 svg.addEventListener('pointercancel', function(e) { 
  dragging = false
  e.stopPropagation() 
 })
 document.getElementById('azimuthInput').addEventListener('change', function() {
  let v = Number(this.value)
  if (Number.isFinite(v)) {
   gAZIMUTH = ((v % 360) + 360) % 360
   update_polar()
   schedule_shader_refresh()
  }
 })
 document.getElementById('elevationInput').addEventListener('change', function() {
  let v = Number(this.value)
  if (Number.isFinite(v)) {
   gSUN_ELEVATION = Math.max(0, Math.min(90, v))
   update_polar()
   schedule_shader_refresh()
  }
 })
 document.getElementById('exaggerationInput').addEventListener('change', function() {
  let v = Number(this.value)
  if ((Number.isFinite(v)) && (v >= 0)) {
   gEXAGGERATION = v
   schedule_shader_refresh()
  }
 })
 document.getElementById('strideScale').addEventListener('change', function() {
  let v = Number(this.value)
  if (Number.isFinite(v)) {
   gSTRIDE_SCALE = Math.max(1, Math.min(16, Math.round(v)))
   redraw_map_layer()
  }
 })
 for (const id of ['lightColor', 'shadowColor']) 
  document.getElementById(id).addEventListener('input', schedule_shader_refresh)
 for (const id of ['azimuthInput', 'elevationInput', 'exaggerationInput', 'strideScale', 'lightColor', 'shadowColor'])
  document.getElementById(id).addEventListener('click', function(e) { e.stopPropagation() })
 document.getElementById('refreshButton').addEventListener('click', function(e) { 
  e.preventDefault()
  e.stopPropagation()
  redraw_map_layer()
 })

 // Shader Controls panel toggle
 let toggle = document.getElementById('shader_controls_toggle')
 let body = document.getElementById('shader_controls_body')
 toggle.addEventListener('click', function(e) {
  e.preventDefault()
  e.stopPropagation()
  let willOpen = (toggle.getAttribute('aria-expanded') !== 'true')
  toggle.setAttribute('aria-expanded', String(willOpen))
  body.hidden = !willOpen
 })

 // Enter in a control input applies its value and collapses the controls panel.
 for (const input of body.querySelectorAll('input, select')) {
  input.addEventListener('keydown', function(e) {
   if ((e.key !== 'Enter') && (e.keyCode !== 13)) 
    return
   e.preventDefault()
   e.stopPropagation()
   if ((input.id === 'lightColor') || (input.id === 'shadowColor'))
    input.dispatchEvent(new Event('input', { bubbles: true }))
   else
    input.dispatchEvent(new Event('change', { bubbles: true }))
   toggle.setAttribute('aria-expanded', 'false')
   body.hidden = true
  })
 }

 document.getElementById('azimuthInput').value = gAZIMUTH.toFixed(1)
 document.getElementById('elevationInput').value = gSUN_ELEVATION.toFixed(1)
 document.getElementById('exaggerationInput').value = String(gEXAGGERATION)
 document.getElementById('strideScale').value = String(gSTRIDE_SCALE)
 document.getElementById('lightColor').value = gMAP_METADATA['default_light_color']
 document.getElementById('shadowColor').value = gMAP_METADATA['default_shadow_color']
 update_polar()
}

function map_startup() {
 map = L.map('map',{"zoomControl": false}).setView([0, 0], 1)
 map.on('click', map_tap)
 map.on('moveend',redraw_map_layer)

 // Shader controls
 L.DomEvent.disableClickPropagation(document.getElementById('shader_inputs'))
 bind_shader_controls()

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

 document.getElementById('select_state').addEventListener('change', select_state_change)
 document.getElementById('select_state').addEventListener('click',
  function(e) {
   e.stopPropagation()
   return false 
  })
 document.getElementById('select_survey').addEventListener('change', select_survey_change)
 document.getElementById('select_survey').addEventListener('click', 
  function(e) {
   e.stopPropagation()
   return false 
  })

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

 status_msg(gMAP_METADATA["author_comment"])

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
 if (gOVERLAY) 
  gOVERLAY.setOpacity(gOVERLAY_OPACITY)
}

function map_tap(evt) {
 close_all_dialogs()
 if (!CFR) return
 if (!map) return

 let x = CFR.getCellValue(gMAP_METADATA['variable'],
                          null, 
                          evt.latlng.lng,
                          evt.latlng.lat,
                          null)

 if ((!x) && (x != 0)) return 

 let dl = document.createElement('dl')
 dl.setAttribute('class', "popup")

 var dt = document.createElement('dt')
 dt.textContent = 'Elevation:'
 dl.appendChild(dt)
 var dd = document.createElement('dd')
 dd.setAttribute('class', "popup_value")
 dd.textContent = Number(x).toFixed(1)+' m'
 dl.appendChild(dd)

 let bound_filters = {}
 bound_filters["Longitude"] = evt.latlng.lng
 bound_filters["Latitude"] = evt.latlng.lat
 for (let f in bound_filters) {
  var dt = document.createElement('dt')
  dt.textContent = f
  dl.appendChild(dt)
  var dd = document.createElement('dd')
  dd.textContent = bound_filters[f] 
  dl.appendChild(dd)
 }

 gPOPUP = L.popup().setLatLng(evt.latlng).setContent(dl)

 gPOPUP.openOn(map)
}

function make_info_dialog() {
 document.getElementById('info_variable').textContent = 
  gMAP_METADATA['variable']
 document.getElementById('info_author_comment').textContent = 
  gMAP_METADATA['author_comment']

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
   <select id="select_state">
    <option value="none">State</option>
   </select>
   <select id="select_survey" disabled="disabled">
    <option value="none">Survey</option>
   </select>
   <div class="shader_inputs" id="shader_inputs">
    <button type="button" class="shader_toggle" id="shader_controls_toggle" aria-expanded="false" aria-controls="shader_controls_body">
     <span>Shader Controls</span><i class="fa fa-chevron-down chevron" aria-hidden="true"></i>
    </button>
    <div class="shader_controls_body" id="shader_controls_body" hidden>
     <div class="shader_polar_wrap">
      <svg id="sunPolar" viewBox="0 0 220 220" role="slider" aria-label="Sun azimuth and elevation" tabindex="0">
       <circle cx="110" cy="110" r="88" fill="#f4f7fa" stroke="#7890a3" stroke-width="1.5"/>
       <circle cx="110" cy="110" r="58.7" fill="none" stroke="#c4d0d9" stroke-dasharray="3 4"/>
       <circle cx="110" cy="110" r="29.3" fill="none" stroke="#c4d0d9" stroke-dasharray="3 4"/>
       <path d="M110 14V206 M14 110H206" stroke="#aab8c3" stroke-width="1"/>
       <g fill="#42596b" font-size="10" text-anchor="middle"><text x="110" y="10">N</text><text x="212" y="114">E</text><text x="110" y="219">S</text><text x="8" y="114">W</text></g>
       <line id="sunRay" x1="110" y1="110" x2="172" y2="72" stroke="#d58a16" stroke-width="2.5"/>
       <circle id="sunHandle" cx="172" cy="72" r="9" fill="#f6b934" stroke="#704b0b" stroke-width="2"/>
      </svg>
      <div class="shader_note">Drag the sun. Centre = 90&deg; overhead; outer ring = horizon.</div>
     </div>
     <div class="coords">
      <div><label for="azimuthInput">Azimuth (&deg;)</label><input id="azimuthInput" type="number" min="0" max="359.9" step="0.1"></div>
      <div><label for="elevationInput">Sun elevation (&deg;)</label><input id="elevationInput" type="number" min="0" max="90" step="0.1"></div>
     </div>
     <label for="exaggerationInput">Vertical exaggeration</label>
     <input id="exaggerationInput" type="number" min="0" max="100" step="0.1">
     <h3>Shader colours</h3>
     <div class="coords">
      <div><label for="lightColor">Illuminated terrain</label><input id="lightColor" type="color"></div>
      <div><label for="shadowColor">Shadowed terrain</label><input id="shadowColor" type="color"></div>
     </div>
     <label for="strideScale">Display cell size (pixels)</label>
     <input id="strideScale" type="number" min="1" max="16" step="1">
     <button id="refreshButton" class="secondary">Refresh hillshade</button>
     <div class="shader_note">Light direction is measured clockwise from north. Elevation is above the horizon. Layer opacity remains available in Map Settings.</div>
    </div>
   </div>
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
   <span id="info_legend" class="information">Illuminated slopes use the selected light colour; slopes facing away from the light use the selected shadow colour.</span>
  </p>
  <h4>Map Instructions</h4>
  <p>
   <span id="info_author_comment" class="information"></span>
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
