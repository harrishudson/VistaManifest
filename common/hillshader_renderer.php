<?php
// Shared renderer: page-specific title and executable METADATA_JS are supplied by the calling page.
require_once __DIR__ . '/common.php';
if (!isset($PAGE_TITLE, $METADATA_JS) || !is_string($METADATA_JS)) {
    throw new RuntimeException('The terrain renderer requires $PAGE_TITLE and $METADATA_JS from the page configuration.');
}
$nonce = page_top($PAGE_TITLE, 'no');
?>
<script nonce="<?php echo $nonce;?>" type="module">

import { CFRender } from '../../common/CFRender.js';
import { TDSMetadataParser } from '../../common/THREDDS_utils.js';
import { SphericalProjection } from '../../common/projection_helpers.js';
import { tile_providers } from '../../common/map_helpers.js';
import { createHillShader } from '../../common/CF_elevation_helpers.js';

<?php echo $METADATA_JS; ?>
const DIMENSION_FILTER = {}; // Bind non-spatial dimensions here when required.
// Resolve THREDDS service URLs through an optional metadata-defined proxy.
// proxy may be null, a URL prefix, a prefix containing {url}, or a function.
function resolveServiceUrl(url) {
  const proxy = METADATA.proxy;
  if (!proxy) return url;
  if (typeof proxy === "function") return proxy(url);
  if (typeof proxy === "string") {
    const encoded = encodeURIComponent(url);
    return proxy.includes("{url}") ? proxy.replace("{url}", encoded) : proxy + encoded;
  }
  throw new TypeError("METADATA.proxy must be null, a string, or a function.");
}

const MAP_CONFIG = {
  subsetEndpoint: METADATA.subsetEndpoint,
  variable: METADATA.variable,
  longitudeWrap: !!METADATA.longitudeWrap,
  querySuffix: METADATA.querySuffix || '&accept=netcdf'
};

// Convert source grid coordinates into the map display coordinate system.
const COORDINATES = {
  sourceXYToDisplay(coords) { return SphericalProjection(coords); }
};

// -----------------------------------------------------------------------------
// State
// -----------------------------------------------------------------------------
const $ = id => document.getElementById(id);
let map;
let gBASE_LAYER = null;
let gOVERLAY_OPACITY = 0.78;
let gPOPUP = null;

let azimuth = ((finiteNumber(METADATA.defaultAzimuth, 315) % 360) + 360) % 360;
let sunElevation = Math.max(0, Math.min(90, finiteNumber(METADATA.defaultSunElevation, 45)));
let exaggeration = Math.max(0, Math.min(100, finiteNumber(METADATA.defaultVerticalExaggeration, 10)));
const requestedVerticalScale = finiteNumber(METADATA.defaultVerticalScale ?? METADATA.verticalScale, 1);
let verticalScale = requestedVerticalScale === -1 ? -1 : 1;
const defaultLightColor = /^#[0-9a-f]{6}$/i.test(String(METADATA.defaultLightColor || '')) ? METADATA.defaultLightColor : '#fff2c6';
const defaultShadowColor = /^#[0-9a-f]{6}$/i.test(String(METADATA.defaultShadowColor || '')) ? METADATA.defaultShadowColor : '#26384a';
let hillshade = null;

let metadata = null;
let variableMetadata = null;
const subsetEndpoint = MAP_CONFIG.subsetEndpoint;
let cfr = null;
let displayedCfr = null; // CFRender grid corresponding to the currently visible image layer.
let overlay = null;
let latestRequest = 0;
let currentController = null;
let projectionCache = null;
let redrawTimer = null;
let shaderRenderTimer = null;
let shaderRenderVersion = 0;
let isInitialFit = false;

function appendNetCDFAttributes(containerId, attributes) {
  const container = $(containerId);
  if (!container) return;
  container.replaceChildren();
  if (!Array.isArray(attributes) || !attributes.length) {
    const note = document.createElement("span");
    note.textContent = "NetCDF attributes are not available until a terrain subset has been loaded.";
    container.appendChild(note);
    return;
  }
  const dl = document.createElement("dl");
  for (const attr of attributes) {
    const dt = document.createElement("dt");
    dt.textContent = `${attr.name ?? "(unnamed)"}${attr.type ? ` (${attr.type})` : ""}`;
    const dd = document.createElement("dd");
    const value = attr.value;
    dd.textContent = Array.isArray(value) ? value.join(", ") : String(value ?? "");
    dl.append(dt, dd);
  }
  container.appendChild(dl);
}

