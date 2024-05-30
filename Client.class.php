<?php
require_once 'MFPad_Node_Base.class.php';

class Client extends MFPad_Node_Base
{
    public $url;
    public $platform;
    protected $host;
    protected $file_key;
    protected $domain;
    protected $sname;
    protected $path;
    protected $path_only;
    protected $query_string;

    public $in_wall = false;
    public $using_web_cache = true;
    public $showClusterHeader = 1;
    public $wildcardMode = false;
    protected $x_COOKIE = array();
    protected $record = array();
    protected $level = 1;

    protected $records;
    protected $startTime;
    protected $escapeContent = false;
    const DEFAULT_DOMAIN = 'cluster.sys';
    const FREE_AD = "/freead";

    const LOGGING_PROTECTION = false;


    public function __construct($configServer = null)
    {
        parent::__construct($configServer);
    }

    public function getProcessTime()
    {
        return number_format((microtime(true) - $this->startTime) * 1000, 2);
    }

    public function run($url, $request = null, $response = null)
    {
        $this->initialize($request, $response);
        $this->parseURL($url);

        // new 1.1 records file with organization support
        $key = $this->host . '/' . $this->path_only;
        $key_wildcards_path = $this->host . '/*';
        // $key_wildcards_domain = '*.' . $this->domain . '/' . $this->path_only;
        $key_wildcards_domain_path = '*.' . $this->domain . '/*';
        $this->record = $this->findRecordByFileKey($key);
        if (!$this->record) {
            $key = $key_wildcards_path;
            $this->record = $this->findRecordByFileKey($key);
            $this->wildcardMode = true;
        }
        if (!$this->record && $this->host != $this->domain) {
            $key = $key_wildcards_domain_path;
            $this->record = $this->findRecordByFileKey($key);
            $this->wildcardMode = true;
        }
        if (!$this->record) {
            return $this->forward_error('norecord');
        }
        if ($this->record && isset($this->record['isAlias']) && $this->record['isAlias']) {
            $bindDomain = $this->record['bind'];
            $newHost = str_replace($this->domain, $bindDomain, $this->host);
            $this->host = $newHost;
            $this->domain = $bindDomain;
            $newUrl = "http://{$newHost}/{$this->path}";
            return $this->run($newUrl, $request, $response);
        }
        $this->header("URL-Record-File: {$key}");
        $this->file_key = $key;

        $this->level = isset($this->record['level']) ? $this->record['level'] : 0;
        if ($this->level == 'account') {
            $account_key = (isset($this->record['account']) ? $this->record['account'] : null);
            $account = $account_key ? $this->readCache($account_key) : null;
            if ($account) {
                $this->level = isset($account['level']) ? $account['level'] : 0;
                $user_id = $account['user_id'] ?? 0;
                $org_id = $account['org_id'] ?? 0;
                $this->platform = $account['platform'] ?? 'MFP';
            } else {  // free account can not found on clusters
                $user_id = 0;
                $org_id = $account_key ? intval($account_key) : 0;
                $this->platform = 'MFP';
            }
            $this->header("URL-Aui: {$user_id}/{$org_id}/{$this->platform}");
            if (isset($account['locked']) && $account['locked'] != 0) {
                return $this->forward_error('suspended');
            }
        }
        // compatibility for updates
        if (!isset($this->record['configs'])) $this->record['configs'] = [];
        return $this->parse_record();
    }

    public function initialize($request = null, $response = null)
    {
        // Initial
        $this->startTime = microtime(true);
        if ($request || $response) {
            $this->request = $request;
            $this->response = $response;
            $this->x_HTTP_HOST = $request->header['host'] ?? '';
            $this->x_COOKIE = $request->cookie;
        } else {
            $this->x_HTTP_HOST = $_SERVER['HTTP_HOST'];
            $this->x_COOKIE = $_COOKIE;
        }

        $this->debug = false;
        $this->debug = $this->input('__debug') == '1';
        $this->using_web_cache = $this->getConfig('cache', true);
        $this->in_wall = false;
        $this->wildcardMode = false;
        $this->showClusterHeader = 1;
    }

    public function parseURL($url)
    {
        $this->url = $url;
        // parse path & querysting from uri
        $_url = parse_url($url);
        // resolve issue with empty host when url like https.urlredirectservice.com
        if (empty($_url['host'])) {
            // preg_match('/https?:\/\/([^\/]+)/', $url, $matches);
            preg_match('/^(?:\w+:\/\/)?([^\/]+)/', $url, $matches);
            $this->host = $matches[1];
        } else {
            $this->host = $_url['host'];
        }
        $this->domain = $this->getTLD($this->host);
        $this->sname = ($this->domain == $this->host) ? '@' : str_replace('.' . $this->domain, '', $this->host);

        if (!isset($_url['path'])) {
            $_url['path'] = '';
        }
        if (!isset($_url['query'])) {
            $_url['query'] = '';
        }
        $this->query_string = $_url['query'];
        $this->path = mb_substr($_url['path'], 1) . ($_url['query'] ? ('?' . $_url['query']) : '');
        $this->path_only = trim($_url['path'], '/');
    }

    public function findRecordByFileKey($key)
    {
        return $this->readCache($key);
    }

