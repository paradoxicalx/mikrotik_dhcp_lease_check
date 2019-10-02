<?php

namespace RouterOS;

use RouterOS\Exceptions\ClientException;
use RouterOS\Exceptions\ConfigException;
use RouterOS\Exceptions\QueryException;
use RouterOS\Helpers\ArrayHelper;

/**
 * Class Client for RouterOS management
 *
 * @package RouterOS
 * @since   0.1
 */
class Client implements Interfaces\ClientInterface
{
    use SocketTrait, ShortsTrait;

    /**
     * Configuration of connection
     *
     * @var \RouterOS\Config
     */
    private $_config;

    /**
     * API communication object
     *
     * @var \RouterOS\APIConnector
     */

    private $_connector;

    /**
     * Client constructor.
     *
     * @param array|\RouterOS\Config $config
     *
     * @throws \RouterOS\Exceptions\ClientException
     * @throws \RouterOS\Exceptions\ConfigException
     * @throws \RouterOS\Exceptions\QueryException
     */
    public function __construct($config)
    {
        // If array then need create object
        if (\is_array($config)) {
            $config = new Config($config);
        }

        // Check for important keys
        if (true !== $key = ArrayHelper::checkIfKeysNotExist(['host', 'user', 'pass'], $config->getParameters())) {
            throw new ConfigException("One or few parameters '$key' of Config is not set or empty");
        }

        // Save config if everything is okay
        $this->_config = $config;

        // Throw error if cannot to connect
        if (false === $this->connect()) {
            throw new ClientException('Unable to connect to ' . $config->get('host') . ':' . $config->get('port'));
        }
    }

    /**
     * Get some parameter from config
     *
     * @param string $parameter Name of required parameter
     *
     * @return mixed
     * @throws \RouterOS\Exceptions\ConfigException
     */
    private function config(string $parameter)
    {
        return $this->_config->get($parameter);
    }

    /**
     * Send write query to RouterOS
     *
     * @param string|array|\RouterOS\Query $query
     *
     * @return \RouterOS\Client
     * @throws \RouterOS\Exceptions\QueryException
     * @deprecated
     * @codeCoverageIgnore
     */
    public function write($query): Client
    {
        if (\is_string($query)) {
            $query = new Query($query);
        } elseif (\is_array($query)) {
            $endpoint = array_shift($query);
            $query    = new Query($endpoint, $query);
        }

        if (!$query instanceof Query) {
            throw new QueryException('Parameters cannot be processed');
        }

        // Submit query to RouterOS
        return $this->writeRAW($query);
    }

    /**
     * Send write query to RouterOS (modern version of write)
     *
     * @param string|Query $endpoint   Path of API query or Query object
     * @param array|null   $where      List of where filters
     * @param string|null  $operations Some operations which need make on response
     * @param string|null  $tag        Mark query with tag
     *
     * @return \RouterOS\Client
     * @throws \RouterOS\Exceptions\QueryException
     * @throws \RouterOS\Exceptions\ClientException
     * @since 1.0.0
     */
    public function query($endpoint, array $where = null, string $operations = null, string $tag = null): Client
    {
        // If endpoint is string then build Query object
        $query = ($endpoint instanceof Query)
            ? $endpoint
            : new Query($endpoint);

        // Parse where array
        if (!empty($where)) {

            // If array is multidimensional, then parse each line
            if (is_array($where[0])) {
                foreach ($where as $item) {

                    // Null by default
                    $key      = null;
                    $operator = null;
                    $value    = null;

                    switch (\count($item)) {
                        case 1:
                            list($key) = $item;
                            break;
                        case 2:
                            list($key, $operator) = $item;
                            break;
                        case 3:
                            list($key, $operator, $value) = $item;
                            break;
                        default:
                            throw new ClientException('From 1 to 3 parameters of "where" condition is allowed');
                    }
                    $query->where($key, $operator, $value);
                }
            } else {
                // Null by default
                $key      = null;
                $operator = null;
                $value    = null;

                switch (\count($where)) {
                    case 1:
                        list($key) = $where;
                        break;
                    case 2:
                        list($key, $operator) = $where;
                        break;
                    case 3:
                        list($key, $operator, $value) = $where;
                        break;
                    default:
                        throw new ClientException('From 1 to 3 parameters of "where" condition is allowed');
                }

                $query->where($key, $operator, $value);
            }

        }

        // Append operations if set
        if (!empty($operations)) {
            $query->operations($operations);
        }

        // Append tag if set
        if (!empty($tag)) {
            $query->tag($tag);
        }

        // Submit query to RouterOS
        return $this->writeRAW($query);
    }

