/* Copyright (c) Harris Hudson 2024 */

import { CFUtils, CFRender } 
 from './CFRender.js'
import { SphericalProjection } 
 from './projection_helpers.js'

var cell_stops = null
var send_grid = false
var send_caches = false
var omit_zero_values = false

const cell_omit_value = function(val) {
 if (((!val) && (val == 0)) || (!isFinite(val)) || 
       (omit_zero_values && (val == 0)))
  return true
 return false
}

let cfu = new CFUtils()

function opacityCell(cellData) {
 return cfu.steppedOpacity(cellData.value, cell_stops)
}

function fillCell(cellData) {
 return cfu.steppedHexColor(cellData.value, cell_stops)
}

function omitCell(cellData) {
 return (cell_omit_value(cellData.value))
}

async function prepare_images(p) {

 let CFR = new CFRender(p.barray, p.extentCache, p.projectionCache, p.longitudeWrap)

 delete p.barray
 p.barray = null

 let bounds = CFR.getXYbbox().bbox
 let global_attributes = CFR.netCDF.headers['globalAttributes']
 var variable_attributes = null
 let NetCDF_variable = CFR.netCDF.headers.variables.find((val) => {
   return val.name === p.variable
  })
 if ((NetCDF_variable) && (NetCDF_variable.attributes))
  variable_attributes = NetCDF_variable.attributes
 self.postMessage({"msg": "begin",
                   "bounds": bounds,
                   "globalAttributes": global_attributes,
                   "variableAttributes": variable_attributes})

 let time_var = CFR.Axes['T']['axis']
 let time_values = CFR.netCDF.getDataVariable(time_var)
 let timeUnits = CFR.getVariableUnits(time_var)

 for (let i=0; i < time_values.length; i++) {
  let thisDimensionFilter = p.dimensionFilter 
  if (!thisDimensionFilter)
   thisDimensionFilter = {}
  thisDimensionFilter[time_var] = time_values[i]

  let theStyle = {
   "fill": fillCell,
   "opacity": opacityCell,
   "omit": omitCell,
   "stroke": "none",
   "strokeWidth": 0
  }
  if (p.idealCellSize)
   theStyle['idealCellSize'] = p.idealCellSize

  let img = await CFR.draw2DbasicGrid(
    p.variable,
    thisDimensionFilter,
    SphericalProjection, 
    'url',
    theStyle)

  let timeZulu = cfu.getTimeISOString(time_values[i], timeUnits)
  let timeLocal = cfu.zuluToLocalTime(timeZulu)

  let obj = {"img": img,
             "timeVariable": time_var,
             "timeValue": time_values[i],
             "timeUnits": timeUnits,
             "timeValueZulu": timeZulu,
             "timeValueLocal": timeLocal}

  if (send_grid) {
   //This has the effect of setting; grid["XYprojectionFunction"] = null
   let grid = CFR.getTransferableData2DGrid()
   obj['grid'] = grid
  }

  let row_obj = {msg: "row",
                 data: obj,
                 sofar: i,
                 total: time_values.length}

  self.postMessage(row_obj) 
 }

 let final_payload = {msg: "end"}
 if (send_caches) {
  final_payload['extentCache'] = CFR.extentCache
  final_payload['projectionCache'] = CFR.projectionCache
 }

 self.postMessage(final_payload)
 self.close()
}

self.onmessage = function(event) {
 const payload = event.data
 cell_stops = payload.stops
 omit_zero_values = payload.omitZeroValues
 send_grid = payload.returnGrid
 send_caches = payload.returnCaches
 prepare_images(payload)
}
