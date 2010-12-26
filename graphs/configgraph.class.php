<?php
require_once 'exceptions.inc.php';
require_once 'lda.inc.php';
require_once 'lib/moriarty/moriarty.inc.php';
require_once 'graphs/pueliagraph.class.php';
require_once 'lib/moriarty/httprequestfactory.class.php';

define('uriTemplateVariableRegex', '@\{([a-zA-Z0-9_\.]+)\}@');

class ConfigGraph extends PueliaGraph {

    private $_endPointRequestVariables = array();

    private $_request;

    private $_endpointUri;
    private $_uriTemplate;
    private $_paramVariableBindings;
    private $_pathVariableBindings;
    private $_allVariableBindings;
    
    
    var $apiUri = false;
    var $prefixesFromLoadedTurtle = array();
    var $_vocab = null;
        
    function __construct($rdf, $request){
        $this->_request = $request;
        parent::__construct($rdf);
                
        // built-in formatters
        $this->add_literal_triple(API.'JsonFormatter',  API.'mimeType', 'application/json');
        $this->add_literal_triple(API.'JsonFormatter',  API.'name', 'json');
        $this->add_literal_triple(API.'RdfJsonFormatter',  API.'mimeType', 'application/json');
        $this->add_literal_triple(API.'RdfJsonFormatter',  API.'mimeType', 'application/x-rdf+json');
        $this->add_literal_triple(API.'RdfJsonFormatter',  API.'name', 'rdfjson');
        $this->add_literal_triple(API.'XmlFormatter',   API.'mimeType', 'application/xml');
        $this->add_literal_triple(API.'XmlFormatter',   API.'name', 'xml');
        $this->add_literal_triple(API.'TurtleFormatter', API.'mimeType', 'text/turtle');
        $this->add_literal_triple(API.'TurtleFormatter', API.'name', 'ttl');
        $this->add_literal_triple(API.'RdfXmlFormatter', API.'mimeType', 'application/rdf+xml');
        $this->add_literal_triple(API.'RdfXmlFormatter', API.'name', 'rdf');
        $this->add_resource_triple(API.'JsonFormatter',  RDF.'type', API.'Formatter');
        $this->add_resource_triple(API.'XmlFormatter',  RDF.'type', API.'Formatter');
        $this->add_resource_triple(API.'RdfXmlFormatter',  RDF.'type', API.'Formatter');
        $this->add_resource_triple(API.'TurtleFormatter',  RDF.'type', API.'Formatter');
        $this->add_resource_triple(API.'RdfJsonFormatter',  RDF.'type', API.'Formatter');


        //built-in viewer: basic
        $this->add_resource_triple(API.'basicViewer', RDF_TYPE, API.'Viewer');
        $this->add_literal_triple(API.'basicViewer', API.'name', "basic");
        $this->add_resource_triple(API.'basicViewer', API.'property', RDF_TYPE);
        $this->add_resource_triple(API.'basicViewer', API.'property', RDFS_LABEL);
        
        //built-in viewer: describe
        $this->add_resource_triple(API.'describeViewer', RDF_TYPE, API.'Viewer');
        $this->add_literal_triple(API.'describeViewer', API.'name', "description");
        $this->add_literal_triple(API.'describeViewer', API.'properties', "*");


        //built-in viewer: labelled describe
        $this->add_resource_triple(API.'labelledDescribeViewer', RDF_TYPE, API.'Viewer');
        $this->add_literal_triple(API.'labelledDescribeViewer', API.'name', "all");
        $this->add_literal_triple(API.'labelledDescribeViewer', API.'properties', "*.label");
        $this->add_literal_triple(RDFS_LABEL, API.'label', "label");

        
    }

	/* added so Config Can be reused between Requests */

	function setRequest($Request){
		$this->_request = $Request;
	}