function populateNetCDFInfo() {
  const headers = cfr?.netCDF?.headers;
  const variable = headers?.variables?.find(v => v.name === MAP_CONFIG.variable);
  const variableLabel = $("info_variable");
  if (variableLabel) variableLabel.textContent = MAP_CONFIG.variable;
  appendNetCDFAttributes("info_global_attributes", headers?.globalAttributes);
  appendNetCDFAttributes("info_variable_attributes", variable?.attributes);
}

function status(message, kind = "", broadcast = true) {
  const text = message instanceof Error ? message.message : String(message ?? "");
  const node = $("status");
  if (node) {
    node.textContent = text;
    node.className = kind;
  }
  // Site-wide status panel used by the existing visualfield pages.
  if (broadcast) status_msg(text);
}
function finiteNumber(value, fallback = 0) {
  const n = Number(value);
  return Number.isFinite(n) ? n : fallback;
}
function removeOverlay() {
  if (overlay && map) map.removeLayer(overlay);
  overlay = null;
}
function addOverlayAndSwap(image, imageBounds, isCurrent = () => true) {
  return new Promise((resolve, reject) => {
    const nextOverlay = L.imageOverlay(image, imageBounds, { opacity: gOVERLAY_OPACITY });
    nextOverlay.once("load", () => {
      if (!isCurrent()) {
        if (map.hasLayer(nextOverlay)) map.removeLayer(nextOverlay);
        resolve(false);
        return;
      }
      nextOverlay.setOpacity(gOVERLAY_OPACITY);
      const previousOverlay = overlay;
      overlay = nextOverlay;
      if (previousOverlay && map.hasLayer(previousOverlay)) map.removeLayer(previousOverlay);
      resolve(true);
    });
    nextOverlay.once("error", () => {
      if (map.hasLayer(nextOverlay)) map.removeLayer(nextOverlay);
      reject(new Error("The newly rendered image layer could not be loaded by Leaflet."));
    });
    nextOverlay.addTo(map);
  });
}

function protectMapControlEvents(element) {
  if (!element) return;
  L.DomEvent.disableClickPropagation(element);
  L.DomEvent.disableScrollPropagation(element);
  for (const eventName of ["pointerdown", "pointerup", "pointermove", "mousedown", "mouseup", 
                           "touchstart", "touchend", "click", "dblclick", "wheel", "contextmenu"]) {
    element.addEventListener(eventName, event => event.stopPropagation());
  }
}
function updateLayerOpacity() {
  if (overlay) overlay.setOpacity(gOVERLAY_OPACITY);
}
function setMapBaseLayer() {
  const selector = $("base_layer_selector");
  if (!selector || !map) return;
  const layer = tile_providers.find(v => v.title === selector.value);
  if (!layer) return;
  try { if (gBASE_LAYER) map.removeLayer(gBASE_LAYER); } catch(e) {}
  gBASE_LAYER = L.tileLayer(layer.url, { attribution: layer.attrib });
  gBASE_LAYER.addTo(map);
  try { localStorage.setItem('base_layer', selector.value); } catch(e) {}
}
async function fetchArrayBuffer(url, signal) {
  const response = await fetch(resolveServiceUrl(url), { signal, headers: { Accept: "application/x-netcdf,application/octet-stream,*/*" } });
  if (!response.ok) {
    const detail = await response.text().catch(() => "");
    throw new Error(`${METADATA.providerName} returned HTTP ${response.status} ${response.statusText}. ${detail.slice(0, 500)}`);
  }
  const buffer = await response.arrayBuffer();
  if (!buffer.byteLength) throw new Error(`${METADATA.providerName} returned an empty NetCDF response.`);
  return buffer;
}

