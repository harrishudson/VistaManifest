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
import { createLOSViewshed } from '../../common/CF_elevation_helpers.js';

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

// -----------------------------------------------------------------------------
// Forward coordinate adapter for display only. Geographic sources use
// SphericalProjection; projected sources may provide their existing forward
// source-X/Y-to-display transform. Geographic-to-native lookup for the antenna
// is handled by CFRender.unprojectLatLon().
// -----------------------------------------------------------------------------
// Forward transform used only to display native NetCDF X/Y in Leaflet.
// Geographic datasets use SphericalProjection directly. Projected datasets can
// still supply their normal source-X/Y-to-display transform here; the inverse
// geographic lookup for the antenna is now handled by CFRender.unprojectLatLon().
const COORDINATES = {
  sourceXYToDisplay(coords) {
    if (typeof window.VIEWSHED_SOURCE_XY_TO_DISPLAY === "function")
      return window.VIEWSHED_SOURCE_XY_TO_DISPLAY(coords);
    return SphericalProjection(coords);
  }
};

// -----------------------------------------------------------------------------
// State
// -----------------------------------------------------------------------------
const $ = id => document.getElementById(id);
let map;
let gBASE_LAYER = null;
let gOVERLAY_OPACITY = 0.78;
let gPOPUP = null;

let antennaMarker = null;
const antennaIcon = L.icon({
    iconUrl: "../../common/antenna-marker.svg",
    iconSize: [32, 43],
    iconAnchor: [16, 41],
    tooltipAnchor: [0, -43]
});
let antennaLatLon = { ...METADATA.initialAntenna };
// Static spatial metadata avoids depending on a THREDDS-specific metadata XML
// representation. The NCSS dataset page itself is the authoritative service entry.
let metadata = null;
let variableMetadata = null;
const subsetEndpoint = MAP_CONFIG.subsetEndpoint;
let cfr = null;
let overlay = null;
let los = null;
let latestRequest = 0;
let currentController = null;
let projectionCache = null;
let redrawTimer = null;
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
function setAntenna(lat, lon, triggerRefresh = true) {
    antennaLatLon = { lat: Number(lat), lon: Number(lon) };

    $("latInput").value = antennaLatLon.lat.toFixed(6);
    $("lonInput").value = antennaLatLon.lon.toFixed(6);

    if (antennaMarker) {
        antennaMarker.setLatLng([lat, lon]);
    } else {
        antennaMarker = L.marker([lat, lon], {
            draggable: true,
            icon: antennaIcon,
            title: "Radio antenna — drag to reposition"
        }).addTo(map);

        antennaMarker.bindTooltip(antennaTooltipContent(), {
            direction: "top",
            opacity: 0.95
        });

        antennaMarker.on("dragend", e => {
            const p = e.target.getLatLng();
            setAntenna(p.lat, p.lng, true);
            e.target.closeTooltip();
            if (map) map.closePopup();
        });

        // Update coordinates and tooltip continuously while dragging.
        antennaMarker.on("drag", e => {
            const p = e.target.getLatLng();
        
            antennaLatLon = {
                lat: p.lat,
                lon: p.lng
            };
        
        antennaMarker.setTooltipContent(antennaTooltipContent());
        antennaMarker.openTooltip();
       });

    }

    antennaMarker.setTooltipContent(antennaTooltipContent());

    if (triggerRefresh) scheduleRedraw(150);
}
function antennaTooltipContent() {
    return `
        <div class="antenna-tooltip">
            <strong>Radio antenna</strong>
            <div>Lat: ${antennaLatLon.lat.toFixed(6)}</div>
            <div>Lon: ${antennaLatLon.lon.toFixed(6)}</div>
            <div>Height: ${Number($("heightInput").value).toFixed(1)} m AGL</div>
            <small>Drag to reposition</small>
	    </div>
	    `;
}

// The marker is created after Leaflet map startup.

// -----------------------------------------------------------------------------
// HTTP helper for the binary NetCDF subset response.
// -----------------------------------------------------------------------------
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
    // Keep the initial view focused on Black Mountain Tower; the dataset bounds
    // are used to clip NCSS requests, not to zoom out to the full Australian extent.
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
// LOS setup. CFRender cell objects are flattened once; the helper consumes the
// actual CFRender instance and its NetCDF headers without modifying CFRender.
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