    function init(){
        $this->apiUri = false;
        $this->_endpointUri = false;
        $this->_uriTemplate = false;
        $this->_allVariableBindings = false;
        $this->_paramVariableBindings = false;
        $this->_pathVariableBindings = false;
        $this->_vocab = false;
        $this->_endPointRequestVariables = false;
        $this->PropertyLabels = false;
        $this->apiUri = $this->getApiUri();

        $endpointInfo = $this->getEndpointMatchingRequest();

        $this->_endpointUri = $endpointInfo['endpoint'];
        $this->_uriTemplate = $endpointInfo['uriTemplate'];

        $this->_paramVariableBindings = $endpointInfo['variableBindings']['paramBindings'];
        $this->_pathVariableBindings = $endpointInfo['variableBindings']['pathBindings'];
    }
    
    function resetApiAndEndpoint($api='_:resetAPI', $endpoint='_:resetEndpoint'){
        $this->_endpointUri = $endpoint;
        $this->apiUri = $api;
    }
    
    function getListEndpoints(){
        return $this->getEndpointsByType(API.'ListEndpoint');    }
    function getItemEndpoints(){
        return $this->getEndpointsByType(API.'ItemEndpoint');
    }
    function getEndpointsByType($type){
        $endpoints = array();
        foreach ($this->get_resource_triple_values($this->apiUri, API.'endpoint') as $endpointUri){
            if($this->has_resource_triple($endpointUri, RDF_TYPE, $type)){
                $endpoints[]=$endpointUri;
            }
        }
        return $endpoints;
    }
    


    
    function getPrefixesFromLoadedTurtle(){
        return $this->prefixesFromLoadedTurtle;
    }

    function add_rdf($rdf=false, $base=''){
        preg_match_all('/@prefix\s+(.+?):\s+<(.+?)>/', $rdf, $m);
        foreach($m[1] as $n => $prefix){
            $this->prefixesFromLoadedTurtle[$prefix] = $m[2][$n];
        }
        
        parent::add_rdf($rdf, $base);
    }


    function getApiUri(){
        
        if($this->apiUri) return $this->apiUri;
        
        $requestUri = $this->_request->getUri();
        $api_subjects = $this->get_subjects_of_type(API.'API');
        foreach($api_subjects as $s){
            $configBase = $this->get_first_literal($s, API.'base');
            if(!empty($configBase)){
                $requestUri = rtrim($requestUri, '/');
                $configBase = rtrim($configBase, '/');
                if(strpos($requestUri, $configBase)===0){
                    $this->apiUri = $s;
                    return $s;
                }
            }
        }
        return false;
    }
    
    function getApisWithoutBase(){
        $api_subjects = $this->get_subjects_of_type(API.'API');
        $return = array();
        foreach($api_subjects as $s){
            if(! $apiBase = $this->get_first_literal($s, API.'base')){
                $return[]=$s;
            }
        }
        return $return;
    }
    
    
    function getEndpointMatchingRequest(){
        $apiSubject = $this->getApiUri();
		
        if(!$apiSubject){
            $endpoints = array();
            $api_subjects = $this->getApisWithoutBase();
            foreach($api_subjects as $s){
                $endpoints = array_merge($endpoints, $this->get_resource_triple_values($s, API.'endpoint'));            
            }

        } else {
            $endpoints = $this->get_resource_triple_values($apiSubject, API.'endpoint');            
        }
        foreach($endpoints as $endpoint){
            foreach($this->get_literal_triple_values($endpoint, API.'uriTemplate') as $uriTemplate){
                if($variableBindings = $this->getRequestMatchesFromUriTemplate($uriTemplate)){
                    $this->apiUri = array_pop($this->get_subjects_where_resource(API.'endpoint', $endpoint));
                    $this->_endPointRequestVariables[$endpoint] = $variableBindings;
                    return array('endpoint' => $endpoint, 'uriTemplate' => $uriTemplate, 'variableBindings'=> $variableBindings);
                }                 
            }
        }
        return false;
    }
    
