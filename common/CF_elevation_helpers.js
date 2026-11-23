/*
 * Terrain line-of-sight calculations for CFRender grids.
 * This module deliberately does not modify or extend CFRender.
*/

const EARTH_RADIUS_M = 6371008.8;
const DEG_TO_RAD = Math.PI / 180;

function finite(value) {
  const n = Number(value);
  return Number.isFinite(n);
}

function validateDimensionFilter(cfr, requested) {
  const active = cfr?.data2DGrid?.DimensionFilter || {};
  if (!cfr?.data2DGrid?.grid) throw new Error('CFRender must build its 2D grid before creating an elevation helper.');
  if (requested !== undefined && JSON.stringify(sortObject(requested || {})) !== JSON.stringify(sortObject(active))) {
    throw new Error('dimensionFilter does not match the dimension filter used to build cfr.data2DGrid. Pass the same filter to draw2DbasicGrid() and the elevation helper.');
  }
  return { ...active };
}

function sortObject(value) {
  if (Array.isArray(value)) return value.map(sortObject);
  if (value && typeof value === 'object') return Object.fromEntries(Object.keys(value).sort().map(k => [k, sortObject(value[k])]));
  return value;
}

function axesFromCells(cells) {
  const xs = new Map(), ys = new Map();
  for (const cell of cells) {
    if (Number.isInteger(cell?.x_offset) && finite(cell.X)) xs.set(cell.x_offset, Number(cell.X));
    if (Number.isInteger(cell?.y_offset) && finite(cell.Y)) ys.set(cell.y_offset, Number(cell.Y));
  }
  return {
    xAxis: [...xs.entries()].sort((a,b) => a[0]-b[0]).map(v => v[1]),
    yAxis: [...ys.entries()].sort((a,b) => a[0]-b[0]).map(v => v[1])
  };
}

function findHeaderVariable(cfr, name) {
  const variables = cfr?.netCDF?.headers?.variables;
  if (!Array.isArray(variables)) throw new Error('CFRender NetCDF headers do not expose a variables array.');
  const variable = variables.find(v => v?.name === name);
  if (!variable) throw new Error(`Projected dataset is missing its '${name}' auxiliary coordinate variable.`);
  return variable;
}

/*
 * Read per-cell geographic coordinates using the NetCDF header's dimension
 * IDs. NetCDF variable dimensions are in file order; the fastest-varying
 * dimension is last. This handles [y,x] and [x,y] without assuming either.
 */
function projectedGeographicCoordinates(cfr, nx, ny) {
  const names = cfr?.varOrdinate;
  const xName = names?.X, yName = names?.Y;
  if (typeof xName !== 'string' || typeof yName !== 'string')
    throw new Error('Projected LOS requires CFR.varOrdinate.X and CFR.varOrdinate.Y to name the longitude and latitude variables.');

  const xVariable = findHeaderVariable(cfr, xName);
  const yVariable = findHeaderVariable(cfr, yName);
  const xDims = xVariable.dimensions;
  const yDims = yVariable.dimensions;
  if (!Array.isArray(xDims) || !Array.isArray(yDims) || xDims.length !== 2 || yDims.length !== 2 ||
      xDims[0] !== yDims[0] || xDims[1] !== yDims[1])
    throw new Error(`Projected auxiliary coordinates '${xName}' and '${yName}' must share the same two-dimensional grid dimensions.`);

  const dimIndex = cfr.dimIndex || {};
  const xDim = dimIndex[cfr.Axes?.X || 'x'];
  const yDim = dimIndex[cfr.Axes?.Y || 'y'];
  if (!Number.isInteger(xDim) || !Number.isInteger(yDim))
    throw new Error('Cannot identify the NetCDF X/Y dimension IDs from CFR.dimIndex and CFR.Axes.');
  const xPos = xDims.indexOf(xDim), yPos = xDims.indexOf(yDim);
  if (xPos < 0 || yPos < 0 || xPos === yPos)
    throw new Error(`Auxiliary coordinate dimensions for '${xName}'/'${yName}' do not match the CFRender X/Y dimensions.`);

  const headersDims = cfr.netCDF.headers.dimensions || [];
  const xSize = headersDims[xDim]?.size, ySize = headersDims[yDim]?.size;
  if (xSize !== nx || ySize !== ny)
    throw new Error(`Auxiliary coordinate grid is ${xSize}×${ySize}, but the rendered terrain grid is ${nx}×${ny}. Ensure the coordinate arrays correspond to the returned NCSS subset.`);

  const lon = cfr.netCDF.getDataVariable(xName);
  const lat = cfr.netCDF.getDataVariable(yName);
  const count = nx * ny;
  if (!lon || !lat || lon.length !== count || lat.length !== count)
    throw new Error(`Projected auxiliary coordinate arrays must each contain ${count} values; received ${lon?.length ?? 'none'} and ${lat?.length ?? 'none'}.`);

  // For [y,x], x is the final/fast dimension: iy*nx+ix.
  // For [x,y], y is the final/fast dimension: ix*ny+iy.
  const indexOf = (ix, iy) => xPos > yPos ? iy * nx + ix : ix * ny + iy;
  return { lon, lat, indexOf };
}