function initialiseLOSForCurrentGrid() {
  if (los) return los;
  const gridObject = cfr?.data2DGrid?.grid;
  if (!gridObject || typeof gridObject !== 'object')
    throw new Error('CFRender has not built data2DGrid yet. Check the terrain variable and draw2DbasicGrid call.');
  const grid = flattenCFRenderGrid(gridObject);
  if (!grid.length) throw new Error('CFRender data2DGrid contains no cell objects.');

  if (typeof cfr.unprojectLatLon !== "function")
    throw new Error("CFRender.unprojectLatLon() is unavailable. Add the inverse-projection method to common/CFRender.js first.");
  const antennaSource = cfr.unprojectLatLon(antennaLatLon.lon, antennaLatLon.lat);
  if (!antennaSource || !Number.isFinite(Number(antennaSource.X)) || !Number.isFinite(Number(antennaSource.Y)))
    throw new Error("The antenna location could not be located inside the returned NetCDF terrain subset.");
  const antennaX = Number(antennaSource.X);
  const antennaY = Number(antennaSource.Y);
  const xValues = new Map(), yValues = new Map();
  for (const cell of grid) {
    if (Number.isInteger(cell.x_offset) && Number.isFinite(Number(cell.X))) xValues.set(cell.x_offset, Number(cell.X));
    if (Number.isInteger(cell.y_offset) && Number.isFinite(Number(cell.Y))) yValues.set(cell.y_offset, Number(cell.Y));
  }
  const xAxis = [...xValues.entries()].sort((a,b) => a[0]-b[0]).map(v => v[1]);
  const yAxis = [...yValues.entries()].sort((a,b) => a[0]-b[0]).map(v => v[1]);
  if (!xAxis.length || !yAxis.length) throw new Error('Could not locate the antenna in the returned terrain axes.');
  const dx = xAxis.length > 1 ? xAxis[1] - xAxis[0] : 1;
  const dy = yAxis.length > 1 ? yAxis[1] - yAxis[0] : 1;
  const ax = Math.round((antennaX - xAxis[0]) / dx);
  const ay = Math.round((antennaY - yAxis[0]) / dy);
  const antennaCell = grid.find(cell => cell.x_offset === ax && cell.y_offset === ay);
  const antennaGround = Number(antennaCell?.value);
  if (!Number.isFinite(antennaGround) || antennaGround <= -1000 || antennaGround >= 10000)
    throw new Error('No valid terrain elevation was returned at the antenna location. The antenna may be outside the downloaded subset.');

  los = createLOSViewshed({
    cfr, grid, antennaX, antennaY,
    antennaLat: antennaLatLon.lat, antennaLon: antennaLatLon.lon,
    antennaAltitudeAMSL: antennaGround + finiteNumber($('heightInput').value, 30),
    targetHeightAboveGround: finiteNumber($('targetHeightInput').value, 1.5),
    samplesPerCell: finiteNumber($('samplesPerCell').value, 4),
    refractionCoefficient: finiteNumber($('refractionCoefficient').value, 0.25),
    dimensionFilter: DIMENSION_FILTER
  });
  return los;
}
function fillLOSColor(cellData) { return initialiseLOSForCurrentGrid().fillColor(cellData); }
function fillLOSOpacity(cellData) { return initialiseLOSForCurrentGrid().fillOpacity(cellData); }
function omitCell(cellData) {
 if (typeof METADATA.cellOmitValue === 'function')
  return METADATA.cellOmitValue(cellData.value);
 else if (METADATA.cellOmitValue === 'number')
  return METADATA.cellOmitValue;
 return false;
}

