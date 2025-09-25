<?php
 require('./common/common.php');
 $nonce = page_top('Vista Manifest - Home','yes');
?>

<script nonce="<?php echo $nonce;?>">
if (location.protocol == 'http:')
 location.href = 'https:' + window.location.href.substring(window.location.protocol.length);
</script>

<div class="indent">
 <p class="smallscreen_only">
  <em>Visualizations of Climate Forecasting Open Data</em>
 </p>
 <p>
 <h4>Demonstration Maps by dataset Providers</h4>
 <ul>
  <li>USA
   <ul>
    <li><a href="./maps/UCAR/">University Corporation for Atmospheric Research (UCAR)</a></li>
    <li><a href="./maps/NOAA/">National Oceanic and Atmospheric Administration (NOAA)</a></li>
    <li><a href="./maps/ORNL/">Oak Ridge National Laboratory (ORNL)</a></li>
    <li><a href="./maps/PACIOOS/">Pacific Islands Ocean Observing System (PacIOOS)</a></li>
    <li><a href="./maps/HYCOM/">Hybrid Coordinate Ocean Model (HYCOM)</a></li>
   </ul>
  </li>
  <li>Australia
   <ul>
    <li><a href="./maps/BOM/">Bureau of Meteorology (BOM)</a></li>
    <li><a href="./maps/NCI/">National Computing Infrastructure (NCI)</a></li>
   </ul>
  </li>
 </ul>
 </p>
 <p>
  <h4>More</h4>
  <ul>
   <li><a href="about.php">About &amp; Technical Information</a></li>
   <li><a href="help.php">Help</a></li>
   <li><a href="privacy.php">Privacy Policy</a></li>
   <li><a href="get_involved.php">Get Involved</a></li>
   <li><a href="contact.php">Contact Us</a></li>
  </ul>
 </p>

</div>

<?php page_bottom(); ?>
