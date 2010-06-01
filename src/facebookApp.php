<?php

/**
 * Description of facebookApp
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
    $this->appAcessToken = $this->getAppAccessToken();
  }


  public function getAppAccessToken() {
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

  private function subscriptions_url() {
    return '/' . parent::getAppId() . '/subscriptions';
  }

  public function getSubscriptions() {
    $params = array('access_token' => $this->appAcessToken);

    return $this->_app_get($this->subscriptions_url(), 'GET', $params);
  }



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


class FacebookMultiUser extends Facebook {

  protected $mch, $handles;

  public function __construct($config) {
    $config['cookie'] = false;
    parent::__construct($config);
    $this->handles = array();
    $this->mch = curl_multi_init();
  }

  public function __destruct() {
    curl_multi_close($this->mch);
  }

  public function getUserChanges($id, $fields, $access_token) {
    $params = array(
      'access_token'  => $access_token,
      'fields'        => $fields,
      'method'        => 'GET'
    );

    $url = parent::getUrl('graph', '/' . $id . '/');
    $this->makeMultiRequest($id, $url,  $params);
  }


  protected function makeMultiRequest($id, $url, $params) {
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

public function storeResults($functionName) {

  $result = array();
  $count = 0;
  $times = 0;

  do {
    curl_multi_exec($this->mch, $active);
  } while($active > 0);

  foreach ($this->handles as $id => $handle) {
    $data = curl_multi_getcontent($handle);
    $result[$id] = json_decode($data, true);
    call_user_func($functionName, $id, $result[$id]);
    curl_multi_remove_handle($this->mch, $handle);
  }

  return $result;
}

}

