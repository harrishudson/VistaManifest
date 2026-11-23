<?php
/*
 * PacIOOS THREDDS Elevation and Batyhmetry catalogue browser + visualisation launcher.
 *
 * One page, three jobs:
 *   (default)                 catalogue browser UI
 *   ?view=V&cat=C&id=ID       render dataset ID of catalogue C with renderer V.
 *
 * THREDDS requests are made by the browser (see pacioos_thredds.js).  When the server
 * sends no CORS headers they go through the site's own PACIOOS_thredds_proxy.php
 * (configured below); this page contains no proxy code of its own.
 *
 * The only things configured by hand are which catalogues exist and which
 * visualisations each one offers.  Everything about a dataset is read from THREDDS.
 */
require_once __DIR__ . '/../../common/common.php';

// --------------------------------------------------------------------- config
$PCB_ENDPOINTS = [
    'dem' => [
        'label' => 'Digital Elevation Models (DEMs)',
        'url'   => 'https://pae-paha.pacioos.hawaii.edu/thredds/dem.xml',
        'views' => ['elevation', 'viewshed', 'hillshade'],
        'stopContext' => 'elevation'
    ],
    'bathymetry' => [
        'label' => 'Bathymetry',
        'url'   => 'https://pae-paha.pacioos.hawaii.edu/thredds/bathymetry.xml',
        'views' => ['elevation', 'hillshade'],
        'stopContext' => 'bathymetry'
    ],
];
$PCB_VIEWS = [
    'elevation' => ['label' => 'Elevation', 'renderer' => 'elevation_renderer.php'],
    'hillshade' => ['label' => 'Hillshade', 'renderer' => 'hillshader_renderer.php'],
    'viewshed'  => ['label' => 'Viewshed',  'renderer' => 'viewshed_renderer.php']
];
$PCB_SELF = $_SERVER['SCRIPT_NAME'] ?? basename(__FILE__);

// Site proxy for THREDDS requests: PACIOOS_thredds_proxy.php, which fetches CATALOG_PREFIX . $_GET['url'].
//   script   URL of the proxy script
//   prefix   MUST equal CATALOG_PREFIX in the proxy; the browser sends everything after it
//   strategy 'proxy' = always use the proxy (PacIOOS sends no CORS headers)
//            'auto'  = try the server directly first, fall back to the proxy on a CORS failure
//            'direct'= never use the proxy
$PCB_PROXY = [
    'script'   => rtrim(dirname($PCB_SELF), '/') . '/PACIOOS_thredds_proxy.php',
    'prefix'   => 'https://pae-paha.pacioos.hawaii.edu/thredds/',
    //'strategy' => 'proxy',
    'strategy' => 'auto',
];
// Default stride scale handed to every renderer (METADATA.defaultStrideScale; renderers clamp to 1..16).
$PCB_DEFAULT_STRIDE = 2;

// ---------------------------------------------------- render mode (?view=...)
$pcbView = isset($_GET['view']) ? (string)$_GET['view'] : '';
$pcbCat  = isset($_GET['cat'])  ? (string)$_GET['cat']  : '';
$pcbId   = isset($_GET['id'])   ? (string)$_GET['id']   : '';
$pcbIdOk = (bool)preg_match('~^[A-Za-z0-9_.:/-]{1,200}$~', $pcbId) && strpos($pcbId, '..') === false;