    public function parse_record()
    {
        $this->header("Content-type: text/html; charset=utf-8");
        $this->header("Url-Cluster: {$this->node_name}");
        if ($this->debug) {
            var_dump($this->url);
            var_dump($this->x_COOKIE);
            var_dump($this->request);
            //            return $this->end();
        }

        if (!isset($this->record['value'])) {
            var_dump($this->record);
            $this->status(500);
            return $this->end();
        }
        $value_info = parse_url($this->record['value']);
        $scheme = isset($value_info['scheme']) ? $value_info['scheme'] : 'url';
        $handler = $this->scheme_handle['other'];  // This Handler is different from Record's handler
        if (isset($this->scheme_handle[$scheme])) {
            $handler = $this->scheme_handle[$scheme];
        }

        // Deal with ACME Challenge
        // Like .well-known/acme-challenge/XT2RkRbYYj4G3r924U01GB5Iu73WiBMQOTjI5yleoCU
        if (
            $this->path !== ''
            && strpos($this->file_key, '.well-known/') == false
            && strpos($this->path, '.well-known/') !== false
        ) {
            $this->forward_acme();
            return $this->end();
        }

        // Deal with favicon.ico
        if (
            $this->path == 'favicon.ico' && $this->wildcardMode
        ) {
            $this->forward_favicon();
            return $this->end();
        }

        // Deal with URLS
        if (
            isset($this->record['urls'])
            && isset($this->record['urls']['list'])
            && is_array($this->record['urls']['list'])
            && count($this->record['urls']['list']) > 0
        ) {
            $urls = $this->record['urls'];
            $count_urls = count($urls['list']);
            if (isset($urls['dispatch']) && $urls['dispatch'] === 'order' && $this->record['record_id']) {
                $record_urls_offset_key = $this->record['record_id'] . '.urls_offset';
                $currentOffset = $this->readCache($record_urls_offset_key, 0);
                if ($currentOffset > $count_urls - 1) {
                    $currentOffset = 0;
                }
                $this->writeCache($record_urls_offset_key, $currentOffset + 1);
            } else {
                try {
                    $currentOffset = rand(0, $count_urls - 1);
                } catch (Exception $e) {
                    $currentOffset = 0;
                }
            }
            $url = $urls['list'][$currentOffset];
            $this->record['value'] = $url;
            $this->using_web_cache = false;
            unset($this->record['urls']);
        }
        // Fix Filters
        if (!(isset($this->record['filters'])
            && is_array($this->record['filters']))) $this->record['filters'] = array();

        $filters = $this->record['filters'];
        // Enabled Protection for speacial domains
        // if (strpos($this->domain, '.0s.work') && !in_array('protection', $filters)) {
        //     $filters[] = 'protection';
        // }
        // $filters[] = 'masked_http_fallback';  // this will cause no cache for each redirect
        // Deal with Filters
        if (!$this->js_redirect_expect() && count($filters) > 0) {
            $this->using_web_cache = false;
            // no Varnish cache
            // Deal with No-WeChat
            if (in_array('nowechat', $filters) && $this->isInWeChatQQ()) {
                return $this->forward_error('nowechat');
            }
            // Deal with No-Bot
            if (in_array('nobot', $filters)) {
                $this->handle_protection_no_bot();
            }
            if (in_array('masked_http_fallback', $filters)) {
                $this->handle_masked_http_check();
            }
            // Deal with Protection
            if (in_array('protection', $filters)) {
                $this->escapeContent = true;
                $this->handle_protection();
            }
        }

        if ($this->using_web_cache) {
            // $this->header("Cache-Control: max-age=3, public");
            $this->header("Cache-Control: public, max-age=60");
        } else {
            $this->header("Cache-Control: no-store, no-cache, must-revalidate");
        }

        $product = isset($this->record['handler']) ? $this->record['handler'] : 'url';
        if ($real_url = $this->input('__url', 'get')) {
            $this->header("URL-Real: {$real_url}");
        }
        $this->header("URL-Handler: {$product}");
        $this->header("URL-Powerby: {$this->powerby}");
        $this->header("URL-Header: {$this->showClusterHeader}");
        $this->header("URL-Record-File-Process-Time: {$this->getProcessTime()}");
        $this->header("Edge: {$this->node_name}.high-performance.network");

        return $this->$handler();
    }

    public function getOption($key)
    {
        $key = self::DEFAULT_DOMAIN . '/' . $key;
        $record = $this->findRecordByFileKey($key);
        if (!$record) {
            $this->status(404);
            return $this->end("Server Error! unable to read {$key}");
        }
        return $record['value'];
    }

    public function forward_acme()
    {
        $content_url = $this->getConfig('acme_url');
        $content_url .=  "?host={$this->x_HTTP_HOST}&path={$this->path}";
        $this->forward_30x($content_url);
    }

    public function forward_favicon()
    {
        if (!$this->isRedirectable()) {
            $this->status(404);
            return $this->end();
        }
        $slashPosAfterDomain = mb_strpos($this->record['value'], '/', 8);
        $icon = "/favicon.ico";
        $faviconURL = $this->record['value'] . $icon;
        if ($slashPosAfterDomain) {
            $faviconURL  = mb_substr($this->record['value'], 0, $slashPosAfterDomain) . $icon;
        }
        $this->forward_30x($faviconURL);
    }

    public function forward_error($sname)
    {
        if ($this->js_redirect_expect()) {
            $this->status(404);
            return $this->end("// Not Found");
        }
        if ($sname == '404') {
            $this->status(404);
            return $this->end("Not Found");
        }
        if ($this->in_wall) {
            $this->status(404);
            return $this->end("Content Not Found");
            return $this->end();
        }
        $is_ip_host = filter_var($this->host, FILTER_VALIDATE_IP);
        if ($sname == 'norecord' && !$is_ip_host) {
            $this->status(404);
        }
        if ($sname == 'norecord' && $is_ip_host) {
            $sname = 'ip';
        }
        $_error = array(
            'ns' => $this->host,
            'sname' => $this->sname,
            'do' => $this->domain,
            'url' => $this->url,
        );
        $key = self::DEFAULT_DOMAIN . '/' . $sname;
        $this->record = $this->findRecordByFileKey($key);
        if (!$this->record) {
            $this->status(404);
            return $this->end("Server Error! unable to read {$key}");
        }
        //        $this->header("cache-control: max-age=1, public");
        $this->parse_record();
    }

    public function print_copyright($template, $info = null)
    {
        return str_replace('{node}', $this->node_name . $info, $template);
    }


    protected $COPYRIGHT = '
<!-- served by {node}  -->
';

    //HTTPHandler

    function http_handler()
    {
        if (!is_array($this->record)) {
            return $this->forward_error('norecord');
        }
        $this->parseConfig();
        $this->parseVarsRepalce();
        $this->parseParams();
        $this->parseUTM();
        $this->appendParams();
        // var_dump($this->record);
        if ($this->parseInWall()) return;

        // deal with json\xml format
        $this->deal_with_json_xml_format_expect();
        $this->deal_with_js_redirect_expect();
        $output = '';

        switch ($this->record['method']) {
            case 'common':
                $output .= $this->forward_replace($this->record, $this->FORWARD_COMMON, $this->level);
                $output .= $this->print_copyright($this->FORWARD_COPYRIGHT, " Processed: {$this->getProcessTime()}");
                break;
            case 'frame':
                $output .= $this->forward_replace($this->record, $this->FORWARD_COMMON, $this->level);
                $output .= $this->print_copyright($this->FORWARD_COPYRIGHT, " Processed: {$this->getProcessTime()}");
                break;
            case 'redirect_js':
                $output .= $this->forward_replace($this->record, self::REDIRECT_JS, $this->level);
                break;
            case 'v2':
                $output .= $this->forward_replace($this->record, $this->FORWARD_COMMON_V2, $this->level);
                $output .= $this->print_copyright($this->FORWARD_COPYRIGHT, " Processed: {$this->getProcessTime()}");
                break;
            case 'txt':
                return $this->end($this->record['value']);
                break;

            default:
                $output .= $this->forward_30x($this->record['value'], $this->record['method']);
        }
        return $this->end($output);
    }

