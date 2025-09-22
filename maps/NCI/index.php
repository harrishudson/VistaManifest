<?php
 require('../../common/common.php');
 $nonce = page_top('Australian National Computing Infrastructure','yes');
?>

<div class="indent">
 <p>
  <h4>Australian National Computing Infrastructure (NCI)</h4>
  <p>Visualizations based on datasets from this provider.</p>
  <p>
   <h4>Map Visualizations</h4>
  <ul>
   <li>Bureau of Meteorology Atmospheric Regional Projections for Australia (BARPA)
    <ul>
     <li>EC-Earth3/ssp585 - Hourly (Latest)
      <ul>
       <li><a href="ANU_BOM_Earth3_min_temp.php">Minimum Temperature</a></li>
       <li><a href="ANU_BOM_Earth3_max_temp.php">Maximum Temperature</a></li>
       <li><a href="ANU_BOM_Earth3_prc.php">Convective Precipitation</a></li>
       <li><a href="ANU_BOM_Earth3_clt.php">Total Cloud Cover</a></li>
       <li><a href="ANU_BOM_Earth3_windspeed.php">Wind Speed</a></li>
      </ul>
     </li>
    </ul>
   </li>
   <li>ANU Water and Landscape Dynamics (ub8)
    <ul>
     <li>Water and Landscape Dynamics - AU
      <ul>
       <li><a href="ANU_water_biodiversity.php">Biodiversity</a></li>
       <li><a href="ANU_water_treecover.php">Tree Cover</a></li>
      </ul>
     </li>
    </ul>
   </li>
   <li>Geoscience Australia Geophysics Reference Data Collection
    <ul>
     <li>Airborne Geophysics
      <ul>
       <li><a href="ANU_GA_geophysics_elevation.php">Elevation Survey</a></li>
      </ul>
     </li>
    </ul>
   </li>
  </ul>

  <p>
   <h4>About this provider</h4>
   <dl>
    <dt>Home page</dt>
    <dd><a href="https://nci.org.au">nci.org.au</a></dd>
    <dt>Server endpoints used for these Visualizations</dt>
    <dd>
     <a href="https://thredds.nci.org.au/thredds/catalog/catalog.html">THREDDS Server</a><br/>
     <a href="https://thredds.nci.org.au/thredds/fileServer/thredds-readme/thredds_readme.txt">THREDDS Server README</a><br/>
     <a href="https://thredds.nci.org.au/thredds/info/serverInfo.html">THREDDS Server Info Page</a><br/>
    </dd>
   </dl>
  </p>

</div>

<?php page_bottom(true); ?>
