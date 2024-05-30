<?php
// cd ~/dev/LaravelMFPad/public/Public/Node/Node_v2 && zip ../node_server.zip ./*.* ./setup
ini_set("display_errors", "On");
error_reporting(E_ALL);
$port = $argv[1] ?? 8001;
$http = new Swoole\HTTP\Server("0.0.0.0", $port);
$http->set(array(
    'worker_num' => 4,
    'max_connection' => 10000,
    'package_max_length'  => 50 * 1024 * 1024,  // 50M
    'reload_async' => true,
    'max_wait_time' => 3,
    'http_parse_post' => false,
    'http_compression' => true,
));

$http->on('start', function ($server) use ($port) {
    echo "Swoole Cluster server is started at http://0.0.0.0:$port\n";
});

$http->on('WorkerStart', function (Swoole\Server $http, int $workerId) {
    try {
        //    var_dump(get_included_files()); //此数组中的文件表示进程启动前就加载了，所以无法reload
        require_once 'Client.class.php';
        require_once 'SyncServer.class.php';
        $http->baseServer = new MFPad_Node_Base();
        echo "{$http->baseServer->node_name} worker started $workerId \n";
        // $banned_ip_auto = getIpListFromRecord($http->baseServer->readCache('cluster.sys/ip-auto'));
        // $banned_ip_manual = getIpListFromRecord($http->baseServer->readCache('cluster.sys/ip-manual'));
        // $http->bannedIps = array_merge($banned_ip_auto, $banned_ip_manual);
        // echo "CC Defenser banned IP: \n";
        // var_dump($http->bannedIps);
        if ($http->baseServer->using_cache) {
            echo "MongoDB using... \n";
        }
    } catch (Throwable $e) {
        echo "Error: {$e->getMessage()} \n\n\n";
        // throw new Swoole\ExitException($e->getMessage());

        // var_dump($e->getFile());
    }
});

$http->on('request', function (\Swoole\Http\Request $request, \Swoole\Http\Response $response) use ($http) {
    if (!isset($http->baseServer)) {
        echo "Server not started \n";
        $response->status(401);
        $response->end("Cluster not configured. 节点未正确配置并启动。");
    }
    try {
        $clientIp = $request->header['x-forwarded-for'] ?? $request->server['remote_addr'];
        // if (is_array($http->bannedIps) && in_array($clientIp, $http->bannedIps)) {
        //     $response->status(429);
        //     $response->header("Cache-Control", "no-store, no-cache, must-revalidate");
        //     $response->end("Your IP has been banned.");
        //     return;
        // }
        $raw_http = explode("\n", $request->getData(), 3);
        $uri = trim(explode(' ', $raw_http[0], 3)[1]) ?? '';
        $host_with_port = $request->header['host'];
        $host = trim(explode(':', $host_with_port, 2)[0]) ?? $host_with_port;
        echo ("{$request->server['request_time']} - $clientIp - $host$uri\n");

        switch ($request->server['request_uri']) {
            case "/node_sync_api.php":
                $key = $request->get['sync_key'] ?? '';
                $node_name = $request->get['node_name'] ?? '';
                $syncServer = new SyncServer();
                $response->end($syncServer->run($node_name, $key, $request, $response));
                if ($request->get['reload'] ?? false) {
                    $http->reload();
                }
                break;
            case "/robots.txt":
                $response->status(404);
                $response->end();
                break;
            default:
                $url = $request->get['__url'] ?? $host . $uri;
                if (strpos($url, 'http') === false) $url = 'http://' . $url;
                $cs = new Client($http->baseServer);
                $cs->run($url, $request, $response);
        }
    } catch (Swoole\ExitException $e) {
    } catch (Throwable $e) {
        $response->end("<h3> {$e->getMessage()} </h3>");
        echo "Error: {$e->getMessage()}\n\n";
        // var_dump($e->getFile());
    }
});

$http->start();


function getResponseHeader($header, $response)
{
    foreach ($response as $key => $r) {
        // Match the header name up to ':', compare lower case
        if (stripos($r, $header . ':') === 0) {
            list($headername, $headervalue) = explode(":", $r, 2);
            return trim($headervalue);
        }
    }
}

function getIpListFromRecord($bannedIpRecord)
{
    // var_dump($bannedIpRecord);
    $bannedIps = $bannedIpRecord['urls']['list'] ?? [];
    $plainIps = [];
    foreach ($bannedIps as $ipLine) {
        $plainIps[] = parse_url($ipLine)['host'] ?? '';
    }
    return $plainIps;
}