// -----------------------------------------------------------------------------
// Dataset metadata is fetched from the NCSS dataset.xml endpoint and parsed with
// TDSMetadataParser. The parsed LatLonBox and grid axes drive map extent and stride.
// -----------------------------------------------------------------------------
async function loadDatasetMetadata() {
  queue_throbber();
  try {
    const metadataUrl = METADATA.metadataUrl || `${subsetEndpoint.replace(/\/$/, '')}/dataset.xml`;
    const response = await fetch(resolveServiceUrl(metadataUrl), { headers: { Accept: 'application/xml,text/xml,*/*' } });
    if (!response.ok) throw new Error(`Metadata request returned HTTP ${response.status} ${response.statusText}`);
    const xmlText = await response.text();
    if (!xmlText.trim()) throw new Error('THREDDS returned an empty dataset metadata document.');
    const xmlDoc = new DOMParser().parseFromString(xmlText, 'application/xml');
    const parseError = xmlDoc.querySelector('parsererror');
    if (parseError) throw new Error(`Invalid THREDDS metadata XML: ${parseError.textContent.slice(0, 240)}`);
    metadata = new TDSMetadataParser(xmlDoc, MAP_CONFIG.longitudeWrap);
    const box = metadata.getLatLonBox();
    if (!box || ![box.west, box.east, box.south, box.north].every(v => Number.isFinite(Number(v))))
      throw new Error('TDSMetadataParser could not determine a valid geographic LatLonBox.');
    variableMetadata = metadata.getGridVariableMetadata(MAP_CONFIG.variable);
    if (!variableMetadata) throw new Error(`Variable '${MAP_CONFIG.variable}' was not found in the THREDDS grid metadata.`);
    const bounds = [[Number(box.south), Number(box.west)], [Number(box.north), Number(box.east)]];
    map.fitBounds(bounds, { padding: [24, 24], maxZoom: Number(METADATA.initialView?.zoom || 8) });
    // fitBounds emits moveend; a short debounce lets Leaflet settle before NCSS.
    scheduleRedraw(120);
  } catch (error) {
    status(`Could not load THREDDS dataset metadata: ${error.message}`, 'error');
    console.error(error);
  } finally {
    dequeue_throbber();
  }
}

// -----------------------------------------------------------------------------
// Hillshade setup. The helper consumes the flattened CFRender grid and caches
// per-cell illumination until the light parameters or downloaded grid change.
// -----------------------------------------------------------------------------
function flattenCFRenderGrid(gridObject) {
  const cells = [];
  function visit(node) {
    if (!node || typeof node !== 'object') return;
    if (Number.isInteger(node.x_offset) && Number.isInteger(node.y_offset)) { cells.push(node); return; }
    for (const value of Object.values(node)) visit(value);
  }
  visit(gridObject);
  return cells;
}

function initialiseHillshadeForCurrentGrid() {
  if (hillshade) return hillshade;
  const gridObject = cfr?.data2DGrid?.grid;
  if (!gridObject || typeof gridObject !== 'object')
    throw new Error('CFRender has not built data2DGrid yet. Check the terrain variable and draw2DbasicGrid call.');
  const grid = flattenCFRenderGrid(gridObject);
  if (!grid.length) throw new Error('CFRender data2DGrid contains no cell objects.');
  hillshade = createHillShader({
    cfr, grid, azimuth, elevation: sunElevation, exaggeration, verticalScale,
    shaderStyle: {
      lightColor: $('lightColor').value,
      lightOpacity: 0.88,
      shadowColor: $('shadowColor').value,
      shadowOpacity: 0.72
    },
    dimensionFilter: DIMENSION_FILTER
  });
  return hillshade;
}
function fillHillshadeColor(cellData) { return initialiseHillshadeForCurrentGrid().fillColor(cellData); }
function fillHillshadeOpacity(cellData) { return initialiseHillshadeForCurrentGrid().fillOpacity(cellData); }
function omitCell(cellData) {
 if (typeof METADATA.cellOmitValue === 'function')
  return METADATA.cellOmitValue(cellData.value);
 else if (METADATA.cellOmitValue === 'number')
  return METADATA.cellOmitValue;
 return false;
}

