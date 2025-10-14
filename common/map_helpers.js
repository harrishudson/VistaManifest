/* Copyright (c) Harris Hudson 2025 */

// Legend Image Creator
// --------------------

export function createColorLegendImg(colorStops) {
 const rectWidth = 40 // Width of each legend rectangle
 const rectHeight = 15 // Height of each legend rectangle
 const padding = 2 // Padding between rectangles and numbers
 const legendHeight = rectHeight + padding + 20 // Total height of the legend
 const legendWidth = (rectWidth + padding) * colorStops.length // Total width of the legend

 let legendSvg = `<svg width="${legendWidth}" height="${legendHeight}" xmlns="http://www.w3.org/2000/svg">`

 for (let i = 0; i < colorStops.length; i++) {
  const colorStop = colorStops[i]
  const x = i * (rectWidth + padding)
  const y = padding
  const rectColor = colorStop.color
  const rectValue = colorStop.value

  legendSvg += `
   <rect x="${x}" y="${y}" width="${rectWidth}" height="${rectHeight}" fill="${rectColor}" />
   <text x="${x + rectWidth / 2}" y="${y + rectHeight + 15}" text-anchor="middle" font-family="Verdana" font-size="10px" >${rectValue}</text>
  `
 }

 legendSvg += '</svg>'
 return "data:image/svg+xml;base64,"+btoa(legendSvg)
}

// Map Base Layers
// ---------------

export const tile_providers = 
 [
  {title: 'OpenStreetMap', 
   url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', 
   attrib: 'Tiles &copy; OpenStreetMap' },
  {title: 'Esri.WorldStreetMap', 
   url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', 
   attrib: 'Tiles &copy; Esri'},
  {title: 'Esri.WorldTopoMap', 
   url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}', 
   attrib: 'Tiles &copy; Esri'},
  {title: 'Esri.WorldImagery', 
   url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', 
   attrib: 'Tiles &copy; Esri'},
  {title: 'OSM', 
   url: 'http://{s}.tile.osm.org/{z}/{x}/{y}.png', 
   attrib: 'Tiles &copy; OpenStreetMap' },
  {title: 'OpenTopoMap',
   url: 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
   attrib: 'Tiles &copy; OpenStreetMap Style &copy; OpenTopoMap'},
  {title: 'Stamen Terrain',
   url: 'https://stamen-tiles-a.a.ssl.fastly.net/terrain/{z}/{x}/{y}.png',
   attrib: 'Tiles by Stamen Design'},
  {title: 'CartoDB.Positron',
   url: 'https://a.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', 
   attrib: '&copy; OpenStreetMap CARTO'}
 ];

// Svg css scaler
export function fitSvgContainerToViewport(el, bbox, viewport = 
  { minLon: 0, maxLon: 360, maxLat: 90, minLat: -90 }) {
  if (!el || !bbox) return;

  const targetWidthDeg  = viewport.maxLon - viewport.minLon;
  const targetHeightDeg = viewport.maxLat - viewport.minLat;

  const widthPct  = ((bbox.east - bbox.west) / targetWidthDeg) * 100;
  const heightPct = ((bbox.north - bbox.south) / targetHeightDeg) * 100;
  const leftPct   = ((bbox.west - viewport.minLon) / targetWidthDeg) * 100;
  const topPct    = ((viewport.maxLat - bbox.north) / targetHeightDeg) * 100;

  el.style.position = 'absolute';
  el.style.left     = `${leftPct}%`;
  el.style.top      = `${topPct}%`;
  el.style.width    = `${widthPct}%`;
  el.style.height   = `${heightPct}%`;
  el.style.transformOrigin = 'top left';
}

// Date processing Helpers
// -----------------------

export function pad(num, size = 2) {
 num = num.toString()
 while (num.length < size) num = "0" + num
 return num
}

export function getFirstDayOfMonth(year, month) {
 return new Date(year, month - 1, 1).getDate()
}

export function getLastDayOfMonth(year, month) {
 let date = new Date(year, month, 0)
 return date.getDate()
}

export function extractYearAndMonth(dateString) {
 const year = parseInt(dateString.slice(0, 4), 10)
 const month = parseInt(dateString.slice(4, 6), 10)
 return { year, month }
}

export function getOneMonthPrior(date) {
 const newDate = new Date(date)
 newDate.setMonth(newDate.getMonth() - 1)
 if (newDate.getMonth() === date.getMonth()) {
  newDate.setDate(0)
 }
 return newDate
}

export function formatDateToYYYYMMDD(date) {
 const year = date.getFullYear()
 const month = String(date.getMonth() + 1).padStart(2, '0') // Months are 0-indexed, so add 1
 const day = String(date.getDate()).padStart(2, '0')
 return `${year}-${month}-${day}`
}

/* Empty (transparent) 1x1 pixel image DataURI used for various temporary placeholders */
/* ----------------------------------------------------------------------------------- */
export const empty_image = 
 "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=="
