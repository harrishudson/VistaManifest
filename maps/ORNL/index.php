<?php
 require('../../common/common.php');
 $nonce = page_top('Oak Ridge National Laboratory (ORNL)','yes');
?>

<div class="indent">

 <h4>Oak Ridge National Laboratory (ORNL)</h4>
 <p>Visualizations based on datasets from this provider.</p>
 <h4>Map Visualizations</h4>
 <ul>
  <li>ORNL DAAC Data
   <ul>
    <li>Regional and Global Data / DAYMET COLLECTIONS
     <ul>
      <li><a href="ORNL_US_precip1.php">Daymet V4 R1 Daily Precipitation for Continental North America</a></li>
      <li><a href="ORNL_US_min_temp1.php">Daymet V4 R1 Daily Minimum Temperature for Continental North America</a></li> 
      <li><a href="ORNL_US_max_temp1.php">Daymet V4 R1 Daily Maximum Temperature for Continental North America</a></li> 
     </ul>
    </li>
   </ul>
  </li>
 </ul>

 <h4>About this provider</h4>
 <dl>
  <dt>Home page</dt>
  <dd>
   <a href="https://www.ornl.gov/">https://www.ornl.gov/</a><br>
  </dd>
  <dt>Server endpoint used for these Visualizations</dt>
  <dd>
   <a href="https://thredds.daac.ornl.gov/thredds/catalog.html">THREDDS Server</a><br>
   <a href="https://daac.ornl.gov/">Server website</a><br>
   <a href="https://thredds.daac.ornl.gov/thredds/serverInfo.html">THREDDS Server Info Page</a><br>
  </dd>
 </dl>

</div>

<?php page_bottom(true); ?>