// -----------------------------------------------------------------------------
// Viewport-dependent NCSS request. The request bbox is the map viewport clipped
// to the current map viewport, clipped to the published survey bounds.
// -----------------------------------------------------------------------------
function scheduleRedraw(delay = 200) {
  clearTimeout(redrawTimer);
  redrawTimer = setTimeout(redrawMapLayer, delay);
}
function redrawMapLayer() {
  if (!metadata || !subsetEndpoint) return;

  const box = metadata.getLatLonBox();
  const mapBounds = map.getBounds();
  const east = Math.min(mapBounds.getEast(), Number(box.east));
  const west = Math.max(mapBounds.getWest(), Number(box.west));
  const north = Math.min(mapBounds.getNorth(), Number(box.north));
  const south = Math.max(mapBounds.getSouth(), Number(box.south));
  if (east <= west || north <= south) {
    removeOverlay();
    status("The current map view is outside this elevation survey.", "", false);
    return;
  }

  const qWest = west, qEast = east, qSouth = south, qNorth = north;

  if (qEast <= qWest || qNorth <= qSouth) {
    removeOverlay();
    status("The current map view does not overlap the survey coverage.", "", false);
    return;
  }

  const queryBounds = [[qWest, qSouth], [qEast, qNorth]];
  const sw = map.latLngToContainerPoint([qSouth, qWest]);
  const ne = map.latLngToContainerPoint([qNorth, qEast]);
  const imageSize = { x: Math.max(150, Math.abs(sw.x-ne.x)),
                      y: Math.max(150, Math.abs(sw.y-ne.y)) };
  const strideScale = Math.max(1, finiteNumber($("strideScale").value, METADATA.defaultStrideScale || 1));
  const horizStride = Math.max(1, Math.ceil(metadata.getHorizStride(imageSize, queryBounds, strideScale, MAP_CONFIG.longitudeWrap) || 1));

  let query = "?var=" + encodeURIComponent(MAP_CONFIG.variable);
  query += "&east=" + encodeURIComponent(qEast);
  query += "&west=" + encodeURIComponent(qWest);
  query += "&north=" + encodeURIComponent(qNorth);
  query += "&south=" + encodeURIComponent(qSouth);
  query += "&horizStride=" + encodeURIComponent(horizStride);
  query += MAP_CONFIG.querySuffix;

  fetchSubset(query);
}

async function fetchSubset(queryString) {
  const url = subsetEndpoint + queryString;
  latestRequest++;
  const requestId = latestRequest;
  if (currentController) currentController.abort();
  currentController = new AbortController();

  status(`Requesting ${METADATA.datasetName} subset (${MAP_CONFIG.variable}) from ${METADATA.providerName}…`, "", false);
  if (gPOPUP && map) map.closePopup(gPOPUP);
  gPOPUP = null;

  const previousCfr = cfr;
  const previousHillshade = hillshade;
  queue_throbber();
  try {
    const buffer = await fetchArrayBuffer(url, currentController.signal);
    if (requestId !== latestRequest) return;

    const candidateCfr = new CFRender(buffer, null, projectionCache, MAP_CONFIG.longitudeWrap);
    cfr = candidateCfr;
    hillshade = null;
    var theCanvasContext = "2d";
    if ("canvasContext" in METADATA)
     theCanvasContext = METADATA["canvasContext"];
    const image = await candidateCfr.draw2DbasicGrid(
      MAP_CONFIG.variable,
      DIMENSION_FILTER,
      coords => COORDINATES.sourceXYToDisplay(coords),
      "url",
      {
        fill: fillHillshadeColor,
        opacity: fillHillshadeOpacity,
        omit: omitCell,
        stroke: "none",
        strokeWidth: 0,
        canvasContext: theCanvasContext
      }
    );

    if (requestId !== latestRequest || cfr !== candidateCfr) {
      if (cfr === candidateCfr) { cfr = previousCfr; hillshade = previousHillshade; }
      return;
    }
    projectionCache = candidateCfr.projectionCache;

    const bounds = candidateCfr.getXYbbox().bbox;
    const imageBounds = [
      [bounds[0][1], bounds[0][0]],
      [bounds[1][1], bounds[1][0]]
    ];
    const swapped = await addOverlayAndSwap(image, imageBounds, () => requestId === latestRequest && cfr === candidateCfr);
    if (!swapped) {
      if (requestId === latestRequest && cfr === candidateCfr) { cfr = previousCfr; hillshade = previousHillshade; }
      return;
    }
    displayedCfr = candidateCfr;
    status(`Hillshade rendered. Grid: ${flattenCFRenderGrid(candidateCfr.data2DGrid.grid).length.toLocaleString()} cells. ` +
           `horizStride=${new URLSearchParams(queryString.slice(1)).get("horizStride")}.`, "ok", false);
  } catch (error) {
    if (error.name === "AbortError") return;
    if (requestId === latestRequest) { cfr = previousCfr; hillshade = previousHillshade; }
    status(`Terrain request/render failed: ${error.message}`, "error");
    console.error(error);
  } finally {
    dequeue_throbber();
  }
}

