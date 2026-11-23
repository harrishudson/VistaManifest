// -----------------------------------------------------------------------------
// pacioos_thredds.js -- metadata-driven THREDDS catalogue browsing / METADATA builder
//
// Built on THREDDS_utils.js:
//   TDSCatalogParser   -> catalogue groups, datasets, catalogRefs, NCSS sub-path
//   TDSMetadataParser  -> NCSS dataset.xml axes, grid list, grid attributes, LatLonBox
//
// What this module adds on top:
//   * Transport      direct browser fetch, falling back to the same-origin PHP proxy
//   * grid analysis  picks the renderable grid variable, longitude convention,
//                    vertical sign/units and download format from dataset.xml
//   * buildMetadata  assembles the METADATA object the shared renderers expect
//   * datasetDetails a small display-only reader for catalogue <documentation>,
//                    creator/publisher (TDSCatalogParser does not expose these)
//
// Nothing dataset-specific is hardcoded.
// -----------------------------------------------------------------------------
import { TDSCatalogParser, TDSMetadataParser } from '../../common/THREDDS_utils.js';

export function safeHref(u) {         // only ever link out to http(s)
  try { const x = new URL(u); return /^https?:$/.test(x.protocol) ? x.href : ''; } catch (e) { return ''; }
}

// ------------------------------------------------------------------ Transport
// Every THREDDS request goes through here.
//   strategy 'proxy'  always use the site proxy (PACIOOS_thredds_proxy.php); use when the
//                     THREDDS server sends no CORS headers
//   strategy 'auto'   try a direct browser fetch first; on a network/CORS failure retry via
//                     the proxy and remember that for the session
//   strategy 'direct' never use the proxy
// proxy is a function(fullUrl) -> proxied URL (see makeProxy), the same shape the shared
// renderers accept in METADATA.proxy.
const MODE_KEY = 'pacioos_thredds.transport.';

// Adapter for a proxy script whose ?url= parameter is a path relative to a fixed prefix
// (PACIOOS_thredds_proxy.php: CATALOG_PREFIX . $_GET['url']).
export function makeProxy({ script, prefix }) {
  return function proxyUrl(url) {
    if (!url.startsWith(prefix)) throw new Error(`Cannot proxy ${url}: it is outside ${prefix}`);
    return script + (script.includes('?') ? '&' : '?') + 'url=' + encodeURIComponent(url.slice(prefix.length));
  };
}

// Site-wide busy indicator (defined by the page chrome); optional so this module also works without it.
const throbberOn = () => { try { if (typeof queue_throbber === 'function') queue_throbber(); } catch (e) { /* ignore */ } };
const throbberOff = () => { try { if (typeof dequeue_throbber === 'function') dequeue_throbber(); } catch (e) { /* ignore */ } };