function haversineMetres(lat1, lon1, lat2, lon2, earthRadiusMetres = EARTH_RADIUS_M) {
  const p1 = lat1 * DEG_TO_RAD, p2 = lat2 * DEG_TO_RAD;
  const dp = (lat2 - lat1) * DEG_TO_RAD;
  const dl = (lon2 - lon1) * DEG_TO_RAD;
  const a = Math.sin(dp / 2) ** 2 + Math.cos(p1) * Math.cos(p2) * Math.sin(dl / 2) ** 2;
  return 2 * earthRadiusMetres * Math.asin(Math.min(1, Math.sqrt(Math.max(0, a))));
}

/**
 * Create a cached viewshed evaluator for the current CFRender terrain grid.
 * antennaX/Y must be in the NetCDF source coordinate system. antennaLat/Lon
 * are geographic degrees and are used for distance calculations.
 */
export function createLOSViewshed({
  cfr,
  grid,
  antennaX,
  antennaY,
  antennaLat,
  antennaLon,
  antennaAltitudeAMSL,
  targetHeightAboveGround = 1.5,
  samplesPerCell = 4,
  refractionCoefficient = 0.25,
  earthRadiusMetres = EARTH_RADIUS_M,
  viewshedStyle = {},
  dimensionFilter = undefined
}) {
  if (!cfr || !Array.isArray(grid) || !grid.length) throw new Error('LOS requires CFRender and a non-empty cell array.');
  const activeFilter = validateDimensionFilter(cfr, dimensionFilter);
  const style = {
    visibleColor: '#00ffff', visibleOpacity: 0.75,
    hiddenColor: '#ff2525', hiddenOpacity: 0.50,
    unknownColor: '#777777', unknownOpacity: 0,
    ...viewshedStyle
  };
  if (![antennaX, antennaY, antennaLat, antennaLon, antennaAltitudeAMSL].every(finite))
    throw new Error('LOS received invalid antenna coordinates or altitude.');
  if (!finite(refractionCoefficient) || refractionCoefficient < 0 || refractionCoefficient >= 1)
    throw new Error('The refraction coefficient must be between 0 (inclusive) and 1 (exclusive).');
  if (!finite(earthRadiusMetres) || earthRadiusMetres <= 0) throw new Error('Earth radius must be a positive number of metres.');

  const { xAxis, yAxis } = axesFromCells(grid);
  const nx = xAxis.length, ny = yAxis.length;
  if (!nx || !ny) throw new Error('Could not recover the NetCDF X/Y axes from CFRender cell properties.');
  const dx = nx > 1 ? xAxis[1] - xAxis[0] : 1;
  const dy = ny > 1 ? yAxis[1] - yAxis[0] : 1;
  if (!finite(dx) || !finite(dy) || dx === 0 || dy === 0) throw new Error('Invalid NetCDF X/Y axis spacing.');

  const elevation = new Float32Array(nx * ny);
  elevation.fill(NaN);
  for (const cell of grid) {
    const ix = cell?.x_offset, iy = cell?.y_offset;
    if (!Number.isInteger(ix) || !Number.isInteger(iy) || ix < 0 || ix >= nx || iy < 0 || iy >= ny) continue;
    const value = Number(cell.value);
    if (cell.value !== null && cell.value !== undefined && finite(value) && value > -1000 && value < 10000)
      elevation[iy * nx + ix] = value;
  }

  const antennaIndexX = (antennaX - xAxis[0]) / dx;
  const antennaIndexY = (antennaY - yAxis[0]) / dy;
  const ax = Math.round(antennaIndexX), ay = Math.round(antennaIndexY);
  if (ax < 0 || ax >= nx || ay < 0 || ay >= ny)
    throw new Error('The antenna lies outside the returned terrain grid.');
  const antennaGround = elevation[ay * nx + ax];
  if (!finite(antennaGround)) throw new Error('No valid terrain elevation was returned at the antenna location.');

  let geographic;
  if (cfr.is_projected_source) {
    geographic = projectedGeographicCoordinates(cfr, nx, ny);
  } else {
    // Geographic source axes already represent longitude and latitude.
    geographic = { lon: null, lat: null, indexOf: (ix, iy) => iy * nx + ix };
  }

  // Precompute one ground distance per cell. The inner LOS loop then performs
  // only array reads and arithmetic; no trigonometry/geodesics per ray sample.
  const distance = new Float32Array(nx * ny);
  const curvatureFactor = (1 - refractionCoefficient) / (2 * earthRadiusMetres);
  for (let iy = 0; iy < ny; iy++) {
    for (let ix = 0; ix < nx; ix++) {
      let lat, lon;
      if (cfr.is_projected_source) {
        const k = geographic.indexOf(ix, iy);
        lon = Number(geographic.lon[k]);
        lat = Number(geographic.lat[k]);
      } else {
        lon = Number(xAxis[ix]);
        lat = Number(yAxis[iy]);
      }
      if (!finite(lat) || !finite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 360) {
        distance[iy * nx + ix] = NaN;
      } else {
        distance[iy * nx + ix] = haversineMetres(antennaLat, antennaLon, lat, lon, earthRadiusMetres);
      }
    }
  }

  const visibility = new Int8Array(nx * ny); // 0 unknown, 1 visible, -1 blocked, -2 no data
  const samples = Math.max(1, Math.min(16, Math.floor(Number(samplesPerCell) || 4)));
  const targetHeight = Number(targetHeightAboveGround) || 0;
  const antennaEffective = Number(antennaAltitudeAMSL);

  function isVisible(cell) {
    const tx = cell?.x_offset, ty = cell?.y_offset;
    if (!Number.isInteger(tx) || !Number.isInteger(ty) || tx < 0 || tx >= nx || ty < 0 || ty >= ny) return null;
    const targetIndex = ty * nx + tx;
    const cached = visibility[targetIndex];
    if (cached !== 0) return cached === 1 ? true : cached === -1 ? false : null;
    const targetGround = elevation[targetIndex];
    const targetDistance = distance[targetIndex];
    if (!finite(targetGround) || !finite(targetDistance)) { visibility[targetIndex] = -2; return null; }

    const ddx = tx - antennaIndexX, ddy = ty - antennaIndexY;
    if (Math.abs(ddx) < 1e-10 && Math.abs(ddy) < 1e-10) { visibility[targetIndex] = 1; return true; }

    const targetEffectiveAltitude = targetGround + targetHeight - curvatureFactor * targetDistance * targetDistance;
    const steps = Math.max(1, Math.ceil(Math.max(Math.abs(ddx), Math.abs(ddy)) * samples));
    let lastIndex = -1;
    for (let s = 1; s < steps; s++) {
      const t = s / steps;
      const ix = Math.round(antennaIndexX + ddx * t);
      const iy = Math.round(antennaIndexY + ddy * t);
      if (ix < 0 || ix >= nx || iy < 0 || iy >= ny) continue;
      const sampleIndex = iy * nx + ix;
      if (sampleIndex === lastIndex || sampleIndex === targetIndex) continue;
      lastIndex = sampleIndex;
      const terrain = elevation[sampleIndex], d = distance[sampleIndex];
      if (!finite(terrain) || !finite(d)) continue;
      const distanceFraction = targetDistance > 0 ? Math.min(1, d / targetDistance) : t;
      const rayAltitude = antennaEffective + (targetEffectiveAltitude - antennaEffective) * distanceFraction;
      const terrainEffectiveAltitude = terrain - curvatureFactor * d * d;
      if (terrainEffectiveAltitude > rayAltitude) { visibility[targetIndex] = -1; return false; }
    }
    visibility[targetIndex] = 1;
    return true;
  }

  return {
    isVisible,
    fillColor(cell) { const v = isVisible(cell); return v === true ? style.visibleColor : v === false ? style.hiddenColor : style.unknownColor; },
    fillOpacity(cell) { const v = isVisible(cell); return v === true ? style.visibleOpacity : v === false ? style.hiddenOpacity : style.unknownOpacity; },
    get dimensions() { return { nx, ny }; },
    get dimensionFilter() { return activeFilter; },
    get antennaGroundAMSL() { return antennaGround; }
  };
}


