<?php
require_once 'lda.inc.php';
require 'setup.php';
require_once 'lda-cache.class.php';
require_once 'lda-request.class.php';
require_once 'lda-response.class.php';
require_once 'graphs/configgraph.class.php';
require_once 'responses/Response304.class.php';

Logger::configure("puelia.logging.properties");

$Request = new LinkedDataApiRequest();
header("Access-Control-Allow-Origin: *");

define("CONFIG_PATH", '/api-config');
define("CONFIG_URL", $Request->getBaseAndSubDir().CONFIG_PATH);

if(rtrim($Request->getPath(), '/')==$Request->getInstallSubDir()){
	header("Location: ".CONFIG_URL, true, 303);
	exit;
} 	    

if (  
    defined("PUELIA_SERVE_FROM_CACHE") 
        AND 
    !$Request->hasNoCacheHeader() 
        AND 
    $cachedResponse = LinkedDataApiCache::hasCachedResponse($Request)
    )
{
	logDebug("Found cached response");
	if (isset($Request->ifNoneMatch) && $cachedResponse->eTag == $Request->ifNoneMatch)
	{
		logDebug("ETag matched, returning 304");
		$Response = new Response304($cachedResponse);
	}
	else if (isset($Request->ifModifiedSince) && $cachedResponse->generatedTime <= $Request->ifModifiedSince)
	{
		logDebug("Last modified date matched, returning 304");
		$Response = new Response304($cachedResponse);
	}
	else
	{
		logDebug("Re-Serving cached response");
		$Response = $cachedResponse;
	}
}
else
{
	logDebug("Generating fresh response");

    $files = glob('api-config-files/*.ttl');
    
    /*
	keep the config graph that matches the request, and keep a 'complete' configgraph to serve if none match
    */
        $CompleteConfigGraph = new ConfigGraph(null, $Request);
	foreach($files as $file){
	  logDebug("Iterating over files in /api-config: $file"); 
		if($ConfigGraph = LinkedDataApiCache::hasCachedConfig($file)){
			logDebug("Found Cached Config");
			$CompleteConfigGraph->add_graph($ConfigGraph);
			$ConfigGraph->setRequest($Request);

		} else {
			logDebug("Checking Config file: $file");
			$rdf = file_get_contents($file);
			$CompleteConfigGraph->add_rdf($rdf);
			$ConfigGraph =  new ConfigGraph(null, $Request);
			$ConfigGraph->add_rdf($rdf);
			logDebug("Caching $file");
			LinkedDataApiCache::cacheConfig($file, $ConfigGraph);
		}
		$ConfigGraph->init();
		
		if($selectedEndpointUri = $ConfigGraph->getEndpointUri()){
			logDebug("Endpoint Uri Selected: $selectedEndpointUri");
		   /* 	$ConfigGraph =  new ConfigGraph(null, $Request);
			if($CachedConfig){
				$ConfigGraph = $CachedConfig;			
				$ConfigGraph->setRequest($Request);
			} else {
				$ConfigGraph->add_rdf($rdf);
								
			}
    			$ConfigGraph->init(); */
			unset($CompleteConfigGraph);
		    	$Response =  new LinkedDataApiResponse($Request, $ConfigGraph);
        		$Response->process();
			break;
		}
	}
	if(!isset($selectedEndpointUri)){	
	    $Response =  new LinkedDataApiResponse($Request, $CompleteConfigGraph);
	    if($Request->getPathWithoutExtension()==CONFIG_PATH){
	        $Response->serveConfigGraph();
	    } else {
	        $Response->process();
	    }

	}
}

$Response->serve();
if (defined("PUELIA_SERVE_FROM_CACHE") AND  $Response->cacheable)
{
	LinkedDataApiCache::cacheResponse($Request, $Response);
}
?>