    function getRequestMatchesFromUriTemplate($uriTemplate){

        $path = $this->_request->getPathWithoutExtension();


        /* if an api:base is set, strip it from the request URI */
        if($this->getApiUri() AND $apiBase = $this->get_first_literal($this->getApiUri(), API.'base')){
            $fullRequestUriWithoutExtension = $this->_request->getBase().$path;
            $path = str_replace($apiBase, '', $fullRequestUriWithoutExtension);
        } else { 
			/* strip install subdir */
			$path = str_replace($this->_request->getInstallSubDir(), '', $path);
		}

        $params = $this->_request->getParams();
        

        $pathTemplate = $this->getPathTemplate($uriTemplate);
        $parameterTemplates = $this->getParameterTemplates($uriTemplate);
        
        $paramMatches = $this->paramsTemplateMatches($parameterTemplates, $params);
        
        $pathMatches = $this->pathTemplateMatches($pathTemplate, $path);

        if(
            $pathMatches !== false
            AND
            $paramMatches !== false
        ){
            return array('paramBindings' => $paramMatches, 'pathBindings' => $pathMatches);
        } else {
            
            return false;
        }
        
    }
    
    function getParamVariableBindings(){
        return $this->_paramVariableBindings;
    }

    function getPathVariableBindings(){
        return $this->_pathVariableBindings;
    }

    
    function paramsTemplateMatches($templates, $params){        
        $variables = array();
        foreach($templates as $k => $v){
            if(isset($params[$k]) AND (preg_match(uriTemplateVariableRegex, $v, $m) OR ($v==$params[$k])  )){
                $variables[$m[1]] = array('value' => $params[$k]);
            } else {
                return false;
            }
        }
        if(!empty($templates) AND count($variables) < count($templates)) return false;
        return $variables;
    }
    


    /**
     * pathTemplateMatches - returns an array of variables if template matches path
     *
     * @return array
     * @author Keith Alexander
     **/
    function pathTemplateMatches( $template, $path){
        
        preg_match_all(uriTemplateVariableRegex, $template, $ms);
        $templateVariables = array();
        $uriTemplateRegex = $template;
        if(!empty($ms[0])){
            foreach($ms[0] as $n =>  $match){
                $templateVariables[]=$ms[1][$n];
                $uriTemplateRegex = str_replace($match, '([^/]+?)', $uriTemplateRegex);
            }
        }
        if(preg_match('@^'.$uriTemplateRegex.'$@', $path, $pathMatches)){
            $variableValuesFromPath = array();
            foreach($templateVariables as $n => $templateVariable){
                $variableValuesFromPath[$templateVariable] = array('value'=> rawurldecode($pathMatches[$n+1]));
            }
            return $variableValuesFromPath;
        } else {
            return false;
        }
    }
    
    function getPathTemplate($template){
        return array_shift(explode('?', $template));
    }
    
    function getParameterTemplates($template){
        $query = parse_url($template, PHP_URL_QUERY);
        $params = queryStringToParams($query);
        return $params;
    }
    
    
    function getEndpointUri(){
        return $this->_endpointUri;
    }
    
    function getEndpointConfigVariableBindings(){
        $endpointUri = $this->getEndpointUri();
        $variableBindings = array();
        foreach($this->get_resource_triple_values($endpointUri, API.'variable') as $variableUri){
            $name = $this->get_first_literal($variableUri, API.'name');
            $value = $this->get_first_literal($variableUri, API.'value');
            $variableBindings[$name]['value'] = $value;
            if($type = $this->get_first_resource($variableUri, API.'type')){
                $variableBindings[$name]['type'] = $type;                    
            }
        }
        return $variableBindings;
        
    }
    
    function getApiConfigVariableBindings(){
        $endpointUri = $this->getEndpointUri();
        $endpointApiUris = $this->get_subjects_where_resource(API.'endpoint', $endpointUri);
        $variableBindings = array();
        foreach($endpointApiUris as $apiUri){
            foreach($this->get_resource_triple_values($apiUri, API.'variable') as $variableUri){
                $name = $this->get_first_literal($variableUri, API.'name');
                $value = $this->get_first_literal($variableUri, API.'value');
                $variableBindings[$name]['value'] = $value;
                if($type = $this->get_first_resource($variableUri, API.'type')){
                    $variableBindings[$name]['type'] = $type;                    
                }
            }
        }
        return $variableBindings;
    }
    