async function renderCurrentHillshade() {
  if (!cfr) { scheduleRedraw(0); return; }
  const activeCfr = cfr;
  const renderVersion = ++shaderRenderVersion;
  hillshade = null;
  queue_throbber();
  try {
    const image = await activeCfr.draw2DbasicGrid(
      MAP_CONFIG.variable,
      DIMENSION_FILTER,
      coords => COORDINATES.sourceXYToDisplay(coords),
      "url",
      {
        fill: fillHillshadeColor,
        opacity: fillHillshadeOpacity,
        omit: omitCell,
        stroke: "none",
        strokeWidth: 0,
      }
    );
    if (activeCfr !== cfr || renderVersion !== shaderRenderVersion) return;
    projectionCache = activeCfr.projectionCache;
    const bounds = activeCfr.getXYbbox().bbox;
    const imageBounds = [[bounds[0][1], bounds[0][0]], [bounds[1][1], bounds[1][0]]];
    const swapped = await addOverlayAndSwap(image, imageBounds, () => activeCfr === cfr && renderVersion === shaderRenderVersion);
    if (!swapped) return;
    status(`Hillshade updated. Grid: ${flattenCFRenderGrid(activeCfr.data2DGrid.grid).length.toLocaleString()} cells.`, "ok", false);
  } catch (error) {
    if (activeCfr === cfr) status(`Hillshade render failed: ${error.message}`, "error");
    console.error(error);
  } finally {
    dequeue_throbber();
  }
}

function polarPosition(az, el) {
  const radius = 88 * (1 - Math.max(0, Math.min(90, el)) / 90);
  const angle = az * Math.PI / 180;
  return { x: 110 + radius * Math.sin(angle), y: 110 - radius * Math.cos(angle) };
}
function updatePolar() {
  const p = polarPosition(azimuth, sunElevation);
  $('sunHandle').setAttribute('cx', p.x);
  $('sunHandle').setAttribute('cy', p.y);
  $('sunRay').setAttribute('x2', p.x);
  $('sunRay').setAttribute('y2', p.y);
  $('azimuthInput').value = azimuth.toFixed(1);
  $('elevationInput').value = sunElevation.toFixed(1);
}
function setPolarFromPointer(event) {
  const svg = $('sunPolar');
  const pt = svg.createSVGPoint(); pt.x = event.clientX; pt.y = event.clientY;
  const p = pt.matrixTransform(svg.getScreenCTM().inverse());
  const dx = p.x - 110, dy = 110 - p.y;
  const radius = Math.min(88, Math.hypot(dx, dy));
  azimuth = (Math.atan2(dx, dy) * 180 / Math.PI + 360) % 360;
  sunElevation = Math.max(0, Math.min(90, 90 * (1 - radius / 88)));
  updatePolar();
  scheduleShaderRefresh();
}
function scheduleShaderRefresh() {
  hillshade = null;
  clearTimeout(shaderRenderTimer);
  shaderRenderTimer = setTimeout(renderCurrentHillshade, 100);
}
function bindShaderControls() {
  const svg = $('sunPolar');
  let dragging = false;
  for (const eventName of ["pointerdown", "pointermove", "pointerup", "pointercancel", "mousedown", "touchstart", "click", "dblclick"]) {
    svg.addEventListener(eventName, e => e.stopPropagation());
  }
  svg.addEventListener('pointerdown', e => { dragging = true; svg.setPointerCapture(e.pointerId); setPolarFromPointer(e); e.preventDefault(); e.stopPropagation(); });
  svg.addEventListener('pointermove', e => { if (dragging) { setPolarFromPointer(e); e.preventDefault(); } e.stopPropagation(); });
  svg.addEventListener('pointerup', e => { dragging = false; e.stopPropagation(); });
  svg.addEventListener('pointercancel', e => { dragging = false; e.stopPropagation(); });
  $('azimuthInput').addEventListener('change', () => {
    const v = Number($('azimuthInput').value); if (Number.isFinite(v)) { azimuth = ((v % 360) + 360) % 360; updatePolar(); scheduleShaderRefresh(); }
  });
  $('elevationInput').addEventListener('change', () => {
    const v = Number($('elevationInput').value); if (Number.isFinite(v)) { sunElevation = Math.max(0, Math.min(90, v)); updatePolar(); scheduleShaderRefresh(); }
  });
  $('verticalScaleInput').addEventListener('change', () => {
    const v = Number($('verticalScaleInput').value); if (v === 1 || v === -1) { verticalScale = v; scheduleShaderRefresh(); }
  });
  $('exaggerationInput').addEventListener('change', () => {
    const v = Number($('exaggerationInput').value); if (Number.isFinite(v) && v >= 0) { exaggeration = v; scheduleShaderRefresh(); }
  });
  for (const id of ['lightColor','shadowColor']) $(id).addEventListener('input', scheduleShaderRefresh);
  updatePolar();
}

