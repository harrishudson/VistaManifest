/* Copyright (c) Harris Hudson 2024 */

// Map Projection Helpers
// ----------------------

export function SphericalProjection(coords) {
 var lon = coords[0]
 var lat = coords[1]

 const EARTH_RADIUS = 6378137.0 // Earth's radius in meters
 const MAX_LATITUDE = 85.0511287798 // Maximum latitude for Web Mercator

 lat = Math.max(Math.min(MAX_LATITUDE, lat), -MAX_LATITUDE)
 const lonRad = (lon * Math.PI) / 180
 const latRad = (lat * Math.PI) / 180
    
 const x = EARTH_RADIUS * lonRad
 const y = EARTH_RADIUS * Math.log(Math.tan(Math.PI / 4 + latRad / 2))
 return [ x, y ]
}

export function mollweideProjection(coords, centralMeridian = 0) {
 const lon = coords[0]
 const lat = coords[1]

 const degToRad = Math.PI / 180
 let normalizedLon = lon
 if (lon > 180) 
  normalizedLon = lon - 360
    
 let lambda = (normalizedLon - centralMeridian) * degToRad
    
 while (lambda > Math.PI) lambda -= 2 * Math.PI
 while (lambda < -Math.PI) lambda += 2 * Math.PI
    
 let phi = lat * degToRad
    
 const MAX_ITERATIONS = 500
 const EPSILON = 1e-10
    
 let theta = phi
 let prevTheta
    
 // Newton-Raphson iteration to solve for theta
 for (let i = 0; i < MAX_ITERATIONS; i++) {
  prevTheta = theta;
  theta = theta - (theta + Math.sin(theta) - Math.PI * Math.sin(phi)) / 
          (1 + Math.cos(theta))
  if (Math.abs(theta - prevTheta) < EPSILON) 
   break
 }
    
 const sqrt2 = Math.sqrt(2)
 const sqrt8 = Math.sqrt(8)
    
 // Earth's radius (can be adjusted as needed)
 const R = 1
 const x = (sqrt8 * R * lambda * Math.cos(theta/2)) / Math.PI
 const y = sqrt2 * R * Math.sin(theta/2)
    
 return [ x, y ]
}

export function inverseMollweideProjection(pixelX, pixelY, width, height, centralMeridian = 0) {
 const centerX = width / 2
 const centerY = height / 2
 const x = pixelX - centerX
 const y = centerY - pixelY  // Flip Y axis
    
 const sqrt2 = Math.sqrt(2)
 const R = width / (4 * sqrt2)
    
 const theta = 2 * Math.asin(y / (sqrt2 * R))
    
 const maxY = sqrt2 * R
 if (Math.abs(y) > maxY)
  return null  // Point is outside the projection
    
 const lat = Math.asin((theta + Math.sin(theta)) / Math.PI)
 const lambda = (Math.PI * x) / (2 * R * sqrt2 * Math.cos(theta/2))
 const latDeg = lat * 180 / Math.PI
 let lonDeg = lambda * 180 / Math.PI + centralMeridian
    
 while (lonDeg < 0) lonDeg += 360
 while (lonDeg > 360) lonDeg -= 360
    
 // Check if longitude is within valid range
 const maxX = 2 * sqrt2 * R
 if (Math.abs(x) > maxX) 
  return null  // Point is outside the projection
    
 return {
  lat: latDeg,
  lon: lonDeg
 }
}

export function isMollweidePointInProjection(pixelX, pixelY, width, height) {
 const centerX = width / 2
 const centerY = height / 2
 const x = pixelX - centerX
 const y = centerY - pixelY
    
 const sqrt2 = Math.sqrt(2)
 const R = width / (4 * sqrt2)
    
 // Check if point is within the elliptical boundary
 const normalizedX = x / (2 * sqrt2 * R)
 const normalizedY = y / (sqrt2 * R)
    
 return (normalizedX * normalizedX + normalizedY * normalizedY) <= 1
}
