<?php
 require('../../common/common.php');
 $nonce = page_top("PacIOOS 1km Global Bathymetry", "no");
?>

<script nonce="<?php echo $nonce;?>" type="module">
import { CFUtils, CFRender } 
 from '../../common/CFRender.js'
import { TDSCatalogParser, TDSMetadataParser } 
 from '../../common/THREDDS_utils.js'
import { createColorLegendImg, tile_providers } 
 from '../../common/map_helpers.js'
import { getCache, setCache, cleanCache } 
 from '../../common/offline_storage_helpers.js'
import { SphericalProjection }
 from '../../common/projection_helpers.js'
import { bathymetry_meters_stops } 
 from '../../common/map_styles.js'

// Primary Map metadata
const gMAP_METADATA = {
 "latest_endpoint": "https://pae-paha.pacioos.hawaii.edu/thredds/ncss/srtm30plus_v11_bathy/dataset.xml",
 "subsetting_endpoint_prefix": 
  "https://pae-paha.pacioos.hawaii.edu/thredds/ncss/srtm30plus_v11_bathy",
 "subsetting_query_string_suffix": 
  "&addLatLon=true&accept=netcdf&format=netcdf",
 "variable": 'elev',
 "map_title": "PacIOOS 1km Global Bathymetry",
 "author_comment": "Click/tap to display depth in meters",
 "catalog_filter_string": "",
 "related_links": 
  [{"label": "THREDDS Endpoint",
    "href": "https://pae-paha.pacioos.hawaii.edu/thredds/bathymetry.html?dataset=srtm30plus_v11_bathy"
   }],
 "map_attribution": 'Data &copy; PacIOOS',
 "map_type": 'grid',
 "layer_starting_opacity": 0.7,
 "cell_color_stops": bathymetry_meters_stops,
 "cell_omit_value": function(val) {
  if (((!val) && (val != 0)) || (!isFinite(val)))
   return true
  return false
 },
 "cell_opacity": 1
}

let cfu = new CFUtils()

function fillCell(cellData) {
 return cfu.steppedHexColor(cellData.value, gMAP_METADATA['cell_color_stops'])
}

function omitCell(cellData) {
 return (gMAP_METADATA['cell_omit_value'](cellData.value))
}

// Main script

var gOVERLAY = null
var gOVERLAY_OPACITY = gMAP_METADATA["layer_starting_opacity"]
var map
var gBASE_LAYER
var gNETCDF_SUBSET_ENDPOINT = gMAP_METADATA["subsetting_endpoint_prefix"] 
var gNETCDF_TDS = null
var gMAP_FITTED = false
var gDOCUMENT_HIDDEN = false
var gMAP_LATEST_REQUEST = 0
var gMAP_CURRENT_REQUEST = null
var projectionCache = null
var CFR = null

function fetch_catalog_index() {
 let latest_endpoint = gMAP_METADATA["latest_endpoint"]

 queue_throbber()

 let cached_endpoint = getCache(latest_endpoint)
 if (cached_endpoint) {
  process_latest(cached_endpoint)
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
    process_latest(str)
  })
  .catch(error => { dequeue_throbber(); status_msg(error) })
}

function process_latest(data) {
 dequeue_throbber()
 if (!data) {
  status_msg('No Data Found.')
  return
 }
 let parser = new DOMParser()
 var xmlDoc = parser.parseFromString(data,"text/xml")
 gNETCDF_TDS = new TDSMetadataParser(xmlDoc)

 fit_map_bounds()
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
 var image_size = {x: img_x_range, y: img_y_range}
 // Fetch at slightly higher resolution
 let HorizStride = gNETCDF_TDS.getHorizStride(image_size, queryBounds, 2)
 
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

 //CFR = new CFRender(barray, null, projectionCache)
 //CFR = new CFRender(barray, null, projectionCache, true)
 CFR = new CFRender(barray, null, projectionCache)
 projectionCache = CFR.projectionCache
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

 let img1 = await CFR.draw2DbasicGrid('elev', 
	                       {},
	                       SphericalProjection, 
	                       'image',
	                        {"fill": fillCell,
				 "opacity": gMAP_METADATA["layer_starting_opacity"],
				 "stroke": "none",
				 "strokeWidth": 0 ,
				 "omit": omitCell
				 })

 remove_overlay()
 let imageBounds1 = [[ bounds[0][1], bounds[0][0]], [ bounds[1][1], bounds[1][0]]]
 gOVERLAY = L.imageOverlay(img1, imageBounds1) 
 gOVERLAY.addTo(map)
 update_layer_opacity()
 dequeue_throbber();
}

function remove_overlay() {
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
 });

 // Map attribution
 map.attributionControl.setPrefix(false)
 map.attributionControl.addAttribution(gMAP_METADATA["map_attribution"])

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

async function map_tap(evt) {
 close_all_dialogs()

 if (!map) 
  return

 let x = CFR.getCellValue(gMAP_METADATA['variable'],
                          {},
                          evt.latlng.lng,
                          evt.latlng.lat,
                          gMAP_METADATA["cell_omit_value"])

 if ((!x) && (x !=0))
  return

 let msg = 'Value: '+ x.toString() + '\n'+
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
