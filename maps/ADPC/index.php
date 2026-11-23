<?php
 require('../../common/common.php');
 $nonce = page_top('Asian Disaster Preparedness Center - VIsta Manifest','yes');
?>

<div class="indent">
 <h4>Asian Disaster Preparedness Center (ADPC)</h4>
 <p>Visualizations based on datasets from this provider.</p>
 <h4>Map Visualizations</h4>
 <ul>
  <li>RAINSTORM
   <ul>
    <li><a href="ADPC_RAINSTORM_rain_rate.php">Rain Rate - ADPC RAINSTORM GSMaP NOW</a></li>
   </ul>
  </li>
 </ul>

 <h4>About this provider</h4>
  <dl>
   <dt>Home page</dt>
   <dd>
    <a href="https://www.adpc.net/ver25/">https://www.adpc.net/ver25/</a><br>
   </dd>
   <dt>Server endpoint used for these Visualizations</dt>
   <dd>
    <a href="https://thredds-servir.adpc.net/thredds/catalog.html">THREDDS Server</a><br>
    <a href="https://thredds-servir.adpc.net/thredds/serverInfo.html">THREDDS Server Info Page</a><br>
   </dd>
  </dl>

</div>

<?php page_bottom(true); ?>