export class Transport {
  constructor({ proxy = null, strategy = 'auto' } = {}) {
    if (proxy !== null && typeof proxy !== 'function') throw new TypeError('Transport proxy must be null or a function.');
    this.proxy = proxy;
    this.strategy = proxy ? strategy : 'direct';
    this.modes = new Map();           // origin -> 'direct' | 'proxy'   (auto strategy only)
  }
  modeFor(url) {
    if (this.strategy === 'proxy' || this.strategy === 'direct') return this.strategy;
    const origin = new URL(url, location.href).origin;
    if (this.modes.has(origin)) return this.modes.get(origin);
    try {
      const s = sessionStorage.getItem(MODE_KEY + origin);
      if (s === 'direct' || s === 'proxy') { this.modes.set(origin, s); return s; }
    } catch (e) { /* storage unavailable */ }
    return null;
  }
  _remember(url, mode) {
    if (this.strategy !== 'auto') return;
    const origin = new URL(url, location.href).origin;
    this.modes.set(origin, mode);
    try { sessionStorage.setItem(MODE_KEY + origin, mode); } catch (e) { /* ignore */ }
  }
  async fetch(url, init = {}) {
    let directError = null;
    if (this.modeFor(url) !== 'proxy') {
      try {
        const r = await fetch(url, { mode: 'cors', credentials: 'omit', signal: init.signal });
        this._remember(url, 'direct');
        return r;                     // any HTTP status: the response was readable, so CORS works
      } catch (e) {
        if (e && e.name === 'AbortError') throw e;
        directError = e;              // TypeError => network or CORS failure
        if (!this.proxy || this.strategy === 'direct') throw new Error(`Request to ${url} failed (${e.message}). The server may not allow cross-origin requests.`);
      }
    }
    try {
      const r = await fetch(this.proxy(url), { credentials: 'same-origin', signal: init.signal });
      this._remember(url, 'proxy');
      return r;
    } catch (e) {
      if (e && e.name === 'AbortError') throw e;
      throw new Error(directError
        ? `Direct request failed (${directError.message}) and the proxy failed too (${e.message}).`
        : `Proxy request failed (${e.message}).`);
    }
  }
  async text(url, init) {
    throbberOn();
    try {
      const r = await this.fetch(url, init);
      const body = await r.text();
      if (!r.ok) throw new Error(`HTTP ${r.status} from ${url}${body ? ': ' + body.slice(0, 200) : ''}`);
      if (!body.trim()) throw new Error(`Empty response from ${url} (the server or proxy returned no data).`);
      return body;
    } finally { throbberOff(); }
  }
  async xml(url, init) {
    const doc = new DOMParser().parseFromString(await this.text(url, init), 'application/xml');
    if (doc.querySelector('parsererror')) throw new Error(`The response from ${url} was not valid XML.`);
    return doc;
  }
}

// ------------------------------------------------------------------ Catalogue
// Groups = the catalogue's top-level folders (TDSCatalogParser's dataset_list_name).
export async function loadCatalogue(url, transport) {
  const doc = await transport.xml(url);
  const root = doc.documentElement;
  if (!root || (root.localName || root.nodeName) !== 'catalog') throw new Error('Not a THREDDS catalog document.');
  const parser = new TDSCatalogParser(doc);

  const groups = new Map();
  const group = name => {
    const key = name || '';
    if (!groups.has(key)) groups.set(key, { name: key, datasets: [], refs: [] });
    return groups.get(key);
  };
  const add = (g, name, ID, urlPath) => g.datasets.push({ name: name || ID || urlPath, ID: ID || '', urlPath, id: ID || urlPath, group: g.name });

  for (const d of parser.catalog_dataset_datasets || []) if (d.urlPath) add(group(d.dataset_list_name), d.name, d.ID, d.urlPath);
  for (const el of doc.querySelectorAll('catalog > dataset'))   // top-level leaves (no folder)
    if (el.getAttribute('urlPath')) add(group(''), el.getAttribute('name'), el.getAttribute('ID'), el.getAttribute('urlPath'));
  for (const r of parser.catalog_dataset_refs || []) {
    let href = '', external = true;
    try { href = new URL(r.href, url).href; external = new URL(href).origin !== new URL(url).origin; } catch (e) { /* keep empty */ }
    group(r.dataset_list_name).refs.push({ title: r.title || r.name || r.href || 'Linked catalogue', href, external });
  }
  const list = [...groups.values()].filter(g => g.datasets.length || g.refs.length);
  const datasets = list.flatMap(g => g.datasets);
  return { url, doc, parser, name: root.getAttribute('name') || '', groups: list, datasets };
}

export function findDataset(catalogue, id) {
  return catalogue.datasets.find(d => d.ID === id) || catalogue.datasets.find(d => d.urlPath === id) || null;
}

// NCSS endpoint for a dataset, via TDSCatalogParser's NetcdfSubset service lookup.
export function ncssEndpoint(catalogue, ds) {
  let sub = catalogue.parser.getDatasetSubsetServerSubPath(ds.name, ds.group || undefined);
  if (!sub) {                                           // top-level leaf: same service, same rule
    const svc = catalogue.doc.querySelector('service[serviceType="NetcdfSubset"]');
    if (!svc) throw new Error('The catalogue declares no NetcdfSubset (NCSS) service, so subsets cannot be requested.');
    sub = svc.getAttribute('base') + ds.urlPath;
  }
  const endpoint = new URL(sub, catalogue.url).href;
  return { endpoint, metadataUrl: endpoint + '/dataset.xml', datasetPageUrl: endpoint + '/dataset.html' };
}

