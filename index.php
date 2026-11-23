<?php
 require('./common/common.php');
 $nonce = page_top('Vista Manifest - Home','yes');
?>

<script nonce="<?php echo $nonce;?>" type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "WebSite",
      "@id": "https://vistamanifest.com/#website",
      "url": "https://vistamanifest.com/",
      "name": "Vista Manifest",
      "description": "Demonstration web application created by Harris Hudson.",
      "author": {
        "@id": "https://harrishudson.com/#harrishudson"
      },
      "creator": {
        "@id": "https://harrishudson.com/#harrishudson"
      }
    },
    {
      "@type": "WebPage",
      "@id": "https://vistamanifest.com/#webpage",
      "url": "https://vistamanifest.com/",
      "name": "Vista Manifest",
      "isPartOf": {
        "@id": "https://vistamanifest.com/#website"
      },
      "author": {
        "@id": "https://harrishudson.com/#harrishudson"
      },
      "about": {
        "@id": "https://vistamanifest.com/#app"
      }
    },
    {
      "@type": "CreativeWork",
      "@id": "https://vistamanifest.com/#app",
      "name": "Vista Manifest",
      "url": "https://vistamanifest.com/",
      "author": {
        "@id": "https://harrishudson.com/#harrishudson"
      },
      "creator": {
        "@id": "https://harrishudson.com/#harrishudson"
      },
      "isBasedOn": {
        "@id": "https://harrishudson.com/#webpage"
      }
    },
    {
      "@type": "Person",
      "@id": "https://harrishudson.com/#harrishudson",
      "name": "Harris Hudson",
      "url": "https://harrishudson.com/",
      "sameAs": [
        "https://github.com/harrishudson"
      ]
    }
  ]
}
</script>

<script nonce="<?php echo $nonce;?>">
function upgrade_connection() {
 if (!window.isSecureContext) {
  status_msg('Insecure page load detected (http). \n'+
             'Reloading as secure (https) in 5 seconds.')
  window.setTimeout(
   function() {
    location.href = 'https:' + 
                     window.location.href.substring(window.location.protocol.length)
  }, 5000)
 }
}
window.onload = upgrade_connection
</script>

<div class="indent">
 <p class="smallscreen_only">
  <em>Visualizations of Climate Forecasting Open Data</em>
 </p>
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
  <li>France
   <ul>
    <li><a href="./maps/CLS/">COLLECTE LOCALISATION SATELLITES (CLS)</a></li>
   </ul>
  </li>
  <li>Thailand
   <ul>
    <li><a href="./maps/ADPC/">Asian Disaster Preparedness Center (ADPC)</a></li>
   </ul>
  </li>
 </ul>

 <h4>More</h4>
 <ul>
  <li><a href="about.php">About &amp; Technical Information</a></li>
  <li><a href="help.php">Help</a></li>
  <li><a href="privacy.php">Privacy Policy</a></li>
  <li><a href="get_involved.php">Get Involved</a></li>
  <li><a href="contact.php">Contact Us</a></li>
 </ul>

</div>

<?php page_bottom(); ?>
