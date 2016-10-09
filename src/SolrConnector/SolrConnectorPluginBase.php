<?php

namespace Drupal\search_api_solr\SolrConnector;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\search_api\Plugin\ConfigurablePluginBase;
use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api_solr\Annotation\SolrConnector;
use Drupal\search_api_solr\SearchApiSolrException;
use Drupal\search_api_solr\SolrConnectorInterface;
use Solarium\Client;
use Solarium\Core\Client\Request;
use Solarium\Core\Query\Helper;
use Solarium\Exception\HttpException;
use Solarium\QueryType\Select\Query\Query;

/**
 * Defines a base class for Solr connector plugins.
 *
 * Plugins extending this class need to define a plugin definition array through
 * annotation. These definition arrays may be altered through
 * hook_search_api_solr_connector_info_alter(). The definition includes the
 * following keys:
 * - id: The unique, system-wide identifier of the backend class.
 * - label: The human-readable name of the backend class, translated.
 * - description: A human-readable description for the backend class,
 *   translated.
 *
 * A complete plugin definition should be written as in this example:
 *
 * @code
 * @SolrConnector(
 *   id = "my_connector",
 *   label = @Translation("My connector"),
 *   description = @Translation("Searches with SuperSearch™.")
 * )
 * @endcode
 *
 * @see \Drupal\search_api_solr\Annotation\SolrConnector
 * @see \Drupal\search_api_solr\SolrConnector\SolrConnectorPluginManager
 * @see \Drupal\search_api_solr\SolrConnectorInterface
 * @see plugin_api
 */
abstract class SolrConnectorPluginBase extends ConfigurablePluginBase {

  use PluginFormTrait {
    submitConfigurationForm as traitSubmitConfigurationForm;
  }

  /**
   * A Solarium Update query.
   *
   * @var \Solarium\QueryType\Update\Query\Query
   */
  protected static $updateQuery;

  /**
   * A Solarium query helper.
   *
   * @var \Solarium\Core\Query\Helper
   */
  protected static $queryHelper;

  /**
   * A connection to the Solr server.
   *
   * @var \Solarium\Client
   */
  protected $solr;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'scheme' => 'http',
      'host' => 'localhost',
      'port' => '8983',
      'path' => '/solr',
      'core' => '',
      'timeout' => 5,
      'index_timeout' => 5,
      'optimize_timeout' => 10,
      'username' => '',
      'password' => '',
      'solr_version' => '',
      'http_method' => 'AUTO',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['scheme'] = array(
      '#type' => 'select',
      '#title' => $this->t('HTTP protocol'),
      '#description' => $this->t('The HTTP protocol to use for sending queries.'),
      '#default_value' => $this->configuration['scheme'],
      '#options' => array(
        'http' => 'http',
        'https' => 'https',
      ),
    );

    $form['host'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Solr host'),
      '#description' => $this->t('The host name or IP of your Solr server, e.g. <code>localhost</code> or <code>www.example.com</code>.'),
      '#default_value' => $this->configuration['host'],
      '#required' => TRUE,
    );

    $form['port'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Solr port'),
      '#description' => $this->t('The Jetty example server is at port 8983, while Tomcat uses 8080 by default.'),
      '#default_value' => $this->configuration['port'],
      '#required' => TRUE,
    );

    $form['path'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Solr path'),
      '#description' => $this->t('The path that identifies the Solr instance to use on the server.'),
      '#default_value' => $this->configuration['path'],
    );

    $form['core'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Solr core'),
      '#description' => $this->t('The name that identifies the Solr core to use on the server.'),
      '#default_value' => $this->configuration['core'],
    );

    $form['timeout'] = array(
      '#type' => 'number',
      '#min' => 1,
      '#max' => 180,
      '#title' => $this->t('Query timeout'),
      '#description' => $this->t('The timeout in seconds for search queries sent to the Solr server.'),
      '#default_value' => $this->configuration['timeout'],
      '#required' => TRUE,
    );

    $form['index_timeout'] = array(
      '#type' => 'number',
      '#min' => 1,
      '#max' => 180,
      '#title' => $this->t('Index timeout'),
      '#description' => $this->t('The timeout in seconds for indexing requests to the Solr server.'),
      '#default_value' => $this->configuration['index_timeout'],
      '#required' => TRUE,
    );

