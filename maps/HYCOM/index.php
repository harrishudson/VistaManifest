<?php
 require('../../common/common.php');
 $nonce = page_top('Hybrid Coordinate Ocean Model (HYCOM)','yes');
?>

<div class="indent">
 <p>
  <h4>Hybrid Coordinate Ocean Model (HYCOM)</h4>
 </p>
 <p>Visualizations based on datasets from this provider.</p>
 <p>
  <h4>Map Visualizations</h4>
 <ul>
  <li>ESPC-D-V02: Global 1/12° Analysis
   <ul>
    <li>Latest
     <ul>
      <li><a href="HYCOM_global_sst1.php">Sea Water Temperature</a></li>
      <li><a href="HYCOM_global_ssv1.php">Sea Water Velocity</a></li>
     </ul>
    </li>
   </ul>
  </li>
 </ul>
 <p>
 <h4>About this provider</h4>
  <dl>
   <dt>HYCOM</dt>
   <dd>
    <a href="https://hycom.org">Home Page (hycom.org)</a><br/>
    <a href="https://tds.hycom.org/thredds/catalog.html">THREDDS Server</a><br/>
    <a href="https://tds.hycom.org/thredds/serverInfo.html">THREDDS Server Info Page</a><br/>
   </dd>
  </dl>
 </p>
</div>

<?php page_bottom(true); ?>
