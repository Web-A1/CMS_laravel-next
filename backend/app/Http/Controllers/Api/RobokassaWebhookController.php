<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\RobokassaWebhookHandler;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class RobokassaWebhookController extends Controller
{
    public function __invoke(Request $request, RobokassaWebhookHandler $handler): Response
    {
        $allowed = (array) config('robokassa.allowed_ips', []);
        if ($allowed === [] || ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            return response('Forbidden', 403);
        }
        if (strtolower(explode(';', (string) $request->header('Content-Type'))[0]) !== 'application/x-www-form-urlencoded'
            || strlen($request->getContent()) > 65536) {
            return response('Invalid request', 400);
        }
        try {
            return response($handler->handle($request->getContent()), 200)->header('Content-Type', 'text/plain');
        } catch (DomainException) {
            return response('Result not accepted', 409);
        }
    }
}