    $form['optimize_timeout'] = array(
      '#type' => 'number',
      '#min' => 1,
      '#max' => 180,
      '#title' => $this->t('Optimize timeout'),
      '#description' => $this->t('The timeout in seconds for background index optimization queries on a Solr server.'),
      '#default_value' => $this->configuration['optimize_timeout'],
      '#required' => TRUE,
    );

    $form['workarounds'] = array(
      '#type' => 'fieldset',
      '#title' => $this->t('Connector Workarounds'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    );

    $form['workarounds']['solr_version'] = array(
      '#type' => 'select',
      '#title' => $this->t('Solr version override'),
      '#description' => $this->t('Specify the Solr version manually in case it cannot be retrived automatically. The version can be found in the Solr admin interface under "Solr Specification Version" or "solr-spec"'),
      '#options' => array(
        '' => $this->t('Determine automatically'),
        '4' => '4.x',
        '5' => '5.x',
        '6' => '6.x',
      ),
      '#default_value' => $this->configuration['solr_version'],
    );

    $form['workarounds']['http_method'] = array(
      '#type' => 'select',
      '#title' => $this->t('HTTP method'),
      '#description' => $this->t('The HTTP method to use for sending queries. GET will often fail with larger queries, while POST should not be cached. AUTO will use GET when possible, and POST for queries that are too large.'),
      '#default_value' => $this->configuration['http_method'],
      '#options' => array(
        'AUTO' => $this->t('AUTO'),
        'POST' => 'POST',
        'GET' => 'GET',
      ),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    if (isset($values['port']) && (!is_numeric($values['port']) || $values['port'] < 0 || $values['port'] > 65535)) {
      $form_state->setError($form['port'], $this->t('The port has to be an integer between 0 and 65535.'));
    }
    if (!empty($values['path']) && strpos($values['path'], '/') !== 0) {
      $form_state->setError($form['path'], $this->t('If provided the path has to start with "/".'));
    }
    if (!empty($values['core']) && strpos($values['core'], '/') === 0) {
      $form_state->setError($form['core'], $this->t('The core must not start with "/".'));
    }

    if (!$form_state->hasAnyErrors()) {
      // Try to orchestrate a server link from form values.
      $solr = new Client();
      $solr->createEndpoint($values + ['key' => 'core'], TRUE);
      try {
        $this->getServerLink();
      } catch (\InvalidArgumentException $e) {
        foreach (['scheme', 'host', 'port', 'path', 'core'] as $part) {
          $form_state->setError($form[$part], $this->t('The server link generated from the form values is illegal.'));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    // Since the form is nested into another, we can't simply use #parents for
    // doing this array restructuring magic. (At least not without creating an
    // unnecessary dependency on internal implementation.)
    foreach ($values['workarounds'] as $key => $value) {
      $form_state->setValue($key, $value);
    }

    // Clean-up the form to avoid redundant entries in the stored configuration.
    $form_state->unsetValue('workarounds');

    $this->traitSubmitConfigurationForm($form, $form_state);
  }

  protected function connect() {
    if (!$this->solr) {
      $this->solr = new Client();
      $this->solr->createEndpoint($this->configuration + ['key' => 'core'], TRUE);
    }
  }

  /**
   * Attaches an endpoint to the Solr connection to communicate with the server.
   *
   * This endpoint is different from the core endpoint which is the default one.
   * The default endpoint for the core is used to communicate with the index.
   * But for some administrative tasks the server itself needs to be contacted.
   * This function is meant to be overwritten as soon as we deal with Solr
   * service provider specific implementations of SolrHelper.
   */
  public function attachServerEndpoint() {
    $this->connect();
    $configuration = $this->configuration;
    $configuration['core'] = NULL;
    $configuration['key'] = 'server';
    $this->solr->createEndpoint($configuration);
  }

  /**
   * Returns a the Solr server URI.
   */
  protected function getServerUri() {
    $url_path = $this->solr->getEndpoint('server')->getBaseUri();
    if ($this->configuration['host'] == 'localhost' && !empty($_SERVER['SERVER_NAME'])) {
      $url_path = str_replace('localhost', $_SERVER['SERVER_NAME'], $url_path);
    }

    return $url_path;
  }

  /**
   * Returns a link to the Solr server.
   */
  public function getServerLink() {
    $url_path = $this->getServerUri();
    $url = Url::fromUri($url_path);

    return Link::fromTextAndUrl($url_path, $url);
  }

  /**
   * Returns a link to the Solr core, if the necessary options are set.
   */
  public function getCoreLink() {
    $url_path = $this->getServerUri() . '#/' . $this->configuration['core'];
    $url = Url::fromUri($url_path);

    return Link::fromTextAndUrl($url_path, $url);
  }

  /**
   * Gets the current Solr version.
   *
   * @param bool $force_auto_detect
   *   If TRUE, ignore user overwrites.
   *
   * @return string
   *   The full Solr version string.
   */
  public function getSolrVersion($force_auto_detect = FALSE) {
    // Allow for overrides by the user.
    if (!$force_auto_detect && !empty($this->configuration['solr_version'])) {
      // In most cases the already stored solr_version is just the major version
      // number as integer. In this case we will expand it to the minimum
      // corresponding full version string.
      $min_version = ['0', '0', '0'];
      $version = explode('.', $this->configuration['solr_version']) + $min_version;

      return implode('.', $version);
    }

    $info = [];
    try {
      $info = $this->getCoreInfo();
    }
    catch (SearchApiSolrException $e) {
      try {
        $info = $this->getServerInfo();
      }
      catch (SearchApiSolrException $e) {
      }
    }

    // Get our solr version number.
    if (isset($info['lucene']['solr-spec-version'])) {
      return $info['lucene']['solr-spec-version'];
    }

    return '0.0.0';
  }

  /**
   * Gets the current Solr major version.
   *
   * @param string $version
   *   An optional Solr version string.
   *
   * @return int
   *   The Solr major version.
   */
  public function getSolrMajorVersion($version = '') {
    list($major, ,) = explode('.', $version ?: $this->getSolrVersion());
    return $major;
  }

  /**
   * Gets the current Solr branch name.
   *
   * @param string $version
   *   An optional Solr version string.
   *
   * @return string
   *   The Solr branch string.
   */
  public function getSolrBranch($version = '') {
    return $this->getSolrMajorVersion($version) . '.x';
  }

  /**
   * Gets the LuceneMatchVersion string.
   *
   * @param string $version
   *   An optional Solr version string.
   *
   * @return string
   *   The lucene match version in V.V format.
   */
  public function getLuceneMatchVersion($version = '') {
    list($major, $minor,) = explode('.', $version ?: $this->getSolrVersion());
    return $major . '.' . $minor;
  }

  /**
   * Gets information about the Solr server.
   *
   * @param boolean $reset
   *   If TRUE the server will be asked regardless if a previous call is cached.
   *
   * @return object
   *   A response object with server information.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function getServerInfo($reset = FALSE) {
    return $this->getDataFromHandler('server', 'admin/info/system', $reset);
  }

  /**
   * Gets information about the Solr Core.
   *
   * @param boolean $reset
   *   If TRUE the server will be asked regardless if a previous call is cached.
   *
   * @return object
   *   A response object with system information.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function getCoreInfo($reset = FALSE) {
    return $this->getDataFromHandler('core', 'admin/system', $reset);
  }

  /**
   * Gets meta-data about the index.
   *
   * @return object
   *   A response object filled with data from Solr's Luke.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function getLuke() {
    return $this->getDataFromHandler('core', 'admin/luke', TRUE);
  }

  /**
   * Gets the full schema version string the core is using.
   *
   * @param boolean $reset
   *   If TRUE the server will be asked regardless if a previous call is cached.
   *
   * @return string
   *   The full schema version string.
   */
  public function getSchemaVersionString($reset = FALSE) {
    return $this->getCoreInfo($reset)['core']['schema'];
  }

  /**
   * Gets the schema version number.
   *
   * @param boolean $reset
   *   If TRUE the server will be asked regardless if a previous call is cached.
   *
   * @return string
   *   The full schema version string.
   */
  public function getSchemaVersion($reset = FALSE) {
    $parts = explode('-', $this->getSchemaVersionString($reset));
    return $parts[1];
  }

  /**
   * Gets data from a Solr endpoint using a given handler.
   *
   * @param boolean $reset
   *   If TRUE the server will be asked regardless if a previous call is cached.
   *
   * @return object
   *   A response object with system information.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  protected function getDataFromHandler($endpoint, $handler, $reset = FALSE) {
    static $previous_calls = [];

    $this->connect();

    $endpoint_uri = $this->solr->getEndpoint($endpoint)->getBaseUri();
    $state_key = 'search_api_solr.endpoint.data';
    $state = \Drupal::state();
    $endpoint_data = $state->get($state_key);

    if (!isset($previous_calls[$endpoint_uri][$handler]) || $reset) {
      // Don't retry multiple times in case of an exception.
      $previous_calls[$endpoint] = TRUE;

      if (!is_array($endpoint_data) || !isset($endpoint_data[$endpoint_uri][$handler]) || $reset) {
        // @todo Finish https://github.com/solariumphp/solarium/pull/155 and stop
        // abusing the ping query for this.
        $query = $this->solr->createPing(array('handler' => $handler));
        try {
          $endpoint_data[$endpoint_uri][$handler] = $this->solr->execute($query, $endpoint)->getData();
        }
        catch (HttpException $e) {
          throw new SearchApiSolrException(t('Solr endpoint @endpoint not found.', ['@endpoint' => $endpoint_uri]), $e->getCode(), $e);
        }

        $state->set($state_key, $endpoint_data);
      }
    }

    return $endpoint_data[$endpoint_uri][$handler];
  }

  /**
   * Pings the Solr core to tell whether it can be accessed.
   *
   * @return mixed
   *   The latency in milliseconds if the core can be accessed,
   *   otherwise FALSE.
   */
  public function pingCore() {
    return $this->doPing();
  }

  /**
   * Pings the Solr server to tell whether it can be accessed.
   *
   * @return mixed
   *   The latency in milliseconds if the core can be accessed,
   *   otherwise FALSE.
   */
  public function pingServer() {
    return $this->doPing(['handler' => 'admin/info/system'], 'server');
  }

  /**
   * Pings the Solr server to tell whether it can be accessed.
   *
   * @param string $endpoint_name
   *   The endpoint to be pinged on the Solr server.
   *
   * @return mixed
   *   The latency in milliseconds if the core can be accessed,
   *   otherwise FALSE.
   */
  protected function doPing($options = [], $endpoint_name = 'core') {
    $this->connect();
    // Default is ['handler' => 'admin/ping'].
    $query = $this->solr->createPing($options);

    try {
      $start = microtime(TRUE);
      $result = $this->solr->execute($query, $endpoint_name);
      if ($result->getResponse()->getStatusCode() == 200) {
        // Add 1 µs to the ping time so we never return 0.
        return (microtime(TRUE) - $start) + 1E-6;
      }
    }
    catch (HttpException $e) {
    }

    return FALSE;
  }

  /**
   * Gets summary information about the Solr Core.
   *
   * @return array
   *   An array of stats about the solr core.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function getStatsSummary() {
    $this->connect();

    $summary = array(
      '@pending_docs' => '',
      '@autocommit_time_seconds' => '',
      '@autocommit_time' => '',
      '@deletes_by_id' => '',
      '@deletes_by_query' => '',
      '@deletes_total' => '',
      '@schema_version' => '',
      '@core_name' => '',
      '@index_size' => '',
    );

    $query = $this->solr->createPing();
    $query->setResponseWriter(Query::WT_PHPS);
    $query->setHandler('admin/mbeans?stats=true');
    try {
      $stats = $this->solr->execute($query)->getData();
      if (!empty($stats)) {
        $update_handler_stats = $stats['solr-mbeans']['UPDATEHANDLER']['updateHandler']['stats'];
        $summary['@pending_docs'] = (int) $update_handler_stats['docsPending'];
        $max_time = (int) $update_handler_stats['autocommit maxTime'];
        // Convert to seconds.
        $summary['@autocommit_time_seconds'] = $max_time / 1000;
        $summary['@autocommit_time'] = \Drupal::service('date.formatter')->formatInterval($max_time / 1000);
        $summary['@deletes_by_id'] = (int) $update_handler_stats['deletesById'];
        $summary['@deletes_by_query'] = (int) $update_handler_stats['deletesByQuery'];
        $summary['@deletes_total'] = $summary['@deletes_by_id'] + $summary['@deletes_by_query'];
        $summary['@schema_version'] = $this->getSchemaVersionString(TRUE);
        $summary['@core_name'] = $stats['solr-mbeans']['CORE']['core']['stats']['coreName'];
        $summary['@index_size'] = $stats['solr-mbeans']['QUERYHANDLER']['/replication']['stats']['indexSize'];
      }
      return $summary;
    }
    catch (HttpException $e) {
      throw new SearchApiSolrException(t('Solr server core @core not found.', ['@core' => $this->solr->getEndpoint()->getBaseUri()]), $e->getCode(), $e);
    }
  }
  /**
   * Sends a REST GET request to the Solr core and returns the result.
   *
   * @param string $path
   *   The path to append to the base URI.
   *
   * @return string
   *   The decoded response.
   */
  public function coreRestGet($path) {
    return $this->restRequest('core', $path);
  }

  /**
   * Sends a REST POST request to the Solr core and returns the result.
   *
   * @param string $path
   *   The path to append to the base URI.
   * @param string $command_json
   *   The command to send encoded as JSON.
   *
   * @return string
   *   The decoded response.
   */
  public function coreRestPost($path, $command_json = '') {
    return $this->restRequest('core', $path, Request::METHOD_POST, $command_json);
  }

  /**
   * Sends a REST GET request to the Solr server and returns the result.
   *
   * @param string $path
   *   The path to append to the base URI.
   *
   * @return string
   *   The decoded response.
   */
  public function serverRestGet($path) {
    return $this->restRequest('server', $path);
  }

  /**
   * Sends a REST POST request to the Solr server and returns the result.
   *
   * @param string $path
   *   The path to append to the base URI.
   * @param string $command_json
   *   The command to send encoded as JSON.
   *
   * @return string
   *   The decoded response.
   */
  public function serverRestPost($path, $command_json = '') {
    return $this->restRequest('server', $path, Request::METHOD_POST, $command_json);
  }

  /**
   * Sends a REST request to the Solr server endpoint and returns the result.
   *
   * @param string $endpoint_key
   *   The endpoint that refelcts the base URI.
   * @param string $path
   *   The path to append to the base URI.
   * @param string $method
   *   The HTTP request method.
   * @param string $command_json
   *   The command to send encoded as JSON.
   *
   * @return string
   *   The decoded response.
   */
  protected function restRequest($endpoint_key, $path, $method = Request::METHOD_GET, $command_json = '') {
    $this->connect();

    $request = new Request();
    $request->setMethod($method);
    $request->addHeader('Accept: application/json');
    if (Request::METHOD_POST == $method) {
      $request->addHeader('Content-type: application/json');
      $request->setRawData($command_json);
    }
    $request->setHandler($path);

    $endpoint = $this->solr->getEndpoint($endpoint_key);
    $timeout = $endpoint->getTimeout();
    // @todo Destinguish between different flavors of REST requests and use
    //   different timeout settings.
    $endpoint->setTimeout($this->configuration['optimize_timeout']);
    $response = $this->solr->executeRequest($request, $endpoint);
    $endpoint->setTimeout($timeout);
    $output = Json::decode($response->getBody());
    // \Drupal::logger('search_api_solr')->info(print_r($output, true));
    if (!empty($output['errors'])) {
      throw new SearchApiSolrException('Error trying to send a REST request.' .
        "\nError message(s):" . print_r($output['errors'], TRUE));
    }
    return $output;
  }

  /**
   * Gets the current Solarium update query, creating one if necessary.
   *
   * @return \Solarium\QueryType\Update\Query\Query
   *   The Update query.
   */
  protected function getUpdateQuery() {
    if (!static::$updateQuery) {
      $this->connect();
      static::$updateQuery = $this->solr->createUpdate();
    }
    return static::$updateQuery;
  }

  /**
   * Returns a Solarium query helper object.
   *
   * @param \Solarium\QueryType\Select\Query\Query|null $query
   *   (optional) A Solarium query object.
   *
   * @return \Solarium\Core\Query\Helper
   *   A Solarium query helper.
   */
  protected function getQueryHelper(Query $query = NULL) {
    if (!static::$queryHelper) {
      if ($query) {
        static::$queryHelper = $query->getHelper();
      }
      else {
        static::$queryHelper = new Helper();
      }
    }

    return static::$queryHelper;
  }

}