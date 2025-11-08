<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// probe_miplan_api.php — diagnóstico de conexión MiPlan → API LUGA
session_start();
require_once __DIR__.'/tickets_api_config.php'; // Debe definir API_BASE, API_TOKEN y los helpers o al menos estos 2 funcs

// === Helpers mínimos de red (si no tienes los míos, usa estos) ===
if (!function_exists('api_request')) {
  function _join_api_url(string $path): string {
    $base = rtrim(API_BASE, '/');
    $p = ltrim($path, '/');
    return $base . '/' . $p;
  }
  function api_request(string $method, string $path, array $data = [], array $query = []): array {
    $url = _join_api_url($path);
    if ($query) $url .= (str_contains($url,'?') ? '&' : '?') . http_build_query($query);
    $headers = [
      'Accept: application/json',
      'Content-Type: application/json',
      'Authorization: Bearer ' . API_TOKEN,
      'X-Origen: MiPlan',
    ];
    $code=0; $raw=''; $err=''; $json=null;

    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
      ]);
      if (in_array(strtoupper($method), ['POST','PUT','PATCH'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
      }
      $raw  = curl_exec($ch);
      $err  = curl_error($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
      curl_close($ch);
    } else {
      $opts = [
        'http' => [
          'method'  => strtoupper($method),
          'header'  => implode("\r\n", array_merge($headers, ['Connection: close'])),
          'timeout' => 20,
          'ignore_errors' => true,
        ]
      ];
      if (in_array(strtoupper($method), ['POST','PUT','PATCH'])) {
        $opts['http']['content'] = json_encode($data, JSON_UNESCAPED_UNICODE);
      }
      $ctx = stream_context_create($opts);
      $raw = @file_get_contents($url, false, $ctx);
      if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) if (preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $h, $m)) { $code=(int)$m[1]; break; }
      }
    }
    $json = json_decode($raw ?? '', true);
    return [
      'ok'    => ($code >= 200 && $code < 300),
      'code'  => $code,
      'url'   => $url,
      'raw'   => $raw ?? '',
      'error' => $err ?? '',
      'json'  => is_array($json) ? $json : null
    ];
  }
  function api_get(string $path, array $query = []) { return api_request('GET', $path, [], $query); }
  function api_post(string $path, array $data = [], array $query = []) { return api_request('POST', $path, $data, $query); }
}

// === Pruebas en 3 rondas ===
$since = date('Y-m-01 00:00:00');

$tests = [
  ['GET',  'tickets.since.php', [],               []],
  ['GET',  'tickets.since.php', [],               ['since'=>$since]],
  ['POST', 'tickets.since.php', ['since'=>$since],[]], // por si ese endpoint espera POST
  // alternos por nombre de parámetro diferente
  ['GET',  'tickets.since.php', [],               ['updated_since'=>$since]],
  ['POST', 'tickets.since.php', ['updated_since'=>$since], []],
];

header('Content-Type: text/plain; charset=UTF-8');
echo "API_BASE: ".API_BASE."\n\n";
foreach ($tests as $i=>$t) {
  [$m,$p,$data,$q] = $t;
  $r = ($m==='POST') ? api_post($p,$data,$q) : api_get($p,$q);
  echo "== TEST #".($i+1)." {$m} {$p} ".($q?json_encode($q):'')." ".($data?json_encode($data):'')." ==\n";
  echo "URL:  {$r['url']}\n";
  echo "HTTP: {$r['code']}\n";
  echo "ERR:  ".($r['error'] ?: '—')."\n";
  $rawHead = mb_substr((string)$r['raw'], 0, 400);
  echo "RAW:  ".($rawHead === '' ? '—' : $rawHead)."\n";
  if (is_array($r['json'])) {
    echo "JSON keys: ".implode(',', array_keys($r['json']))."\n";
    if (isset($r['json']['tickets'])) echo "count(tickets): ".count($r['json']['tickets'])."\n";
  }
  echo "\n";
}
