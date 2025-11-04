<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Middleware to authenticate requests using a temporary encrypted token.
 *
 * This middleware validates an encrypted temporary token provided in the
 * X-Temp-Token header, decrypts it, retrieves the associated JWT from cache,
 * and authenticates the user using the JWT. It ensures secure, time-limited
 * access to protected API endpoints.
 */
class AuthenticateWithTempToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request  The incoming HTTP request.
     * @param  \Closure  $next  The next middleware or request handler in the stack.
     * @return mixed  The response from the next middleware or a JSON error response.
     */
    public function handle(Request $request, Closure $next)
    {
        // Retrieve the encrypted token from the X-Temp-Token header
        $encodedToken = $request->header('X-Temp-Token');
        // Log the received token for debugging (logs 'null' if missing)
        Log::info('Received X-Temp-Token: ' . ($encodedToken ?? 'null'));

        // Check if the token is missing
        if (!$encodedToken) {
            // Log an error if the token is not provided
            Log::error('Missing X-Temp-Token header');
            // Return a 401 response indicating missing token
            return response()->json(['error' => 'Unauthorized: Missing token'], 401);
        }

        try {
            // URL-decode the token to reverse any URL encoding from the client
            $decodedToken = rawurldecode($encodedToken);
            // Log the URL-decoded token for debugging
            Log::info('URL-decoded token: ' . $decodedToken);

            // Decode the encryption key from the environment variable
            $key = base64_decode(env('ENCRYPTION_KEY'));
            // Base64-decode the token to get the IV and encrypted data
            $data = base64_decode($decodedToken);

            // Check if base64 decoding was successful
            if ($data === false) {
                // Log an error if decoding fails
                Log::error('Base64 decoding failed');
                // Return a 401 response for invalid token encoding
                return response()->json(['error' => 'Unauthorized: Invalid token encoding'], 401);
            }

            // Get the initialization vector (IV) length for AES-256-CBC (16 bytes)
            $ivLength = openssl_cipher_iv_length('aes-256-cbc');
            // Extract the IV from the start of the decoded data
            $iv = substr($data, 0, $ivLength);
            // Extract the encrypted token from the remaining data
            $encrypted = substr($data, $ivLength);

            // Decrypt the token using AES-256-CBC with the key and IV
            $tempToken = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            // Log the decrypted temporary token (logs 'null' if decryption fails)
            Log::info('Decrypted tempToken: ' . ($tempToken ?? 'null'));

            // Retrieve the JWT from the cache using the decoded token as part of the key
            $jwt = Cache::get('temp_token_' . $decodedToken);
            // Log the retrieved JWT for debugging (logs 'null' if not found)
            Log::info('Retrieved JWT: ' . ($jwt ?? 'null'));

            // Check if the JWT exists in the cache
            if (!$jwt) {
                // Log an error if the JWT is not found
                Log::error('No JWT found in cache');
                // Return a 401 response for invalid token
                return response()->json(['error' => 'Unauthorized: Invalid token'], 401);
            }

            // Manually authenticate the user using the retrieved JWT
            JWTAuth::setToken($jwt)->authenticate();
            // Log successful authentication
            Log::info('User authenticated via temp token');

            // Pass the request to the next middleware or handler
            return $next($request);
        } catch (\Exception $e) {
            // Log any errors during the authentication process
            Log::error('Authentication error: ' . $e->getMessage());
            // Return a 401 response with the error message
            return response()->json(['error' => 'Unauthorized: ' . $e->getMessage()], 401);
        }
    }
}
