<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use App\Traits\ResponseTrait;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use ResponseTrait;

    /**
     * Register a new user and return a JWT token.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     *
     */
    public function register(Request $request)
    {
        // Validate request data
        $validator = Validator::make($request->all(), [
            'store_id'    => 'required|integer',
            'store_name'  => 'required|string',
            'domain_name' => 'required|string',
            'status'      => 'required|in:0,1',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation error', $validator->errors(), Response::HTTP_BAD_REQUEST); // 400 status code
        }

        // Check if store_id ,store_name and domain_name combination already exists
        $existingUser = User::where('store_id', $request->store_id)
            ->where('domain_name', $request->domain_name)
            ->first();

        if ($existingUser) {
            // Update existing user's status
            $existingUser->status = (string) $request->status;
            $existingUser->save();

            if ($existingUser->status === '1') {
                // If the status is active, generate JWT token
                $token = JWTAuth::claims([
                    'store_id' => $existingUser->store_id,
                    'email'    => $existingUser->email
                ])->fromUser($existingUser);

                return $token ? $this->respondWithToken($token) :
                    $this->errorResponse('Token generation failed', [], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return $this->successResponse('User status has been updated to inactive', Response::HTTP_OK);
        }

        // Generate a random email based on the store name
        $generatedEmail = str_replace(' ', '_', strtolower($request->store_name)) . '_' . uniqid() . '@voxmg.com';

        // Create user with the provided status
        $user = User::create([
            'name'        => $request->store_name,
            'email'       => $generatedEmail,
            'domain_name' => $request->domain_name,
            'store_id'    => $request->store_id,
            'store_name'  => $request->store_name,
            'status'      => (string) $request->status,
        ]);

        // Only generate and return JWT token, if  the user status is active '1'
        if ($request->status == '1') {
            $token = JWTAuth::claims([
                 'store_id' => $user->store_id,
                 'email'    => $user->email
             ])->fromUser($user);

            return $token ? $this->respondWithToken($token) :
            $this->errorResponse('Token generation failed', [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->successResponse('User registered successfully', Response::HTTP_OK);
    }

    /**
     * Login user and return JWT token.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $credentials = $request->only(['email', 'password']);

        // Validate credentials
        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return $this->errorResponse('Invalid credentials', [], Response::HTTP_UNAUTHORIZED);  // 401 status code
        }

        // Generate JWT token with store_id and email as claims
        $token = JWTAuth::claims([
            'store_id' => $user->store_id,
            'email'    => $user->email
        ])->fromUser($user);

        return $token ? $this->respondWithToken($token) :
        $this->errorResponse('Token generation failed', [], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Refresh JWT token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        $user = auth()->user();

        //invalidate old token and process to generate new one
        \auth()->logout();

        $token = JWTAuth::claims([
            'store_id' => $user->store_id,
            'email'    => $user->email
        ])->fromUser($user);

        return $token ? $this->respondWithToken($token) :
        $this->errorResponse('Token generation failed', [], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Format token response.
     *
     * @param  string  $token
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithToken($token)
    {
        return $this->successResponseWithData('Token generated successfully', [
            'access_token' => $token,
            'token_type' => 'Bearer'
        ], Response::HTTP_OK);  // 200 status code
    }
}