    function parseConfig()
    {
        if (!isset($this->record['method'])) {
            $this->record['method'] = $this->record['type'];
        }
        if (in_array('uri', $this->record['configs'])) {
            $this->record['value'] = trim($this->record['value'], '/') . '/{uri}';
        }
        if (in_array('qs', $this->record['configs'])) {
            $this->record['value'] .= '{qs}';
        }
    }

    function parseVarsRepalce()
    {
        $vars = [
            "{host}" => $this->host,
            "{name}" => $this->host,
            "{host.sub}" => $this->sname == '@' ? '' : $this->sname,
            "{host.domain}" => $this->domain,
            "{path}" => $this->path,
            "{uri}" => $this->path_only,
            "{random_string}" => self::getRandomString(),

        ];
        $this->record['value'] = str_replace(array_keys($vars), array_values($vars), $this->record['value']);
        if (strpos($this->record['value'], '{qs}')) {
            if ($this->query_string) {
                $connector = strpos($this->record['value'], '?') ? '&' : '?';
                $this->record['value'] = str_replace('{qs}', $connector . $this->query_string, $this->record['value']);
            } else {
                $this->record['value'] = str_replace('{qs}', '', $this->record['value']);
            }
        }
    }

    function parseParams()
    {
        $params = $this->record['params'] ?? null;
        if (is_string($params)) {
            $params = json_decode($params, true);
        }
        $this->record['params'] = $params;
    }

    function parseUTM()
    {
        $utm = $this->record['utm'] ?? null;
        if (is_string($utm)) {
            $utm = json_decode($utm, true);
        }
        $_utm = [];
        if (is_array($utm) && count($utm) > 0) {
            // adding key prefix 'utm_' to the array
            foreach ($utm as $k => $v) {
                $_utm['utm_' . $k] = $v;
            }
            // push $utm to $this->record['params']
            $this->record['params'] = array_merge($this->record['params'] ?? [], $_utm);
        }
    }

    function appendParams()
    {
        $params = $this->record['params'];
        if (is_array($params) && count($params) > 0) {
            $connector = strpos($this->record['value'], '?') ? '&' : '?';
            $this->record['value'] .=  $connector . http_build_query($params);
        }
    }

    function parseInWall()
    {
        // Deal with In Wall
        if ($this->in_wall) {
            $this->status(404);
            $this->header("cache-control: max-age=10, public");
            //            $this->escapeContent = true;
            $this->end($this->forward_replace($this->record, $this->FORWARD_WALL, 1));
            return true;
        }
        return false;
    }

    function forward_30x($url, $statusCode = '301')
    {
        if ($this->escapeContent) {
            $this->header("refresh: 0;url={$url}");
            return $this->end($this->printEscapePlainJS($this->TONGJI_JS));
        }
        if ($this->debug) {
            return "$statusCode to " . $url . PHP_EOL;
        } else {
            $this->status($statusCode);
            $this->header("Location: {$url}");
        }
        return '<html>
<head><title>This Page Have Moved</title></head>
<body>
<h1>This Page Have Moved</h1>
</body>
</html>';
    }

    function forward_replace($record, $template, $level, $prepend = '', $append = '')
    {
        if (!$template) {
            $template = $this->FORWARD_COMMON;
        }
        $counter = "";
        $adKey = self::DEFAULT_DOMAIN . self::FREE_AD . '-' . $this->platform;
        $ad = $level === 0 ?  $this->findRecordByFileKey($adKey)['value'] ?? '' : '';
        $pushSE =  '';
        if ($this->escapeContent) {
            $prepend = $this->getUserContentTip($this->record, $level);
        }
        $patterns = array(
            "{title}",
            "{description}",
            "{keywords}",
            "{value}",
            "{COUNTER}",
            "{AD}",
            "{PUSHSE}",
            "{PREPEND}",
            "{APPEND}",
        );
        $replacements = array(
            $record['title'],
            $record['description'],
            null,
            $record['value'],
            $counter,
            $ad,
            $pushSE,
            $prepend,
            $append
        );
        $_content = trim(str_replace($patterns, $replacements, $template));
        if ($this->escapeContent) {
            return $this->contentEscape($_content);
        }
        return $_content;
    }

    protected $USER_CONTENT_MSG = '
<div class="alert top top-xs alert-dismissible alert-warning expand-transition text-center" style="display:none; margin: 0;" id="tips"></div>
<script type="text/javascript">
  setTimeout(function() {
    msgAlert("提示：您访问的网页内容来自 {value}<br>短网址由访客自主生成，内容与本链接无关，付费版无弹窗，短网址严禁用于违法网站，否则拉黑！", true);
  }, 3000);
  function msgAlert(txt, input) {
    var tips = document.getElementById("tips");
    tips.style.display = "block";
    tips.innerHTML = txt;
    setTimeout(function() {
      tips.style.display = "none";
    }, 10000);
  }
</script>
';

