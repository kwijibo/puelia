<?php
require_once '../lda-response.class.php';
require_once '../lda-request.class.php';

class lda-responseTest extends PHPUnit_Framework_TestCase {
    
    var $Response  = false;
    
    function setUp(){
        $this->Response = new lda-response();
    }
    
    function tearDown(){
        $this->Response = false;
    }
    
    function test_chooseOutput(){
        $this->Response->chooseOutputFormat();
    }
    
}
?>