// Display-only: summary, rights, creator, publisher, keywords, places, links.  THREDDS
// inheritance: own <metadata> plus <metadata inherited="true"> of each ancestor.
export function datasetDetails(catalogue, ds) {
  const out = { summary: '', rights: '', creators: [], publishers: [], keywords: [], places: [], links: [], timeCoverage: null };
  const el = Array.from(catalogue.doc.getElementsByTagName('dataset'))
    .find(e => (ds.ID && e.getAttribute('ID') === ds.ID) || (!ds.ID && e.getAttribute('urlPath') === ds.urlPath));
  if (!el) return out;
  const chain = [];
  for (let n = el; n && n.localName === 'dataset'; n = n.parentElement) chain.unshift(n);
  const T = e => (e && e.textContent || '').trim();
  const first = (e, name) => Array.from(e.children).find(c => c.localName === name);
  const push = (arr, v) => { if (v && !arr.includes(v)) arr.push(v); };
  const absorb = c => {
    switch (c.localName) {
      case 'documentation': {
        const href = c.getAttributeNS('http://www.w3.org/1999/xlink', 'href') || c.getAttribute('xlink:href');
        const type = c.getAttribute('type');
        if (href) { if (!out.links.some(l => l.href === href)) out.links.push({ href, title: c.getAttributeNS('http://www.w3.org/1999/xlink', 'title') || c.getAttribute('xlink:title') || href }); }
        else if (type === 'summary' && T(c)) out.summary = T(c);
        else if (type === 'rights' && T(c)) out.rights = T(c);
        break;
      }
      case 'creator': push(out.creators, T(first(c, 'name'))); break;
      case 'publisher': push(out.publishers, T(first(c, 'name'))); break;
      case 'keyword': push(out.keywords, T(c)); break;
      case 'geospatialCoverage': Array.from(c.children).filter(x => x.localName === 'name').forEach(x => push(out.places, T(x))); break;
      case 'timeCoverage': out.timeCoverage = { start: T(first(c, 'start')), end: T(first(c, 'end')) }; break;
      default: break;
    }
  };
  for (const n of chain) {
    for (const c of Array.from(n.children)) {
      if (c.localName === 'metadata') { if (n === el || c.getAttribute('inherited') === 'true') Array.from(c.children).forEach(absorb); }
      else if (n === el) absorb(c);
    }
  }
  return out;
}

// ------------------------------------------------------------- NCSS dataset.xml
export async function fetchDatasetMetadata(catalogue, ds, transport) {
  const ep = ncssEndpoint(catalogue, ds);
  const doc = await transport.xml(ep.metadataUrl);
  let tds;
  try { tds = new TDSMetadataParser(doc, false); }       // constructor reads <LatLonBox>
  catch (e) { throw new Error('dataset.xml has no usable <LatLonBox>, which the renderers require.'); }
  if (!tds.GridVariableList || !tds.GridVariableList.length) throw new Error('dataset.xml describes no grids (is this a gridded dataset?).');
  return { catalogue, ds, endpoint: ep, doc, tds };
}

// ------------------------------------------------------------------ Derivation
const METRES = /^(m|meter|meters|metre|metres)$/i;
const ELEVATION_WORDS = /(^|[_\s])(height|altitude|elevation|depth|relief)([_\s]|$)/i;
const num = v => { const n = Number(v); return (v !== null && v !== '' && Number.isFinite(n)) ? n : null; };

function shapeLength(doc, axisName) {                   // axis without <values>: use its shape attribute
  const a = Array.from(doc.querySelectorAll('gridDataset > axis')).find(x => x.getAttribute('name') === axisName);
  const s = a ? (a.getAttribute('shape') || '').trim() : '';
  return /^\d+$/.test(s) ? Number(s) : null;
}