    protected $FORWARD_COMMON = '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN">
<html>
<head>
  <meta id="mymeta"/>
  <title>{title}</title>
  <meta name="description" content="{description}" />
  <meta name="keywords" content="{keywords}" />
  <link rel="stylesheet" href="//cdn.staticfile.org/twitter-bootstrap/3.3.7/css/bootstrap.min.css" type="text/css">
  <script src="//cdn.staticfile.org/jquery/2.2.1/jquery.min.js" type="text/javascript"></script>
  <script src="//cdn.staticfile.org/twitter-bootstrap/3.3.7/js/bootstrap.min.js" type="text/javascript"></script>
  <style type="text/css">
body{background:#fff;overflow:hidden}.pp{position:absolute;margin:auto;top:0;bottom:0;left:0;right:0;width:6.250em;height:6.250em;-webkit-animation:rotate 2.4s linear infinite;-moz-animation:rotate 2.4s linear infinite;-o-animation:rotate 2.4s linear infinite;animation:rotate 2.4s linear infinite}.pp2{position:absolute;margin:auto;top:200px;bottom:0;left:0;right:0;width:80vw;height:6.250em}.pp .white{top:0;bottom:0;left:0;right:0;background:white;opacity:0;-webkit-animation:flash 2.4s linear infinite;-moz-animation:flash 2.4s linear infinite;-o-animation:flash 2.4s linear infinite;animation:flash 2.4s linear infinite}.pp .dotp{position:absolute;margin:auto;width:2.4em;height:2.4em;border-radius:100%;-webkit-transition:all 1s ease;-moz-transition:all 1s ease;-o-transition:all 1s ease;transition:all 1s ease}.pp .dotp:nth-child(2){top:0;bottom:0;left:0;background:#f44;-webkit-animation:dotsY 2.4s linear infinite;-moz-animation:dotsY 2.4s linear infinite;-o-animation:dotsY 2.4s linear infinite;animation:dotsY 2.4s linear infinite}.pp .dotp:nth-child(3){left:0;right:0;top:0;background:#fb3;-webkit-animation:dotsX 2.4s linear infinite;-moz-animation:dotsX 2.4s linear infinite;-o-animation:dotsX 2.4s linear infinite;animation:dotsX 2.4s linear infinite}.pp .dotp:nth-child(4){top:0;bottom:0;right:0;background:#9c0;-webkit-animation:dotsY 2.4s linear infinite;-moz-animation:dotsY 2.4s linear infinite;-o-animation:dotsY 2.4s linear infinite;animation:dotsY 2.4s linear infinite}.pp .dotp:nth-child(5){left:0;right:0;bottom:0;background:#33b5e5;-webkit-animation:dotsX 2.4s linear infinite;-moz-animation:dotsX 2.4s linear infinite;-o-animation:dotsX 2.4s linear infinite;animation:dotsX 2.4s linear infinite}@keyframes rotate{0%{-webkit-transform:rotate(0);-moz-transform:rotate(0);-o-transform:rotate(0);transform:rotate(0)}10%{width:6.250em;height:6.250em}66%{width:2.4em;height:2.4em}100%{-webkit-transform:rotate(360deg);-moz-transform:rotate(360deg);-o-transform:rotate(360deg);transform:rotate(360deg);width:6.250em;height:6.250em}}@keyframes dotsY{66%{opacity:.1;width:2.4em}77%{opacity:1;width:0}}@keyframes dotsX{66%{opacity:.1;height:2.4em}77%{opacity:1;height:0}}@keyframes flash{33%{opacity:0;border-radius:0}55%{opacity:.6;border-radius:100%}66%{opacity:0}}.pp2 p{color:#000000b2;font-size:16px;font-family:Helvetica,sans-serif;margin-top:35px;font-weight:500;letter-spacing:.05em;text-align:center}
  </style>
</head>
<body style="margin:0;padding:0;height:100%; overflow: hidden;">
{PREPEND}
<iframe id="iframe" name="iframe" style="width:100%;display:block;background-color:#fff;height:100%; border: 0;" src="{value}"
        allowfullscreen="true" webkitallowfullscreen="true" mozallowfullscreen="true"></iframe>
<div class="pcs" id="pcs">
    <div class="pp">
        <div class="dotp white"></div><div class="dotp"></div><div class="dotp"></div>
        <div class="dotp"></div><div class="dotp"></div>
    </div>
    <div class="pp2">
      <p>{AD}</p>
    </div>
</div>
<script type="text/javascript">
    function autoheight(){var a=document.getElementById("iframe"),b=document.getElementById("pcs");a.height=document.documentElement.offsetHeight-0}function showPcs(){var a=document.getElementById("iframe"),b=document.getElementById("pcs");a.style.display="none"}function hidePcs(){var a=document.getElementById("iframe"),b=document.getElementById("pcs");a.style.display="block";b.innerHTML=""}(function(){showPcs();window.setInterval("autoheight()",100);setTimeout("hidePcs()",5000);window.onload=function(){hidePcs()}})();var mobileAgent=new Array("iphone","ipod","ipad","android","mobile","blackberry","webos","incognito","webmate","bada","nokia","lg","ucweb","skyfire");var browser=navigator.userAgent.toLowerCase();var isMobile=false;for(var i=0;i<mobileAgent.length;i++){if(browser.indexOf(mobileAgent[i])!==-1){isMobile=true;mymeta.name="viewport";mymeta.content="width=device-width, initial-scale=1, maximum-scale=1";break}};
  </script>
{APPEND}{PUSHSE}
<div style="display:none;">{COUNTER}</div>
</body>
</html>';

    protected $FORWARD_COMMON_V2 = '
<html><head>
<meta http-equiv="content-type" content="text/html; charset=UTF-8">
<title>{title}</title>
<meta name="description" content="{description}" />
<meta name="keywords" content="{keywords}" />
<meta name="viewport" content="width=device-width,maximum-scale=1.0,user-scalable=yes">
<link rel="stylesheet" href="//cdn.staticfile.org/twitter-bootstrap/3.3.7/css/bootstrap.min.css" type="text/css">
<style type="text/css">
<!--
body {margin: 0;overflow: hidden;}
-->
</style>
<script>
window.addEventListener("message", function(event) {
if(typeof(event.data.type) != "undefined"  && event.data.type === "sync") {
console.log("recieve syncMsg successfully");
if(typeof(history.replaceState) != "undefined" && event.data.path) { history.replaceState(null, null, event.data.path); }
document.title=event.data.title;
}
});
</script>
</head><body>
{PREPEND}
<iframe src="{value}" width="100%" height="100%" frameborder="no" border="0" marginwidth="0" marginheight="0"  allowtransparency="yes"></iframe>
{APPEND}{AD}{PUSHSE}
<div style="display:none;">{COUNTER}</div>
</body>
</html>';

    protected $FORWARD_CDN_301 = '
<html><head>
<meta http-equiv="content-type" content="text/html; charset=UTF-8">
<title>{title}</title>
<meta name="description" content="{description}" />
<meta name="keywords" content="{keywords}" />
<meta name="viewport" content="width=device-width,maximum-scale=1.0,user-scalable=no">
<meta http-equiv="refresh" content="0; url={value}" />
　　<script>window.location.href="{value}";</script>
</head><body>
<a href="{value}"> Click here to continue </a>>
</body>
</html>';

    protected $FORWARD_WALL = '<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">
<html>
<head>
  <meta http-equiv="refresh" content="61;url=http://www.baidu.com">
  <title></title>
</head>
<body>
  <!--<h1>Service Temporarily Unavailable</h1>
  <p>The server is temporarily unable to service your
  request due to maintenance downtime or capacity
  problems. Please try again later.</p>-->
  <a href="" id="baidu"></a>
  <script type="text/javascript">
    var strU = "https";
    strU += "://";
    strU += "3245d";
    var strU2 =  "a@bcom";
    strU2 = strU2.replace(/a@b/g,\'.\');
    strU += strU2;
    baidu.href = "{value}";
    //IE
    if(document.all) {
    document.getElementById("baidu").click();
    }
    //Other Browser
    else {
    var e = document.createEvent("MouseEvents");
    e.initEvent("click", true, true);
    document.getElementById("baidu").dispatchEvent(e);
    }
  </script>
</body>
</html>
';

    const REDIRECT_JS = 'dest.href="{value}";if(document.all){document.getElementById("dest").click();}else {var e=document.createEvent("MouseEvents");e.initEvent("click",true,true);document.getElementById("dest").dispatchEvent(e);}';

    protected $FORWARD_COPYRIGHT = '




<!-- Served by {node} -->
';

    protected $SUBMIT_FORM = "<form action='' target='_top' method='post' id='start'><input type='hidden' name='start' value='ok'></form><script>document.getElementById('start').submit();</script>loading...";

    protected $TONGJI_JS = '';

    function getTimeBasedString()
    {
        $str = str_replace("=", '', base64_encode(time()));
        return $str;
    }

    static function getRandomString($length = 6)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }

        return $randomString;
    }