// -----------------------------------------------------------------------------
// Hillshade support
// -----------------------------------------------------------------------------

// Same as CFutils color interpolation, just implemented locally here.
function blendHexColors(shadow, light, amount) {
  const parse = value => {
    const h = String(value || '#000000').replace('#', '').trim();
    if (/^[0-9a-f]{3}$/i.test(h)) return h.split('').map(c => parseInt(c + c, 16));
    if (/^[0-9a-f]{6}$/i.test(h)) return [parseInt(h.slice(0,2),16), parseInt(h.slice(2,4),16), parseInt(h.slice(4,6),16)];
    return [0, 0, 0];
  };
  const a = parse(shadow), b = parse(light);
  const t = Math.max(0, Math.min(1, amount));
  return '#' + a.map((v, i) => Math.round(v + (b[i] - v) * t).toString(16).padStart(2, '0')).join('');
}

function localDistanceEastNorth(lat1, lon1, lat2, lon2) {
  const meanLat = ((lat1 + lat2) * 0.5) * DEG_TO_RAD;
  const east = (lon2 - lon1) * DEG_TO_RAD * EARTH_RADIUS_M * Math.cos(meanLat);
  const north = (lat2 - lat1) * DEG_TO_RAD * EARTH_RADIUS_M;
  return { east, north, distance: Math.hypot(east, north) };
}