    function bindVariablesInValue($value, $variables){
        foreach($variables as $name => $props){
            $value = str_replace('{'.$name.'}', $props['value'], $value);
        }
        return $value;
    }
    
    function getCompletedItemTemplate(){
        $itemTemplate = $this->getEndpointItemTemplate();
        $bindings = $this->getAllProcessedVariableBindings();
        $filledInTemplate = $this->bindVariablesInValue($itemTemplate, $bindings);
        return $filledInTemplate;
    }


    /**
     * dataUriToEndpointItem
     * takes a URI from the data, and transforms it into a URI one of the ItemEndpoints knows how to handle - if it does.
     * @param $dataUri
     * @return URL Path
     * @author Keith Alexander
     **/


    function dataUriToEndpointItem($dataUri){
	  $subjects = $this->getItemEndpoints();		
	  foreach($subjects as $s){
		foreach($this->get_subject_properties($s) as $p){
			if($p==API.'itemTemplate'){
				$itemTemplate = $this->get_first_literal($s, $p);
				$dataPath = parse_url($dataUri, PHP_URL_PATH);
				$uriTemplate = $this->get_first_literal($s, API.'uriTemplate');		
				$itemPathTemplate = parse_url($itemTemplate, PHP_URL_PATH);
                if($matches = $this->pathTemplateMatches($itemPathTemplate, $dataPath )){
					$endpointItem =  $this->bindVariablesInValue($uriTemplate, $matches);		
                    return $endpointItem;
				}
			}
		}
	  }
	  return false;
    }

    function getRequestVariableBindings(){
        $bindings= array();
        $langBindings = array();
        foreach($this->_request->getUnreservedParams() as $k => $v){
            if (strpos($k, 'lang-') === 0) {
                $langBindings[substr($k, 5)] = $v;
            } else {
                $bindings[$k] = array('value' => $v);
            }
        }
        foreach($langBindings as $k => $v) {
            if (array_key_exists($k, $bindings)) {
                $bindings[$k]['lang'] = $v;
            }
        }
        return $bindings;
    }
    
    function getAllProcessedVariableBindings(){
        $bindings = $this->getAllVariableBindings();
        foreach($bindings as $name => $value){
            if($name=='callback'){
                throw new ConfigGraphException("'callback' is reserved and cannot be used as a variable name");
            }
          $bindings[$name]['value']  = $this->processVariableBinding($name, $bindings);
        }
        return $bindings;
    }
    
    function processVariableBinding($name, $bindings, $history=array()){
        if(in_array($name, $history)){
            throw new ConfigGraphException("The variable '$name' has a circular dependency and cannot be resolved");
        }                
        $history[]=$name;    
        $val = $bindings[$name]['value'];
        $varNames = $this->variableNamesInValue($val);
        if(is_array($varNames)){
            foreach($varNames as $var){
                $bindings[$var]['value'] = $this->processVariableBinding($var, $bindings, $history);
            }
            return $this->bindVariablesInValue($val, $bindings);
        } else {
            return $val;
        }
    }
    
    function variableNamesInValue($val){
        if(preg_match_all(uriTemplateVariableRegex, $val, $matches)){
            return $matches[1];
        } else {
            return false;
        }

    }
    
    function getAllVariableBindings(){
        if(empty($this->_allVariableBindings)){
            $this->_allVariableBindings = array_merge(
                $this->getApiConfigVariableBindings(),
                $this->getPathVariableBindings(),
                $this->getParamVariableBindings(),
                $this->getRequestVariableBindings(),
                $this->getEndpointConfigVariableBindings()
            );
        }
        return  $this->_allVariableBindings;
        
    }
    
    function getEndpointItemTemplate(){
        $endpointUri = $this->getEndpointUri();
        return $this->get_first_literal($endpointUri, API.'itemTemplate');
    }
    
	function getDatasetUri(){
		if($uri = $this->get_first_resource($this->getEndpointUri(), API.'dataset')) return $uri;
		else if($uri = $this->get_first_resource($this->getApiUri(), API.'dataset')) return $uri;
		else return false;
	}

    function getSelectorUri(){
        return $this->get_first_resource($this->getEndpointUri(), API.'selector');
    }
    