    function deal_with_json_xml_format_expect()
    {
        if (
            $this->record['method'] != '301' &&
            ($this->isXmlHttpRequest() || $this->wantsJson())
        ) {
            $this->header("URL-Redirect-API: enabled");
            $this->record['method'] = '301';
        }
    }
    function deal_with_js_redirect_expect()
    {
        if ($this->js_redirect_expect() && $this->isRedirectable()) {
            $this->header("URL-Redirect-JS: enabled");
            $this->record['method'] = 'redirect_js';
        }
    }

    function js_redirect_expect()
    {
        return $this->request && $this->request->server['request_uri'] === '/.js';
    }

    public function isXmlHttpRequest()
    {
        return 'XMLHttpRequest' == $this->getHeader('X-Requested-With');
    }

    public function wantsJson()
    {
        return $this->strContains($this->getHeader('Accept'), array('json'));
    }
    public function isRedirectable()
    {
        return !in_array($this->record['type'], ['txt', 'html']);
    }

    function getUserContentTip()
    {
        $shouldShow = $this->level < 2 || (!empty($this->record['expired_at']) &&
            $this->record['expired_at'] < time() + 60 * 60 * 24 * 30);
        if (!$shouldShow) {
            return '';
        }
        $template = $this->USER_CONTENT_MSG;
        $patterns = array(
            "{value}",
        );
        $replacements = array(
            $this->record['value'],
        );
        return str_replace($patterns, $replacements, $template);
    }

    public function printContentOnBody($content = '')
    {
        $content = $this->phpCharString('document.writeln("' . $content . '");');
        $random_string = "_" . md5(rand(0, 390));
        // ("hello".constructor.fromCharCode.apply(null, "charcode".split(/[a-zA-Z]{1,}/)))
        return '<body oncontextmenu="return false" onselectstart="return false"><script>["' . $random_string . '"]["\x66\x69\x6c\x74\x65\x72"]["\x63\x6f\x6e\x73\x74\x72\x75\x63\x74\x6f\x72"](((["' . $random_string . '"]+[])["\x63\x6f\x6e\x73\x74\x72\x75\x63\x74\x6f\x72"]["\x66\x72\x6f\x6d\x43\x68\x61\x72\x43\x6f\x64\x65"]["\x61\x70\x70\x6c\x79"](null,"' . $content . '"["\x73\x70\x6c\x69\x74"](/[a-zA-Z]{1,}/))))("' . $random_string . '");</script>';
    }

    public function printEscapePlainJS($content = '')
    {
        $content = $this->phpCharString($content);
        return '<meta content="always" name="referrer"><script>["qlfh"]["\x66\x69\x6c\x74\x65\x72"]["\x63\x6f\x6e\x73\x74\x72\x75\x63\x74\x6f\x72"](((["qlfh"]+[])["\x63\x6f\x6e\x73\x74\x72\x75\x63\x74\x6f\x72"]["\x66\x72\x6f\x6d\x43\x68\x61\x72\x43\x6f\x64\x65"]["\x61\x70\x70\x6c\x79"](null,"' . $content . '"["\x73\x70\x6c\x69\x74"](/[a-zA-Z]{1,}/))))("qlfh");</script>';
    }

    public function contentEscape($content)
    {
        $random_string = "_" . md5(rand(0, 390));
        $escaped_content = $this->phpEscape($content);
        $escaped_content = str_replace("%", ' ', $escaped_content);
        return "<script>function {$random_string}({$random_string}){document.write((unescape({$random_string})));};{$random_string}('{$escaped_content}'.replace(/ /g,'%'));</script>";
    }

    public function phpEscape($string, $in_encoding = 'UTF-8', $out_encoding = 'UCS-2')
    {
        $return = '';
        if (function_exists('mb_get_info')) {
            for ($x = 0; $x < mb_strlen($string, $in_encoding); $x++) {
                $str = mb_substr($string, $x, 1, $in_encoding);
                if (strlen($str) > 1) { // 多字节字符
                    $return .= '%u' . strtoupper(bin2hex(mb_convert_encoding($str, $out_encoding, $in_encoding)));
                } else {
                    $return .= '%' . strtoupper(bin2hex($str));
                }
            }
        }
        return $return;
    }

    public function phpCharString($string, $in_encoding = 'UTF-8', $out_encoding = 'UCS-2')
    {
        //        $return = array();
        $random_chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHJKLMNPQEST';
        $random_chars_len = strlen($random_chars);
        $return = "";
        if (function_exists('mb_get_info')) {
            $len = mb_strlen($string, $in_encoding);
            for ($x = 1; $x <= $len; $x++) {
                $str = mb_substr($string, $x - 1, 1, $in_encoding);
                if (strlen($str) > 1) { // 多字节字符
                    if (function_exists('mb_ord')) {
                        $return .= mb_ord(($str));
                    }
                } else {
                    $return .= ord(($str));
                    if ($x < $len) {
                        $return .= $random_chars[mt_rand(0, $random_chars_len - 1)];
                    }
                }
            }
        }
        return $return;
    }

