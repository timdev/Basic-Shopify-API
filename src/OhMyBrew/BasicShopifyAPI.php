<?php

namespace OhMyBrew;

use Closure;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use stdClass;

/**
 * Basic Shopify API for REST & GraphQL.
 */
class BasicShopifyAPI implements LoggerAwareInterface
{
    /**
     * API version pattern.
     *
     * @var string
     */
    const VERSION_PATTERN = '/([0-9]{4}-[0-9]{2})|unstable/';

    /**
     * The key to use for logging (prefix for filtering).
     *
     * @var string
     */
    const LOG_KEY = '[BasicShopifyAPI]';

    /**
     * Paths to GraphQL endpoints.
     */
    const GRAPHQL_PATHS = [
        'admin' => '/admin/api/graphql.json',
        'storefront' => '/api/graphql.json'
    ];

    /**
     * The Guzzle client.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * The version of API.
     *
     * @var string
     */
    protected $version;

    /**
     * The Shopify domain.
     *
     * @var string
     */
    protected $shop;

    /**
     * The Shopify access token.
     *
     * @var string
     */
    protected $accessToken;

    /**
     * The Shopify API key.
     *
     * @var string
     */
    protected $apiKey;

    /**
     * The Shopify API password.
     *
     * @var string
     */
    protected $apiPassword;

    /**
     * The Shopify API secret.
     *
     * @var string
     */
    protected $apiSecret;

    /**
     * The Storefront API access token
     * @var string
     */
    protected $storefrontToken;

    /**
     * If API calls are from a public or private app.
     *
     * @var string
     */
    protected $private;

    /**
     * If the API was called with per-user grant option, this will be filled.
     *
     * @var stdClass
     */
    protected $user;

    /**
     * The current API call limits from last request.
     *
     * @var array
     */
    protected $apiCallLimits = [
        'rest'  => [
            'left'  => 0,
            'made'  => 0,
            'limit' => 40,
        ],
        'graph' => [
            'left'          => 0,
            'made'          => 0,
            'limit'         => 1000,
            'restoreRate'   => 50,
            'requestedCost' => 0,
            'actualCost'    => 0,
        ],
    ];

    /**
     * If rate limiting is enabled.
     *
     * @var bool
     */
    protected $rateLimitingEnabled = false;

    /**
     * The rate limiting cycle (in ms).
     *
     * @var int
     */
    protected $rateLimitCycle = 0.5 * 1000;

    /**
     * The rate limiting cycle buffer (in ms).
     *
     * @var int
     */
    protected $rateLimitCycleBuffer = 0.1 * 1000;

    /**
     * Request timestamp for every new call.
     * Used for rate limiting.
     *
     * @var int
     */
    protected $requestTimestamp;