function classifyGrid(tds, doc, g) {
  const meta = tds.getGridVariableMetadata(g.name);
  const refs = meta ? Object.values(meta.Axes) : [];
  const axes = refs.filter(Boolean);
  const isLat = a => /^lat$/i.test(a.axisType || '') || (!a.axisType && /^degrees?_?n/i.test(a.units || ''));
  const isLon = a => /^lon$/i.test(a.axisType || '') || (!a.axisType && /^degrees?_?e/i.test(a.units || ''));
  const lat = axes.filter(isLat), lon = axes.filter(isLon);
  const extra = axes.filter(a => !isLat(a) && !isLon(a) && (a.values.length || shapeLength(doc, a.name)) !== 1);
  let reason = '';
  if (!meta || axes.length !== refs.length) reason = 'its axes are not described in dataset.xml';
  else if (!lat.length || !lon.length) reason = axes.some(a => /^geo[xy]$/i.test(a.axisType || '')) ? 'it is on a projected (GeoX/GeoY) grid, not a latitude/longitude grid' : 'it has no latitude and longitude axes';
  else if (lat.length > 1 || lon.length > 1 || lat[0].values.length < 2 || lon[0].values.length < 2) reason = 'its coordinates are not 1-D axes with values';
  else if (extra.length) reason = `it has extra dimensions (${extra.map(a => a.name).join(', ')}) that the renderers cannot bind`;
  return { name: g.name, desc: g.desc || '', type: g.type || '', attrs: g.nestedAttributes || {},
           latAxis: lat[0] || null, lonAxis: lon[0] || null, spatialOk: !reason, reason };
}

export function chooseGrid(tds, doc, requested) {
  const all = tds.GridVariableList.map(g => classifyGrid(tds, doc, g));
  const candidates = all.filter(g => g.spatialOk);
  if (requested) {
    const g = all.find(x => x.name === requested);
    if (!g) throw new Error(`Variable "${requested}" is not a grid in this dataset.`);
    if (!g.spatialOk) throw new Error(`Variable "${requested}" cannot be rendered: ${g.reason}.`);
    return { grid: g, candidates, how: 'requested explicitly', ambiguous: false };
  }
  if (!candidates.length) throw new Error(`No renderable latitude/longitude grid variable found (${all.map(g => `${g.name}: ${g.reason}`).join('; ')}).`);
  if (candidates.length === 1) return { grid: candidates[0], candidates, how: 'the only 2-D latitude/longitude grid in dataset.xml', ambiguous: false };
  const pool = candidates.filter(g => ELEVATION_WORDS.test(g.attrs.standard_name || '') || ELEVATION_WORDS.test(g.attrs.long_name || g.desc || ''));
  if (pool.length === 1) return { grid: pool[0], candidates, how: 'the grid whose CF standard_name / long_name describes height, elevation or depth', ambiguous: false };
  return { grid: (pool.length ? pool : candidates)[0], candidates, how: 'first of several candidate grids (please confirm)', ambiguous: true };
}

// CF: attribute "positive" ("up"/"down"); failing that, a depth standard_name.
export function verticalSign(grid) {
  const positive = String(grid.attrs.positive || '').trim().toLowerCase();
  if (positive === 'down') return { scale: -1, source: 'variable attribute positive="down"' };
  if (positive === 'up') return { scale: 1, source: 'variable attribute positive="up"' };
  const sn = String(grid.attrs.standard_name || '').toLowerCase();
  if (/(^|_)depth(_|$)/.test(sn) && !/(height|altitude)/.test(sn)) return { scale: -1, source: `standard_name "${sn}" (depth is positive downwards in CF)` };
  return { scale: 1, source: 'no "positive" attribute or depth standard_name: treated as elevation positive upwards' };
}

