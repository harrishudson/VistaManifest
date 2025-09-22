/* Copyright (c) Harris Hudson 2025 */

import { CFUtils } from './CFRender.js'

let cfu = new CFUtils()

export const probability_percentage_stops = [
  { value: 0, color: "#ffffff", opacity: 0.2 },    // No chance, fully transparent
  { value: 10, color: "#add8e6", opacity: 0.25 },  // Very low chance, light blue
  { value: 20, color: "#add8e6", opacity: 0.3 },   // Light blue
  { value: 30, color: "#87ceeb", opacity: 0.35 },  // Sky blue
  { value: 40, color: "#87ceeb", opacity: 0.4 },   // Sky blue, more pronounced
  { value: 50, color: "#4682b4", opacity: 0.45 },  // Steel blue
  { value: 60, color: "#4682b4", opacity: 0.5 },   // Steel blue, darker
  { value: 70, color: "#1e90ff", opacity: 0.55 },  // Dodger blue
  { value: 80, color: "#0000ff", opacity: 0.6 },   // Pure blue
  { value: 90, color: "#00008b", opacity: 0.65 },  // Dark blue
  { value: 100, color: "#000080", opacity: 0.7 }   // Navy blue
 ]

export const risk_percentage_stops = [
  { value: 0, color: "#ffffff", opacity: 0.0 },   // No risk, fully transparent
  { value: 10, color: "#e0f7fa", opacity: 0.2 },  // Very low risk, pale cyan
  { value: 20, color: "#b3e5fc", opacity: 0.3 },  // Low risk, light cyan
  { value: 30, color: "#81d4fa", opacity: 0.4 },  // Low-medium risk, light blue
  { value: 40, color: "#4fc3f7", opacity: 0.5 },  // Medium risk, medium blue
  { value: 50, color: "#29b6f6", opacity: 0.6 },  // Moderate risk, darker blue
  { value: 60, color: "#ffeb3b", opacity: 0.7 },  // Elevated risk, yellow
  { value: 70, color: "#ffb74d", opacity: 0.8 },  // High risk, orange
  { value: 80, color: "#ff9800", opacity: 0.85 }, // Very high risk, darker orange
  { value: 90, color: "#f44336", opacity: 0.9 },  // Severe risk, red
  { value: 100, color: "#b71c1c", opacity: 1.0 }  // Extreme risk, deep red
 ]

export const risk_color_stops = [
  { value: 0.0, color: "#ffffff", opacity: 0.0 }, // White, no risk
  { value: 0.2, color: "#00ff00", opacity: 0.4 }, // Green, thunderstorm risk (tstm)
  { value: 0.4, color: "#ffff00", opacity: 0.6 }, // Yellow, slight risk
  { value: 0.6, color: "#ffa500", opacity: 0.8 }, // Orange, moderate risk
  { value: 0.8, color: "#ff0000", opacity: 1.0 }, // Red, high risk
  { value: 1.0, color: "#800080", opacity: 1.0 }  // Purple, extreme risk
]

export const risk_color_deci_stops = [
  { value: 0, color: "#ffffff", opacity: 0.0 }, // White, no risk
  { value: 2, color: "#00ff00", opacity: 0.4 }, // Green, thunderstorm risk (tstm)
  { value: 4, color: "#ffff00", opacity: 0.6 }, // Yellow, slight risk
  { value: 6, color: "#ffa500", opacity: 0.8 }, // Orange, moderate risk
  { value: 8, color: "#ff0000", opacity: 1.0 }, // Red, high risk
  { value: 10, color: "#800080", opacity: 1.0 }  // Purple, extreme risk
]

export const cloud_cover_percentage_stops = [
  { value: 0, color: "#ffffff", opacity: 0.0 },    // Clear, fully transparent
  { value: 10, color: "#f0f0f0", opacity: 0.1 },   // Very light gray, barely visible
  { value: 20, color: "#e0e0e0", opacity: 0.2 },   // Light gray
  { value: 30, color: "#d3d3d3", opacity: 0.3 },   // Light gray, slightly darker
  { value: 40, color: "#c0c0c0", opacity: 0.4 },   // Gray, more visible
  { value: 50, color: "#a9a9a9", opacity: 0.5 },   // Medium gray
  { value: 60, color: "#909090", opacity: 0.6 },   // Darker gray
  { value: 70, color: "#808080", opacity: 0.7 },   // Medium-dark gray
  { value: 80, color: "#696969", opacity: 0.8 },   // Dark gray
  { value: 90, color: "#505050", opacity: 0.9 },   // Very dark gray
  { value: 100, color: "#383838", opacity: 1.0 }   // Near-black for full cloud cover
 ]

