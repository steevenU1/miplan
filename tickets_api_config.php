<?php
// MiPlan → LUGA (API)
const API_BASE    = 'https://TU-LUGA.com/api';   // ← ajusta tu dominio
const API_TOKEN   = 'TOK-MIPLAN-REEMPLAZA-ESTO-456'; // ← el token de MiPlan que agregaste en _auth.php de LUGA
const API_TIMEOUT = 15;

function api_get(string $path, array $query=[]): array {
  $url = rtrim(API_BASE,'/').$path.($query?('?'.http_build_query($query)):'');
  $ch=curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_HTTPHEADER=>['Authorization: Bearer '.API_TOKEN],
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_TIMEOUT=>API_TIMEOUT,
  ]);
  $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
  return ['http'=>$code?:0,'json'=>json_decode((string)$resp,true), 'raw'=>$resp, 'err'=>$err];
}

function api_post_json(string $path, array $payload): array {
  $url = rtrim(API_BASE,'/').$path;
  $ch=curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.API_TOKEN],
    CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_TIMEOUT=>API_TIMEOUT,
  ]);
  $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
  return ['http'=>$code?:0,'json'=>json_decode((string)$resp,true), 'raw'=>$resp, 'err'=>$err];
}
