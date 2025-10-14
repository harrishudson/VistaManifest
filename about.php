<?php
 require('./common/common.php');
 $nonce = page_top('Vista Manifest - About','yes');
?>

<div class="indent">
 <h4>About Vista Manifest &amp; Technical Information</h4>

 <p>
  <span style="background-color:#DADAFE;padding:3px">
  <em>
  Vista Manifest is a data driven website for educational purposes.  
  It's an Open Source website based on visualizations of Open Datasets.
  </em>
  </span>
 </p>

 <p>
  Scientific Weather and Climate data has generally been available on the web as Open Datasets for some time now.
  Such data is often in a technical file format called <em>NetCDF</em> and in a certain convention referred to as 
  <em>CF Convention</em>.  Weather and Climate Forecasting websites that publish NetCDF CF datasets, whilst very 
  important information, possibly unfortunately to-date, don't make this data available in a super basic easy way 
  to digest in a modern, mobile-friendly, browser.  Vista Manifest is a simple technical interface, between these 
  publicly available Open Datasets and your browser.  This may simply involve making the data accessible in a 
  more intuitive way and adding some improved styling context to the renderings of these publicly available raw 
  datasets and you.  Vista Manifest essentially uses 100% client side rendering to display your NetCDF data -
  there is essentially no back-end data processing done as part of these visualizations.  Some of the more
  complex or animated visualizations here may take a few seconds to load - but keep in mind you are looking
  at renderings from the real data source.  Whether that be a live, near live, or archived dataset.  There 
  is no latency introduced by any back-end processing.  Sometimes the generation of the visualizations takes 
  a few seconds.  It's as simple as that.  If you would like further information or have some suggestions, 
  please see the <a href="get_involved.php">Get Involved</a> page.
 </p>

 <h4>Attention Publishers, Custodians, and Server Admin's of datasets</h4>
 <p>
  <em>Did you get to this page because of a "User Agent" message in your logs about missing CORS headers?  Then please read this.</em>
 </p>

 <p>
  If your datasets are truly Open Data - then ideally you are serving your NetCDF files over <em>https</em> with 
  <em>CORS </em>
  headers (cross origin resource sharing http headers).  This would enable direct consumption of your NetCDF resources 
  in an end-user browser.  If your web, or THREDDS TDS,  server isn't serving your files with CORS headers, then Vista 
  Manifest may have had to implement a simple proxy to fetch your resources for consumption by a third party end user
  (a users web browser). 
  Setting up such a proxy is just a workaround and only necessary if your web server lacks CORS headers.  Which is less 
  than ideal as is completely unnecessary.  As a publisher, custodian, or server admin, of Open Data, please ensure your 
  files are served over https with http CORS headers (this applies whether your files are served via http or using a 
  THREDDS server). Unfortunately, to date (as of Dec 2024), a large number of public THREDDS servers don't have these 
  vital CORS headers -  and for several visualizations on this website, have necessitated the need to proxy upstream
  THREDDS server responses.  If you have recently updated your servers to include these vital CORS headers - please 
  inform us on the <a href="contact.php">Contact Us</a> page so we can remove our proxy relaying your data.
 </p>

 <h4>Acknowledgement</h4>
 <p>
  Vista Manifest utilizes; Leaflet and Font Awesome libraries.  And is built using the CFRender library
  (which is dependent upon on NetCDFjs) and along with; HTML5, javascript and php.
 </p>

 <h4>Publish Date - <em>Epoch</em></h4>
 <p>
  Vista Manifest website was first published in <em>December 2024</em>.  This website content, links to third party 
  resources, demonstration examples using modern javascript, and third party upstream dataset providers, was all valid 
  at the time of deployment.  There may have been subsequent example updates or changes to upstream dataset providers.
 </p>

</div>

<?php page_bottom(true); ?>