    /**
     * The logger.
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Constructor.
     *
     * @param bool $private If this is a private or public app
     *
     * @return self
     */
    public function __construct(bool $private = false)
    {
        // Set if app is private or public
        $this->private = $private;

        // Create the stack and assign the middleware which attempts to fix redirects
        $stack = HandlerStack::create();
        $stack->push(Middleware::mapRequest([$this, 'authRequest']));

        // Create a default Guzzle client with our stack
        $this->client = new Client([
            'handler'  => $stack,
            'headers'  => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Determines if the calls are private.
     *
     * @return bool
     */
    public function isPrivate()
    {
        return $this->private === true;
    }

    /**
     * Determines if the calls are public.
     *
     * @return bool
     */
    public function isPublic()
    {
        return !$this->isPrivate();
    }

    /**
     * Sets the Guzzle client for the API calls (allows for override with your own).
     *
     * @param \GuzzleHttp\Client $client The Guzzle client
     *
     * @return self
     */
    public function setClient(Client $client)
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Sets the version of Shopify API to use.
     *
     * @param string $version
     *
     * @return self
     */
    public function setVersion(string $version)
    {
        if (!preg_match(self::VERSION_PATTERN, $version)) {
            // Invalid version string
            throw new Exception('Version string must be of YYYY-MM or unstable');
        }

        $this->version = $version;

        return $this;
    }

    /**
     * Returns the current in-use API version.
     *
     * @return string
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * Sets the Shopify domain (*.myshopify.com) we're working with.
     *
     * @param string $shop The myshopify domain
     *
     * @return self
     */
    public function setShop(string $shop)
    {
        $this->shop = $shop;

        return $this;
    }

    /**
     * Gets the Shopify domain (*.myshopify.com) we're working with.
     *
     * @return string
     */
    public function getShop()
    {
        return $this->shop;
    }

    /**
     * Sets the access token for use with the Shopify API (public apps).
     *
     * @param string $accessToken The access token
     *
     * @return self
     */
    public function setAccessToken(string $accessToken)
    {
        $this->accessToken = $accessToken;

        return $this;
    }

    /**
     * Gets the access token.
     *
     * @return string
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * Sets the API key for use with the Shopify API (public or private apps).
     *
     * @param string $apiKey The API key
     *
     * @return self
     */
    public function setApiKey(string $apiKey)
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    /**
     * Sets the API secret for use with the Shopify API (public apps).
     *
     * @param string $apiSecret The API secret key
     *
     * @return self
     */
    public function setApiSecret(string $apiSecret)
    {
        $this->apiSecret = $apiSecret;

        return $this;
    }

    /**
     * Sets the API password for use with the Shopify API (private apps).
     *
     * @param string $apiPassword The API password
     *
     * @return self
     */
    public function setApiPassword(string $apiPassword)
    {
        $this->apiPassword = $apiPassword;

        return $this;
    }

    public function setStorefrontToken(string $token)
    {
        $this->storefrontToken = $token;

        return $this;
    }

    public function getStorefrontToken()
    {
        return $this->storefrontToken;
    }

    /**
     * Sets the user (public apps).
     *
     * @param stdClass $user The user returned from the access request.
     *
     * @return self
     */
    public function setUser(stdClass $user)
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Gets the user.
     *
     * @return stdClass
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Checks if we have a user.
     *
     * @return bool
     */
    public function hasUser()
    {
        return $this->user !== null;
    }

    /**
     * Set the rate limiting state to enabled.
     *
     * @param int|null $cycle  The rate limiting cycle (in ms, default 500ms).
     * @param int|null $buffer The rate limiting cycle buffer (in ms, default 100ms).
     *
     * @return self
     */
    public function enableRateLimiting(int $cycle = null, int $buffer = null)
    {
        $this->rateLimitingEnabled = true;

        if (!is_null($cycle)) {
            $this->rateLimitCycle = $cycle;
        }

        if (!is_null($cycle)) {
            $this->rateLimitCycleBuffer = $buffer;
        }

        return $this;
    }

    /**
     * Set the rate limiting state to disabled.
     *
     * @return self
     */
    public function disableRateLimiting()
    {
        $this->rateLimitingEnabled = false;

        return $this;
    }

    /**
     * Determines if rate limiting is enabled.
     *
     * @return bool
     */
    public function isRateLimitingEnabled()
    {
        return $this->rateLimitingEnabled === true;
    }

    /**
     * Simple quick method to set shop and access token in one shot.
     *
     * @param string $shop        The shop's domain
     * @param string $accessToken The access token for API requests
     *
     * @return self
     */
    public function setSession(string $shop, string $accessToken)
    {
        $this->setShop($shop);
        $this->setAccessToken($accessToken);

        return $this;
    }

    /**
     * Accepts a closure to do isolated API calls for a shop.
     *
     * @param string  $shop        The shop's domain
     * @param string  $accessToken The access token for API requests
     * @param Closure $closure     The closure to run isolated
     *
     * @throws \Exception When closure is missing or not callable
     *
     * @return self
     */
    public function withSession(string $shop, string $accessToken, Closure $closure)
    {
        $this->log("WithSession started for {$shop}");

        // Clone the API class and bind it to the closure
        $clonedApi = clone $this;
        $clonedApi->setSession($shop, $accessToken);

        return $closure->call($clonedApi);
    }

    /**
     * Returns the base URI to use.
     *
     * @return \GuzzleHttp\Psr7\Uri
     */
    public function getBaseUri()
    {
        if ($this->shop === null) {
            // Shop is required
            throw new Exception('Shopify domain missing for API calls');
        }

        return new Uri("https://{$this->shop}");
    }

    /**
     * Gets the authentication URL for Shopify to allow the user to accept the app (for public apps).
     *
     * @param string|array $scopes      The API scopes as a comma seperated string or array
     * @param string       $redirectUri The valid redirect URI for after acceptance of the permissions.
     *                                  It must match the redirect_uri in your app settings.
     * @param string|null  $mode        The API access mode, offline or per-user.
     *
     * @return string Formatted URL
     */
    public function getAuthUrl($scopes, string $redirectUri, string $mode = 'offline')
    {
        if ($this->apiKey === null) {
            throw new Exception('API key is missing');
        }

        if (is_array($scopes)) {
            $scopes = implode(',', $scopes);
        }

        $query = [
            'client_id'    => $this->apiKey,
            'scope'        => $scopes,
            'redirect_uri' => $redirectUri,
        ];

        if ($mode !== null && $mode !== 'offline') {
            $query['grant_options'] = [$mode];
        }

        return (string) $this->getBaseUri()
            ->withPath('/admin/oauth/authorize')
            ->withQuery(
                preg_replace('/\%5B\d+\%5D/', '%5B%5D', http_build_query($query))
            );
    }

    /**
     * Verify the request is from Shopify using the HMAC signature (for public apps).
     *
     * @param array $params The request parameters (ex. $_GET)
     *
     * @return bool If the HMAC is validated
     */
    public function verifyRequest(array $params)
    {
        if ($this->apiSecret === null) {
            // Secret is required
            throw new Exception('API secret is missing');
        }

        // Ensure shop, timestamp, and HMAC are in the params
        if (array_key_exists('shop', $params)
            && array_key_exists('timestamp', $params)
            && array_key_exists('hmac', $params)
        ) {
            // Grab the HMAC, remove it from the params, then sort the params for hashing
            $hmac = $params['hmac'];
            unset($params['hmac']);
            ksort($params);

            // Encode and hash the params (without HMAC), add the API secret, and compare to the HMAC from params
            return $hmac === hash_hmac('sha256', urldecode(http_build_query($params)), $this->apiSecret);
        }

        // Not valid
        return false;
    }

    /**
     * Gets the access object from a "code" supplied by Shopify request after successfull authentication (for public apps).
     *
     * @param string $code The code from Shopify
     *
     * @throws \Exception When API secret is missing
     *
     * @return array The access object
     */
    public function requestAccess(string $code)
    {
        if ($this->apiSecret === null || $this->apiKey === null) {
            // Key and secret required
            throw new Exception('API key or secret is missing');
        }

        // Do a JSON POST request to grab the access token
        $request = $this->client->request(
            'POST',
            $this->getBaseUri()->withPath('/admin/oauth/access_token'),
            [
                'json' => [
                    'client_id'     => $this->apiKey,
                    'client_secret' => $this->apiSecret,
                    'code'          => $code,
                ],
            ]
        );

        // Decode the response body
        $body = json_decode($request->getBody());

        $this->log('RequestAccess response: '.json_encode($body));

        return $body;
    }

    /**
     * Gets the access token from a "code" supplied by Shopify request after successfull authentication (for public apps).
     *
     * @param string $code The code from Shopify
     *
     * @return string The access token
     */
    public function requestAccessToken(string $code)
    {
        return $this->requestAccess($code)->access_token;
    }

    /**
     * Gets the access object from a "code" and sets it to the instance (for public apps).
     *
     * @param string $code The code from Shopify
     *
     * @return void
     */
    public function requestAndSetAccess(string $code)
    {
        $access = $this->requestAccess($code);

        // Set the access token
        $this->setAccessToken($access->access_token);

        if (property_exists($access, 'associated_user')) {
            // Set the user if applicable
            $this->setUser($access->associated_user);
            $this->log('User access: '.json_encode($access->associated_user));
        }
    }

    /**
     * Alias for REST method for backwards compatibility.
     *
     * @see rest
     */
    public function request()
    {
        return call_user_func_array([$this, 'rest'], func_get_args());
    }

    /**
     * Returns the current API call limits.
     *
     * @param string|null $key The key to grab (left, made, limit, etc)
     *
     * @throws \Exception When attempting to grab a key that doesn't exist
     *
     * @return array An array of the Guzzle response, and JSON-decoded body
     */
    public function getApiCalls(string $type = 'rest', string $key = null)
    {
        if ($key) {
            $keys = array_keys($this->apiCallLimits[$type]);
            if (!in_array($key, $keys)) {
                // No key like that in array
                throw new Exception('Invalid API call limit key. Valid keys are: '.implode(', ', $keys));
            }

            // Return the key value requested
            return $this->apiCallLimits[$type][$key];
        }

        // Return all the values
        return $this->apiCallLimits[$type];
    }

    /**
     * Runs a request to the Shopify API.
     *
     * @param string $query     The GraphQL query
     * @param array  $variables The optional variables for the query
     * @param string $path      Endpoint path for request.
     *
     * @throws \Exception When missing api password is missing for private apps
     * @throws \Exception When missing access key is missing for public apps
     *
     * @return object An object containing of the Guzzle response, and JSON-decoded body
     */
    private function _graph(string $query, array $variables = [], $path = '/admin/api/graphql.json')
    {

        // Build the request
        $request = ['query' => $query];
        if (count($variables) > 0) {
            $request['variables'] = $variables;
        }

        $limits = $this->apiCallLimits['graph'];


        if($limits && $limits['left'] < 500 && $this->requestTimestamp > microtime(true)-1){
            // restore rate of 50/second.
            // we want at least 600 to before continuing.
            $secondsToSleep = ((600 - $limits['left'])/50) + 2;
            sleep($secondsToSleep);
        }

        // Update the timestamp of the request
        $tmpTimestamp = $this->requestTimestamp;
        $this->requestTimestamp = microtime(true);

        // Create the request, pass the access token and optional parameters
        $req = json_encode($request);
        $response = $this->client->request(
            'POST',
            $this->getBaseUri()->withPath(
                $this->versionPath($path)
            ),
            ['body' => $req]
        );
        $this->log("Graph request: {$req}");

        // Grab the data result and extensions
        $body = $this->jsonDecode($response->getBody());
        if (property_exists($body, 'extensions') && property_exists($body->extensions, 'cost')) {
            // Update the API call information
            $calls = $body->extensions->cost;
            $this->apiCallLimits['graph'] = [
                'left'          => (int) $calls->throttleStatus->currentlyAvailable,
                'made'          => (int) ($calls->throttleStatus->maximumAvailable - $calls->throttleStatus->currentlyAvailable),
                'limit'         => (int) $calls->throttleStatus->maximumAvailable,
                'restoreRate'   => (int) $calls->throttleStatus->restoreRate,
                'requestedCost' => (int) $calls->requestedQueryCost,
                'actualCost'    => (int) $calls->actualQueryCost,
            ];
        }
        $this->log('Graph response: '.json_encode(property_exists($body, 'errors') ? $body->errors : $body->data));

        // Return Guzzle response and JSON-decoded body
        return (object) [
            'response'   => $response,
            'body'       => property_exists($body, 'errors') ? $body->errors : $body->data,
            'errors'     => property_exists($body, 'errors'),
            'timestamps' => [$tmpTimestamp, $this->requestTimestamp],
        ];
    }

    /**
     * Wraps _graph() with exponential backoff in case of throttling.
     * @return object
     * @throws Exception
     */
    protected function _graphWithRetry(string $query, array $variables = [], int $maxTries = 10, string $path = null)
    {
        $throttleCount = 0;
        while(true) {
            $res = $this->_graph($query, $variables, $path);

            // Handle errors
            if ($res->errors) {

                // Throttled?
                if ($res->body[0]->message && $res->body[0]->message === 'Throttled'){

                    // Only retry for so long.
                    if ($throttleCount === $maxTries){
                        $this->logger->error("Throttled {$maxTries} times by Shopify.  Giving up.");
                        throw new \RuntimeException('Shopify was too busy to get us the data we need.  Please try again later.');
                    }

                    // Retry with exponential back-off.
                    $sleepTime = 2 + ($throttleCount * 2);
                    $this->logger->debug("ShopifyAPI Throttled.  Waiting {$sleepTime} seconds.");
                    ++$throttleCount;
                    sleep($sleepTime);
                    continue;
                }

                $this->logger->error("Error while querying", ['body' => $res->body, 'status' => $res->response->getStatusCode()]);
                throw new \RuntimeException("graphql query failed");
            }

            // Success, return result.
            return $res;
        }
    }

    public function graph(string $query, array $variables = [])
    {
        return $this->_graphWithRetry($query, $variables, 10, self::GRAPHQL_PATHS['admin']);
    }

    public function storefront(string $query, array $variables = [])
    {
        return $this->_graph($query, $variables, self::GRAPHQL_PATHS['storefront']);
    }


    private function waitForRateLimit()
    {
        // Check the rate limit before firing the request
        if ($this->isRateLimitingEnabled() && $this->requestTimestamp) {
            // Calculate in milliseconds the duration the API call took
            $duration = round(microtime(true) - $this->requestTimestamp, 3) * 1000;
            $waitTime = ($this->rateLimitCycle - $duration) + $this->rateLimitCycleBuffer;

            if ($waitTime > 0) {
                // Do the sleep for X mircoseconds (convert from milliseconds)
                $this->log('Rest rate limit hit');
                usleep($waitTime * 1000);
            }
        }
    }

    /**
     * Runs a request to the Shopify API.
     *
     * @param string     $type   The type of request... GET, POST, PUT, DELETE
     * @param string     $path   The Shopify API path... /admin/xxxx/xxxx.json
     * @param array|null $params Optional parameters to send with the request
     *
     * @return object An object with properties containing the Guzzle Response and the JSON-decoded body content
     */
    public function rest(string $type, string $path, array $params = null)
    {
        $this->waitForRateLimit();

        // Update the timestamp of the request
        $tmpTimestamp = $this->requestTimestamp;
        $this->requestTimestamp = microtime(true);

        $errors = false;
        $response = null;
        $body = null;

        try {
            // Build URI and try the request
            $uri = $this->getBaseUri()->withPath($this->versionPath($path));

            // Build the request parameters for Guzzle
            $guzzleParams = [];
            if ($params !== null) {
                $guzzleParams[strtoupper($type) === 'GET' ? 'query' : 'json'] = $params;
            }

            $this->log("[{$uri}:{$type}] Request Params: ".json_encode($params));

            // Set the response
            $response = $this->client->request($type, $uri, $guzzleParams);
            $body = $response->getBody();
        } catch (Exception $e) {
            if ($e instanceof ClientException || $e instanceof ServerException) {
                // 400 or 500 level error, set the response
                $response = $e->getResponse();
                $body = $response->getBody();

                // Build the error object
                $errors = (object) [
                    'status'    => $response->getStatusCode(),
                    'body'      => $this->jsonDecode($body),
                    'exception' => $e,
                ];

                $this->log("[{$uri}:{$type}] {$response->getStatusCode()} Error: {$body}");
            } else {
                // Else, rethrow
                throw $e;
            }
        }

        // Grab the API call limit header returned from Shopify
        $callLimitHeader = $response->getHeader('http_x_shopify_shop_api_call_limit');
        if ($callLimitHeader) {
            $calls = explode('/', $callLimitHeader[0]);
            $this->apiCallLimits['rest'] = [
                'left'  => (int) $calls[1] - $calls[0],
                'made'  => (int) $calls[0],
                'limit' => (int) $calls[1],
            ];
        }

        $this->log("[{$uri}:{$type}] {$response->getStatusCode()}: ".json_encode($errors ? $body->getContents() : $body));

        // Return Guzzle response and JSON-decoded body
        return (object) [
            'response'   => $response,
            'errors'     => $errors,
            'body'       => $errors ? $body->getContents() : $this->jsonDecode($body),
            'timestamps' => [$tmpTimestamp, $this->requestTimestamp],
        ];
    }

    /**
     * Ensures we have the proper request for private and public calls.
     * Also modifies issues with redirects.
     *
     * @param Request $request
     * @return Request
     */
    public function authRequest(Request $request)
    {
        // Get the request URI
        $uri = $request->getUri();

        if ($this->isAuthableRequest((string) $uri)) {
            if ($this->isRestRequest((string) $uri)) {
                // Checks for REST
                if ($this->private && ($this->apiKey === null || $this->apiPassword === null)) {
                    // Key and password are required for private API calls
                    throw new Exception('API key and password required for private Shopify REST calls');
                }

                // Private: Add auth for REST calls
                if ($this->private) {
                    // Add the basic auth header
                    return $request->withHeader(
                        'Authorization',
                        'Basic ' . base64_encode("{$this->apiKey}:{$this->apiPassword}")
                    );
                }

                // Public: Add the token header
                return $request->withHeader(
                    'X-Shopify-Access-Token',
                    $this->accessToken
                );

            }

            if ($this->isStorefrontGraphRequest($uri)) {
                // Checks for Storefront-Graph
                if(null === $this->storefrontToken){
                    throw new Exception('Storefront access token required for Shopify Storefront GraphQL calls');
                }
                return $request->withHeader(
                    'X-Shopify-Storefront-Access-Token',
                    $this->storefrontToken
                );
            }

            if ($this->isAdminGraphRequest($uri)) {
                // Checks for Admin-Graph
                if ($this->private && ($this->apiPassword === null && $this->accessToken === null)) {
                    // Private apps need password for use as access token
                    throw new Exception('API password/access token required for private Shopify GraphQL calls');
                } elseif (!$this->private && $this->accessToken === null) {
                    // Need access token for public calls
                    throw new Exception('Access token required for public Shopify GraphQL calls');
                }

                // Public/Private: Add the token header
                return $request->withHeader(
                    'X-Shopify-Access-Token',
                    $this->apiPassword ?? $this->accessToken
                );
            }
        }

        return $request;
    }

    /**
     * Sets a logger instance on the object.
     *
     * @param LoggerInterface $logger
     *
     * @return void
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Log a message to the logger.
     *
     * @param string $msg   The message to send.
     * @param int    $level The level of message.
     *
     * @return bool
     */
    public function log(string $msg, string $level = LogLevel::DEBUG)
    {
        if ($this->logger === null) {
            // No logger, do nothing
            return false;
        }

        // Call the logger by level and pass the message
        call_user_func([$this->logger, $level], self::LOG_KEY.' '.$msg);

        return true;
    }

    /**
     * Decodes the JSON body.
     *
     * @param string $json The JSON body
     *
     * @return object The decoded JSON
     */
    protected function jsonDecode($json)
    {
        // From firebase/php-jwt
        if (!(defined('JSON_C_VERSION') && PHP_INT_SIZE > 4)) {
            /**
             * In PHP >=5.4.0, json_decode() accepts an options parameter, that allows you
             * to specify that large ints (like Steam Transaction IDs) should be treated as
             * strings, rather than the PHP default behaviour of converting them to floats.
             */
            $obj = json_decode($json, false, 512, JSON_BIGINT_AS_STRING);
        } else {
            // @codeCoverageIgnoreStart
            /**
             * Not all servers will support that, however, so for older versions we must
             * manually detect large ints in the JSON string and quote them (thus converting
             * them to strings) before decoding, hence the preg_replace() call.
             * Currently not sure how to test this so I ignored it for now.
             */
            $maxIntLength = strlen((string) PHP_INT_MAX) - 1;
            $jsonWithoutBigints = preg_replace('/:\s*(-?\d{'.$maxIntLength.',})/', ': "$1"', $json);
            $obj = json_decode($jsonWithoutBigints);
            // @codeCoverageIgnoreEnd
        }

        return $obj;
    }

    /**
     * Determines if the request is to Graph API.
     *
     * @param string $uri
     *
     * @return bool
     */

    protected function isAdminGraphRequest(Uri $uri)
    {
        return $uri->getPath() === self::GRAPHQL_PATHS['admin'];
    }

    /**
     * Determines if the request
     * @param Uri $uri
     *
     * @return bool
     */
    protected function isStorefrontGraphRequest(Uri $uri)
    {
        return $uri->getPath() === self::GRAPHQL_PATHS['storefront'];
    }

    /**
     * Determines if the request is to REST API.
     *
     * @param string $uri
     *
     * @return bool
     */
    protected function isRestRequest(string $uri)
    {
        $uri = new Uri($uri);
        return
            $this->isAdminGraphRequest($uri) === false
            &&
            $this->isStorefrontGraphRequest($uri) === false;
    }

    /**
     * Determines if the request requires auth headers.
     *
     * @param string $uri
     *
     * @return bool
     */
    protected function isAuthableRequest(string $uri)
    {
        return preg_match('/\/admin\/oauth\/(authorize|access_token|access_scopes)/', $uri) === 0;
    }

    /**
     * Versions the API call with the set version.
     *
     * @param string $uri
     *
     * @return string
     */
    protected function versionPath(string $uri)
    {
        if ($this->version === null || preg_match(self::VERSION_PATTERN, $uri) || !$this->isAuthableRequest($uri)) {
            // No version set, or already versioned... nothing to do
            return $uri;
        }

        // Graph request
        if ($this->isGraphRequest($uri)) {
            return str_replace('/admin/api', "/admin/api/{$this->version}", $uri);
        }

        // REST request
        return preg_replace('/\/admin(\/api)?\//', "/admin/api/{$this->version}/", $uri);
    }

    /**
     * Determines if the request is to Graph API.
     *
     * @param string $uri The request URI.
     *
     * @return bool
     */
    protected function isGraphRequest(string $uri): bool
    {
        $uri = new Uri($uri);
        return $this->isAdminGraphRequest($uri) || $this->isStorefrontGraphRequest($uri);
    }


}