    function getSelectQuery(){
        return $this->get_first_literal($this->getSelectorUri(), API.'select');
    }
    
    function getSelectWhere(){
        return $this->get_first_literal($this->getSelectorUri(), API.'where');
    }

    function getSelectFilter(){
        return $this->get_first_literal($this->getSelectorUri(), API.'filter');
    }

    function getOrderBy(){
        return $this->get_first_literal($this->getSelectorUri(), API.'orderBy');
    }

    function getSort(){
        return $this->get_first_literal($this->getSelectorUri(), API.'sort');
    }

    function getApiDefaultFormatter(){
        return $this->get_first_resource($this->getApiUri(), API.'defaultFormatter');
    }

    function getEndpointDefaultFormatter(){
        return $this->get_first_resource($this->getEndpointUri(), API.'defaultFormatter');
    }

    function getDefaultMimeTypes() {
      $formatter = $this->getEndpointDefaultFormatter();
      if (!$formatter) {
        $formatter = $this->getApiDefaultFormatter();
      }
      return $this->get_literal_triple_values($formatter, API.'mimeType');
    }

    function getMimeTypesOfFormatterByName($formatName){
        $formatterUri = $this->getFormatterUriByName($formatName);
        return $this->get_literal_triple_values($formatterUri, API.'mimeType');
    }
    
    function getXsltStylesheetOfFormatterByName($formatName){
        $formatterUri = $this->getFormatterUriByName($formatName);
        return $this->get_first_literal($formatterUri, API.'stylesheet');        
    }
    
    function getFormatterTypeByName($formatName){
        $formatterUri = $this->getFormatterUriByName($formatName);
        return $this->get_first_resource($formatterUri, RDF.'type');                
    }
    
    function getInheritedSelectFilters(){
        $filters = array();
        foreach($this->get_resource_triple_values($this->getSelectorUri(), API.'parent') as $parent){
            $filter = $this->get_first_literal($parent, API.'filter');
            if(!$filter){
                $selectUri = $this->getSelectorUri();
               throw new ConfigGraphException("<{$selectUri}> has a parent (<{$parent}>) which doesn't use the api:filter property.");
            }
            $filters[]=$filter;
        }
        return $filters;
    }

    function getAllFilters(){
        return array_merge(array($this->getSelectFilter()), $this->getInheritedSelectFilters());
    }
    
    function getMaxPageSize(){
        return $this->get_first_literal($this->getApiUri(), API.'maxPageSize');
    }
    
    function getApiDefaultPageSize(){
        return $this->get_first_literal($this->getApiUri(), API.'defaultPageSize');
    }
    function getEndpointDefaultPageSize(){
        return $this->get_first_literal($this->getEndpointUri(), API.'defaultPageSize');
    }

    function getApiDefaultLangs(){
        return $this->get_first_literal($this->getApiUri(), API.'lang');
    }
    
    function getEndpointDefaultLangs(){
        return $this->get_first_literal($this->getEndpointUri(), API.'lang');
    }
    
    function getVocabularies(){
        return $this->get_resource_triple_values($this->getApiUri(), API.'vocabulary');
    }
    
    function getSparqlEndpointUri(){
        if($uri = $this->get_first_resource($this->getApiUri(), API.'sparqlEndpoint')){
            return $uri;
        } else {
            throw new ConfigGraphException("No sparqlEndpoint was specified for <".$this->getApiUri().">");
        }
        
    }
    
    function getViewers(){
        $viewers = array_merge(
            $this->get_resource_triple_values($this->getApiUri(), API.'viewer'),
            $this->get_resource_triple_values($this->getApiUri(), API.'defaultViewer'),
            $this->get_resource_triple_values($this->getEndpointUri(), API.'viewer'),
            $this->get_resource_triple_values($this->getEndpointUri(), API.'defaultViewer')
        );
        return $viewers;
        return array_filter($viewers);
    }
    
