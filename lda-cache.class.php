<?php

require_once 'lda.inc.php';

class LinkedDataApiCachedResponse
{
	var $eTag;
	var $generatedTime;
	var $lastModified;
	var $mimetype;
	var $body;
	var $cacheable;

	function serve()
	{
		header("Content-type: {$this->mimetype}");
		header("Last-Modified: {$this->lastModified}");
		header("ETag: {$this->eTag}");
		header("x-served-from-cache: true");
		echo $this->body;
	}
}

class LinkedDataApiCache
{
	public static function hasCachedResponse(LinkedDataApiRequest $request)
	{
		if(!function_exists("memcache_connect")) return false;
		$acceptableTypes = $request->getAcceptTypes();
		$uri = $request->uri;
		foreach ($acceptableTypes as $mimetype)
		{
			$key = LinkedDataApiCache::cacheKey($uri, $mimetype);
			//todo make memcache host and port configurable
			$mc = memcache_connect("localhost", 11211);
			$cachedObject = $mc->get($key);
			if ($cachedObject)
			{
				logDebug("Found a cached response for $mimetype under key $key");
				return $cachedObject;
			} 
			logDebug("No cached response for $mimetype under key $key");
		}
		logDebug('No suitable cached responses found');
		return false;
	}

	public static function cacheResponse(LinkedDataApiRequest $request, LinkedDataApiResponse $response)
	{
		if(!function_exists("memcache_connect")) return false;
		$cacheableResponse = new LinkedDataApiCachedResponse();
		$cacheableResponse->eTag = $response->eTag;
		$cacheableResponse->generatedTime = $response->generatedTime;
		$cacheableResponse->lastModified = $response->lastModified;
		$cacheableResponse->mimetype = $response->mimetype;
		$cacheableResponse->body = $response->body;

		$key = LinkedDataApiCache::cacheKey($request->uri, $cacheableResponse->mimetype);
		logDebug('Caching Response as '.$key.' with mimetype '.$cacheableResponse->mimetype);
		//todo make memcache host and port configurable
		$mc = memcache_connect("localhost", 11211);
		$mc->add($key, $cacheableResponse, false, PUELIA_CACHE_AGE);
	}

	public static function cacheConfig($filepath, ConfigGraph $configgraph){
		if(!function_exists("memcache_connect")) return false;
		
		logDebug('Caching '.$filepath);
		$key = LinkedDataApiCache::configCacheKey($filepath);
		//todo make memcache host and port configurable
		$mc = memcache_connect("localhost", 11211);
		$mc->add($key, $configgraph, false );
		return $key;
	}

	public static function hasCachedConfig($filepath){
		
		logDebug("Looking in memcache for $filepath");	
		if(!function_exists("memcache_connect")) return false;
		$key = LinkedDataApiCache::configCacheKey($filepath);
		//todo make memcache host and port configurable
		$mc = memcache_connect("localhost", 11211);
		$cachedObject = $mc->get($key);
		if ($cachedObject)
		{
			return $cachedObject;
		} 
		logDebug("No cached version of ConfigGraph from $filepath");
		return false;
	}

	public static function configCacheKey($filepath){
		$mtime = filemtime($filepath);	
		return md5($filepath.$mtime);
	}

	private static function cacheKey($requestUri, $mimetype)
	{
		$key  = $requestUri;
		$key .= trim($mimetype);
		return md5($key);
	}
	
}