    /**
     * Send write query object to RouterOS
     *
     * @param \RouterOS\Query $query
     *
     * @return \RouterOS\Client
     * @throws \RouterOS\Exceptions\QueryException
     * @since 1.0.0
     */
    private function writeRAW(Query $query): Client
    {
        // Send commands via loop to router
        foreach ($query->getQuery() as $command) {
            $this->_connector->writeWord(trim($command));
        }

        // Write zero-terminator (empty string)
        $this->_connector->writeWord('');

        return $this;
    }

    /**
     * Read RAW response from RouterOS
     *
     * @return array
     * @since 1.0.0
     */
    private function readRAW(): array
    {
        // By default response is empty
        $response = [];
        // We have to wait a !done or !fatal
        $lastReply = false;

        // Read answer from socket in loop
        while (true) {
            $word = $this->_connector->readWord();

            if ('' === $word) {
                if ($lastReply) {
                    // We received a !done or !fatal message in a precedent loop
                    // response is complete
                    break;
                }
                // We did not receive the !done or !fatal message
                // This 0 length message is the end of a reply !re or !trap
                // We have to wait the router to send a !done or !fatal reply followed by optionals values and a 0 length message
                continue;
            }

            // Save output line to response array
            $response[] = $word;

            // If we get a !done or !fatal line in response, we are now ready to finish the read
            // but we need to wait a 0 length message, switch the flag
            if ('!done' === $word || '!fatal' === $word) {
                $lastReply = true;
            }
        }

        // Parse results and return
        return $response;
    }

    /**
     * Read answer from server after query was executed
     *
     * A Mikrotik reply is formed of blocks
     * Each block starts with a word, one of ('!re', '!trap', '!done', '!fatal')
     * Each block end with an zero byte (empty line)
     * Reply ends with a complete !done or !fatal block (ended with 'empty line')
     * A !fatal block precedes TCP connexion close
     *
     * @param bool $parse
     *
     * @return mixed
     */
    public function read(bool $parse = true)
    {
        // Read RAW response
        $response = $this->readRAW();

        // Parse results and return
        return $parse ? $this->rosario($response) : $response;
    }

    /**
     * Read using Iterators to improve performance on large dataset
     *
     * @return \RouterOS\ResponseIterator
     * @since 1.0.0
     */
    public function readAsIterator(): ResponseIterator
    {
        return new ResponseIterator($this);
    }

    /**
     * This method was created by memory save reasons, it convert response
     * from RouterOS to readable array in safe way.
     *
     * @param array $raw Array RAW response from server
     *
     * @return mixed
     *
     * Based on RouterOSResponseArray solution by @arily
     *
     * @link    https://github.com/arily/RouterOSResponseArray
     * @since   1.0.0
     */
    private function rosario(array $raw): array
    {
        // This RAW should't be an error
        $positions = array_keys($raw, '!re');
        $count     = count($raw);
        $result    = [];

        if (isset($positions[1])) {

            foreach ($positions as $key => $position) {
                // Get length of future block
                $length = isset($positions[$key + 1])
                    ? $positions[$key + 1] - $position + 1
                    : $count - $position;

                // Convert array to simple items
                $item = [];
                for ($i = 1; $i < $length; $i++) {
                    $item[] = array_shift($raw);
                }

                // Save as result
                $result[] = $this->parseResponse($item)[0];
            }

        } else {
            $result = $this->parseResponse($raw);
        }

        return $result;
    }