export const surface_temperature_kelvin_stops = [
  { value: 223.15, color: "#7300a6", opacity: 1}, // Deep purple, extreme cold
  { value: 233.15, color: "#9900ff", opacity: 1 }, // Purple, very cold
  { value: 243.15, color: "#6f00ff", opacity: 1 }, // Violet, very cold
  { value: 253.15, color: "#0000ff", opacity: 1 }, // Blue, cold
  { value: 263.15, color: "#007fff", opacity: 1 }, // Sky blue, cold to cool
  { value: 273.15, color: "#00bfff", opacity: 1 }, // Cyan, freezing point
  { value: 278.15, color: "#00ffff", opacity: 1 }, // Aqua, cool
  { value: 283.15, color: "#66ffcc", opacity: 1 }, // Light green, mild
  { value: 288.15, color: "#99ff99", opacity: 1 }, // Green, mild to warm
  { value: 293.15, color: "#ccff66", opacity: 1 }, // Yellow-green, warm
  { value: 298.15, color: "#ffff00", opacity: 1 }, // Yellow, warm to hot
  { value: 303.15, color: "#ffc100", opacity: 1 }, // Orange-yellow, hot
  { value: 308.15, color: "#ff8000", opacity: 1 }, // Orange, very hot
  { value: 313.15, color: "#ff4000", opacity: 1 }, // Red-orange, extreme heat
  { value: 318.15, color: "#ff0000", opacity: 1 }, // Red, extreme heat
  { value: 323.15, color: "#b30000", opacity: 1 }  // Deep red, searing heat
 ]

export const surface_temperature_celsius_stops = [
  { value: -50, color: "#7300a6", opacity: 1 }, // Deep purple, extreme cold
  { value: -40, color: "#9900ff", opacity: 1 }, // Purple, very cold
  { value: -30, color: "#6f00ff", opacity: 1 }, // Violet, very cold
  { value: -20, color: "#0000ff", opacity: 1 }, // Blue, cold
  { value: -10, color: "#007fff", opacity: 1 }, // Sky blue, cold to cool
  { value: 0,   color: "#00bfff", opacity: 1 }, // Cyan, freezing point
  { value: 5,   color: "#00ffff", opacity: 1 }, // Aqua, cool
  { value: 10,  color: "#66ffcc", opacity: 1 }, // Light green, mild
  { value: 15,  color: "#99ff99", opacity: 1 }, // Green, mild to warm
  { value: 20,  color: "#ccff66", opacity: 1 }, // Yellow-green, warm
  { value: 25,  color: "#ffff00", opacity: 1 }, // Yellow, warm to hot
  { value: 30,  color: "#ffc100", opacity: 1 }, // Orange-yellow, hot
  { value: 35,  color: "#ff8000", opacity: 1 }, // Orange, very hot
  { value: 40,  color: "#ff4000", opacity: 1 }, // Red-orange, extreme heat
  { value: 45,  color: "#ff0000", opacity: 1 }, // Red, extreme heat
  { value: 50,  color: "#b30000", opacity: 1 }  // Deep red, searing heat
 ]

export const daily_precipitation_totals_mm_stops = [
  {value: 1, color: "#FFBF59", opacity: 1},
  {value: 2, color: "#FEAD78", opacity: 1},
  {value: 5, color: "#FEFE00", opacity: 1},
  {value: 7.5, color: "#B2FF00", opacity: 1},
  {value: 10, color: "#4CFF00", opacity: 1},
  {value: 12.5, color: "#00E599", opacity: 1},
  {value: 15, color: "#00A5FF", opacity: 1},
  {value: 20, color: "#3F3FFF", opacity: 1},
  {value: 30, color: "#B200FF", opacity: 1},
  {value: 40, color: "#FF00FF", opacity: 1},
  {value: 100, color: "#FF4C9B", opacity: 1}
 ]

