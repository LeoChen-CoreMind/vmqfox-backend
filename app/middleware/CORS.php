<?php
namespace app\middleware;

use think\Response;

class CORS
{
    /**
     * 处理跨域请求
     * @param \think\Request $request
     * @param \Closure $next
     * @return Response
     */
    public function handle($request, \Closure $next)
    {
        $headers = [
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, If-Match, If-Modified-Since, If-None-Match, If-Unmodified-Since, X-Requested-With',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Expose-Headers' => 'Content-Length, Content-Range',
        ];

        if (strtoupper($request->method()) === "OPTIONS") {
            return Response::create('', 'html', 204)->header($headers);
        }

        return $next($request)->header($headers);
    }
}
