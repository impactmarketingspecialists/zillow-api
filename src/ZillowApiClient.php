<?php

namespace ZillowApi;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use ZillowApi\Exception\XmlParseException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use ZillowApi\Model\Response;

/**
 * Zillow PHP API Client
 *
 * @author Brent Mullen <brent.mullen@gmail.com>
 */
class ZillowApiClient
{
    /**
     * @var string
     */
    protected $url = 'http://www.zillow.com/webservice/';

    /**
     * @var GuzzleClient
     */
    protected $client;

    /**
     * @var string
     */
    protected $zwsid;

    /**
     * @var int
     */
    protected $responseCode = 0;

    /**
     * @var string
     */
    protected $responseMessage = null;

    /**
     * @var array
     */
    protected $response;

    /**
     * @var array
     */
    protected $results;

    /**
     * @var array
     */
    protected $photos = [];

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @var array
     *
     * Valid API functions
     */
    public static $validMethods = [
        'GetZestimate',
        'GetSearchResults',
        'GetChart',
        'GetComps',
        'GetDeepComps',
        'GetDeepSearchResults',
        'GetUpdatedPropertyDetails',
        'GetDemographics',
        'GetRegionChildren',
        'GetRegionChart',
        'GetRateSummary',
        'GetMonthlyPayments',
        'CalculateMonthlyPaymentsAdvanced',
        'CalculateAffordability',
        'CalculateRefinance',
        'CalculateAdjustableMortgage',
        'CalculateMortgageTerms',
        'CalculateDiscountPoints',
        'CalculateBiWeeklyPayment',
        'CalculateNoCostVsTraditional',
        'CalculateTaxSavings',
        'CalculateFixedVsAdjustableRate',
        'CalculateInterstOnlyVsTraditional',
        'CalculateHELOC',
    ];

    /**
     * @param string $zwsid
     * @param string|null $url
     */
    public function __construct($zwsid, $url = null)
    {
        $this->zwsid = $zwsid;

        if ($url) {
            $this->url = $url;
        }
    }

    /**
     * @return string
     */
    protected function getUrl()
    {
        return $this->url;
    }

    /**
     * @return string
     */
    protected function getZwsid()
    {
        return $this->zwsid;
    }

    /**
     * @param GuzzleClientInterface $client
     *
     * @return ZillowApiClient
     */
    public function setClient(GuzzleClientInterface $client)
    {
        $this->client = $client;

        return $this;
    }

    /**
     * @return GuzzleClient
     */
    public function getClient()
    {
        if (!$this->client) {
            $this->client = new GuzzleClient(
                [
                    'defaults' => [
                        'allow_redirects' => false,
                        'cookies'         => true
                    ]
                ]
            );
        }

        return $this->client;
    }

  /**
   * @param string $name
   * @param array $arguments
   *
   * @return Response
   * @throws ZillowException
   */
    public function execute($name, $arguments)
    {
        if (!in_array($name, self::$validMethods)) {
            throw new ZillowException(sprintf('Invalid Zillow API method (%s)', $name));
        }

        return $this->doRequest($name, $arguments);
    }

    /**
     * @param string $call
     * @param array $params
     *
     * @return Response
     * @throws ZillowException
     */
    protected function doRequest($call, array $params)
    {
        if (!$this->getZwsid()) {
            throw new ZillowException('Missing ZWS-ID');
        }

        $response = $this->getClient()->get(
            $this->url . $call . '.htm',
            [
                'query' => array_merge(
                    ['zws-id' => $this->getZwsid()],
                    $params
                ),
            ]
        );

        return $this->parseResponse($call, $response);
    }

    /**
     * @param string $call
     * @param ResponseInterface $rawResponse
     *
     * @return Response
     */
    protected function parseResponse($call, ResponseInterface $rawResponse)
    {
        $response      = new Response();

        if ($rawResponse->getStatusCode() === '200') {
            try {
                $responseArray = json_decode(json_encode($this->parseXML($rawResponse)), true);
            } catch (XmlParseException $e) {
                $this->fail($response, $rawResponse, true, $e);

                return $response;
            }

            $response->setMethod($call);

            if (!array_key_exists('message', $responseArray)) {
                $this->fail($response, $rawResponse, false);
            } else {
                $response->setCode(intval($responseArray['message']['code']));
                $response->setMessage($responseArray['message']['text']);
            }

            if ($response->isSuccessful() && array_key_exists('response', $responseArray)) {
                $response->setData($responseArray['response']);
            }
        } else {
            $this->fail($response, $rawResponse, true);
        }

        return $response;
    }

    /**
     * @param Response $response
     * @param ResponseInterface $rawResponse
     * @param bool $logException
     * @param null $exception
     */
    private function fail(Response $response, ResponseInterface $rawResponse, $logException = false, $exception = null)
    {
        $response->setCode(999);
        $response->setMessage('Invalid response received.');

        if ($logException && $this->logger) {
            $this->logger->error(
                new \Exception(
                    sprintf(
                        'Failed Zillow call.  Status code: %s, Response string: %s',
                        $rawResponse->getStatusCode(),
                        (string) $rawResponse->getBody()
                    ),
                    0,
                    $exception
                )
            );
        }
    }

    private function parseXML(ResponseInterface $response) {
      /**
       * xml method from Guzzle 5
       */
      $config = [];
      $disableEntities = libxml_disable_entity_loader(true);
      $internalErrors = libxml_use_internal_errors(true);
      try {
        // Allow XML to be retrieved even if there is no response body
        $xml = new \SimpleXMLElement(
            (string) $response->getBody() ?: '<root />',
            isset($config['libxml_options']) ? $config['libxml_options'] : LIBXML_NONET,
            false,
            isset($config['ns']) ? $config['ns'] : '',
            isset($config['ns_is_prefix']) ? $config['ns_is_prefix'] : false
        );
        libxml_disable_entity_loader($disableEntities);
        libxml_use_internal_errors($internalErrors);
      } catch (\Exception $e) {
        libxml_disable_entity_loader($disableEntities);
        libxml_use_internal_errors($internalErrors);
        throw new XmlParseException(
            'Unable to parse response body into XML: ' . $e->getMessage(),
            $this,
            $e,
            (libxml_get_last_error()) ?: null
        );
      }
      return $xml;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
}