/**
 * Create a cached hillshade evaluator for a CFRender elevation grid.
 * Azimuth is clockwise from north (0=N, 90=E); elevation is degrees above
 * the horizon. Geographic cell distances are derived from the grid's own
 * coordinates, including auxiliary lon/lat arrays for projected datasets.
 */
export function createHillShader({
  cfr,
  grid,
  azimuth = 315,
  elevation = 45,
  exaggeration = 1,
  verticalScale = 1,
  shaderStyle = {},
  dimensionFilter = undefined
}) {
  if (!cfr || !Array.isArray(grid) || !grid.length)
    throw new Error('Hillshade requires CFRender and a non-empty cell array.');
  const activeFilter = validateDimensionFilter(cfr, dimensionFilter);
  if (!finite(azimuth) || !finite(elevation) || !finite(exaggeration) || Number(exaggeration) < 0 || !finite(verticalScale) || Number(verticalScale) === 0)
    throw new Error('Hillshade azimuth, elevation, exaggeration and verticalScale must be valid numbers; exaggeration cannot be negative and verticalScale cannot be zero.');
  azimuth = ((Number(azimuth) % 360) + 360) % 360;
  elevation = Math.max(0, Math.min(90, Number(elevation)));
  exaggeration = Number(exaggeration);
  verticalScale = Number(verticalScale);

  const style = {
    lightColor: '#fff2c6',
    lightOpacity: 0.88,
    shadowColor: '#26384a',
    shadowOpacity: 0.72,
    unknownColor: '#777777',
    unknownOpacity: 0,
    ...shaderStyle
  };
  const { xAxis, yAxis } = axesFromCells(grid);
  const nx = xAxis.length, ny = yAxis.length;
  if (!nx || !ny) throw new Error('Could not recover the NetCDF X/Y axes from CFRender cell properties.');

  const z = new Float32Array(nx * ny);
  z.fill(NaN);
  for (const cell of grid) {
    const ix = cell?.x_offset, iy = cell?.y_offset;
    if (!Number.isInteger(ix) || !Number.isInteger(iy) || ix < 0 || ix >= nx || iy < 0 || iy >= ny) continue;
    const value = Number(cell.value);
    if (cell.value !== null && cell.value !== undefined && finite(value) && value > -12000 && value < 10000)
      z[iy * nx + ix] = value * verticalScale;
  }

  let geographic;
  if (cfr.is_projected_source) {
    geographic = projectedGeographicCoordinates(cfr, nx, ny);
  } else {
    geographic = { lon: null, lat: null, indexOf: (ix, iy) => iy * nx + ix };
  }

  const lon = new Float64Array(nx * ny), lat = new Float64Array(nx * ny);
  for (let iy = 0; iy < ny; iy++) for (let ix = 0; ix < nx; ix++) {
    const k = iy * nx + ix;
    if (cfr.is_projected_source) {
      const q = geographic.indexOf(ix, iy);
      lon[k] = Number(geographic.lon[q]);
      lat[k] = Number(geographic.lat[q]);
    } else {
      lon[k] = Number(xAxis[ix]);
      lat[k] = Number(yAxis[iy]);
    }
  }

  const shade = new Float32Array(nx * ny);
  shade.fill(NaN);
  const az = azimuth * DEG_TO_RAD;
  const el = elevation * DEG_TO_RAD;
  const lx = Math.cos(el) * Math.sin(az); // east
  const ly = Math.cos(el) * Math.cos(az); // north
  const lz = Math.sin(el);                // up

  function sampleGradient(ix, iy) {
    const k = iy * nx + ix;
    if (!finite(z[k]) || !finite(lat[k]) || !finite(lon[k])) return null;
    const west = ix > 0 ? k - 1 : -1;
    const east = ix < nx - 1 ? k + 1 : -1;
    const south = iy < ny - 1 ? k + nx : -1; // row direction is resolved below from coordinates
    const north = iy > 0 ? k - nx : -1;

    let dzdx = NaN, dzdy = NaN;
    const a = west >= 0 && finite(z[west]) ? west : -1;
    const b = east >= 0 && finite(z[east]) ? east : -1;
    if (a >= 0 && b >= 0) {
      const d = localDistanceEastNorth(lat[a], lon[a], lat[b], lon[b]);
      if (Math.abs(d.east) > 1e-6) dzdx = (z[b] - z[a]) / d.east;
    } else if (b >= 0) {
      const d = localDistanceEastNorth(lat[k], lon[k], lat[b], lon[b]);
      if (Math.abs(d.east) > 1e-6) dzdx = (z[b] - z[k]) / d.east;
    } else if (a >= 0) {
      const d = localDistanceEastNorth(lat[a], lon[a], lat[k], lon[k]);
      if (Math.abs(d.east) > 1e-6) dzdx = (z[k] - z[a]) / d.east;
    }

    // Select the neighbour with the larger geographic latitude as north;
    // this works for both ascending and descending NetCDF Y axes.
    let n = -1, s = -1;
    for (const candidate of [north, south]) {
      if (candidate < 0 || !finite(z[candidate])) continue;
      if (lat[candidate] > lat[k]) n = candidate; else s = candidate;
    }
    if (n >= 0 && s >= 0) {
      const d = localDistanceEastNorth(lat[s], lon[s], lat[n], lon[n]);
      if (Math.abs(d.north) > 1e-6) dzdy = (z[n] - z[s]) / d.north;
    } else if (n >= 0) {
      const d = localDistanceEastNorth(lat[k], lon[k], lat[n], lon[n]);
      if (Math.abs(d.north) > 1e-6) dzdy = (z[n] - z[k]) / d.north;
    } else if (s >= 0) {
      const d = localDistanceEastNorth(lat[s], lon[s], lat[k], lon[k]);
      if (Math.abs(d.north) > 1e-6) dzdy = (z[k] - z[s]) / d.north;
    }
    if (!finite(dzdx) || !finite(dzdy)) return null;
    return { dzdx: dzdx * exaggeration, dzdy: dzdy * exaggeration };
  }

  for (let iy = 0; iy < ny; iy++) for (let ix = 0; ix < nx; ix++) {
    const g = sampleGradient(ix, iy);
    if (!g) continue;
    const nxv = -g.dzdx, nyv = -g.dzdy, nzv = 1;
    const norm = Math.hypot(nxv, nyv, nzv);
    const illumination = (nxv * lx + nyv * ly + nzv * lz) / norm;
    shade[iy * nx + ix] = Math.max(0, Math.min(1, illumination));
  }

  function valueAt(cell) {
    const ix = cell?.x_offset, iy = cell?.y_offset;
    if (!Number.isInteger(ix) || !Number.isInteger(iy) || ix < 0 || ix >= nx || iy < 0 || iy >= ny) return NaN;
    return shade[iy * nx + ix];
  }
  return {
    shade: valueAt,
    fillColor(cell) { const v = valueAt(cell); return !finite(v) ? style.unknownColor : blendHexColors(style.shadowColor, style.lightColor, v); },
    fillOpacity(cell) { const v = valueAt(cell); return !finite(v) ? style.unknownOpacity : Number(style.shadowOpacity) + (Number(style.lightOpacity) - Number(style.shadowOpacity)) * v; },
    get dimensions() { return { nx, ny }; },
    get dimensionFilter() { return activeFilter; },
    get parameters() { return { azimuth, elevation, exaggeration, verticalScale }; }
  };
}