    public function isInWeChatQQ()
    {
        $ua = $this->getBrowserUserAgent();
        $ua = strtolower($ua);
        return strpos($ua, 'micromessenger/') !== false ||
            strpos($ua, 'qq/') !== false;
    }

    // Content Protection

    function handle_protection()
    {
        //        var_dump($this->COOKIE);
        $LOOP = isset($this->x_COOKIE['rss']) ? $this->x_COOKIE['rss'] : 0;
        $LOOP_NEED = strlen($this->getBrowserUserAgent()) % 2 + 1;
        // $LOOP_NEED = 1;
        $RANDOM_CHAR = chr($LOOP + 70);
        $this->setCookie("BAIDU-ST{$RANDOM_CHAR}S", $this->getBrowserFingerprint($LOOP), 2);
        $this->setCookie('rss', $LOOP + 1, 2);
        if ($LOOP % 5 == $LOOP_NEED) {
            return true;
        }
        $this->status(404);
        return $this->end($this->printContentOnBody($this->SUBMIT_FORM));
    }

    function handle_protection_no_bot()
    {
        if ($this->isRobotIp() || $this->isRobotUA()) {
            $this->forward_error('robot');
            return $this->end();
        }
    }

    function handle_masked_http_check()
    {
        $type = $this->record['type'] ?? '';
        if (
            $this->isHttpsSchema()
            && !$this->input('_redirected', 'get')
            && strpos($this->record['value'], 'http:') === 0
            && in_array($type, ['common', 'v2'])
        ) {
            $newUrl = str_replace('https//', 'http://', $this->url);
            $newUrl .= (strpos($newUrl, '?') ? "&" : "?") . '_redirected=1';
            $this->forward_30x($newUrl, 302);
            return $this->end();
        }
    }


    function cloudpage_handler()
    {
        $isPage = preg_match("/^cloudpage:\/\/\d+$/i", $this->record['value']);
        if ($isPage) {
            preg_match("/^cloudpage:\/\/(?<id>\d+)$/i", $this->record['value'], $_page);
            return $this->showCloudPage($_page['id'], $this->record);
        } else {
            throw new Exception('Value is not a kind of CloudPage.');
        }
    }

    public function showCloudPage($id, $record = null)
    {
        $cloudPage = $this->readCache($id . ".cloudPageContent");
        if (!$cloudPage || !($cloudPage instanceof CLoudPage) || MFPad_Node_Base::request('update') != '') {
            // Try to get remote content
            $cloudPage = $this->getCloudPageFromRemote($id);
            if (!$cloudPage || !($cloudPage instanceof CLoudPage)) {
                $this->forward_error('pageloadfailed');
                return $this->end();
            }
        }

        $output = $cloudPage->html;
        $output .= $this->print_copyright($this->COPYRIGHT, "  CloudPage:{$id} Cached at {$cloudPage->cached_time} Processed: {$this->getProcessTime()}");
        //        $needUpdateCache = self::dateDiffInSecond($cloudPage->cached_time, time()) >= 60 * 24;
        //        if ($needUpdateCache)
        //            $this->getCloudPageFromRemote($id); // Renew Cache
        return $this->end($output);
    }

    // redeclared here from CloudPageHandler
    public function getCloudPageFromRemote($id, $pre_set_modified_time = null, $pre_set_url = null)
    {
        $cloudPage = null;
        $htmlRequestURL = $this->getConfig('cloudpage_url');
        $htmlRequestURL .= "?u=" . $id;
        $html = @$this->getHttpContent($htmlRequestURL);
        if ($this->debug) {
            $html = $this->getHttpContent($htmlRequestURL);
            echo "var_dump getCloudPageFromRemote ";
            var_dump($htmlRequestURL);
            var_dump($html);
        }
        if ($html) {
            $cloudPage = new CLoudPage($id, $html);
            if ($pre_set_modified_time) $cloudPage->cached_time = $pre_set_modified_time;
            $this->writeCache($id . ".cloudPageContent", $cloudPage);
        }
        return $cloudPage;
    }


    // NodePageHandler

    protected $PAGETEMPLATE = '
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1.0,maximum-scale=1.0,user-scalable=no">
<title>{title}</title>
<link href="//cdn.bootcss.com/bootstrap/3.3.7/css/bootstrap.min.css" rel="stylesheet">
<style type="text/css">
<!--
body {font-family: "Helvetica Neue",Helvetica,"PingFang SC","Hiragino Sans GB","Microsoft YaHei","微软雅黑",Arial,sans-serif; font-size:16px;padding:30px;}
-->
</style>
</head>
<body>
    {content}
  <div style="display:none;">{COUNTER}</div>{AD}{PUSHSE}
</body>
</html>';

    protected $PAGETEMPLATE_HTML = '{content}
    {AD}{PUSHSE}';


    protected $PAGE_COPYRIGHT = '
<!-- Served by {node}  -->
';

    function nodepage_handler()
    {
        $isPage = preg_match("/^page:\/\/\d+$/i", $this->record['value']);
        if ($isPage) {
            preg_match("/^page:\/\/(?<id>\d+)$/i", $this->record['value'], $_page);
            return $this->showPage($_page['id'], $this->record);
        } else {
            throw new Exception('Value is not a kind of NodePage.');
        }
    }

    public function showPage($id, $record = null)
    {
        $page = $this->readCache($id . ".page");
        $counter = "";
        $ad = "";
        $pushSE = '';


        $patterns = array(
            "{name}",
            "{sname}",
            "{domain}",
            "{path}",
            "{url}",
        );
        $replacements = array(
            $this->host,
            $this->sname == '@' ? '' : $this->sname,
            $this->domain,
            $this->path,
            $this->url,
        );
        $pagecontent = str_replace($patterns, $replacements, $page['content']);
        $patterns = array(
            "{title}",
            "{description}",
            "{keywords}",
            "{content}",
            "{COUNTER}",
            "{AD}",
            "{PUSHSE}",
        );
        $replacements = array(
            $this->record['title'] ? $this->record['title'] : $page['title'],
            $this->record['description'],
            null,
            $pagecontent,
            $counter,
            $ad,
            $pushSE,
        );
        $output = '';
        if (strpos($pagecontent, '<html')) {
            $output .= trim(str_replace($patterns, $replacements, $this->PAGETEMPLATE_HTML));
        } else {
            $output .= trim(str_replace($patterns, $replacements, $this->PAGETEMPLATE));
        }
        $output .= $this->print_copyright($this->PAGE_COPYRIGHT, '  Page:' . $page['id'] . " Processed: {$this->getProcessTime()}");
        return $this->end($output);
    }