if ($pcbView !== '') {
    $valid = isset($PCB_VIEWS[$pcbView]) && isset($PCB_ENDPOINTS[$pcbCat])
          && in_array($pcbView, $PCB_ENDPOINTS[$pcbCat]['views'], true) && $pcbIdOk;
    if (!$valid) {
        http_response_code(400);
        $nonce = page_top('Vista Manifest - PacIOOS catalogue');
        echo '<p>That visualisation request is not valid. ';
        echo '<a href="' . htmlspecialchars($PCB_SELF, ENT_QUOTES) . '">Return to the catalogue browser</a>.</p>';
        page_bottom();
        exit;
    }
    $pcbVar = (isset($_GET['var']) && preg_match('~^[A-Za-z0-9_.-]{1,100}$~', (string)$_GET['var'])) ? (string)$_GET['var'] : null;
    $cfg = [
        'catalogueUrl'  => $PCB_ENDPOINTS[$pcbCat]['url'],
        'datasetId'     => $pcbId,
        'view'          => $pcbView,
        'viewLabel'     => $PCB_VIEWS[$pcbView]['label'],
        'variable'      => $pcbVar,
        'stopContext'   => $PCB_ENDPOINTS[$pcbCat]['stopContext'] ?? null,
        'canvasContext' => ($pcbView === 'hillshade' && ($_GET['ctx'] ?? '') === 'webgl') ? 'webgl' : null,
        'proxy'         => ['script' => $PCB_PROXY['script'], 'prefix' => $PCB_PROXY['prefix']],
        'strategy'      => $PCB_PROXY['strategy'],
        'defaultStrideScale' => $PCB_DEFAULT_STRIDE,
        'backUrl'       => $PCB_SELF . '?cat=' . rawurlencode($pcbCat) . '&id=' . rawurlencode($pcbId),
    ];
    $json = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $PAGE_TITLE = 'Vista Manifest - PacIOOS ' . $PCB_VIEWS[$pcbView]['label'] . ': ' . $pcbId;
    $METADATA_JS = "import { resolveDatasetMetadata, showFatalError } from './pacioos_thredds.js';\n"
                 . "const PCB_CONFIG = " . $json . ";\n"
                 . <<<'JS'
// METADATA is derived at load time from the THREDDS catalogue + the dataset's NCSS dataset.xml.
const METADATA = await resolveDatasetMetadata(PCB_CONFIG).catch(error => { showFatalError(error, PCB_CONFIG); throw error; });

// The await above does network I/O before the renderer's own code runs, so the window "load"
// event has usually fired already and the renderer's window.addEventListener("load", mapStartup)
// would never be called.  Run late "load" listeners on the next tick instead.  (Cleaner fix:
// change that line in each renderer to a document.readyState check; then delete this block.)
if (document.readyState === 'complete') {
  const nativeAdd = window.addEventListener;
  window.addEventListener = function (type, listener, options) {
    if (type !== 'load') return nativeAdd.call(this, type, listener, options);
    setTimeout(() => (typeof listener === 'function' ? listener.call(window, new Event('load')) : listener.handleEvent(new Event('load'))), 0);
  };
  setTimeout(() => { delete window.addEventListener; }, 0);
}
JS;
    require_once __DIR__ . '/../../common/' . $PCB_VIEWS[$pcbView]['renderer'];
    exit;
}

// ----------------------------------------------------------- browser UI mode
$PAGE_TITLE = 'Vista Manifest - PacIOOS Elevation and Bathymetry Catalogues';
$nonce = page_top($PAGE_TITLE);