// -----------------------------------------------------------------------------
// Map-click elevation popup. Use the CFRender instance matching the image
// currently visible on the map, not a newer request that may still be rendering.
// This follows the getCellValue() pattern from ANU_GA_geophysics_elevation.php.
// -----------------------------------------------------------------------------
function mapTap(evt) {
  if (!displayedCfr || !map) return;
  const value = displayedCfr.getCellValue(MAP_CONFIG.variable, DIMENSION_FILTER,
                                          evt.latlng.lng, evt.latlng.lat, null);
  if (value === undefined || value === null || !Number.isFinite(Number(value))) return;

  const dl = document.createElement("dl");
  dl.className = "popup";
  const rows = [
    ["Elevation:", `${Number(value).toFixed(1)} m`],
    ["Latitude:", evt.latlng.lat.toFixed(6)],
    ["Longitude:", evt.latlng.lng.toFixed(6)]
  ];
  for (const [label, text] of rows) {
    const dt = document.createElement("dt");
    dt.textContent = label;
    const dd = document.createElement("dd");
    dd.textContent = text;
    dl.append(dt, dd);
  }
  gPOPUP = L.popup().setLatLng(evt.latlng).setContent(dl).openOn(map);
}

// Enter in a control input applies its value and collapses the controls panel.
function bindEnterToControls(containerId, toggleId, bodyId, applyInput) {
  const container = $(containerId);
  const toggle = $(toggleId);
  const body = $(bodyId);
  if (!container || !toggle || !body) return;
  for (const input of container.querySelectorAll("input, select")) {
    input.addEventListener("keydown", event => {
      if (event.key !== "Enter" && event.keyCode !== 13) return;
      event.preventDefault();
      event.stopPropagation();
      applyInput(input);
      toggle.setAttribute("aria-expanded", "false");
      body.hidden = true;
    });
  }
}

// -----------------------------------------------------------------------------
// VistaManifest map startup and UI events
// -----------------------------------------------------------------------------
function makeInfoDialog() { populateNetCDFInfo(); }

