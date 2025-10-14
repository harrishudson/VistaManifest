<?php
 require('../../common/common.php');
 $nonce = page_top('HYCOM Global Sea Water Temperature - Latest', 'yes');
?>

<script nonce="<?php echo $nonce;?>" type="module">
import { CFUtils, CFRender } 
 from '../../common/CFRender.js'
import { TDSCatalogParser, TDSMetadataParser } 
 from '../../common/THREDDS_utils.js'
import { createColorLegendImg, tile_providers, fitSvgContainerToViewport,
         pad, getOneMonthPrior, formatDateToYYYYMMDD } 
 from '../../common/map_helpers.js'
import { getCache, setCache } 
 from '../../common/offline_storage_helpers.js'
import { mollweideProjection, inverseMollweideProjection, isMollweidePointInProjection } 
 from '../../common/projection_helpers.js'
import { sea_surface_temperature_celsius_stops } 
 from '../../common/map_styles.js'

// Primary Map metadata
const gMAP_METADATA = {
 "proxy": "HYCOM_thredds_proxy.php?url=",
 "catalog_endpoint": 
  "/ncss/grid/ESPC-D-V02/ice/dataset.xml",
 "subsetting_endpoint_prefix": 
  "/ncss/ESPC-D-V02/ice",
 "subsetting_query_string_suffix": 
  "&disableLLSubset=on&disableProjSubset=on&&addLatLon=true&accept=netcdf&format=netcdf3",
 "variable": "sst",
 "author_comment": 
  "Click/Tap to display Sea Water Temperature.",
 "related_links": 
  [{"label":"THREDDS Endpoint",
    "href":"https://tds.hycom.org/thredds/catalogs/ESPC-D-V02_ice.html"}],
 "layer_starting_opacity": 0.7,
 "cell_color_stops": sea_surface_temperature_celsius_stops,
 "cell_omit_value": function(val) {
  if (((!val) && (val != 0)) || (!isFinite(val)))
   return true
  return false
 },
 "cell_opacity": 1,
}

let cfu = new CFUtils()

function fillCell(cellData) {
 return cfu.steppedHexColor(cellData.value, gMAP_METADATA['cell_color_stops'])
}

function omitCell(cellData) {
 return gMAP_METADATA['cell_omit_value'](cellData.value) 
}

// Main script

var CFR  // CFR is the main CFRender object
var gOVERLAY_OPACITY = gMAP_METADATA["layer_starting_opacity"]
var gNETCDF_TDS = null
var gNETCDF_SUBSET_ENDPOINT = null
var gNETCDF_PROJECTION_CACHE = null
var gDATASET_TIME_MESSAGE = null

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
    clear_render()
    status_msg(error) 
   })
}

function process_catalog_index(data) {
 dequeue_throbber()

 gNETCDF_SUBSET_ENDPOINT = gMAP_METADATA["subsetting_endpoint_prefix"] 

 if (!data) {
  remove_overlay()
  clear_render()
  status_msg('No Data Found.')
  return
 }

 let parser = new DOMParser()
 var xmlDoc = parser.parseFromString(data,"text/xml")
 gNETCDF_TDS = new TDSMetadataParser(xmlDoc)

 redraw_image()
}

function redraw_image() {
 // Grid Bounds
 let LatLonBox = gNETCDF_TDS.getLatLonBox()
 let east = LatLonBox['east']
 let west = LatLonBox['west']
 let north = LatLonBox['north']
 let south = LatLonBox['south']
 let queryBounds = [[west, south],[east, north]]

 var query_string = "?var="+encodeURIComponent(gMAP_METADATA.variable)

 //HorizStride
 let image_size = {x: 800, y: 400}  // Use a set image size for info
 let HorizStride = gNETCDF_TDS.getHorizStride(image_size, queryBounds)
 
 query_string += "&horizStride="+encodeURIComponent(HorizStride)

 let reftime_units = gNETCDF_TDS.Axes['time']['units']
 let time_values = gNETCDF_TDS.Axes['time']['values']
 let theDate = cfu.getTimeISOString(time_values[time_values.length - 1], reftime_units)
 gDATASET_TIME_MESSAGE = theDate

 query_string += "&time="+encodeURIComponent(theDate)
 
 query_string += gMAP_METADATA['subsetting_query_string_suffix']
 
 // Commit to fetch

 CFR = null  

 fetch_netcdf_for_render(query_string, queryBounds)
}

function fetch_netcdf_for_render(query_string) {
 let proxy = gMAP_METADATA["proxy"]
 let netcdf_subset_url_endpoint = gNETCDF_SUBSET_ENDPOINT + query_string
 let netcdf_subset_url = proxy + encodeURIComponent(netcdf_subset_url_endpoint)
                       
 queue_throbber()

 fetch(netcdf_subset_url)
 .then(response => response.arrayBuffer() )
 .then(buf => process_netcdf(buf))
 .catch(error => { 
  dequeue_throbber()
  remove_overlay()
  clear_render()
  status_msg(error) 
 })
}

function process_netcdf(barray) {
 if (!barray) {
  dequeue_throbber()
  remove_overlay()
  clear_render()
  status_msg('No Data Found.')
  return
 }

 CFR = new CFRender(barray, null, gNETCDF_PROJECTION_CACHE)
  
 render_image()
}