// -----------------------------------------------------------------------------
// Viewport-dependent NCSS request. The request bbox is the map viewport clipped
// to the survey, expanded to include the antenna so every target ray has terrain
// between the antenna and target available in the returned grid.
// -----------------------------------------------------------------------------
function scheduleRedraw(delay = 200) {
  clearTimeout(redrawTimer);
  redrawTimer = setTimeout(redrawMapLayer, delay);
}
function redrawMapLayer() {
  if (!metadata || !subsetEndpoint || !antennaLatLon) return;

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

  // Keep the requested antenna-to-viewport corridor bounded. LOS needs
  // intervening terrain, so the NCSS bbox includes both the visible viewport
  // and the antenna, but we cap the working radius to avoid huge downloads.
  const maxRangeKm = Math.max(1, Math.min(500, finiteNumber($("rangeKm").value, 100)));
  const latPad = maxRangeKm / 111.32;
  const lonPad = maxRangeKm / (111.32 * Math.max(0.15, Math.cos(antennaLatLon.lat * Math.PI / 180)));
  const rangeWest = antennaLatLon.lon - lonPad;
  const rangeEast = antennaLatLon.lon + lonPad;
  const rangeSouth = antennaLatLon.lat - latPad;
  const rangeNorth = antennaLatLon.lat + latPad;
  const clippedWest = Math.max(west, rangeWest);
  const clippedEast = Math.min(east, rangeEast);
  const clippedSouth = Math.max(south, rangeSouth);
  const clippedNorth = Math.min(north, rangeNorth);
  if (clippedEast <= clippedWest || clippedNorth <= clippedSouth) {
    removeOverlay();
    status(`The current map view is outside the configured ${maxRangeKm} km antenna range.`, "", false);
    return;
  }
  // Include antenna location and a small pad so NCSS returns its terrain cell.
  const pad = 0.02;
  const qWest = Math.max(Number(box.west), Math.min(clippedWest, antennaLatLon.lon - pad));
  const qEast = Math.min(Number(box.east), Math.max(clippedEast, antennaLatLon.lon + pad));
  const qSouth = Math.max(Number(box.south), Math.min(clippedSouth, antennaLatLon.lat - pad));
  const qNorth = Math.min(Number(box.north), Math.max(clippedNorth, antennaLatLon.lat + pad));

  if (qEast <= qWest || qNorth <= qSouth) {
    removeOverlay();
    status("No overlap between the antenna, map view and survey coverage.", "", false);
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

  const previousCfr = cfr;
  const previousLos = los;
  queue_throbber();
  try {
    const buffer = await fetchArrayBuffer(url, currentController.signal);
    if (requestId !== latestRequest) return;

    const candidateCfr = new CFRender(buffer, null, projectionCache, MAP_CONFIG.longitudeWrap);
    cfr = candidateCfr;
    los = null;
    var theCanvasContext = "2d";
    if ("canvasContext" in METADATA)
     theCanvasContext = METADATA["canvasContext"];
    const image = await candidateCfr.draw2DbasicGrid(
      MAP_CONFIG.variable,
      DIMENSION_FILTER,
      coords => COORDINATES.sourceXYToDisplay(coords),
      "url",
      {
        fill: fillLOSColor,
        opacity: fillLOSOpacity,
        omit: omitCell,
        stroke: "none",
        strokeWidth: 0,
        idealCellSize: 1,
        canvasContext: theCanvasContext
      }
    );

    if (requestId !== latestRequest || cfr !== candidateCfr) {
      if (cfr === candidateCfr) { cfr = previousCfr; los = previousLos; }
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
      if (requestId === latestRequest && cfr === candidateCfr) { cfr = previousCfr; los = previousLos; }
      return;
    }
    status(`Viewshed rendered. Grid: ${flattenCFRenderGrid(candidateCfr.data2DGrid.grid).length.toLocaleString()} cells. ` +
           `horizStride=${new URLSearchParams(queryString.slice(1)).get("horizStride")}.`, "ok", false);
  } catch (error) {
    if (error.name === "AbortError") return;
    if (requestId === latestRequest) { cfr = previousCfr; los = previousLos; }
    status(`Terrain request/render failed: ${error.message}`, "error");
    console.error(error);
  } finally {
    dequeue_throbber();
  }
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
  map.on("click", e => setAntenna(e.latlng.lat, e.latlng.lng, true));
  map.on("moveend", () => scheduleRedraw(180));
  map.on("dragend", () => { if (antennaMarker) antennaMarker.closeTooltip(); map.closePopup(); });
  map.attributionControl.setPrefix(false);
  L.DomEvent.disableClickPropagation($("viewshed_inputs"));
  L.DomEvent.disableClickPropagation($("viewshed_buttons"));
  const controlsToggle = $("viewshed_controls_toggle");
  const controlsBody = $("viewshed_controls_body");
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
  protectMapControlEvents($("viewshed_inputs"));
  $("opacity_range").value = gOVERLAY_OPACITY * 100;
  $("opacity_range_label").textContent = `${Math.round(gOVERLAY_OPACITY*100)}%`;
  $("opacity_range").addEventListener("input", function() {
    gOVERLAY_OPACITY = Number(this.value) / 100;
    $("opacity_range_label").textContent = `${this.value}%`;
    updateLayerOpacity();
  });

  $("setAntennaButton").addEventListener("click", e => {
    e.preventDefault(); e.stopPropagation();
    const lat = Number($("latInput").value), lon = Number($("lonInput").value);
    if (!Number.isFinite(lat) || !Number.isFinite(lon) || Math.abs(lat)>90 || Math.abs(lon)>360) {
      status("Please enter a valid latitude and longitude.", "error"); return;
    }
    setAntenna(lat, lon, true);
  });
  $("refreshButton").addEventListener("click", e => { e.preventDefault(); e.stopPropagation(); scheduleRedraw(0); });
  bindEnterToControls("viewshed_controls_body", "viewshed_controls_toggle", "viewshed_controls_body", input => {
    if (input.id === "latInput" || input.id === "lonInput") {
      $("setAntennaButton").click();
    } else {
      $("refreshButton").click();
    }
  });
  for (const id of ["latInput","lonInput","heightInput","targetHeightInput","rangeKm","strideScale","samplesPerCell","refractionCoefficient"]) {
    $(id).addEventListener("click", e => e.stopPropagation());
  }
  $("latInput").value = antennaLatLon.lat.toFixed(6);
  const defaultAntennaHeight = Math.max(0, finiteNumber(METADATA.defaultAntennaHeight, 30));
  const defaultTargetHeight = Math.max(0, finiteNumber(METADATA.defaultTargetHeight, 1.5));
  const defaultRangeKm = Math.max(1, Math.min(500, finiteNumber(METADATA.defaultRangeKm, 100)));
  const defaultSamplesPerCell = Math.max(1, Math.min(12, Math.round(finiteNumber(METADATA.defaultSamplesPerCell, 4))));
  const supportedRefraction = [0.25, 0, 0.5];
  const requestedRefraction = finiteNumber(METADATA.defaultRefractionCoefficient, 0.25);
  const defaultRefractionCoefficient = supportedRefraction.includes(requestedRefraction) ? requestedRefraction : 0.25;
  $("heightInput").value = String(defaultAntennaHeight);
  $("targetHeightInput").value = String(defaultTargetHeight);
  $("rangeKm").value = String(defaultRangeKm);
  $("samplesPerCell").value = String(defaultSamplesPerCell);
  $("refractionCoefficient").value = String(defaultRefractionCoefficient);
  const defaultStrideScale = Math.max(1, Math.min(16, Math.round(finiteNumber(METADATA.defaultStrideScale, 1))));
  $("strideScale").value = String(defaultStrideScale);
  $("lonInput").value = antennaLatLon.lon.toFixed(6);
  setAntenna(antennaLatLon.lat, antennaLatLon.lon, false);
  $("heightInput").addEventListener("input", () => {
    if (antennaMarker) {
        antennaMarker.setTooltipContent(antennaTooltipContent());
    }
  });
  window.addEventListener("resize", () => map.invalidateSize());
  status("Click the map to position the antenna, or enter coordinates.");
  loadDatasetMetadata();
}
function closeDialogs() {
  for (const dialog of document.querySelectorAll("dialog")) if (dialog.open) dialog.close();
}
window.addEventListener("load", mapStartup);

// Expose a few diagnostics for browser-console inspection.
window.viewshedDebug = {
  get map() { return map; },
  get metadata() { return metadata; },
  get cfRender() { return cfr; },
  get los() { return los; },
  get subsetEndpoint() { return subsetEndpoint; },
  get antenna() { return antennaLatLon; },
  get latestRequest() { return latestRequest; }
};
</script>
<div class="full_screen">
 <div id="map">
  <div class="map_buttons" id="viewshed_buttons">
   <button id="button_zoom_in" title="Zoom In">+</button><br>
   <button id="button_zoom_out" title="Zoom Out">−</button><br>
   <button id="button_info" title="Information"><i class="fa fa-info-circle"></i></button><br>
   <button id="button_settings" title="Settings"><i class="fa fa-cog"></i></button>
  </div>
  <div class="map_headings">
   <span id="map_title" class="map_title">Terrain Line-of-Sight Viewshed</span>
    <div class="viewshed_inputs" id="viewshed_inputs">
   <button type="button" class="viewshed_toggle" id="viewshed_controls_toggle" aria-expanded="false" aria-controls="viewshed_controls_body">
    <span>Viewshed Controls</span><i class="fa fa-chevron-down chevron" aria-hidden="true"></i>
   </button>
   <div class="viewshed_controls_body" id="viewshed_controls_body" hidden>

  <div class="coords">
    <div>
      <label for="latInput">Antenna latitude</label>
      <input id="latInput" type="number" step="any">
    </div>
    <div>
      <label for="lonInput">Antenna longitude</label>
      <input id="lonInput" type="number" step="any">
    </div>
  </div>

  <label for="heightInput">Antenna height above ground (m)</label>
  <input id="heightInput" type="number" min="0" step="0.1">

  <label for="targetHeightInput">Target height above ground (m)</label>
  <input id="targetHeightInput" type="number" min="0" step="0.1">

  <button id="setAntennaButton" class="primary">Set antenna position</button>

  <h3>Calculation and grid settings</h3>

  <div class="coords">
    <div>
      <label for="rangeKm">Maximum LOS range (km)</label>
      <input id="rangeKm" type="number" min="1" max="500" step="1">
    </div>
    <div>
      <label for="samplesPerCell">LOS samples / cell</label>
      <input id="samplesPerCell" type="number" min="1" max="12" step="1">
    </div>
  </div>

  <label for="refractionCoefficient">Atmospheric refraction (k-factor)</label>
  <select id="refractionCoefficient">
    <option value="0.25">Standard radio approximation (0.25)</option>
    <option value="0">No refraction (0)</option>
    <option value="0.5">Higher refraction (0.5)</option>
  </select>

  <label for="strideScale">Display cell size (pixels)</label>
  <input id="strideScale" type="number" min="1" max="16" step="1">

  <button id="refreshButton" class="secondary">Recalculate viewshed</button>

  <div class="viewshed_note">
    Display cell size controls sampling/downloading density. Actual source resolution is described by the THREDDS axis metadata.
  </div>

  <div class="viewshed_legend">
    <span><i class="viewshed_swatch" style="background:#00ffff"></i>Visible</span>
    <span><i class="viewshed_swatch" style="background:#ff2525"></i>Blocked</span>
  </div>

  <div id="status">Initialising…</div>

  <div class="viewshed_note">
    <span id="sourceNote"></span><br>
    You can also click or drag the antenna marker on the map to reposition it.
  </div>

  </div>

  </div>
 </div>
 <dialog id="map_info_dialog" class="map_dialog">
  <h3>Information</h3>
  <h4>Map legend</h4>
  <p><span style="display:inline-block;background:#00ffff;width:16px;height:16px;border:1px solid #333;vertical-align:middle"></span> Visible — unobstructed terrain line of sight</p>
  <p><span style="display:inline-block;background:#ff2525;width:16px;height:16px;border:1px solid #333;vertical-align:middle"></span> Blocked — terrain obstructs line of sight</p>
  <h4>Dataset</h4>
  <p id="datasetDescription"></p>
  <p><a id="datasetLink" href="#" target="_blank" rel="noopener">Open dataset</a></p>
  <h4>Dataset Global Metadata</h4>
  <div id="info_global_attributes" class="information"></div>
  <h4>Dataset Variable Metadata (<span id="info_variable"></span>)</h4>
  <div id="info_variable_attributes" class="information"></div>
  <p>Viewshed calculations include Earth curvature and an adjustable effective-Earth refraction coefficient. Projected grids use their two-dimensional auxiliary longitude/latitude coordinates for ground distances; CFRender continues to render using its native projected X/Y coordinates.</p>
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