export const monthly_precipitation_totals_mm_stops = [
  {value: 0, color: "#FFFFFF", opacity: 1},
  {value: 1, color: "#ffbf59", opacity: 1},
  {value: 5, color: "#fead00", opacity: 1},
  {value: 10, color: "#ffff00", opacity: 1},
  {value: 25, color: "#b2ff00", opacity: 1},
  {value: 50, color: "#4cff00", opacity: 1},
  {value: 100, color: "#00e599", opacity: 1},
  {value: 200, color: "#00a5ff", opacity: 1},
  {value: 300, color: "#3f3fff", opacity: 1},
  {value: 400, color: "#b200ff", opacity: 1},
  {value: 600, color: "#ff00ff", opacity: 1},
  {value: 800, color: "#ff4c9b", opacity: 1} 
 ]

export const radar_reflectivity1_stops = [
  { value: 0, color: "#ffffff", opacity: 0.0 },   // No reflectivity, transparent
  { value: 5, color: "#d4f1f9", opacity: 0.25 },   // Very light, almost no precipitation, pale blue
  { value: 10, color: "#a0e3f2", opacity: 0.35 },  // Light rain, soft blue
  { value: 15, color: "#70c9e5", opacity: 0.45 },  // Light-moderate rain, light cyan-blue
  { value: 20, color: "#47aedd", opacity: 0.55 },  // Moderate rain, medium cyan-blue
  { value: 30, color: "#2b88c5", opacity: 0.65 },  // Heavier rain, deeper blue
  { value: 35, color: "#437fd9", opacity: 0.75 },  // Moderate-heavy, blue transitioning to darker blue
  { value: 40, color: "#ffaa1c", opacity: 0.80 }, // Heavy rain, bright yellow-orange
  { value: 45, color: "#f07d15", opacity: 0.85 },  // Very heavy rain, orange
  { value: 50, color: "#e64a19", opacity: 0.90 }, // Severe, deep orange
  { value: 55, color: "#d32f2f", opacity: 0.95 },  // Intense storm, red
  { value: 60, color: "#9b0000", opacity: 1.0 }   // Extreme, dark red/maroon
 ]

export const sea_surface_temperature_celsius_stops = [
  { value: -2, color: "#2b83ba", opacity: 1 },    // Deep Blue for very cold, polar temperatures
  { value: 0, color: "#3288bd", opacity: 1 },     // Light Blue for freezing
  { value: 5, color: "#66c2a5", opacity: 1 },     // Teal for cold temperate regions
  { value: 10, color: "#abdda4", opacity: 1 },    // Light Green for cool waters
  { value: 15, color: "#e6f598", opacity: 1 },    // Pale Yellow-Green for mild waters
  { value: 20, color: "#ffffbf", opacity: 1 },    // Light Yellow for warm temperate waters
  { value: 25, color: "#fee08b", opacity: 1 },    // Soft Orange for warm waters
  { value: 27, color: "#fdae61", opacity: 1 },    // Orange for tropical regions
  { value: 29, color: "#f46d43", opacity: 1 },    // Deep Orange for hot tropical waters
  { value: 31, color: "#d73027", opacity: 1 },    // Red for very warm, equatorial waters
  { value: 33, color: "#a50026", opacity: 1 },    // Deep Red for extreme equatorial temperatures
  { value: 35, color: "#730000", opacity: 1 }     // Dark Red for superheated regions
 ]

export const elevation_meters_stops = [
  { value: -5,     color: "#41b6c4", opacity: 1 }, // Light Blue near coast
  { value: 0,      color: "#66c2a4", opacity: 1 }, // Teal for sea level
  { value: 100,    color: "#a1dab4", opacity: 1 }, // Light Green for coastal lowlands
  { value: 300,    color: "#ccebc5", opacity: 1 }, // Softer green for lowlands
  { value: 500,    color: "#e6f5c9", opacity: 1 }, // Pale Green plains
  { value: 700,    color: "#ffffcc", opacity: 1 }, // Light Yellow gradual rise
  { value: 900,    color: "#ffeda0", opacity: 1 }, // Yellow low hills
  { value: 1200,   color: "#fed976", opacity: 1 }, // Yellow-Orange foothills
  { value: 1500,   color: "#feb24c", opacity: 1 }, // Orange highlands
  { value: 2000,   color: "#fd8d3c", opacity: 1 }, // Deep Orange slopes
  { value: 2500,   color: "#f03b20", opacity: 1 }, // Red-orange steep terrain
  { value: 3000,   color: "#bd0026", opacity: 1 }, // Deep Red rugged mountains
  { value: 4000,   color: "#800026", opacity: 1 }, // Dark Maroon extreme peaks
  { value: 5000,   color: "#54278f", opacity: 1 }, // Purple for high alpine
  { value: 6000,   color: "#756bb1", opacity: 1 }, // Violet for very high alpine
  { value: 7000,   color: "#bcbddc", opacity: 1 }, // Grayish purple transition
  { value: 8000,   color: "#f7f7f7", opacity: 1 }, // Snow-capped zones
  { value: 8850,   color: "#ffffff", opacity: 1 }  // Pure White (Everest)
];

