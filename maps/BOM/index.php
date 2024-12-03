<?php
 require('../../common/common.php');
 $nonce = page_top('Australian Bureau of Meteorology','yes');
?>

<div class="indent">
 <p>
  <h4>Australian Bureau of Meteorology</h4>
  <p>Visualizations based on datasets from this provider.</p>
  <p>
   <h4>Map Visualizations</h4>
  <ul>
   <li>Australian Gridded Climate Data (AGCD)
    <ul>
     <li>Daily
      <ul>
       <li><a href="BOM_AGCD_daily_mean_min_temp.php">Mean Minimum Temperature</a></li>
       <li><a href="BOM_AGCD_daily_mean_max_temp.php">Mean Maximum Temperature</a></li>
       <li><a href="BOM_AGCD_daily_precip1.php">Precipitation Totals</a></li>
      </ul>
     </li>
     <li>Monthly
      <ul>
       <li><a href="BOM_AGCD_monthly_mean_min_temp.php">Mean Minimum Temperature</a></li>
       <li><a href="BOM_AGCD_monthly_mean_max_temp.php">Mean Maximum Temperature</a></li>
       <li><a href="BOM_AGCD_monthly_precip1.php">Precipitation Totals</a></li>
      </ul>
     </li>
    </ul>
   </li>
  </ul>

  <p>
   <h4>About this provider</h4>
   <dl>
    <dt>Home page</dt>
    <dd><a href="http://bom.gov.au">http://bom.gov.au</a></dd>
    <dt>Server endpoints used for these Visualizations</dt>
    <dd>
     <a href="http://opendap.bom.gov.au/thredds/catalog.html">THREDDS Server</a><br/>
     <a href="http://opendap.bom.gov.au/thredds/serverInfo.html">THREDDS Server Info Page</a><br/>
    </dd>
   </dl>
  </p>

</div>

<?php page_bottom(true); ?>
