<?php
 require('../../common/common.php');
 $nonce = page_top('University Corporation for Atmospheric Research (UCAR)','yes');
?>

<div class="indent">
 <h4>University Corporation for Atmospheric Research (UCAR)</h4>
 <p>Visualizations based on datasets from this provider.</p>
 <h4>Map Visualizations</h4>
 <ul>
  <li>National Digital Forecast Database (NDFD)
   <ul>
    <li>Latest National Weather Service CONUS Forecast Grids (CONDUIT) - Timeseries
     <ul>
      <li><a href="UCAR_US_forecast_precip1.php">Probability Total Precipitation (12_Hour Accumulation) above_0.254 kg.m-2</a></li>
      <li><a href="UCAR_US_forecast_precip_totals1.php">Total Precipitation (6_Hour Accumulation) @ Ground or water surface</a></li>
      <li><a href="UCAR_US_forecast_total_cloud_cover.php">Total Cloud Cover @ Ground or water surface</a></li>
      <li><a href="UCAR_US_forecast_wind1.php">Forecast Wind</a></li>
      <li><a href="UCAR_US_forecast_min_temp1.php">Minimum Temperature (12_Hour Minimum) @ Specified height level above ground</a></li>
      <li><a href="UCAR_US_forecast_max_temp1.php">Maximum Temperature (12_Hour Maximum) @ Specified height level above ground</a></li>
     </ul>
    </li>
    <li>Storm Prediction Center CONUS Forecast Grids - Timeseries
     <ul>
      <li><a href="UCAR_US_forecast_convective_hazard1.php">Convective Hazard Outlook (24_Hour Average) @ Ground or water surface</a></li>
      <li><a href="UCAR_US_forecast_probability_of_hail1.php">Probability Hail Probability (24_Hour Average) above_0 % @ Ground or water surface</a></li>
     </ul>
    </li>
   </ul>
  </li>
  <li>Radar Data
  <ul>
   <li>Unidata NEXRAD Composites (GINI)
    <ul>
     <li><a href="UCAR_US_radar_dhr_1km.php">dhr/1km</a></li>
    </ul>
   </li>
  </ul>
  </li>
 </ul>

 <h4>About this provider</h4>
  <dl>
   <dt>Home page</dt>
   <dd>
    <a href="https://ucar.edu/">https://ucar.edu/</a><br>
   </dd>
   <dt>Server endpoint used for these Visualizations</dt>
   <dd>
    <a href="https://thredds.ucar.edu/thredds/catalog/catalog.html">THREDDS Server</a><br>
    <a href="https://thredds.ucar.edu/thredds/info/serverInfo.html">THREDDS Server Info Page</a><br>
    <a href="https://www.unidata.ucar.edu/data/guidelines-data-use">Guidelines for Data Use</a><br>
    <a href="https://www.unidata.ucar.edu/">Server website</a><br>
   </dd>
  </dl>

</div>

<?php page_bottom(true); ?>