export const bathymetry_meters_stops =  [
  { "value": 0, "color": "#fefefe" },     // sea level edge (white highlight)
  { "value": -50, "color": "#ffb347" },   // shallow shelf (warm orange)
  { "value": -200, "color": "#ff7733" },  // upper slope (red-orange)
  { "value": -1000, "color": "#cc3366" }, // continental slope (magenta)
  { "value": -3000, "color": "#662e9b" }, // deep purple
  { "value": -6000, "color": "#2e1a47" }, // abyssal dark purple
  { "value": -11000, "color": "#0d0d0d" } // trench (black)
]

export const shoreline_distance_stops = [
  // Very close to shore
  { value: 0,   color: "#ffffcc", opacity: 1.0 },   // bright yellow (beach/shoreline)
  { value: 2,   color: "#a1dab4", opacity: 1.0 },   // aqua green (immediate coastal water / edge land)

  // Coastal zone
  { value: 10,  color: "#41b6c4", opacity: 0.95 },  // teal (shallow seas / coastal plains)
  { value: 25,  color: "#2c7fb8", opacity: 0.9 },   // medium blue (continental shelf / nearshore land)

  // Regional transition
  { value: 100, color: "#253494", opacity: 0.85 },  // deep navy (open ocean / deeper inland)
  { value: 250, color: "#081d58", opacity: 0.8 },   // very dark blue (remote ocean centers)

  // Inland far from coasts
  { value: 500,  color: "#fed976", opacity: 0.85 }, // golden yellow (continental interiors)
  { value: 1000, color: "#fd8d3c", opacity: 0.85 }, // orange (far inland desert/plateau)
  { value: 2000, color: "#e31a1c", opacity: 0.9 },  // red (deep continental heartlands)

  // Extremely remote (both land + sea)
  { value: 3000, color: "#800026", opacity: 0.95 }  // dark crimson (Sahara core, Pacific center)
];

export const wind_magnitude_cell_stops = [
  { value: 0, color: "#ffffff" },
  { value: 1, color: "#cce6ff" },
  { value: 5, color: "#66b3ff" },
  { value: 10, color: "#0066cc" },
  { value: 15, color: "#ffcc00" },
  { value: 20, color: "#ff6600" },
  { value: 25, color: "#cc0000" }
 ]

export const wind_magnitude_arrow_stops = [
  { value: 0, color: "#cccccc" },  // Gray (calm)
  { value: 2, color: "#00e6e6" },  // Cyan (light wind)
  { value: 6, color: "#ffdd00" },  // Yellow (moderate wind)
  { value: 11, color: "#ff6600" }, // Orange (strong wind)
  { value: 16, color: "#cc00ff" }, // Purple (very strong)
  { value: 21, color: "#ffffff" }  // White (extreme wind)
 ]

// Generic Wind Vector Styles

export function windOmitArrow(data) {
 if (data['magnitude'] < 3)
  return true
 return false 
}

export function windArrowOpacity(data) {
 return Math.max(0,Math.min(1,(0.4+(data['magnitude']/25))))
}

export function windScaleArrow(data) {
 const minMag = 0.5
 const maxMag = 25;  
 return Math.min(0.7,Math.max(0,(data['magnitude'] - minMag)/(maxMag - minMag)))
}

export function windAnimateArrow(data) {
 const baseDuration = 5
 const minDuration = 0.8
 const scaleFactor = 4
 return Math.max(minDuration, baseDuration - data['magnitude'] / scaleFactor)
}

export function windArrowFill(data) {
 return cfu.steppedHexColor(data['magnitude'], wind_magnitude_arrow_stops)
}

export function windCellFill(cellData) {
 return cfu.steppedHexColor(cellData.value, wind_magnitude_cell_stops)
}

export function windOmitCell(cellData) {
 if (cellData.value <= 0.5)
  return true;
 return false;
}

