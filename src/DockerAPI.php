<?php


namespace src\DockerAPI;


use Curl\Curl;
use Exception;
use WebSocket\BadOpcodeException;
use WebSocket\Client;

/**
 * Class DockerAPI
 * @package common\libs
 */
class DockerAPI
{

    private $base_url;

    private $container_prefix;

    private $ip;

    /**
     * DockerAPI constructor.
     *
     * @param $ip
     */
    public function __construct($ip)
    {
        $this->ip               = $ip;
        $this->base_url         = 'http://' . $ip . ':2376/';
        $this->container_prefix = getenv('DOCKER_PREFIX');
    }

    /**
     * @param $imageTag
     *
     * @return bool
     * @throws Exception
     */
    public function hasImage($imageTag): bool
    {
        $data = $this->listImages();
        foreach ($data as $image) {
            if (!is_array($image->RepoTags)) {
                continue;
            }
            if (in_array($imageTag, $image->RepoTags, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Object
     * @throws Exception
     */
    public function listImages()
    {
        $data = $this->get('images/json');
        if ($data->httpStatusCode === 200) {
            return $data->response;
        }

        throw new DockerException(
            'Failed to list images',
            [
                'status'   => $data->httpStatusCode,
                'response' => $data->response,
            ]
        );
    }

    /**
     * @param $endpoint
     * @param array $params
     *
     * @return Curl
     */
    private function get($endpoint, $params = []): Curl
    {
        $curl = new Curl();
        $curl->setTimeout(getenv('DOCKER_API_TIMEOUT') ?? 5);
        $curl->get($this->base_url . $endpoint, $params);

        return $curl;
    }

    /**
     * @param $image
     * @param $version
     * @param $name
     * @param array $binds
     * @param array $ports
     *
     * @param array $env
     *
     * @return Object
     * @throws Exception
     */
    public function createContainer($image, $version, $name, $binds = [], $ports = [], $env = [])
    {
        $env[]    = 'MC_VERSION=' . $version;
        $portData = $this->parsePorts($ports);
        $payload  = [
            'image'        => $image,
            'Tty'          => true,
            'AttachStdin'  => true,
            'AttachStdout' => true,
            'OpenStdin'    => true,
            'Shell'        => ['/bin/bash'],
//            "AttachStderr" => true,
//            "OpenStdin"    => true,
            'Env'          => $env,
            'Binds'        => $binds,
        ];
        if (count($portData['ExposedPorts']) > 0) {
            $payload['ExposedPorts']               = $portData['ExposedPorts'];
            $payload['HostConfig']                 = [];
            $payload['HostConfig']['Binds']        = $binds;
            $payload['HostConfig']['PortBindings'] = $portData['PortBindings'];
        }
        $data = $this->post('containers/create', ['name' => $this->container_prefix . $name], $payload);
        if ($data->httpStatusCode === 201) {
            return $data->response;
        }
        throw new DockerException(
            'Failed to create container ' . $this->container_prefix . $name,
            [
                'status'   => $data->httpStatusCode,
                'response' => $data->response,
            ]
        );
    }


    /**
     * @param array $ports
     *
     * @return array[]
     */
    public function parsePorts($ports = []): array
    {
        $exposedPorts = [];
        $portBindings = [];
        foreach ($ports as $key => $value) {
            $exposedPorts[$key] = (object) null;
            $portBindings[$key] = [
                [
                    'HostPort' => $value,
                ],
            ];
        }

        return [
            'ExposedPorts' => $exposedPorts,
            'PortBindings' => $portBindings,
        ];
    }

    /**
     * @param $endpoint
     * @param array $params
     * @param array $data
     *
     * @return Curl
     */
    private function post($endpoint, $params = [], $data = []): Curl
    {
        $curl = new Curl();
        $curl->setTimeout(getenv('DOCKER_API_TIMEOUT') ?? 5);
        $curl->setHeader('Content-Type', 'application/json');
        $urlParams = '';
        if (count($params) > 0) {
            $urlParams .= '?';
            foreach ($params as $key => $value) {
                $urlParams .= "$key=$value&";
            }
        }
        $curl->post($this->base_url . $endpoint . $urlParams, $data);

        return $curl;
    }

    /**
     * @param $image
     * @param $name
     * @param array $binds
     * @param array $ports
     * @param array $env
     *
     * @return Object
     * @throws Exception
     */
    public function createAbstractContainer($image, $name, $binds = [], $ports = [], $env = [])
    {
        $portData = $this->parsePorts($ports);
        $payload  = [
            'image'        => $image,
            'Tty'          => true,
            'AttachStdin'  => true,
            'AttachStdout' => true,
            'OpenStdin'    => true,
            'Env'          => $env,
            'Binds'        => $binds,
        ];
        if (count($portData['ExposedPorts']) > 0) {
            $payload['ExposedPorts']               = $portData['ExposedPorts'];
            $payload['HostConfig']                 = [];
            $payload['HostConfig']['Binds']        = $binds;
            $payload['HostConfig']['PortBindings'] = $portData['PortBindings'];
        }
        $data = $this->post('containers/create', ['name' => $name], $payload);
        if ($data->httpStatusCode === 201) {
            return $data->response;
        }
        throw new DockerException(
            'Failed to create container ' . $name,
            [
                'status'   => $data->httpStatusCode,
                'response' => $data->response,
            ]
        );
    }

    /**
     * @param $hash
     *
     * @return Object
     * @throws Exception
     */
    public function startContainer($hash)
    {
        $data = $this->post('containers/' . $hash . '/start');
        if ($data->httpStatusCode === 204) {
            return $data->response;
        }
        throw new DockerException(
            'Failed to start container ' . $hash,
            [
                'status'   => $data->httpStatusCode,
                'response' => $data->response,
            ]
        );
    }

    /**
     * @param $hash
     *
     * @return Object
     * @throws Exception
     */
    public function reStartContainer($hash)
    {
        $data = $this->post('containers/' . $hash . '/restart');
        if ($data->httpStatusCode === 204) {
            return $data->response;
        }
        throw new DockerException(
            'Failed to start container ' . $hash,
            [
                'status'   => $data->httpStatusCode,
                'response' => $data->response,
            ]
        );
    }

    /**
     * @param $hash
     *
     * @return Object
     * @throws Exception
     */
    public function killContainer($hash)
    {
        $data = $this->post('containers/' . $hash . '/kill');
        if ($data->httpStatusCode === 204) {
            return $data->response;
        }
        throw new DockerException(
            'Failed to kill container ' . $hash,
            [
                'status'   => $data->httpStatusCode,
                'response' => $data->response,
            ]
        );
    }

    /**
     * @param $hash
     *
     * @param int $forceTimeout
     *
     * @return Object
     * @throws Exception
     */
    public function stopContainer($hash, $forceTimeout = 30)
    {
        // t = seconds to force kill
        $data = $this->post('containers/' . $hash . '/stop', ['t' => $forceTimeout]);
        if ($data->httpStatusCode === 204) {
            return $data->response;
        }
        throw new DockerException(
            'Failed to stop container ' . $hash,
            [
                'status'   => $data->httpStatusCode,
                'response' => $data->response,
            ]
        );
    }

    /**
     * @param $hash
     * @param int $lines
     *
     * @return array<int, string>
     * @throws Exception
     */
    public function getLogs($hash, $lines = 50): array
    {
        $terminalMappings = [
            "\r"                   => "\r\n",
            "[m>....\r[K[31;1m" => "\r\n",
            "\t\t"                 => "",
            "[m[m]"              => "",
            "[K"                 => "",
            "[[m["               => "",
            ">[2K\r"              => "\r\n",
            "["                   => "\r\n",
            ""                   => "\r\n",
            "]0;"                  => "",
            "m>...."               => "",
            "[32m"                 => "",
        ];
        $logsDelimiter    = "\r\n"; //Returned by docker as a new line character
        $logsDelimiterEnd = '
>'; //Returned by docker as a new line character
        $data             = $this->get(
            'containers/' . $hash . '/logs',
            [
                'tail'   => $lines,
                'stdout' => true,
            ]
        );
        $rawLog           = $data->response;
        $parsedLog        = $rawLog;
        foreach ($terminalMappings as $key => $value) {
            $parsedLog = str_replace($key, $value, $parsedLog);
        }
        if ($data->httpStatusCode === 200) {
            $data     = explode($logsDelimiter, $parsedLog);
            $response = [];
            foreach ($data as $line) {
                if ($line === '>' || empty($line) || $line === 'm') {
                    continue;
                }
                $response[] = trim(str_replace($logsDelimiterEnd, '', $line));
            }

            return $response;
        }
        throw new DockerException(
            'Failed get container logs' . $hash,
            [
                'status'   => $data->httpStatusCode,
                'response' => $data->response,
            ]
        );
    }

    /**
     * @param $hash
     * @param $cmd
     * @param $useRcon
     *
     * @return bool|null
     * @throws BadOpcodeException
     */
    public function sendCommand($hash, $cmd, $useRcon)
    {
        if ($useRcon === 1) {
            $data = $this->post(
                'containers/' . $hash . '/exec',
                [],
                [
                    'Cmd'          => $cmd,
                    'WorkingDir'   => '/server',
                    'Tty'          => true,
                    'AttachStdout' => true,
                ]
            );
            if ($data->httpStatusCode === 201) {
                $execId = $data->response->Id;
                $data   = $this->post(
                    'exec/' . $execId . '/start',
                    [],
                    [
                        'Detach' => true,
                        'Tty'    => true,
                    ]
                );
                if ($data->httpStatusCode === 200) {
                    return $data->response;
                }
            }
            throw new DockerException(
                'Failed to execute command on container ' . $hash,
                [
                    'status'   => $data->httpStatusCode,
                    'response' => $data->response,
                ]
            );
        }
        $client = new Client('ws://' . $this->ip . ':2376/containers/' . $hash . '/attach/ws?stream=true');
        $client->send($cmd . "\n");
        $client->close();

        return true;
    }

    /**
     * @param $hash
     *
     * @return Object
     * @throws Exception
     */
    public function inspect($hash)
    {
        $data = $this->get('containers/' . $hash . '/json');
        if ($data->httpStatusCode === 200) {
            return $data->response;
        }
        throw new DockerException(
            'Failed to inspect container ' . $hash,
            [
                'status'   => $data->httpStatusCode,
                'response' => $data->response,
            ]
        );
    }

    /**
     * @param $hash
     *
     * @return Object
     * @throws Exception
     */
    public function stats($hash)
    {
        $data = $this->get('containers/' . $hash . '/stats?stream=false');
        if ($data->httpStatusCode === 200) {
            return $data->response;
        }
        throw new DockerException(
            'Failed get container stats ' . $hash,
            [
                'status'   => $data->httpStatusCode,
                'response' => $data->response,
            ]
        );
    }

    /**
     * @param $hash
     * @param bool $deleteVolumes
     * @param bool $forceKill
     *
     * @return Object
     * @throws Exception
     */
    public function deleteContainer($hash, $deleteVolumes = true, $forceKill = true)
    {
        $data = $this->delete('containers/' . $hash, ['v' => $deleteVolumes, 'force' => $forceKill]);
        if ($data->httpStatusCode === 204) {
            return $data->response;
        }
        throw new DockerException(
            'Failed to delete container ' . $hash,
            [
                'status'   => $data->httpStatusCode,
                'response' => $data->response,
            ]
        );
    }

    /**
     * @param $endpoint
     * @param array $params
     * @param array $data
     *
     * @return Curl
     */
    private function delete($endpoint, $params = [], $data = []): Curl
    {
        $curl = new Curl();
        $curl->setTimeout(getenv('DOCKER_API_TIMEOUT') ?? 5);
        $curl->setHeader('Content-Type', 'application/json');
        $urlParams = '';
        if (count($params) > 0) {
            $urlParams .= '?';
            foreach ($params as $key => $value) {
                $urlParams .= "$key=$value&";
            }
        }
        $curl->delete($this->base_url . $endpoint . $urlParams, $data);

        return $curl;
    }

    /**
     * @param $hash
     * @param $startMemory
     * @param $maxMemory
     * @param $memorySwap
     * @param $cpuQuota
     * @param $cpuPriority
     *
     * @return Object
     * @throws DockerException
     */
    public function updateContainer($hash, $startMemory, $maxMemory, $memorySwap, $cpuQuota, $cpuPriority)
    {
        $payload = [
            'CpuShares'         => $cpuPriority,
            'Memory'            => $maxMemory * 1048576,
            'MemoryReservation' => $startMemory * 1048576,
            'MemorySwap'        => $memorySwap * 1048576,
            'NanoCPUs'          => $cpuQuota * 10 ^ 9,
        ];

        $data = $this->post('containers/' . $hash . '/update', [], $payload);
        if ($data->httpStatusCode === 200) {
            return $data->response;
        }
        throw new DockerException(
            'Failed to update container ' . $hash,
            [
                'status'   => $data->httpStatusCode,
                'response' => $data->response,
            ]
        );
    }

}