    function getViewerByName($name){
        $viewers = $this->getViewers();
        foreach($viewers as $uri){
            if($this->has_literal_triple($uri, API.'name', $name)){
                return $uri;
            }
        }
        if($name=='all'){
            return API.'labelledDescribeViewer' ;
        } else if($name=='description'){
            return API.'describeViewer';
        } else if($name=='basic'){
            return API.'basicViewer';
        }
    }
    
    function getApiDefaultViewer(){
        return $this->get_first_resource($this->getApiUri(), API.'defaultViewer');
    }
    
    function getEndpointDefaultViewer(){
        return $this->get_first_resource($this->getEndpointUri(), API.'defaultViewer');
    }
    
    function getVocabularyGraph(){
        $requestFactory = new HttpRequestFactory();
        if(!empty($this->_vocab)){
            return $this->_vocab;
        } 
        else {
                $this->_vocab = new VocabularyGraph();
                $vocabUris = $this->getVocabularies();
                $vocabUris = array_unique($vocabUris);
                if(!empty($vocabUris)){
                    foreach($this->getVocabularies() as $vocab){
                        $vocabUrl = preg_replace('/#.*/', '', $vocab);
                        $request = $requestFactory->make('GET', $vocabUrl);
                        $request->set_accept('application/rdf+xml,application/turtle,text/turtle,text/rdf+n3,application/xml,text/plain,text/html,*/*');
                        $response = $request->execute();
                        if($response->is_success()){
                          $this->_vocab->add_rdf($response->body);
                          logDebug("Loaded vocabulary: {$vocab}");  
                        } 
                        else {
                            throw new ConfigGraphException("The vocabulary {$vocabUrl} could not be fetched. a GET returned a {$response->status_code}");
                        }
                    }
                }
                return $this->_vocab;
            }
    }
    
    function getEndpointType(){
        if(!$this->getEndpointUri()) return false;
        
        if($types = $this->get_resource_triple_values($this->getEndpointUri(), RDF_TYPE)){
            if(in_array(API.'ItemEndpoint', $types)){
                return API.'ItemEndpoint';
            } else if(in_array(API.'ListEndpoint', $types)){
                return API.'ListEndpoint';
            }
        }
        $itemTemplate = $this->getEndpointItemTemplate();
        if(!empty($itemTemplate)){
            return API.'ItemEndpoint';
        } 
    }
    
    function getDisplayPropertiesOfViewer($viewerUri){
        $properties =  $this->get_resource_triple_values($viewerUri, API.'property');
        $propertiesNotLists = array();
        foreach($properties as $p){
            if(!$this->resource_is_a_list($p)){
                $propertiesNotLists[]=$p;
            }
        }
        return $propertiesNotLists;
    }
    
    function getDisplayPropertyChainsOfViewer($viewerUri){
        $properties =  $this->get_resource_triple_values($viewerUri, API.'property');
        $chains = array();
        foreach($properties as $p){
            if($this->resource_is_a_list($p)){
                $chains[]=$this->list_to_array($p);
            } else {
                $chains[] = array($p);
            }
        }
        return $chains;
    }
    
    function getAllViewerPropertyChains($viewerUri){
        $currentViewerChains = $this->getDisplayPropertyChainsOfViewer($viewerUri);
        if($includedViewers = $this->get_resource_triple_values($viewerUri, API.'include')){
            foreach($includedViewers as $includeViewerUri){
                $includeChain = $this->getDisplayPropertyChainsOfViewer($includeViewerUri);
                foreach($includeChain as $chain){
                    $currentViewerChains[]=$chain;
                }
            }
        }
        return $currentViewerChains;
    }
    
    function list_to_array($listUri){
        $array = array();
        while(!empty($listUri) AND $listUri != RDF.'nil'){
            $array[]=$this->get_first_resource($listUri, RDF_FIRST);
            $listUri = $this->get_first_resource($listUri, RDF_REST);
        }
        return $array;
    }
    
    function resource_is_a_list($uri){
        if($this->has_resource_triple($uri, RDF_TYPE, RDF_LIST)){
            return true;
        } else if($this->get_first_resource($uri, RDF_FIRST)){
            return true;
        } else {
            return false;
        }
    }
    