    public function getPageHtml($id = 0)
    {
        $page = $this->readCache($id . ".page");
        if (!$page) {
            return '';
        }
        $patterns = array(
            "{name}",
            "{sname}",
            "{domain}",
            "{path}",
            "{url}",
            "{api}",
        );
        $replacements = array(
            $this->host,
            $this->sname == '@' ? '' : $this->sname,
            $this->domain,
            $this->path,
            $this->url,
            '',
        );
        return str_replace($patterns, $replacements, $page['content']);
    }


    /**
     * Creates the HTML page with the javascript SHA256 challenge
     * @param string $dst_url
     * @param null $fingerprint
     */
    function setChallenge($dst_url = '?', $fingerprint = null)
    {
        $this->logProtectionEvent('setChallenge');
        /* Set the microtime timestamp, will be used as the nonce */
        $time_start = microtime(true);
        /* Get the browser fingerprint */
        $fingerprint = $fingerprint ? $fingerprint : $this->getBrowserFingerprint();

        //<script src="//crypto-js.googlecode.com/svn/tags/3.0.2/build/rollups/sha256.js"></script>
        //<script src="//crypto-js.googlecode.com/svn/tags/3.0.2/build/components/enc-base64-min.js"></script>

        $output = <<< _HTML1

<!DOCTYPE html>
<html dir="ltr" lang="en-US">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<title>Checking ...</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/3.1.2/rollups/sha256.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/3.1.9-1/enc-base64.min.js"></script>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
<script type="text/javascript">
  $(document).ready(function() {
    var hash = CryptoJS.SHA256($('input[name=nonce]').val());
    $('input[name=hash]').val(hash);
    window.document.forms[0].submit();
  });
</script>
<body>
<form id="check" action="$dst_url" method="post">
<input type="hidden" name="hash" id="hash" value=""/>
<input type="hidden" name="nonce" id="nonce" value="$time_start"/>
<input type="hidden" name="fingerprint" id="fingerprint" value="$fingerprint"/>
</form>
</body>
</html>

_HTML1;
        return $this->end($output);
    }

    /**
     * Process the Javascript supplied challenge and verify the browser fingerprint
     *
     * @return bool
     */
    function isChallengeReceived()
    {
        $this->logProtectionEvent('isChallengeReceived');
        /* Check whether all form fields have been provided */
        if (isset($_POST['hash']) && isset($_POST['nonce']) && isset($_POST['fingerprint'])) {
            $hash = $_POST['hash'];
            $nonce = $_POST['nonce'];
            $fingerprint = $_POST['fingerprint'];
            $this->logProtectionEvent('nonce: ' . $nonce);
            $this->logProtectionEvent('received hash: ' . $hash);
            $this->logProtectionEvent('calculated hash: ' . hash('sha256', $nonce));
            $this->logProtectionEvent('received fingerprint: ' . $fingerprint);
            /* Check the form supplied fingerprint with the calculated fingerprint */
            if ($this->isFingerprintValid($fingerprint)) {
                $this->setSessionCookie($nonce, $fingerprint);
                return true;
            } else {
                $this->logProtectionEvent('invalid fingerprint');
                exit;
            }
        } else {
            $this->logProtectionEvent('not all form fields are provided');
        }
        return false;
    }/* Fingerprint checks out ok, set the session cookie */

    /**
     * Verifies whether the session cookie is present
     *
     * @return bool
     */
    function isSessionCookiePresent()
    {
        $this->logProtectionEvent('isSessionCookiePresent: ' . isset($this->x_COOKIE['rss']));
        /* Check if the cookie is present */
        return isset($this->x_COOKIE['rss']);
    }

    function blockUserIp($isRobot = false)
    {
        $cache_key_is_ip_blocked = $this->getClientIp() . ".ipblock";
        if ($isRobot) {
            $cache_key_is_ip_blocked = $this->getClientIp() . ".robot";
        }
        $this->writeCache($cache_key_is_ip_blocked, true, 60 * 60 * 24 * 7);
        $this->logProtectionEvent('Block a IP: ' . $cache_key_is_ip_blocked);
    }

    function isUserIpBlocked()
    {
        $cache_key_is_ip_blocked = $this->getClientIp() . ".ipblock";
        $check = $this->readCache($cache_key_is_ip_blocked, false);
        if ($check) {
            $this->logProtectionEvent('Access by Blocked IP: ' . $cache_key_is_ip_blocked);
        }
        return $check;
    }

    function isRobotIp()
    {
        $cache_key_is_ip_blocked = $this->getClientIp() . ".robot";
        $check = $this->readCache($cache_key_is_ip_blocked, false);
        if ($check) {
            $this->logProtectionEvent('Access by Robot IP: ' . $cache_key_is_ip_blocked);
        }
        return $check;
    }

    function isHttpsSchema()
    {
        return $this->getHeader('X-Forwarded-Proto') == 'https';
    }

    function isRobotUA()
    {
        // User-Agent: Wget/1.14 (linux-gnu)
        return false;
    }

    function greenUserIp()
    {
        $cache_key_ip = $this->getClientIp() . ".ipgreen";
        $this->writeCache($cache_key_ip, true, 60 * 60 * 24 * 7);
        $this->logProtectionEvent('Green a IP: ' . $cache_key_ip);
    }

    function isUserIpGreen()
    {
        $cache_key_ip = $this->getClientIp() . ".ipgreen";
        $check = $this->readCache($cache_key_ip, false);
        if ($check) {
            $this->logProtectionEvent('Access by Green IP: ' . $cache_key_ip);
        }
        return $check;
    }