export function analyseGrid(meta, requestedVariable) {
  const { tds, doc } = meta;
  const pick = chooseGrid(tds, doc, requestedVariable || null);
  const grid = pick.grid, problems = [], warnings = [];

  const b = tds.getLatLonBox();                           // strings, as parsed by TDSMetadataParser
  const box = { west: num(b.west), east: num(b.east), south: num(b.south), north: num(b.north) };
  const boxOk = Object.values(box).every(v => v !== null) && box.north > box.south;
  if (!boxOk) problems.push('dataset.xml\'s <LatLonBox> is not a valid extent.');

  // TDSMetadataParser's longitudeWrap = "dataset longitudes are 0..360".
  const lv = grid.lonAxis.values;
  const longitudeWrap = Math.min(lv[0], lv[lv.length - 1]) >= 0 && Math.max(lv[0], lv[lv.length - 1]) > 180;

  let querySuffix = '&accept=netcdf';
  const accepts = Array.from(doc.querySelectorAll('accept')).filter(a => !a.closest('GridAsPoint')).map(a => (a.textContent || '').trim()).filter(Boolean);
  if (accepts.length) {
    const fmt = accepts.find(a => /^netcdf$/i.test(a)) || accepts.find(a => /^netcdf3$/i.test(a));
    if (fmt) querySuffix = '&accept=' + encodeURIComponent(fmt);
    else problems.push(`The server offers no classic NetCDF output (offers: ${accepts.join(', ')}).`);
  } else warnings.push('dataset.xml lists no output formats; using accept=netcdf.');

  const units = grid.attrs.units || '';
  const unitsAreMetres = METRES.test(units.trim());
  if (!units) warnings.push('The variable has no units attribute; the renderers assume metres.');
  else if (!unitsAreMetres) warnings.push(`Vertical units are "${units}"; the renderers assume metres, so values will be mis-scaled.`);

  const step = a => a.values.length > 1 ? Math.abs(a.values[1] - a.values[0]) : null;
  return { pick, box: boxOk ? box : null, longitudeWrap, querySuffix, units, unitsAreMetres,
           vertical: verticalSign(grid), resolution: { lat: step(grid.latAxis), lon: step(grid.lonAxis) }, problems, warnings };
}

// ------------------------------------------------------------- METADATA builder
// Zoom at which the box fills a ~1000x700 map; only used as the renderer's initial
// view / fitBounds maxZoom, so slightly generous is fine.
export function fitZoom(box, w = 1000, h = 700) {
  const clampLat = v => Math.max(-85.05, Math.min(85.05, v));
  const merc = v => Math.log(Math.tan(Math.PI / 4 + clampLat(v) * Math.PI / 360));
  const lonSpan = Math.max(1e-9, Math.min(360, Math.abs(box.east - box.west)));
  const latSpan = Math.max(1e-9, merc(box.north) - merc(box.south));
  const z = Math.min(Math.log2((w / 256) * 360 / lonSpan), Math.log2((h / 256) * 2 * Math.PI / latSpan));
  return Math.max(1, Math.min(18, Math.floor(z) + 1));
}