$endpointsForJs = [];
foreach ($PCB_ENDPOINTS as $key => $ep) {
    $endpointsForJs[] = ['key' => $key, 'label' => $ep['label'], 'url' => $ep['url'],
                         'views' => array_map(fn($v) => ['key' => $v, 'label' => $PCB_VIEWS[$v]['label']], $ep['views'])];
}
$uiConfig = [
    'endpoints' => $endpointsForJs,
    'self'      => $PCB_SELF,
    'proxy'     => ['script' => $PCB_PROXY['script'], 'prefix' => $PCB_PROXY['prefix']],
    'strategy'  => $PCB_PROXY['strategy'],
    'preselect' => (isset($PCB_ENDPOINTS[$pcbCat]) && $pcbIdOk) ? ['cat' => $pcbCat, 'id' => $pcbId] : null,
];
$uiJson = json_encode($uiConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<style nonce="<?php echo $nonce; ?>">
.pcb { --pcb-line: color-mix(in srgb, currentColor 22%, transparent); --pcb-tint: color-mix(in srgb, currentColor 6%, transparent); --pcb-accent: #1685a5; }
.pcb * { box-sizing: border-box; }
.pcb-intro, .pcb-muted { opacity: .75; }
.pcb-toolbar { display: flex; flex-wrap: wrap; gap: 10px 12px; align-items: center; margin: 14px 0; }
.pcb-toolbar input[type=search] { min-width: min(340px, 100%); }
.pcb-badge { font-size: .82rem; border: 1px solid var(--pcb-line); border-radius: 99px; padding: 3px 10px; }
.pcb-status { border: 1px solid var(--pcb-line); border-radius: 6px; padding: 8px 12px; margin: 10px 0; background: var(--pcb-tint); }
.pcb-status.pcb-bad, .pcb-err { color: #c44; }
.pcb-warn { color: #b7791f; }
.pcb-layout { display: grid; grid-template-columns: minmax(280px, .9fr) minmax(420px, 1.6fr); gap: 16px; align-items: start; }
.pcb-panel { border: 1px solid var(--pcb-line); border-radius: 8px; overflow: hidden; }
.pcb-panel > h2 { margin: 0; padding: 10px 14px; font-size: 1.05rem; border-bottom: 1px solid var(--pcb-line); background: var(--pcb-tint); }
.pcb-tree { padding: 8px; max-height: 74vh; overflow: auto; }
.pcb-tree details { margin: 2px 0; }
.pcb-tree summary { cursor: pointer; font-weight: 600; padding: 5px 6px; border-radius: 5px; }
.pcb-tree .pcb-children { padding-left: 14px; }
.pcb-leaf { display: block; width: 100%; text-align: left; padding: 6px 8px 6px 22px; border: 0; border-radius: 5px; background: transparent; color: inherit; font: inherit; cursor: pointer; }
.pcb-leaf:hover, .pcb-tree summary:hover { background: color-mix(in srgb, var(--pcb-accent) 12%, transparent); }
.pcb-leaf.pcb-selected { background: color-mix(in srgb, var(--pcb-accent) 22%, transparent); outline: 1px solid var(--pcb-accent); }
.pcb-ref { display: block; padding: 6px 8px 6px 22px; }
.pcb-details { padding: 14px; min-height: 280px; overflow-wrap: anywhere; }
.pcb-details h2 { margin: 0 0 8px; font-size: 1.2rem; }
.pcb-details h3 { margin: 18px 0 6px; font-size: 1rem; }
.pcb-details dl { display: grid; grid-template-columns: minmax(120px, .32fr) 1fr; gap: 5px 12px; margin: 8px 0; }
.pcb-details dt { font-weight: 650; opacity: .75; }
.pcb-details dd { margin: 0; }
.pcb-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 16px 0 4px; }
.pcb-btn { display: inline-block; padding: 9px 16px; border-radius: 6px; border: 1px solid var(--pcb-accent); background: var(--pcb-accent); color: #fff !important; text-decoration: none; font-weight: 600; }
.pcb-btn[aria-disabled=true] { opacity: .4; pointer-events: none; }
.pcb ul.pcb-list { margin: 6px 0; padding-left: 20px; }
@media (max-width: 850px) { .pcb-layout { grid-template-columns: 1fr; } .pcb-tree { max-height: 42vh; } }
</style>

<div class="pcb indent" id="pcb">
  <h1>PacIOOS Elevation &amp; Bathymetry Catalogues</h1>
  <p class="pcb-intro">Browse a THREDDS catalogue, pick a dataset, 
   then open it as an elevation, hillshade or viewshed map.</p>

  <div class="pcb-toolbar">
    <label for="pcb-endpoint">Catalogue</label>
    <select id="pcb-endpoint"></select>
    <input id="pcb-search" type="search" placeholder="Filter by name, ID, place or keyword" aria-label="Filter datasets">
    <button id="pcb-reload" type="button">Reload</button>
    <span id="pcb-transport" class="pcb-badge" hidden></span>
  </div>
  <div id="pcb-status" class="pcb-status" role="status">Loading catalogue&hellip;</div>

  <div class="pcb-layout">
    <section class="pcb-panel">
      <h2>Datasets</h2>
      <div id="pcb-tree" class="pcb-tree"></div>
    </section>
    <section class="pcb-panel">
      <h2>Dataset</h2>
      <div id="pcb-details" class="pcb-details">
       <p class="pcb-muted">Select a dataset to read its metadata and choose a visualisation.</p>
      </div>
    </section>
  </div>
</div>

<script type="application/json" id="pcb-config"><?php echo $uiJson; ?></script>
<script nonce="<?php echo $nonce; ?>" type="module">
import { Transport, makeProxy, loadCatalogue, findDataset, datasetDetails, fetchDatasetMetadata, analyseGrid, safeHref } from './pacioos_thredds.js';

const CFG = JSON.parse(document.getElementById('pcb-config').textContent);
const $ = id => document.getElementById(id);
const transport = new Transport({ proxy: CFG.proxy ? makeProxy(CFG.proxy) : null, strategy: CFG.strategy });
const state = { ep: null, catalogue: null, index: new Map(), selected: null, token: 0 };

// -------------------------------------------------------------- tiny DOM helper
function h(tag, props = {}, ...children) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(props)) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') el.className = v;
    else if (k === 'text') el.textContent = v;
    else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else el.setAttribute(k, v === true ? '' : v);
  }
  for (const c of children.flat(Infinity)) if (c !== null && c !== undefined && c !== false) el.append(c.nodeType ? c : document.createTextNode(String(c)));
  return el;
}
// replaceChildren() would render null/false children as the text "null"/"false"; drop them first.
function setKids(el, ...kids) { el.replaceChildren(...kids.flat(Infinity).filter(k => k !== null && k !== undefined && k !== false)); }
function setStatus(msg, bad = false) { const s = $('pcb-status'); s.textContent = msg; s.classList.toggle('pcb-bad', bad); }
function showTransport() {
  const m = state.ep ? transport.modeFor(state.ep.url) : null, b = $('pcb-transport');
  b.hidden = !m;
  b.textContent = m === 'proxy' ? 'Fetching via the site proxy' : 'Fetching directly from THREDDS';
}
const fmt = (n, d = 6) => (n === null || n === undefined) ? '—' : String(Number(Number(n).toFixed(d)));

// --------------------------------------------------------------------- catalogue
async function loadEndpoint(key, preselectId = null) {
  state.ep = CFG.endpoints.find(e => e.key === key);
  state.catalogue = null; state.selected = null; state.index.clear(); state.token++;
  setKids($('pcb-tree'), h('p', { class: 'pcb-muted', text: 'Loading…' }));
  setKids($('pcb-details'), h('p', { class: 'pcb-muted', text: 'Select a dataset to read its metadata and choose a visualisation.' }));
  setStatus('Fetching ' + state.ep.url + ' …');
  try {
    state.catalogue = await loadCatalogue(state.ep.url, transport);
    for (const d of state.catalogue.datasets) {
      const det = datasetDetails(state.catalogue, d);
      state.index.set(d, [d.name, d.id, d.urlPath, d.group, det.summary, det.keywords.join(' '), det.places.join(' ')].join(' ').toLowerCase());
    }
    renderTree();
    showTransport();
    const n = state.catalogue.datasets.length;
    setStatus(`Loaded “${state.catalogue.name || state.ep.label}”: ${n} dataset${n === 1 ? '' : 's'}.`);
    if (preselectId) { const d = findDataset(state.catalogue, preselectId); if (d) selectDataset(d); }
  } catch (e) {
    setKids($('pcb-tree'), h('p', { class: 'pcb-err', text: 'The catalogue could not be loaded.' }));
    setStatus('Catalogue request failed: ' + e.message, true);
    showTransport();
  }
}
function renderTree() {
  const terms = $('pcb-search').value.trim().toLowerCase().split(/\s+/).filter(Boolean);
  const tree = $('pcb-tree');
  tree.replaceChildren();
  let total = 0;
  for (const g of state.catalogue.groups) {
    const items = g.datasets.filter(d => terms.every(t => (state.index.get(d) || '').includes(t)));
    const refs = g.refs.filter(r => terms.every(t => r.title.toLowerCase().includes(t)));
    if (!items.length && !refs.length) continue;
    total += items.length;
    const kids = [
      ...items.map(d => h('button', { type: 'button', class: 'pcb-leaf' + (state.selected === d ? ' pcb-selected' : ''),
        title: [d.ID, d.urlPath].filter(Boolean).join(' · '), text: d.name, onclick: () => selectDataset(d) })),
      ...refs.map(r => { const href = safeHref(r.href);
        return h('div', { class: 'pcb-ref pcb-muted', title: r.external ? 'Hosted on a different server; it cannot be rendered from here' : 'Linked catalogue' },
          r.title + ' ', href ? h('a', { href, target: '_blank', rel: 'noopener', text: r.external ? '↗ external catalogue' : '↗ linked catalogue' }) : ''); })
    ];
    tree.append(g.name
      ? h('details', { open: terms.length > 0 || state.catalogue.groups.length <= 8 }, h('summary', { text: `${g.name} (${items.length})` }), h('div', { class: 'pcb-children' }, kids))
      : h('div', {}, kids));
  }
  if (!tree.childNodes.length) tree.append(h('p', { class: 'pcb-muted', text: 'No matching datasets.' }));
}

// ----------------------------------------------------------------------- details
function selectDataset(ds) {
  state.selected = ds;
  renderTree();
  try { history.replaceState(null, '', `${CFG.self}?cat=${encodeURIComponent(state.ep.key)}&id=${encodeURIComponent(ds.id)}`); } catch (e) { /* ignore */ }
  const det = datasetDetails(state.catalogue, ds);
  const row = (k, v) => (v === '' || v === null || v === undefined) ? null : [h('dt', { text: k }), h('dd', { text: v })];
  const resolved = h('div', {}, h('p', { class: 'pcb-muted', text: 'Reading the dataset\'s NCSS dataset.xml …' }));
  setKids($('pcb-details'), 
    h('h2', { text: ds.name }),
    det.summary ? h('p', { text: det.summary }) : null,
    h('dl', {}, row('Group', ds.group), row('Dataset ID', ds.ID), row('urlPath', ds.urlPath),
      row('Creator', det.creators.join('; ')), row('Publisher', det.publishers.join('; ')),
      row('Places', det.places.join('; ')), row('Time coverage', det.timeCoverage ? `${det.timeCoverage.start} to ${det.timeCoverage.end}` : '')),
    det.rights ? h('details', {}, h('summary', { text: 'Rights / licence' }), h('p', { text: det.rights })) : null,
    det.links.length ? h('ul', { class: 'pcb-list' }, det.links.map(l => safeHref(l.href) ? h('li', {}, h('a', { href: safeHref(l.href), target: '_blank', rel: 'noopener', text: l.title })) : null)) : null,
    h('h3', { text: 'Read from the dataset\'s NCSS metadata' }),
    resolved
  );
  const token = ++state.token;
  fetchDatasetMetadata(state.catalogue, ds, transport).then(meta => {
    if (token !== state.token) return;
    showTransport();
    renderResolved(resolved, meta, null);
  }).catch(e => {
    if (token !== state.token) return;
    showTransport();
    setKids(resolved, h('p', { class: 'pcb-err', text: 'Could not read dataset metadata: ' + e.message }));
  });
}

function renderResolved(box, meta, requestedVar) {
  let a;
  try { a = analyseGrid(meta, requestedVar); }
  catch (e) { setKids(box, h('p', { class: 'pcb-err', text: e.message })); return; }
  const g = a.pick.grid;
  const ax = x => `${x.name} — ${x.values.length.toLocaleString()} points, ${fmt(Math.abs(x.values[1] - x.values[0]), 8)} ${x.units || ''} spacing`;

  let varCell;
  const how = h('small', { class: 'pcb-muted', text: 'Chosen as ' + a.pick.how });
  if (a.pick.candidates.length > 1) {
    const sel = h('select', { 'aria-label': 'Grid variable', onchange: () => renderResolved(box, meta, sel.value) },
      a.pick.candidates.map(c => h('option', { value: c.name, selected: c.name === g.name, text: c.name + (c.desc ? ` — ${c.desc}` : '') })));
    varCell = h('span', {}, sel, h('br'), how);
  } else varCell = h('span', {}, `${g.name}${g.desc ? ' — ' + g.desc : ''}`, h('br'), how);

  const b = a.box;
  const rows = [
    ['Grid variable', varCell],
    ['Vertical units', a.units || '(none given)'],
    ['Vertical sign', (a.vertical.scale === -1 ? 'Depth positive downwards (hillshade inverts)' : 'Elevation positive upwards') + ` — ${a.vertical.source}`],
    ['Latitude axis', ax(g.latAxis)], ['Longitude axis', ax(g.lonAxis)],
    ['Extent', b ? `W ${fmt(b.west, 5)}  E ${fmt(b.east, 5)}  S ${fmt(b.south, 5)}  N ${fmt(b.north, 5)}` : '—'],
    ['Longitude convention', a.longitudeWrap ? '0 to 360° (wrapped)' : '−180 to 180°'],
    ['Download format', a.querySuffix.replace(/^&accept=/, '') + ' (from the server\'s accept list)'],
    ['NCSS endpoint', h('span', {}, meta.endpoint.endpoint, ' ', h('a', { href: meta.endpoint.metadataUrl, target: '_blank', rel: 'noopener', text: '[dataset.xml]' }), ' ', h('a', { href: meta.endpoint.datasetPageUrl, target: '_blank', rel: 'noopener', text: '[dataset.html]' }))]
  ];
  const dl = h('dl', {}, rows.map(([k, v]) => [h('dt', { text: k }), h('dd', {}, v)]));

  const ep = state.ep;
  const explicit = a.pick.candidates.length > 1 ? `&var=${encodeURIComponent(g.name)}` : '';
  const webgl = h('input', { type: 'checkbox', id: 'pcb-webgl' });
  const links = ep.views.map(v => h('a', { class: 'pcb-btn', 'data-view': v.key, 'aria-disabled': a.problems.length ? 'true' : null, text: v.label }));
  const setHrefs = () => links.forEach(l => {
    const v = l.getAttribute('data-view');
    l.href = `${CFG.self}?view=${v}&cat=${encodeURIComponent(ep.key)}&id=${encodeURIComponent(meta.ds.id)}${explicit}${v === 'hillshade' && webgl.checked ? '&ctx=webgl' : ''}`;
  });
  webgl.addEventListener('change', setHrefs); setHrefs();

  setKids(box, 
    dl,
    a.problems.length ? h('ul', { class: 'pcb-list pcb-err' }, a.problems.map(p => h('li', { text: p }))) : null,
    a.warnings.length ? h('ul', { class: 'pcb-list pcb-warn' }, a.warnings.map(w => h('li', { text: w }))) : null,
    h('div', { class: 'pcb-actions' }, links,
      ep.views.some(v => v.key === 'hillshade') ? h('label', {}, webgl, ' WebGL hillshade') : null)
  );
}

// ------------------------------------------------------------------------ wiring
const sel = $('pcb-endpoint');
for (const ep of CFG.endpoints) sel.append(h('option', { value: ep.key, text: ep.label }));
sel.addEventListener('change', () => loadEndpoint(sel.value));
$('pcb-reload').addEventListener('click', () => loadEndpoint(sel.value));
$('pcb-search').addEventListener('input', () => { if (state.catalogue) renderTree(); });

const pre = CFG.preselect;
if (pre) sel.value = pre.cat;
loadEndpoint(sel.value, pre ? pre.id : null);
</script>
<?php page_bottom(); ?>
