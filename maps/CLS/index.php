<?php
 require('../../common/common.php');
 $nonce = page_top('COLLECTE LOCALISATION SATELLITES - Vista Manifest','yes');
?>

<div class="indent">
 <h4>COLLECTE LOCALISATION SATELLITES (CLS)</h4>
 <p>Visualizations based on datasets from this provider.</p>
 <h4>Map Visualizations</h4>
 <ul>
  <li>Bathymetry - global - ETOPO2V2
   <ul>
    <li><a href="CLS_elevation.php">Elevation</a></li>
    <li><a href="CLS_elevation_hillshade.php">Elevation Hillshade</a></li>
    <li><a href="CLS_bathymetry.php">Bathymetry</a></li>
    <li><a href="CLS_bathymetry_hillshade.php">Bathymetry Hillshade</a></li>
   </ul>
  </li>
  <li>Ocean Color Global
   <ul>
    <li><a href="CLS_ocean_chlorophyl1-co03.php">Ocean Color - Global - standard chlorophyll - high resolution</a></li>
    <li><a href="CLS_ocean_chlorophyl1-co04.php">Ocean Color - Global - objective analysis chlorophyll</a></li>
   </ul>
  </li>
 </ul>

 <h4>About this provider</h4>
  <dl>
   <dt>Home page</dt>
   <dd>
    <a href="https://cls.fr">https://cls.fr</a></br>
   </dd>
   <dt>Server endpoint used for these Visualizations</dt>
   <dd>
    <a href="https://tds-datastore.cls.fr/thredds/catalog.html">THREDDS Server</a><br>
    <a href="https://tds-datastore.cls.fr/thredds/serverInfo.html">THREDDS Server Info Page</a><br>
   </dd>
  </dl>

</div>

<?php page_bottom(true); ?>