    function getViewerTemplate($viewerUri){
        return $this->get_first_literal($viewerUri, API.'template');
    }
    
    function getViewerDisplayPropertiesValueAsPropertyChainArray($viewerUri){
            $vocab = $this->getVocabularyGraph();
            $viewerDisplayPropertiesValue = $this->get_first_literal($viewerUri, API.'properties');
            return $this->propertiesStringToArray($viewerDisplayPropertiesValue);
    }
    
    function getRequestPropertyChainArray(){
        $chainString = $this->_request->getParam('_properties');
        return $this->propertiesStringToArray($chainString);
    }
    
    function propertiesStringToArray($chainString){
        if(empty($chainString)){
             return array();
        } else {
            $chains = array();
            $sections = explode(',',$chainString);
            foreach($sections as $section){
                $chain = array();
                $names = explode('.', $section);
                foreach($names as $name){
                    $propertyUri = $this->getUriForVocabPropertyLabel($name);
                    if(empty($propertyUri)) throw new UnknownPropertyException($name);
                    $chain[]=$propertyUri;
                }
                $chains[]=$chain;
            }
            return $chains;
        }        
    }
    

	function getProjectionPropertyBindings(){
		$selectorUri = $this->getSelectorUri();
		if(!$selectorUri) throw new Exception("No Selector Uri available");
		$bindingUris = $this->get_resource_triple_values($selectorUri, API.'projectionPropertyBinding');
		$bindings = array();
		foreach($bindingUris as $bUri){
			$bindings[]=array(
				'varName' => $this->get_first_literal($bUri, API.'sparqlVarName'),
				'targetProperty' => $this->get_first_resource($bUri, API.'projectionTargetProperty'),
			);
		}
		return $bindings;
	}

    function getApiContentNegotiation(){
          return $this->get_first_resource($this->getApiUri(), API.'contentNegotiation');      
    }
    
    function apiSupportsFormat($format){
        if($uri = $this->getFormatterUriByName($format)){
            return true;
        } else {
            return false;
        }
    }
    
    function getFormatters(){
        
        $formatters = array( # builtins
            'rdf' => API.'RdfXmlFormatter',
            'ttl' => API.'TurtleFormatter',
            'json' => API.'JsonFormatter',
            'xml' => API.'XmlFormatter',
            );

        if($apiDefaultUri = $this->getApiDefaultFormatter()){
            $apiDefaultName = $this->get_first_literal($apiDefaultUri, API.'name');
            $formatters[$apiDefaultName] = $apiDefaultUri;
        }
        foreach($this->get_resource_triple_values($this->getApiUri(), API.'formatter') as $formatterUri){
            $name = $this->get_first_literal($formatterUri, API.'name');
            $formatters[$name] = $formatterUri;
        }
        if($endpointDefaultUri = $this->getEndpointDefaultFormatter()){
            $endpointDefaultName = $this->get_first_literal($endpointDefaultUri, API.'name');
            $formatters[$endpointDefaultName] = $endpointDefaultUri;
        }
        foreach($this->get_resource_triple_values($this->getEndpointUri(), API.'formatter') as $formatterUri){
            $name = $this->get_first_literal($formatterUri, API.'name');
            $formatters[$name] = $formatterUri;
        }
        return $formatters;
    }
    
    function getFormatterUriByName($name){
        return array_shift($this->get_subjects_where_literal(API.'name', $name));
    }
        
    function getUriForVocabPropertyLabel($label){
        if ($label == '*') {
            return API.'allProperties';
        } else if($configLabelUri = $this->getUriForPropertyLabel($label)){
            return $configLabelUri;
        } else {
            return $this->getVocabularyGraph()->getUriForPropertyLabel($label);
        }
    }
    
    function getVocabPropertyRange($uri){
        if($range = $this->getPropertyRange($uri)){
            return $range;
        } else {
            return $this->getVocabularyGraph()->getPropertyRange($uri);
        }
    }
    
    function getVocabPropertyLabels(){
        return array_merge($this->getPropertyLabels(), $this->getVocabularyGraph()->getPropertyLabels());
    }
    
}

?>
