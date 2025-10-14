<?php
 require('./common/common.php');
 $nonce = page_top('Vista Manifest - Get Involved','yes');
?>

<div class="indent">
 <h4>Get Involved</h4>
 <p>
 Vista Manifest, and the CFRender rendering engine used, to date have been authored by one individual - 
 Harris Hudson.  See the <a href="contact.php">Contact Us</a> page on how to reach out to Harris.  
 This website is maintained as an Open Source website in order to encourage other technically minded 
 software developers, GIS analysts, and scientists to possibly reconsider the way they might look 
 at their NetCDF data.  The intention here is to simply put scientific datasets into the hands 
 of the every-day person.  To date, this website is a bit of an eclectic collection of some random 
 weather and climate renderings but is based on real datasets from publicly available resources.  
 If you have a suggestion for a new live data map rendering, please see below. 
 </p>

 <h4>Have a Map Suggestion?</h4>
 <p>
 If you have some NetCDF Open Datasets, in CF format, that require a basic grid rendering or
 a vector (wind) rendering, then please reach out with your suggestion and it may be considered for
 inclusion here.  Both Grid and Vector rendering is available.  To consider your dataset for possible
 inclusion on this website, ideally it should be;
 </p>
 <ol>
  <li>A visualization for educational purposes</li>
  <li>Open Data - that can be accessed without requiring any user authentication nor API key registration</li>
  <li>Available in NetCDF version 3 format (currently CFRender only supports this version)</li>
  <li>Available in CF (Climate Forecasting) convention</li>
  <li>If the dataset is more than 1Mb in size, or your have multiple NetCDF files that make up
      your dataset, then ideally it will be available via a <em>THREDDS
      TDS Subsetting Service</em> - so it can be scalably sliced and diced.</li>
  <li>Ideally your files will be served with CORS headers over https (to allow cross origin sharing of your data).</li>
 </ol>

 <h4>This Website Repo</h4>
 <a href="https://github.com/harrishudson/VistaManifest">https://github.com/harrishudson/VistaManifest</a>

 <h4>The CFRender Repo</h4>
 This is the main rendering engine used in this website.  If you are technically minded - feel free
 to suggest an improvement or raise an issue if you find a bug.<br><br>
 <a href="https://github.com/harrishudson/CFRender">https://github.com/harrishudson/CFRender</a>
 
 <h4>Donate / Sponsor</h4>
 <p>
 Did you find a visualization on the website useful to you?  Want to help keep this site
 up and running?
 </p>
 <a href="https://harrishudson.com/#sponsor">https://harrishudson.com/#sponsor</a>

</div>

<?php page_bottom(true); ?>