export function buildMetadata(meta, analysis, { view, viewLabel, canvasContext = null, stopContext = null, defaultStrideScale = null, transport = null } = {}) {
  const { ds, endpoint, catalogue } = meta;
  const det = datasetDetails(catalogue, ds);
  const grid = analysis.pick.grid, box = analysis.box;
  const wrap180 = v => ((((v + 180) % 360) + 360) % 360) - 180;
  const centre = { lat: (box.south + box.north) / 2, lon: wrap180((box.west + box.east) / 2) };

  const sig = n => String(Number(n.toPrecision(4)));
  const gridNote = `Native grid ${grid.lonAxis.values.length.toLocaleString()} × ${grid.latAxis.values.length.toLocaleString()} cells`
    + (analysis.resolution.lon !== null ? `, ${sig(analysis.resolution.lon)}° spacing.` : '.');
  const sourceNote = [det.creators.length && `Source: ${det.creators.join('; ')}.`,
                      det.publishers.length && `Published by ${det.publishers.join('; ')}.`, gridNote].filter(Boolean).join(' ');
  const usingProxy = !!(transport && transport.proxy && transport.modeFor(endpoint.endpoint) === 'proxy');

  const md = {
    mapTitle: `${ds.name} — ${viewLabel}`,
    datasetName: ds.name,
    datasetDescription: [det.summary, `NetCDF variable: ${grid.name}.`].filter(Boolean).join(' '),
    sourceNote,
    providerName: det.publishers[0] || new URL(endpoint.endpoint).hostname,
    proxy: usingProxy ? transport.proxy : null,      // function; resolveServiceUrl() in the renderers accepts it
    subsetEndpoint: endpoint.endpoint,
    metadataUrl: endpoint.metadataUrl,
    datasetPageUrl: endpoint.datasetPageUrl,
    variable: grid.name,
    longitudeWrap: analysis.longitudeWrap,
    querySuffix: analysis.querySuffix,
    initialView: { lat: centre.lat, lon: centre.lon, zoom: fitZoom(box) },
    // hillshader_renderer.php: -1 = depth positive downwards (CF positive="down")
    defaultVerticalScale: analysis.vertical.scale,
    verticalScale: analysis.vertical.scale
  };
  if (view === 'viewshed') md.initialAntenna = { lat: centre.lat, lon: centre.lon };
  if (canvasContext) md.canvasContext = canvasContext;
  if (stopContext) md.stopContext = stopContext;                       // elevation_renderer.php: which colour stops
  if (Number.isFinite(Number(defaultStrideScale)) && defaultStrideScale !== null) md.defaultStrideScale = Number(defaultStrideScale);
  return md;
}

// ---------------------------------------------------- render-page entry points
export async function resolveDatasetMetadata(cfg) {
  const transport = new Transport({ proxy: cfg.proxy ? makeProxy(cfg.proxy) : null, strategy: cfg.strategy || 'auto' });
  const catalogue = await loadCatalogue(cfg.catalogueUrl, transport);
  const ds = findDataset(catalogue, cfg.datasetId);
  if (!ds) throw new Error(`Dataset "${cfg.datasetId}" was not found in ${cfg.catalogueUrl}.`);
  const meta = await fetchDatasetMetadata(catalogue, ds, transport);
  const analysis = analyseGrid(meta, cfg.variable || null);
  if (analysis.problems.length) throw new Error(analysis.problems.join(' '));
  const md = buildMetadata(meta, analysis, { view: cfg.view, viewLabel: cfg.viewLabel, canvasContext: cfg.canvasContext, stopContext: cfg.stopContext, defaultStrideScale: cfg.defaultStrideScale, transport });
  document.title = `Vista Manifest - ${md.mapTitle}`;
  for (const w of analysis.warnings) console.warn('[pacioos_thredds]', w);
  if (analysis.pick.ambiguous) console.warn('[pacioos_thredds] several candidate grids; using', analysis.pick.grid.name);
  window.pacioosResolution = { meta, analysis, metadata: md };     // console diagnostics
  return md;
}

export function showFatalError(error, { backUrl = '' } = {}) {
  const message = error instanceof Error ? error.message : String(error);
  if (typeof status_msg === 'function') { try { status_msg(message); } catch (e) { /* ignore */ } }
  const box = document.createElement('div');
  box.setAttribute('role', 'alert');
  const s = box.style;
  s.setProperty('position', 'fixed'); s.setProperty('z-index', '10000');
  s.setProperty('left', '50%'); s.setProperty('top', '20%'); s.setProperty('transform', 'translateX(-50%)');
  s.setProperty('max-width', 'min(640px, 90vw)'); s.setProperty('padding', '16px 20px');
  s.setProperty('background', 'Canvas'); s.setProperty('color', 'CanvasText');
  s.setProperty('border', '2px solid #c44'); s.setProperty('border-radius', '8px');
  const h = document.createElement('strong'); h.textContent = 'Could not set up this visualisation';
  const p = document.createElement('p'); p.textContent = message;
  box.append(h, p);
  if (backUrl) { const a = document.createElement('a'); a.href = backUrl; a.textContent = '← Back to the catalogue browser'; box.append(a); }
  document.body.append(box);
}
