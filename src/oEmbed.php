<?php

/* 
 * Copyright (C) 2016 Ullashnetwork
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Main class of PHPOEmbed.
 * 
 * @author Akash Bose <bose@ullashkolkata.com>
 * @since Jan 15, 2016 [10:55:12 PM]
 */
class PHPOEmbed{
  
  private $url;  
  private $parsedUrl;

  private static $timeout = 20;
  private static $providers = array();
  private static $limitDesc = 400;
  private static $userAgent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/47.0.2526.106 Safari/537.36';
  
  /**
   * 
   * @param string $url
   */
  private function addUrl( $url ){
      
      $url = trim($url);
      
      if ( strpos($url, 'http') !== 0 ){
          $url = 'http://' . $url;
      }      
      $this->url = $url;
      $this->parsedUrl = parse_url($url);      
  }
  
  /**
   *   
   * @param string $k 
   * @return mixed
   */
  private function getEmbed( $k ){
      
      $cApi = preg_replace('/:url/i', $this->url, self::$providers[$k]['api']);            
      return $this->getUrlContent($cApi);
  }
  
  /**
   * Get content from an url.
   * 
   * This method uses curl to grab the website content.
   * Requests are sent as humanly as possible.
   * 
   * @param string $url
   * @return string
   */
  private function getUrlContent( $url ){
      
      /**
       * First init the curl.
       */
      $curlI = curl_init();
      
      /*
       * Now set the options. one by one.
       */
      curl_setopt($curlI, CURLOPT_AUTOREFERER, true);
      curl_setopt($curlI, CURLOPT_SSL_VERIFYPEER, false); //ToDo verify ssl to secure man in the middle attack.      
      curl_setopt($curlI, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($curlI, CURLOPT_TIMEOUT, self::$timeout);
      curl_setopt($curlI, CURLOPT_ENCODING, 'gzip,deflate');
      
      //set follow automatic location to false
      curl_setopt($curlI, CURLOPT_FOLLOWLOCATION, false);
      
      //we also want the response header back.
      curl_setopt($curlI, CURLOPT_HEADER, true);

      //set up the request header.
      $header = array(
          'Accept: text/html,application/xhtml+xml,application/xml;q=0.8,image/jpg,*/*;q=0.5',
          'Accept-Language:en-US,en;q=0.8',          
          'Cache-Control:max-age=0',
          'Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7',
          'Connection:keep-alive',          
          'User-Agent: ' . self::$userAgent
      );

      //now set the header.
      curl_setopt($curlI, CURLOPT_HTTPHEADER, $header);
      
      //finalyy set the url.
      curl_setopt($curlI, CURLOPT_URL, $url);
      
      $content = curl_exec($curlI);
      
      $headerCode = curl_getinfo($curlI, CURLINFO_HTTP_CODE);
      
      //we will later use the header size to subtract header from
      //content.
      $headerSize = curl_getinfo($curlI, CURLINFO_HEADER_SIZE);
      
      curl_close($curlI);
      
      //get the header.
      $h = substr($content, 0, $headerSize);
      
      //get the body  to return
      $body = substr($content, $headerSize);
      
      if ( $headerCode == 301 || $headerCode == 302 ){
          
          //grab the redirect url.
          $matches = array();
          preg_match('/(?:Location:|URI:) (.*)/i', $h, $matches);          
          $newUrl = trim($matches[1]);
          
          if ( !empty($newUrl) && filter_var($newUrl, FILTER_VALIDATE_URL) ) {
              
              //now we have to change the current url to redirected one.
              $this->addUrl($newUrl);
              $body = $this->getUrlContent($newUrl);
          } 
      }
    
      return $body;
  }
  
  /**
   * Check if it matches any provider given
   * @return boolean
   */
  private function checkForMatchedPattern(){
      
    foreach ( self::$providers as $key => $provider ){

        foreach ( $provider['pattern'] as $p ){
            if (preg_match($p, $this->url) ){                
                return $key;
            }
        }
    }

    return false;
  }
 
  /**
   * Get the type of an url
   * 
   * @return string
   */
  private function getUrlType(){
      $uInfo = $this->parsedUrl;
      
      if ( empty( $uInfo['path'] ) ){
          return 'link';
      }
      
      $e = explode('.', $uInfo['path']);
      
      switch ( strtolower( end($e) ) ){
          case 'gif':
          case 'jpeg':
          case 'jpg':
          case 'png':
              return 'photo';
          default: 
              return 'link';
      }
      
  }
  
  /**
   * This is a simple method to covert encoding.
   * 
   * @param string $string string to converts 
   * @param string $encoding encoding type. eg, `UTF-8`.
   * @return string encoded string.
   */
  private function convertStringEncoding( $string, $encoding = 'UTF-8' ){
      
      return mb_convert_encoding($string, $encoding, mb_detect_encoding($string, "auto"));
      
  }
  
  /**
   * This method is called by `parseUrl()` method.
   * 
   * This method will be called if there is no provider pattern found for 
   * the given url. It will get and filter out the data depending on 
   * the type.   
   *
   * @return mixed it returns the json encoded response or null on 
   * nothing, which is unlikely.
   */
  private function fetchDefault(){
       
      $content = $this->getUrlContent($this->url);            
      $type = $this->getUrlType();      
      
      if ( $type == 'photo' ){
          return json_encode(array(
              'type' => 'photo',
              'url' => $this->url,
              'href' => $this->url,
              'version' => '1.0'
          ));
      }
      
      if ( $type == 'link' ){
          return $this->filterContent( $content );
      }
      
      return null;
  } 
  
  /**
   * It filters out the content for embed.
   * 
   * @param string $content give the website content as string.
   * @return mixed
   */
  private function filterContent( $content ){      
        //ToDo may be grab open graph properties.
        //try to get the website title
        $matches = array();
        preg_match('/<\s*title[^>]*>([\s\S]*?)<\s*\/\s*title\s*>/i', $content, $matches);
        $title = empty($matches[1]) ? null : $this->cleanString($matches[1]);        
        //end getting website title
        
        //try to get the website metha description
        $matches = array();
        $meta = "";
        preg_match('/<\s*meta\s*[^\>]*?name=[\'"]description[\'"][^\>]*?\s*>/i',$content,$matches);
        $meta = empty($matches[0]) ? null : $matches[0];
        
        $matches = array();
        preg_match('/content=([\'"])([\s\S]*?)\1/i',$meta,$matches);        
        $description = empty($matches[2]) ? null : $this->cleanString($matches[2]);
        //end getting the description
        
        //try to get the meta author
        $matches = array();
        $meta = "";
        preg_match('/<\s*meta\s*[^\>]*?name=[\'"]author[\'"][^\>]*?\s*>/i', $content, $matches);
        $meta = empty($matches[0]) ? null : $matches[0];
        
        $matches = array();
        preg_match('/content=?([\'"])(.*?)\1/i', $meta, $matches);
        $author = empty($matches[2]) ? null : $this->cleanString($matches[2]);
        //end getting website author
        
        //try to get all the images from <img> tag
        $imatches = array();
        preg_match_all('/<\s*img\s*.*?src=[\'"](.+?)[\'"][^>]*>/i',$content, $imatches);
        //end getting all the images
        
        //if the description is empty run the code bellow
        if ( empty($description)  ){
            //try to grab one of <p> tags
            $matches = array();            
            preg_match('/<\s*p[^>]*>([\w\W]*)<\s*\/\s*p\s*>/i', $content, $matches);
            
            //if the <p> tag is found filter it
            if ( !empty($matches[1]) ){ 
                //remove any unwanted <script> tag
                $d = preg_replace('/<\s*script[^>]*>(?:[\w\W]*)<\s*\/\s*script\s*>/i', '', $matches[1]);
                //remove any unwanted <style> tag
                $d = preg_replace('/<\s*style[^>]*>(?:[\w\W]*)<\s*\/\s*style\s*>/i', '', $d);                  
                //remove any unwanted commant tag for html
                $d = preg_replace('/<!--[^>]*>/i', '', $d);
                //clean the description string
                $d = $this->cleanString($d);
                //assign the new description
                $description = !empty($d) ? $d : null;
            }
            
        }
        
        $images = array();
        
        //loop through every images it grabed
        foreach ( $imatches[1] as $img )
        {
            //before doing anything trim the image.            
            $img = trim($img);
            
            //if the images are base64 then ignore for now.
            //ToDo may be base64 images workaround.
            if ( preg_match('/^data:image\/png;base64,/i', $img) ){
                continue;
            }
            
            /*
             * Fix for the php version 5.3
             */
            if ( strpos($img, '//') === 0 ){
                $img = 'http:' . $img;
            }
            
            $urlInfo = $this->parsedUrl;
            $imgInfo = parse_url($img);
            
            if ( empty($imgInfo['host']) )
            {
                $imgDir = dirname($imgInfo['path']);
                
                $urlScheme = empty($urlInfo['scheme']) ? '' : $urlInfo['scheme'] . '://';
                $urlAddr = $urlScheme . $urlInfo['host'];

                if ( strpos($imgDir, '/') === 0 )
                {
                    $img = $urlAddr . $imgInfo['path'];
                    
                }
                elseif ( !empty($urlInfo['path']) )
                {
                    $pp = pathinfo($urlInfo['path']);
                    $urlPath = $pp['dirname'] . ( empty($pp['extension']) ? $pp['basename'] . '/' : '' );
                    $img = $urlAddr . $urlPath . $imgInfo['path'];
                }
                else
                {
                    $img = $urlAddr . '/' . $imgInfo['path'];
                }
            }
            //filter for unique images
            if ( array_search($img, $images) === FALSE ){
                $images[] = $img;
            }
        }

        $firstImg = reset($images);
        $firstImg = $firstImg ? $firstImg : null;
        
        //return data as json object
        return json_encode(array(
            'type' => 'link',
            'version' => '1.0',
            'title' => $title,
            'url' =>  $this->url,
            'author_name' => $author,
            'description' => $description,
            'photos' => $images
        ));
        
  }
  
  /**
   * This method is used by `filterContent()` method.
   * 
   * This method filters the data for xss attack. may be not enough but 
   * enough to keep going.
   * 
   * @param string $string
   * @param int $length
   * @return string
   */
  private function cleanString( $string, $length = null ){
      
        $invisiblePtr = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/S';

        do {

            $string = preg_replace($invisiblePtr, '', $string, -1 ,$count);

        }while ( $count );
      
        //remove any tags
        $string = preg_replace('/<[^>]*>/i', ' ', $string);                                             
        
        $ht = get_html_translation_table(HTML_ENTITIES, ENT_COMPAT | ENT_HTML5, 'UTF-8');
        
        /*
         * Now its time to convert all html entities chars to 
         * their entity value.
         * But before doing that we have to convert all entities to original chars first.
         * Because if website already have some entities like &lt; or &amp; it could 
         * turn up like &amp;lt; or &amp;amp;
         */        
        $string = str_ireplace(array_values($ht), array_keys($ht), $string);
        /*
         * Now reconvert those values to original states.
         * That is how if there is any special char left it will be converted too.
         * For example all angle brackets website had will now converted to &lt; or $gt;
         */        
        $string = str_ireplace(array_keys($ht), array_values($ht), $string);
        
        
        /************ DIRTY FIX FOR OVER REPLACING BUG ***********/
        $string = str_ireplace(array('&semi;', '&amp;', '&num;'), array(';', '&', '#'), $string);
        $string = str_ireplace(array('&NewLine;', '&Tab;'), array(' ', ' '), $string);
        /************* END oF DIRTY FIX *************************/
                
        //convert all \r\n chars to a single space
        $string = preg_replace('/[\r\n\t]/i', ' ', $string);   
        //convert more than one spaces, tabs to one space or none.        
        $pattrns = array('/^\s+/', '/\s+/', '/\s+$/', '/(?:&nbsp;)+/i');
        $replace = array('', ' ', '', ' ');
        $string = preg_replace($pattrns, $replace, $string);        
        //again remove multiple spaces
        $string = preg_replace('/\s\s+/', ' ', $string);        
        
        if ( (bool) self::$limitDesc ){
            $oldst = $string;
            $string = substr($oldst, 0, self::$limitDesc);
            
            $string .= strlen($oldst) > self::$limitDesc ? '...' : '';
        }
        
        /**
         * Finally convert encodings to utf-8 
         */
        $string = $this->convertStringEncoding($string);
        
        return $string;
  }
  
  /**
   * This is the constractor function. It checks the all the requirements 
   * before creating the object, including php version checking.
   * 
   * ### Warning
   *    
   * @param int $timeOut provide the timeout in seconds. 
   * (Leave it or set it to null if you do not know what you are doing, risky.)
   * @param string $userAgent provide a valid user agent. 
   * (Same here, leave it or set it to null if you do not know what you are doing, risky.)
   */
  public function __construct( $timeOut = null, $userAgent = null ){      
      
      if ( $timeOut >= 0 ){
          self::$timeout = $timeOut;
      }
      
      if ( is_string($userAgent) ){
          self::$userAgent = $userAgent;
      }
      
      //now check the php version.
      if ( version_compare(PHP_VERSION, '5.3', '<' ) ){
          trigger_error("PHP version 5.3+ require.");
      }
      
      if ( !function_exists('curl_init') ){
          trigger_error("curl extention require to run.");
      }
  }
  
  /**
   * Use this method to add a provider of your own.
   * 
   * <pre>
   * $api = 'http://www.hulu.com/api/oembed.json?url=:url';
   * $pattern = '~hulu\.com/watch/.+~';
   * $key = 'hulu';
   * $provider = new PHPOEmbedProvider($api, $pattern, $key);
   * </pre>
   * 
   * <b>$api:</b> provide the api url for the provider. Remember to use :url so that it can be replaced with the url you want to embed.
   * 
   * <b>$pattern:</b> write a pattern for the url that it will match for this provider.
   * 
   * <b>$key:</b> give this provider an unique key. (It could be anything).
   * 
   * Now add the provider.
   * <code>PHPOEmbed::addProvider($provider);</code>
   * 
   * @example provider.php see this file to know more about adding
   * providers.
   * 
   * @param PHPOEmbedProvider $provider
   */
  public static function addProvider( PHPOEmbedProvider $provider ){
      
      self::$providers[$provider->getUKey()] = array('api' => $provider->getApi() , 'pattern' => $provider->getPattern() );

  }
  
  /**
   * Parse and get the oembed data from an url.
   * 
   * This method will run the whole work to return your oembed response.
   * 
   * @param string $url provide the url to parse. If you are asking the url from users then
   * validate it before parsing, else provide a valid url. Remember PHPOEmbed does not validate your provided 
   * url.
   * 
   * @return string it will return the response as a json encoded string, with a valid format of 
   * {@link http://oembed.com/ oembed.}
   */
  public final function parse( $url ){
      
        $this->addUrl(  $url  );
        $k = $this->checkForMatchedPattern();        
        //check the url if it mathches any pattern
        if ( $k  !== FALSE ){
            if ( $ge = $this->getEmbed( $k ) ){
                return $ge;
            }
        }        
        //if it does not matches any pattern then default fetch
        return $this->fetchDefault();
  }
  
}

/**
 * Provider class of PHPOEmbed.
 * 
 * @author Akash Bose <bose@ullashkolkata.com>
 * @since Jan 15, 2016 [10:56:13 PM]
 */
class PHPOEmbedProvider{

  private $api;
  private $pattern;
  private $uKey;
  
  /**
   * 
   * @param string $api provide the api 
   * @param string $pattern regex pattern for url pattern
   * @param string $key provide an unique key for provider
   */
  public final function __construct( $api, $pattern, $key ){
    $this->api = $api;
    $this->pattern = (array) $pattern;
    $this->uKey = $key;
  }

  public final function getApi(){
    return $this->api;
  }

  public final function getPattern(){
    return $this->pattern;
  }
  
  public final function getUKey(){
      return $this->uKey;
  }

}

require 'providers.php';