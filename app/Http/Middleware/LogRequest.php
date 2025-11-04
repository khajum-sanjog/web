<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\RequestLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogRequest
{
    /**
     * The routes that should be excluded from logging.
     *
     * @var array
     */
    private $excludedRoutes = [
        'api/login' => ['POST'],
        'api/register' => ['POST'],
        'api/refresh' => ['POST'],
        'api/payments/template' => ['POST'],
    ];

    /**
     * Handle an incoming request and log it, excluding certain routes.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('accept', 'application/json');

        // Process the request and get the response

        $response = $next($request);

        // Log the request if it's not excluded
        if (!$this->shouldExcludeRequest($request)) {
            $this->logRequest($request, $response);
        }

        return $response;
    }

    /**
     * Determine if the request should be excluded from logging.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function shouldExcludeRequest(Request $request): bool
    {
        foreach ($this->excludedRoutes as $route => $methods) {
            if ($request->is($route) && in_array($request->method(), $methods)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Log the request and response details to the database.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return void
     */
    protected function logRequest(Request $request, Response $response): void
    {
        $payload = $request->getContent();
        $decodedPayload = json_decode($payload, false); // false for object
        $store_id = null;

        // Determine gateway based on URL
        $gateway = null;
        if (auth()->check()) {
            $userId = auth()->id();
            $gateway = $request->url() === route('webhook.stripe', ['userId' => $userId]) ? 'Stripe' :
                ($request->url() === route('webhook.authorize', ['userId' => $userId]) ? 'Authorize.net' : null);
        }

        if ($gateway && $decodedPayload) {
            try {
                switch ($gateway) {
                    case 'Stripe':
                        $store_id = $decodedPayload->data->object->metadata->store_id ?? null;
                        break;
                    case 'Authorize.net':
                        $invoiceNumber = $decodedPayload->payload->invoiceNumber ?? '';
                        $parts = explode('-', $invoiceNumber);
                        $store_id = $parts[4] ?? null;  // Retrieve store_id from invoiceNumber
                        break;
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to extract store_id from webhook payload', [
                    'gateway' => $gateway,
                    'error' => $e->getMessage(),
                    'payload' => $payload,
                ]);
            }
        }

        try {
            RequestLog::create([
                'store_id' => $store_id ?? null, // Use the determined store ID
                'url' => $request->fullUrl(), // Full URL of the request
                'method' => $request->method(), // HTTP method (GET, POST, etc.)
                'request' => $request->except(['password']),
                'response_status' => $response->getStatusCode(),
                'headers' => $this->filterHeaders($request->headers->all()),
                'response' => $response->getContent(),
                'query_parameters' => [],

            ]);
        } catch (\Exception $e) {
            Log::error('Request logging failed', [
                'error' => $e->getMessage(),
                'url' => $request->fullUrl(),
            ]);
        }
    }
    /**
     * Filter out sensitive headers from the request.
     *
     * @param  array  $headers
     * @return array
     */
    protected function filterHeaders(array $headers): array
    {
        $sensitive = ['authorization', 'cookie', 'x-csrf-token'];
        return array_diff_key($headers, array_flip($sensitive));
    }
}