function mapStartup() {
  $("map_title").textContent = METADATA.mapTitle;
  $("sourceNote").textContent = METADATA.sourceNote;
  $("datasetDescription").textContent = METADATA.datasetDescription;
  $("datasetLink").href = METADATA.datasetPageUrl;
  $("datasetLink").textContent = `Open ${METADATA.datasetName} dataset`;
  map = L.map("map", { zoomControl: false }).setView([METADATA.initialView.lat, METADATA.initialView.lon], METADATA.initialView.zoom);
  map.on("click", mapTap);
  map.on("moveend", () => scheduleRedraw(180));
  map.attributionControl.setPrefix(false);
  L.DomEvent.disableClickPropagation($("shader_inputs"));
  L.DomEvent.disableClickPropagation($("shader_buttons"));
  const controlsToggle = $("shader_controls_toggle");
  const controlsBody = $("shader_controls_body");
  controlsToggle.addEventListener("click", e => {
    e.preventDefault(); e.stopPropagation();
    const willOpen = controlsToggle.getAttribute("aria-expanded") !== "true";
    controlsToggle.setAttribute("aria-expanded", String(willOpen));
    controlsBody.hidden = !willOpen;
  });

  const baseSelector = $("base_layer_selector");
  for (const provider of tile_providers) {
    const option = document.createElement("option");
    option.value = provider.title;
    option.textContent = provider.title;
    baseSelector.appendChild(option);
  }
  try {
    const saved = localStorage.getItem("base_layer");
    if (saved && [...baseSelector.options].some(o => o.value === saved)) baseSelector.value = saved;
  } catch(e) {}
  baseSelector.addEventListener("change", setMapBaseLayer);
  setMapBaseLayer();

  $("button_zoom_in").addEventListener("click", e => { map.zoomIn(); e.stopPropagation(); });
  $("button_zoom_out").addEventListener("click", e => { map.zoomOut(); e.stopPropagation(); });
  $("button_settings").addEventListener("click", e => { closeDialogs(); $("map_settings_dialog").showModal(); e.stopPropagation(); });
  $("button_info").addEventListener("click", e => { makeInfoDialog(); closeDialogs(); $("map_info_dialog").showModal(); e.stopPropagation(); });
  $("map_settings_dialog_close").addEventListener("click", () => $("map_settings_dialog").close());
  $("map_info_dialog_close").addEventListener("click", () => $("map_info_dialog").close());
  for (const dialog of document.querySelectorAll("dialog")) {
    protectMapControlEvents(dialog);
    // A click on the native dialog backdrop is retargeted to the dialog itself.
    // Close only for that case; clicks inside the dialog content remain untouched.
    dialog.addEventListener("click", event => {
      if (event.target === dialog) dialog.close();
    });
  }
  protectMapControlEvents($("shader_inputs"));
  $("opacity_range").value = gOVERLAY_OPACITY * 100;
  $("opacity_range_label").textContent = `${Math.round(gOVERLAY_OPACITY*100)}%`;
  $("opacity_range").addEventListener("input", function() {
    gOVERLAY_OPACITY = Number(this.value) / 100;
    $("opacity_range_label").textContent = `${this.value}%`;
    updateLayerOpacity();
  });

  for (const id of ["azimuthInput", "elevationInput", "exaggerationInput", "verticalScaleInput", "strideScale", "lightColor", "shadowColor"]) {
    $(id).addEventListener("click", e => e.stopPropagation());
  }
  $("azimuthInput").value = azimuth.toFixed(1);
  $("elevationInput").value = sunElevation.toFixed(1);
  $("exaggerationInput").value = String(exaggeration);
  $("verticalScaleInput").value = String(verticalScale);
  $("lightColor").value = defaultLightColor;
  $("shadowColor").value = defaultShadowColor;
  // METADATA is the source of truth for the initial display-cell size.
  const defaultStrideScale = Math.max(1, Math.min(16, Math.round(finiteNumber(METADATA.defaultStrideScale, 1))));
  $("strideScale").value = String(defaultStrideScale);
  bindShaderControls();
  bindEnterToControls("shader_controls_body", "shader_controls_toggle", "shader_controls_body", input => {
    if (input.id === "strideScale") {
      scheduleRedraw(0);
    } else if (input.id === "lightColor" || input.id === "shadowColor") {
      input.dispatchEvent(new Event("input", { bubbles: true }));
    } else {
      input.dispatchEvent(new Event("change", { bubbles: true }));
    }
  });
  $("refreshButton").addEventListener("click", e => { e.preventDefault(); e.stopPropagation(); scheduleRedraw(0); });
  window.addEventListener("resize", () => map.invalidateSize());
  status("Adjust the sun position and shader colours using Shader Controls.");
  loadDatasetMetadata();
}
function closeDialogs() {
  for (const dialog of document.querySelectorAll("dialog")) if (dialog.open) dialog.close();
}
window.addEventListener("load", mapStartup);

