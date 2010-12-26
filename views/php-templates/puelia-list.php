<ol>
<?php
$renderedUris = array();
foreach($page->getItems() as $itemUri):
?>
    <li>
        <?php
         $topic = $itemUri;
         require 'puelia-item.php' 
         ?>
    </li>
<?php endforeach ?>
</ol>