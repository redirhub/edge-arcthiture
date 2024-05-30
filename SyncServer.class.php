<?php
require_once 'MFPad_Node_Base.class.php';

class SyncServer extends MFPad_Node_Base
{
    public $loaded = false;
    private $server = null;

    public function __construct()
    {
        // @mkdir(".node_logs", 0777);
        // @mkdir(".node_certs", 0777);
        // @mkdir(".node_nginx", 0777);
        // @mkdir(".node_route_config", 0777);
        parent::__construct();
    }

    public function run($node_name = null, $key = null, $request = null, $response = null)
    {
        if ($request) {
            $this->request = $request;
            $this->response = $response;
            $this->x_HTTP_HOST = $request->header['host'] ?? '';
        }
        try {
            $this->header("Content-Type: application/json; charset=UTF-8");
            if ($this->input("action") == 'config') {
                $api_response = new Node_API_Response();
                return $this->out($this->postData());
                // return $this->out(new Node_API_Response('Config refreshed'));
            }

            $this->loaded = false;
            $api_response = null;
            if ($this->node_key != $key) {
                return $this->out(new Node_API_Response("同步密钥错误: {$key} 本节点ID：{$this->node_name}", 'failed'));
            }
            if ($this->node_name != $node_name) {
                return $this->out(new Node_API_Response("节点数据包标示不符错误，装载被拒绝！ID:" . $node_name, 'failed'));
            }
            $method = $this->input("method");
            if ($this->input('ver') != MFPad_Node_Base::NODE_VER) {
                return $this->out(new Node_API_Response('同步程序版本不符！Ver: ' . MFPad_Node_Base::NODE_VER, 'failed'));
            }
            switch ($method) {
                case "postData":
                    $api_response = new Node_API_Response();
                    return $this->out($this->postData());
                    break;
                case "checkCloudPageUpdate":
                    return $this->out($this->checkCloudPageUpdate());
                    break;
                default:
                    return $this->out(new Node_API_Response('操作未指定！', 'failed'));
            }
        } catch (Exception $e) {
            $api_response = new Node_API_Response($e->getMessage(), 'failed');
            return $this->out($api_response);
        }
    }

    public function postData()
    {
        $api_response = new Node_API_Response();
        if ($this->request) {
            $body = $this->request->getContent();
        } else {
            $body = file_get_contents("php://input");
        }
        //        if (!$body) $body = $GLOBALS["HTTP_RAW_POST_DATA"];
        $compress = $this->input('compress');
        if ($compress == 'gzip' && $body) {
            $body = gzdecode($body);
        }
        $domainsData = json_decode($body, true);
        $count = 0;
        $count_file = 0;
        if (!is_array($domainsData)) {
            throw new Exception("数据包为空");
        }
        foreach ($domainsData as $key => $data) {
            if (!$key) throw new Exception('post Data in Error(key is null)!');
            if (isset($data['is_file'])) {
                file_put_contents(dirname(__FILE__) . DIRECTORY_SEPARATOR . $key, $data['content']);
                $count_file += 1;
            } else {
                $this->writeCache($key, $data);
                $count += 1;
            }
        }
        $this->loaded = true;
        $api_response->message = "loaded with $count data & $count_file files";
        return $api_response;
    }

    public function out($content)
    {
        return $content;
    }
}