// Expose a few diagnostics for browser-console inspection.
window.shaderDebug = {
  get map() { return map; },
  get metadata() { return metadata; },
  get cfRender() { return cfr; },
  get hillshade() { return hillshade; },
  get subsetEndpoint() { return subsetEndpoint; },
  get latestRequest() { return latestRequest; }
};
</script>
<div class="full_screen">
 <div id="map">
  <div class="map_buttons" id="shader_buttons">
   <button id="button_zoom_in" title="Zoom In">+</button><br>
   <button id="button_zoom_out" title="Zoom Out">−</button><br>
   <button id="button_info" title="Information"><i class="fa fa-info-circle"></i></button><br>
   <button id="button_settings" title="Settings"><i class="fa fa-cog"></i></button>
  </div>
  <div class="map_headings">
   <span id="map_title" class="map_title">Terrain Hillshade</span>
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
    <div class="shader_note">Drag the sun. Centre = 90° overhead; outer ring = horizon.</div>
  </div>
  <div class="coords">
    <div><label for="azimuthInput">Azimuth (°)</label><input id="azimuthInput" type="number" min="0" max="359.9" step="0.1"></div>
    <div><label for="elevationInput">Sun elevation (°)</label><input id="elevationInput" type="number" min="0" max="90" step="0.1"></div>
  </div>
  <label for="exaggerationInput">Vertical exaggeration</label>
  <input id="exaggerationInput" type="number" min="0" max="100" step="0.1">
  <label for="verticalScaleInput">Elevation / depth sign</label>
  <select id="verticalScaleInput"><option value="1">Elevation positive upwards</option><option value="-1">Depth positive downwards (invert)</option></select>
  <h3>Shader colours</h3>
  <div class="coords">
    <div><label for="lightColor">Illuminated terrain</label><input id="lightColor" type="color"></div>
    <div><label for="shadowColor">Shadowed terrain</label><input id="shadowColor" type="color"></div>
  </div>
  <label for="strideScale">Display cell size (pixels)</label>
  <input id="strideScale" type="number" min="1" max="16" step="1">
  <button id="refreshButton" class="secondary">Refresh hillshade</button>
  <div class="shader_note">Light direction is measured clockwise from north. Elevation is above the horizon. Layer opacity remains available in Map Settings.</div>

  <div id="status">Initialising…</div>

  <div class="shader_note" id="sourceNote"></div>

  </div>

  </div>
 </div>
 <dialog id="map_info_dialog" class="map_dialog">
  <h3>Information</h3>
  <h4>Map legend</h4>
  <p>Illuminated slopes use the selected light colour; slopes facing away from the light use the selected shadow colour.</p>
  <h4>Dataset</h4>
  <p id="datasetDescription"></p>
  <p><a id="datasetLink" href="#" target="_blank" rel="noopener">Open dataset</a></p>
  <h4>Dataset Global Metadata</h4>
  <div id="info_global_attributes" class="information"></div>
  <h4>Dataset Variable Metadata (<span id="info_variable"></span>)</h4>
  <div id="info_variable_attributes" class="information"></div>
  <p>Hillshade gradients use geographic distances derived from the NetCDF coordinate arrays. Projected grids use their two-dimensional auxiliary longitude/latitude coordinates; CFRender continues to render using native projected X/Y coordinates.</p>
  <button id="map_info_dialog_close" title="Close">Close</button>
 </dialog>
 <dialog id="map_settings_dialog" class="map_dialog">
  <h3>Settings</h3>
  <h4>Layer opacity</h4>
  <p><input type="range" min="20" max="100" id="opacity_range"><span id="opacity_range_label"></span></p>
  <h4>Base layer</h4>
  <p><select id="base_layer_selector"></select></p>
  <button id="map_settings_dialog_close" title="Close">Close</button>
 </dialog>
</div>

<?php page_bottom(); ?>
