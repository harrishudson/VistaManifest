<?php
 require('../../common/common.php');
 $nonce = page_top('ANU Water and Landscape Dynamics - AU - Tree Cover', 'no');
?>

<script nonce="<?php echo $nonce;?>" type="module">
import { CFUtils, CFRender } 
 from '../../common/CFRender.js'
import { TDSCatalogParser, TDSMetadataParser } 
 from '../../common/THREDDS_utils.js'
import { createColorLegendImg, tile_providers, formatDateToYYYYMMDD } 
 from '../../common/map_helpers.js'
import { getCache, setCache, cleanCache, openDatabase, 
         storeData, getData, clearAllData, fetchData } 
 from '../../common/offline_storage_helpers.js'
import { SphericalProjection }
 from '../../common/projection_helpers.js'

// Primary Map metadata
const gMAP_METADATA = {
 "catalog_endpoint": 
  "https://thredds.nci.org.au/thredds/catalog/ub8/au/treecover/catalog.xml",
 "subsetting_endpoint_prefix": 
  "https://thredds.nci.org.au/thredds/ncss/grid/ub8/au/treecover/",
 "subsetting_query_string_suffix": 
  "&addLatLon=true&accept=netcdf&format=netcdf3",
 "map_title": 
  "ANU Water and Landscape Dynamics - AU - Tree Cover",
 "variable":
  "TreeCover",
 "author_comment": 
  "Select a Year.",
 "related_links": 
  [{"label":"THREDDS Endpoint",
    "href": "https://thredds.nci.org.au/thredds/catalog/ub8/au/treecover/catalog.html"}],
 "map_attribution": 'Data &copy; ANU',
 "map_type": 'grid',
 "layer_starting_opacity": 0.7,
 "cell_omit_value": function(val) {
  if ((val <= 0) || (!isFinite(val)))
    return true
  return false
 },
 "cell_opacity": 1,
}

let cfu = new CFUtils()

function omitCell(cellData) {
 return (gMAP_METADATA['cell_omit_value'](cellData.value))
}

function opacityCell(cellData) {
 return (gMAP_METADATA['cell_opacity'])
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
var gMAP_FITTED = false

function fetch_catalog_index() {
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
 populate_years(TDS)
}

function populate_years(TDS) {
 let datasets = TDS.catalog_dataset_datasets
 let s = document.getElementById('select_year')
 s.innerHTML = '<option value="none" selected="selected">Year</option>'

 let reg = new RegExp('ANUWALD.TreeCover.25m.')
 let treecover_filtered =
  datasets.filter(function(obj) {return reg.test(obj.name)});

 treecover_filtered.forEach(optionData => {
  const option = document.createElement('option')
  option.value = optionData.name
  let year =  optionData.name.replaceAll('ANUWALD.TreeCover.25m.','').replaceAll('.nc','')
  option.textContent = year
  s.appendChild(option)
  }) 
}

function select_year_change(e) {
 if (this.value == 'none') {
  remove_overlay()
  clear_render()
  return
 }

 let endpoint = gMAP_METADATA["subsetting_endpoint_prefix"] +
  this.value 
 let endpoint_url = endpoint + '/dataset.xml'
 
 queue_throbber()

 fetch(endpoint_url, {
  method: 'GET', 
  headers: {'Content-Type': 'application/xml'},
  mode: 'cors' 
 })
  .then(response => response.text())
  .then(str => process_year_index(str, endpoint))
  .catch(error => { dequeue_throbber(); status_msg(error) })
}

function process_year_index(data, endpoint) {
 dequeue_throbber()

 if (!data) {
  status_msg('No Data Found.')
  return
 }
 let parser = new DOMParser()
 var xmlDoc = parser.parseFromString(data,"text/xml")
 var TDS = new TDSMetadataParser(xmlDoc, true)

 gNETCDF_TDS = TDS
 gNETCDF_SUBSET_ENDPOINT = endpoint

 fit_map_bounds()

 window.setTimeout(redraw_map_layer, 100)
}

function fit_map_bounds() {
 if (!gMAP_FITTED) {
  let LatLonBox = gNETCDF_TDS.getLatLonBox()
  let NetCDFBounds = [ [LatLonBox['south'], LatLonBox['west']],
                       [LatLonBox['north'], LatLonBox['east']] ]
                      
  map.fitBounds(NetCDFBounds)
  gMAP_FITTED = true
 }
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

 var query_string = "?var="+encodeURIComponent(gMAP_METADATA['variable'])

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
 let image_size = {x: img_x_range, y: img_y_range}
 let HorizStride = gNETCDF_TDS.getHorizStride(image_size, queryBounds)
 
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
 
 render_image()
}

async function render_image() {
 let img = await CFR.draw2DbasicGrid(gMAP_METADATA['variable'],
                                     null,
                                     SphericalProjection, 
                                     'url',
                                     {"fill": document.getElementById('layer_color').value,
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
 CFR = null
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

 document.getElementById('layer_color').addEventListener('change', redraw_map_layer)

 document.getElementById('opacity_range').addEventListener('input',
  function(e) { 
   document.getElementById('opacity_range_label').textContent = this.value+'%' 
   gOVERLAY_OPACITY = this.value / 100
   update_layer_opacity()
  })
 document.getElementById('opacity_range').value = gOVERLAY_OPACITY * 100
 document.getElementById('opacity_range_label').textContent = (gOVERLAY_OPACITY * 100).toString()+'%'

 document.getElementById('select_year').addEventListener('change', select_year_change)
 document.getElementById('select_year').addEventListener('click',
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

 var x = null;
 try {
  x = CFR.getCellValue(gMAP_METADATA['variable'],
                       null, 
                       evt.latlng.lng,
                       evt.latlng.lat,
                       null)
 } catch(e) { return; }

 if ((!x) && (x != 0)) return 

 let dl = document.createElement('dl')
 dl.setAttribute('class', "popup")

 var dt = document.createElement('dt')
 dt.textContent = 'Value:'
 dl.appendChild(dt)
 var dd = document.createElement('dd')
 dd.setAttribute('class', "popup_value")
 dd.textContent = x
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
   <select id="select_year">
    <option value="none">Year</option>
   </select>
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
   <h4>Map Instructions</h4>
    <span id="info_author_comment" class="information"></span>
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
  <p><h4>Layer Color</h4>
   <input type="color" id="layer_color" value="#8B3C66">
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