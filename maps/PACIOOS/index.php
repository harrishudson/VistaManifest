<?php
 require('../../common/common.php');
 $nonce = page_top('Pacific Islands Ocean Observing System','yes');
?>

<div class="indent">
 <h4>Pacific Islands Ocean Observing System (PacIOOS)</h4>
 <p>Visualizations based on datasets from this provider.</p>
 <h4>Map Visualizations</h4>
 <ul>
  <li>PacIOOS
   <ul>
    <li><a href="PACIOOS_1km_global_elevation.php">DEM Global 1km Elevation (Land)</a></li>
    <li><a href="PACIOOS_1km_global_bathymetry.php">DEM Global 1km Bathymetry (Sea)</a></li>
    <li><a href="PACIOOS_nearest_coastline_land.php">Nearest Coastline (Land)</a></li>
    <li><a href="PACIOOS_nearest_coastline_sea.php">Nearest Coastline (Sea)</a></li>
    <li><a href="PACIOOS_wave_model_forecast.php">WaveWatch III Global Wave Model - Timeseries</a></li>
   </ul>
  </li>
 </ul>

 <h4>About this provider</h4>
 <dl>
  <dt>Home page</dt>
  <dd><a href="https://www.pacioos.hawaii.edu/">pacioos.hawaii.edu</a></dd>
  <dt>Provider Requested Notice</dt>
  <dd><em><span style="font-size: smaller;">Data provided by PacIOOS (www.pacioos.org), which is a part of the U.S. Integrated Ocean Observing System (IOOS®), funded in part by National Oceanic and Atmospheric Administration (NOAA) Award #NA21NOS0120091</span></em></dd>
  <dt>Server endpoints used for these Visualizations</dt>
  <dd>
   <a href="https://pae-paha.pacioos.hawaii.edu/thredds/catalog.html">THREDDS Server</a><br>
   <a href="https://pae-paha.pacioos.hawaii.edu/thredds/serverInfo.html">THREDDS Server Info Page</a><br>
  </dd>
 </dl>

</div>

<?php page_bottom(true); ?>
