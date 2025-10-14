<?php
 require('../../common/common.php');
 $nonce = page_top('National Oceanic and Atmospheric Administration (NOAA)','yes');
?>

<div class="indent">
 <h4>National Oceanic and Atmospheric Administration (NOAA)</h4>
 <p>Visualizations based on datasets from this provider.</p>
 <h4>Map Visualizations</h4>
 <ul>
  <li>STAR Environmental Mapping Service (Coastwatch)
   <ul>
    <li>CMORPH-2 High Resolution Global Precipitation Estimates
     <ul>
      <li><a href="COASTWATCH_NOAA_precip1.php">Latest (map)</a>
     </ul>
    </li>
    <li>NCEI Blended Seawinds Analysis at STAR THREDDS Server
     <ul>
      <li>Global 10 km Blended Daily Seawinds UVComp (Aggregated View)
       <ul>
        <li><a href="COASTWATCH_NOAA_seawinds_latest.php">Latest</a></li>
        <li><a href="COASTWATCH_NOAA_seawinds_latest_map.php">Map</a></li>
       </ul>
      </li>
     </ul>
    </li>
   </ul>
  </li>
  <li>Datasets (PSL)
   <ul>
    <li>cpc_global_precip
     <ul>
      <li><a href="PSL_NOAA_precip1.php">Daily Files</a></li>
      <li><a href="PSL_NOAA_precip1_map.php">Map</a></li>
     </ul>
    </li>
    <li>cpc_global_temp 
     <ul>
      <li>tmin
       <ul>
        <li><a href="PSL_NOAA_min_temp1.php">Daily Files</a></li>
        <li><a href="PSL_NOAA_min_temp1_map.php">Map</a></li>
       </ul>
      </li>
      <li>tmax
       <ul>
        <li><a href="PSL_NOAA_max_temp1.php">Daily Files</a></li>
        <li><a href="PSL_NOAA_max_temp1_map.php">Map</a></li>
       </ul>
      </li>
     </ul>
    </li>
   </ul>
  </li>
  <li>NOAA/NCEI 1/4 Degree Daily Optimum Interpolation Sea Surface Temperature (OISST) Analysis, Version 2.1
   <ul>
    <li><a href="PSL_NOAA_sst1.php">Daily Files</a></li>
    <li><a href="PSL_NOAA_sst1_map.php">Map</a></li>
   </ul>
  </li>
  <li>Marine and Ocean (NCEI)
   <ul>
    <li>OISST: Optimum Interpolation Sea Surface Temperatures
     <ul>
      <li><a href="NCEI_NOAA_sst1.php">Optimally Interpolated V2.1 SST AVHRR (Daily Files)</a></li>
     </ul>
    </li>
   </ul>
  </li>
 </ul>

 <h4>About these providers</h4>
 <dl>
  <dt>CoastWatch</dt>
  <dd>
   <a href="https://coastwatch.noaa.gov/cwn/index.html">Home Page (coastwatch.noaa.gov)</a><br>
   <a href="https://coastwatch.noaa.gov/thredds/catalog.html">THREDDS Server</a><br>
   <a href="https://coastwatch.noaa.gov/thredds/serverInfo.html">THREDDS Server Info Page</a><br>
   <a href="https://www.star.nesdis.noaa.gov/star/index.php">Server website</a>
  </dd>
  <dt>PSL</dt>
  <dd>
   <a href="https://psl.noaa.gov/">Home Page (psl.noaa.gov)</a><br>
   <a href="https://psl.noaa.gov/thredds/catalog/catalog.html">THREDDS Server</a><br>
   <a href="https://psl.noaa.gov/thredds/info/serverInfo.html">THREDDS Server Info Page</a><br>
  </dd>
  <dt>NCEI</dt>
  <dd>
   <a href="https://www.ncei.noaa.gov/">Home Page (ncei.noaa.gov)</a><br>
   <a href="https://www.ncei.noaa.gov/thredds/catalog/catalog.html">THREDDS Server</a><br>
   <a href="https://www.ncei.noaa.gov/thredds/serverInfo.html">THREDDS Server Info Page</a><br>
  </dd>
 </dl>

</div>

<?php page_bottom(true); ?>
