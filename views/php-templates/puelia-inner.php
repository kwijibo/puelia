<?php
switch ($page->endpointType):
    case API.'ItemEndpoint' :
        $topic = $page->topic; 
        require 'puelia-item.php';
        break;
    case API.'ListEndpoint':
        require 'puelia-list.php';
        break;
endswitch;
?>