async function render_image() {
 if (!CFR) {
  dequeue_throbber()
  return
 }

 let img1 = await CFR.draw2DbasicGrid(gMAP_METADATA['variable'], 
                                      null, 
                                      mollweideProjection,
                                      'svg',
                                      {"fill": fillCell,
                                       "omit": omitCell,
                                       "opacity": gOVERLAY_OPACITY,
                                       "stroke": "none",
                                       "strokeWidth": 0,
                                       "meridianSkip": 180
                                      })
 remove_overlay()
 let container = document.getElementById('img')
 container.appendChild(img1)
 let bbox = CFR.getXYbbox().bbox
 let bounds = {"east": bbox[1][0], "west": bbox[0][0], 
               "north": bbox[1][1], "south": bbox[0][1]} 
 fitSvgContainerToViewport(container, bounds)

 gNETCDF_PROJECTION_CACHE = CFR.projectionCache
 
 dequeue_throbber()
}

function remove_overlay() {
 document.getElementById('img').innerHTML = null
}

function clear_render() {
 gNETCDF_SUBSET_ENDPOINT = null
 gNETCDF_PROJECTION_CACHE = null
 CFR = null
}

function set_img_opacity() {
 document.getElementById('img').style.opacity = gOVERLAY_OPACITY
}

function container_click(e) {
 let w = this.clientWidth
 let h = this.clientHeight

 if (!isMollweidePointInProjection(e.layerX, e.layerY, w, h))
  return

 let ll = 
  inverseMollweideProjection(e.layerX, e.layerY, w, h, 0)

 if (!CFR) return

 let theTime = CFR.netCDF.getDataVariable('time')
 let DimensionFilter = CFR.data2DGrid.DimensionFilter

 let x = CFR.getCellValue(gMAP_METADATA['variable'],
                          //{time: theTime[0]},
                          DimensionFilter,
                          ll.lon,
                          ll.lat,
                          null)

 if ((!x) && (x != 0)) return 

 let timeUnits = CFR.getVariableUnits('time')
 let timeValue = cfu.getTimeISOString(theTime, timeUnits)

 status_msg('Value: '+
            x.toString()+ '\n'+
            'Date: '+
            timeValue+ '\n'+
            'Lat: '+
            ll.lat.toString() + '\n'+
            'Lon: '+
            ll.lon.toString())
}

function make_info_dialog() {
 document.getElementById('info_variable').textContent = 
  gMAP_METADATA['variable']
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

function page_startup() {
 set_img_opacity()
 fetch_catalog_index() 

 document.getElementById('container').addEventListener('click', container_click)
 document.getElementById('page_legend').src = 
  createColorLegendImg(gMAP_METADATA["cell_color_stops"])

 document.getElementById("button_info").addEventListener('click', 
  function(e) { 
   close_all_dialogs()
   make_info_dialog()
   let d = document.getElementById('info_dialog')
   d.show()
   document.body.appendChild(d)
   d.scrollTo(0,0)
   e.stopPropagation() 
   return false 
  })
 document.getElementById('info_dialog').addEventListener('click', 
  function(e) {
   e.stopPropagation()
   return false
  })
 document.getElementById('info_dialog_close').addEventListener('click', 
  function(e) { 
   document.getElementById('info_dialog').close()
   e.stopPropagation()
   return false
  })

 document.getElementById("button_settings").addEventListener('click', 
  function(e) { 
   close_all_dialogs()
   let d = document.getElementById('settings_dialog')
   d.show()
   document.body.appendChild(d)
   e.stopPropagation() 
   return false 
  })
 document.getElementById('settings_dialog').addEventListener('click', 
  function(e) {
   e.stopPropagation()
   return false
  })
 document.getElementById('settings_dialog_close').addEventListener('click', 
  function(e) { 
   document.getElementById('settings_dialog').close()
   e.stopPropagation()
   return false
  })

 document.getElementById('opacity_range').addEventListener('input',
  function(e) { 
   document.getElementById('opacity_range_label').textContent = this.value+'%' 
   gOVERLAY_OPACITY = this.value / 100
   set_img_opacity()
  })
 document.getElementById('opacity_range').value = gOVERLAY_OPACITY * 100
 document.getElementById('opacity_range_label').textContent = (gOVERLAY_OPACITY * 100).toString()+'%'

 document.body.addEventListener('click', close_all_dialogs);

 status_msg(gMAP_METADATA["author_comment"])
}

window.onload = page_startup
</script>

<div class="indent">
 <h4>HYCOM Global Sea Water Temperature - Latest</h4>
 <dl>
  <dt>Controls</dt>
  <dd>
   <button id="button_info" title="Information"><i class="fa fa-info-circle"></i></button>
   <button id="button_settings" title="Settings"><i class="fa fa-cog"></i></button><br>
  </dd>
 </dl>

 <div id="container" 
  style="position:relative; width:calc(100dvw - 40px); max-width:720px; 
          aspect-ratio:2/1; margin:0px; padding:0px;">
  <img src="<?php echo(get_root()); ?>/common/Mollweide.png" 
   alt="Mollweide Background"
   style="width:100%; height:100%; position:absolute; 
          top:0px; left:0px; opacity:0.7; padding: 0px;">
  <svg id="img" 
   style="width:100%; height:100%; position:absolute; 
          top:0px; left:0px; padding:0px;">
  </svg>
 </div>

 <p>
  <img id="page_legend" style="max-width: calc(100dvw - 30px);"
   alt="Legend"
   src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==">
 </p>
 
 <dialog id="info_dialog" class="map_dialog">
  <h3>Information</h3>
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
  <button id="info_dialog_close" title="Close">Close</button>
 </dialog>

 <dialog id="settings_dialog" class="map_dialog">
  <h3>Settings</h3>
  <h4>Layer Opacity</h4>
  <p>
   <input type="range" min="20" max="100" id="opacity_range">
   <span id="opacity_range_label"></span>
  </p>
  <button id="settings_dialog_close" title="Close">Close</button>
 </dialog>

</div>

<?php page_bottom(true); ?>