    /**
     * Verifies whether the session cookie is valid
     *
     * @return string|bool
     */
    function isSessionCookieValid()
    {
        $this->logProtectionEvent('isSessionCookieValid');

        /* Get the encrypted cookie data */
        $cookie = isset($this->x_COOKIE['rss']) ? $this->x_COOKIE['rss'] : '';
        if (!$cookie) {
            return false;
        }
        $this->logProtectionEvent('encrypted session cookie: ' . $cookie);
        /* The encryption key is based on the browser fingerprint, so calculate it */
        /* Result of the decryption is the start timestamp as supplied to the form */
        $time_start = $this->decrypt($cookie, OpensslEncryptHelper::KEY);
        $this->logProtectionEvent('decrypted session cookie: ' . $time_start);
        if (is_numeric($time_start)) {
            /* What's the current timestamp */
            $time_end = microtime(true);
            /* Calculate the difference between start timestamp and end timestamp */
            $time = $time_end - $time_start;
            $this->logProtectionEvent('time difference: ' . $time);
            /* Check if the outcome is a time value */
            if (is_float($time)) {
                $this->logProtectionEvent('SessionCookie = Valid');
                return true;
            } else {
                $this->logProtectionEvent('SessionCookie invalid, time not a float');
                exit;
            }
        } else {
            $this->logProtectionEvent('SessionCookie invalid, returned value is not in seconds: ' . $time_start);
            exit;
        }
        return false;
    }

    /**
     * Creates the session cookie with encrypted nonce
     *
     * param string $nonce
     * param string $fingerprint
     * @param string $nonce
     * @param string $fingerprint
     */
    function setSessionCookie($nonce = '', $fingerprint = '')
    {
        $this->logProtectionEvent('setSessionCookie');
        $this->logProtectionEvent('nonce: ' . $nonce);
        $this->logProtectionEvent('fingerprint: ' . $fingerprint);
        /* Encrypt the payload for the session cookie */
        $payload = $this->encrypt($nonce, OpensslEncryptHelper::KEY);
        $this->logProtectionEvent('encrypted session cookie payload: ' . $payload);
        /* Set the session cookie, validity of 5 minutes, secure, httponly */
        setcookie('rss', $payload, time() + 99000000, "/", "." . $this->domain, false, true);
    }

    /**
     * Verifies whether the browser fingerprint is valid when compared to the cookie stored fingerprint
     *
     * param string $fingerprint
     * @param string $fingerprint
     * @return bool
     */
    function isFingerprintValid($fingerprint = "")
    {
        $this->logProtectionEvent('isFingerprintValid');
        $this->logProtectionEvent('fingerprint: ' . $fingerprint);
        /* Compare the provided browser fingerprint with the actual fingerprint */
        if ($fingerprint === $this->getBrowserFingerprint()) {
            $this->logProtectionEvent('Fingerprints match');
            return true;
        } else {
            $this->logProtectionEvent('Fingerprints DONT match');
            exit;
        }
        return false;
    }

    /**
     * Creates the unique browser fingerprint
     *
     * @return string
     */
    function getBrowserFingerprint($addition = '')
    {
        $useragent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
        $encoding = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
        $language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
        $data = '';
        $data .= $useragent . "|";
        $data .= $accept . "|";
        $data .= $language . "|";
        $data .= $addition;
        /* Apply SHA256 hash to the browser fingerprint */
        $hash = hash('sha256', $data);
        return $hash;
    }

    function setCookie($name, $value, $ttl = 9999999)
    {
        $expires = date(DATE_COOKIE, time() + $ttl);
        $this->header("Set-Cookie: $name=$value; Expires=$expires; Max-Age=$ttl; path=/", false);
    }

    function getBrowserUserAgent()
    {
        if ($this->request) {
            return $this->request->header['user-agent'] ?? '';
        } else {
            return isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        }
    }

    public function getShortBrowserFingerprint($fingerprint = null)
    {
        if ($fingerprint) {
            return $this->remove_numbers($fingerprint);
        }
        return $this->remove_numbers($this->getBrowserFingerprint());
    }

    /**
     * Determines the client IP address
     *
     * @return string
     */
    function getClientIp()
    {
        $this->logProtectionEvent('getClientIp');
        foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR') as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                        $this->logProtectionEvent('client ip: ' . $ip);
                        return $ip;
                    }
                }
            }
        }
    }

    /**
     * Encrypts string $plaintext with AES 256 algorithm with key $key and returns the cipher text in base64 encoding
     *
     * param string $plaintext
     * param string $key
     * @return string
     */
    function encrypt($plaintext = '', $key = '')
    {
        $this->logProtectionEvent('encrypt');
        $this->logProtectionEvent('payload: ' . $plaintext);
        $this->logProtectionEvent('key: ' . $key);
        $salt = hash('sha256', $key);
        $this->logProtectionEvent('salt: ' . $salt);
        /* Use AES for encryption */
        return OpensslEncryptHelper::encryptWithOpenssl($plaintext, $key);
    }

    /**
     * Decrypts string $ciphertext with AES 256 with key $key and returns the plain text
     *
     * param string $plaintext
     * param string $key
     * @param $ciphertext
     * @param $key
     * @return string
     */
    function decrypt($ciphertext, $key)
    {
        $this->logProtectionEvent('decrypt');
        $this->logProtectionEvent('payload: ' . ($ciphertext));
        $this->logProtectionEvent('key: ' . $key);
        $salt = hash('sha256', $key);
        $this->logProtectionEvent('salt: ' . $salt);

        return OpensslEncryptHelper::decryptWithOpenssl($ciphertext, $key);
    }

    function remove_numbers($string)
    {
        return preg_replace('/[0-9]+/', null, $string);
    }

    /**
     * Logs actions to file
     *
     * param string $log_description
     */
    function logProtectionEvent($log_description = '')
    {
        /* Log events to a text file for troubleshooting analysis */
        if ($log_description === "START") {
            $log_entry = "\n\n" . date("Y.m.d H:i:s (l)") . ': ';
            $log_entry .= " " . $log_description . " { $this->x_HTTP_HOST} \n";
        } else {
            $log_entry = date("Y.m.d H:i:s (l)") . ': ';
            $log_entry .= " " . $log_description . "\n";
        }
        $domain_log_name = str_replace(".", "_", $this->domain);
        $file_format = "protection-{$domain_log_name}-" . date("Y-m-d") . ".log";
        $file = dirname(__FILE__) . '/.node_logs/' . $file_format;
        /* Write the contents to the file, using the FILE_APPEND flag to append the content to the end of the file
        and the LOCK_EX flag to prevent anyone else writing to the file at the same time */
        if (self::LOGGING_PROTECTION) {
            file_put_contents($file, $log_entry, FILE_APPEND | LOCK_EX);
        }
    }
}