    /**
     * Parse response from Router OS
     *
     * @param array $response Response data
     *
     * @return array Array with parsed data
     */
    public function parseResponse(array $response): array
    {
        $result = [];
        $i      = -1;
        $lines  = \count($response);
        foreach ($response as $key => $value) {
            switch ($value) {
                case '!re':
                    $i++;
                    break;
                case '!fatal':
                    $result = $response;
                    break 2;
                case '!trap':
                case '!done':
                    // Check for =ret=, .tag and any other following messages
                    for ($j = $key + 1; $j <= $lines; $j++) {
                        // If we have lines after current one
                        if (isset($response[$j])) {
                            $this->pregResponse($response[$j], $matches);
                            if (isset($matches[1][0], $matches[2][0])) {
                                $result['after'][$matches[1][0]] = $matches[2][0];
                            }
                        }
                    }
                    break 2;
                default:
                    $this->pregResponse($value, $matches);
                    if (isset($matches[1][0], $matches[2][0])) {
                        $result[$i][$matches[1][0]] = $matches[2][0];
                    }
                    break;
            }
        }
        return $result;
    }

    /**
     * Parse result from RouterOS by regular expression
     *
     * @param string $value
     * @param array  $matches
     */
    private function pregResponse(string $value, &$matches)
    {
        preg_match_all('/^[=|\.](.*)=(.*)/', $value, $matches);
    }

    /**
     * Authorization logic
     *
     * @param bool $legacyRetry Retry login if we detect legacy version of RouterOS
     *
     * @return bool
     * @throws \RouterOS\Exceptions\ClientException
     * @throws \RouterOS\Exceptions\ConfigException
     * @throws \RouterOS\Exceptions\QueryException
     */
    private function login(bool $legacyRetry = false): bool
    {
        // If legacy login scheme is enabled
        if ($this->config('legacy')) {
            // For the first we need get hash with salt
            $response = $this->query('/login')->read();

            // Now need use this hash for authorization
            $query = new Query('/login', [
                '=name=' . $this->config('user'),
                '=response=00' . md5(\chr(0) . $this->config('pass') . pack('H*', $response['after']['ret']))
            ]);
        } else {
            // Just login with our credentials
            $query = new Query('/login', [
                '=name=' . $this->config('user'),
                '=password=' . $this->config('pass')
            ]);

            // If we set modern auth scheme but router with legacy firmware then need to retry query,
            // but need to prevent endless loop
            $legacyRetry = true;
        }

        // Execute query and get response
        $response = $this->query($query)->read(false);

        // if:
        //  - we have more than one response
        //  - response is '!done'
        // => problem with legacy version, swap it and retry
        // Only tested with ROS pre 6.43, will test with post 6.43 => this could make legacy parameter obsolete?
        if ($legacyRetry && $this->isLegacy($response)) {
            $this->_config->set('legacy', true);
            return $this->login();
        }

        // If RouterOS answered with invalid credentials then throw error
        if (!empty($response[0]) && $response[0] === '!trap') {
            throw new ClientException('Invalid user name or password');
        }

        // Return true if we have only one line from server and this line is !done
        return (1 === count($response)) && isset($response[0]) && ($response[0] === '!done');
    }

    /**
     * Detect by login request if firmware is legacy
     *
     * @param array $response
     *
     * @return bool
     * @throws ConfigException
     */
    private function isLegacy(array &$response): bool
    {
        return \count($response) > 1 && $response[0] === '!done' && !$this->config('legacy');
    }

    /**
     * Connect to socket server
     *
     * @return bool
     * @throws \RouterOS\Exceptions\ClientException
     * @throws \RouterOS\Exceptions\ConfigException
     * @throws \RouterOS\Exceptions\QueryException
     */
    private function connect(): bool
    {
        // By default we not connected
        $connected = false;

        // Few attempts in loop
        for ($attempt = 1; $attempt <= $this->config('attempts'); $attempt++) {

            // Initiate socket session
            $this->openSocket();

            // If socket is active
            if (null !== $this->getSocket()) {
                $this->_connector = new APIConnector(new Streams\ResourceStream($this->getSocket()));
                // If we logged in then exit from loop
                if (true === $this->login()) {
                    $connected = true;
                    break;
                }

                // Else close socket and start from begin
                $this->closeSocket();
            }

            // Sleep some time between tries
            sleep($this->config('delay'));
        }

        // Return status of connection
        return $connected;
    }
}
