<?php
 require('./common/common.php');
 $nonce = page_top('Vista Manifest - Help','yes');
?>

<div class="indent">
 <h4>Help - Map Visualisations</h4>
 <dl>
  <dt><button><i class="fa fa-plus"></i></button></dt>
  <dd>Zoom-In.  Map Zoom-In</dd>
  <dt><button><i class="fa fa-minus"></i></button></dt>
  <dd>Zoom-Out. Map Zoom-Out</dd>
  <dt><button><i class="fa fa-info-circle"></i></button></dt>
  <dd>Information.  Display information about the particular map layer including 
      associated metadata, legend and any other map specific information.</dd>
  <dt><button><i class="fa fa-cog"></i></button></dt>
  <dd>Settings.  Enables the setting of the map baselayer (if applicable), overlay 
      opacity, possibly the layer color, and any other map specific settings.</dd>
  <dt><button><i class="fa fa-filter"></i></button></dt>
  <dd>Filter. Rendered Map Layers may have additional Dimension Filter Criteria.  Such as 
      layer "time" or "depth" - for example.  Depends entirely on the overlay rendered.
      This will allow the setting of Dimension Filters particular to the rendered layer.
      Not used in all maps.</dd>
  <dt><button><i class="fa fa-location-arrow"></i></button></dt>
  <dd>Geo-location.  If displayed - facilitates setting the map, or rendering, 
      of your geo-location on the map.  Not used in all maps</dd>
  <dt><button><i class="fa fa-pause"></i></button> <button><i class="fa fa-play"></i></button></dt>
  <dd>Animation Controls.  If displayed - facilitates animation controls of 
      "Stop" and "Play" to animate the map through a particular dimension.
      Not used in all maps.</dd>
  <dt><em>Click</em></dt>
  <dd>Click or Tap on map to reveal cell value information, and any data filter dimensions, 
      for a given map location.</dd>
 </dl>
</div>

<?php page_bottom(true); ?>
