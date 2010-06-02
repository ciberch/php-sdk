<?php

/**
 * Class which allows you to invoke methods available on a per app basis
 * such as the management of real-time updates.
 *
 * @author mciberch
 */
class FacebookApp extends Facebook {

  protected $appAcessToken = 0;


  /**
   * Initialize a Facebook Application.
   *
   * The configuration:
   * - appId: the application API key
   * - secret: the application secret
   * - domain: (optional) domain for the cookie
   *
   * @param Array $config the application configuration
   */
  public function __construct($config) {
    $config['cookie'] = false;
    parent::__construct($config);
    $this->appAcessToken = $this->get_app_access_token();
  }

  /**
   * Internal method which fetches the access token for the app
   * @return string the access token
   */
  private function get_app_access_token() {
    $params = array(
            'client_id'     => parent::getAppId(),
            'client_secret' => parent::getApiSecret(),
            'type'          => 'client_cred',
            'method'        => 'GET'
    );

    $url = parent::getUrl('graph', '/oauth/access_token');
    $text = parent::makeRequest($url, $params);

    list($name, $token) = explode('=', $text);

    return $token;
  }

  /**
   * Internal helper function which builds the subscription url
   * @return string The subscription url
   */
  private function subscriptions_url() {
    return '/' . parent::getAppId() . '/subscriptions';
  }

  /**
   * Method which allows you to get the list of subscriptions
   * @return array Returns the array containing the list of subscriptions
   */
  public function getSubscriptions() {
    $params = array('access_token' => $this->appAcessToken);

    return $this->_app_get($this->subscriptions_url(), 'GET', $params);
  }

  /**
   * This method adds a subscription if and only if you pass valid data and have
   * the callback_url properly acknowledging the subscriptions.
   *
   * @param string $verify_token The token which you want echoed to prevent DOS
   * attacks. Ignore all requests unless they pass the proper verify token.
   * @param strng $callback_url Tour endpoint url. Must be absolute. Must
   * support echoing the challenge which will be sent.
   * @param string $object Name of the object. 'user' or 'permissions'
   * @param string $fields Comma delimited list of fields. Error may not be
   * thrown for invalid field names
   * @return <type> MK: TODO check
   */
  public function addSubscription($verify_token, $callback_url, $object,
          $fields) {

    if (is_array($fields)) {
      $params = array(
              'access_token'  => $this->appAcessToken,
              'verify_token'  => $verify_token,
              'callback_url'  => $callback_url,
              'object'        => $object,
              'fields'        => implode(",", $fields)
      );

      return $this->_app_get($this->subscriptions_url(), 'POST', $params);

    } else {
      return null;
    }
  }

  /**
   * This method does the HTTP request passing the app credentials
   * @param string $path The path after http://graph.facebook.com
   * @param string $method HTTP method such as 'GET', 'POST', 'DELETE'
   * @param array $params Parameters for the method. Usually inclide the token
   * @return array Deserialized json response
   */
  private function _app_get($path, $method='GET', $params=array()) {
    if (is_array($method) && empty($params)) {
      $params = $method;
      $method = 'GET';
    }
    $params['method'] = $method; // method override as we always do a POST
    $url = parent::getUrl('graph', $path);
    return json_decode(parent::makeRequest($url, $params), true);

  }
}

/**
 * This class allows you to make
 * multiple parallel calls to get user data suitable for being invoked
 * after receiving a real time notification.
 *
 * @author Monica Keller
 */
class FacebookMultiUser extends Facebook {

  protected $mch, $handles;

   /**
   * Initializes the multi user data fetcher
   *
   * The configuration:
   * - appId: the application API key
   * - secret: the application secret
   * - domain: (optional) domain for the cookie
   *
   * @param Array $config the application configuration
   */
  public function __construct($config) {
    $config['cookie'] = false;
    parent::__construct($config);
    $this->handles = array();
    $this->mch = curl_multi_init();
  }

  public function __destruct() {
    curl_multi_close($this->mch);
  }

  /**
   * This method fires an async request for the user data.
   * Call it as many times as needed and then call storeResults
   *
   * @param double $id Facebook user id
   * @param string $fields Comma delimited list of fields
   * @param string $access_token for the user
   * @param string $since  A string containing a date which can be parsed by
   * strtotime(). All the results returned will have occurred after that date.
   */
  public function getUserChanges($id, $fields, $access_token, $since) {
    $params = array(
      'access_token'  => $access_token,
      'fields'        => $fields,
      'method'        => 'GET',
      'since'         => $since // this needs to be in GMT
    );

    $url = parent::getUrl('graph', '/' . $id . '/');

    $key = implode(";", array($id, $fields, $since));
    $this->make_multi_request($key, $url,  $params);
  }

  /**
   * Internal method which fires the http requests
   * @param string $id Identifier for the url
   * @param string $url URL to invoke
   * @param array $params List of parameters
   */
  private function make_multi_request($id, $url, $params) {
    $ch = curl_init();
    $opts = parent::$CURL_OPTS;
    $opts[CURLOPT_RETURNTRANSFER] = 1;
    $opts[CURLOPT_POSTFIELDS] = http_build_query($params, null, '&');
    $opts[CURLOPT_URL] = $url;

    curl_setopt_array($ch, $opts);

    curl_multi_add_handle($this->mch, $ch);
    curl_multi_exec($this->mch, $active);

    $this->handles[$id] = $ch;
  }

  /**
   * Call this method to start waiting for the HTTP requests
   * to finish
   * @param string $functionName Name of the static function to invoke
   * It will be passed the $id which identifies the HTTP request and the
   * $result which will be array obtained after deserializing the json response
   */
  public function storeResults($functionName) {
    do {
      curl_multi_exec($this->mch, $active);
    } while($active > 0);

    foreach ($this->handles as $id => $handle) {
      $data = curl_multi_getcontent($handle);
      $result[$id] = json_decode($data, true);
      call_user_func($functionName, $id, $result[$id]);
      curl_multi_remove_handle($this->mch, $handle);
    }
  }

}

