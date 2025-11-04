<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trait ResponseTrait
 *
 * Provides standardized methods for returning JSON responses in a Laravel application.
 */
trait ResponseTrait
{
    /**
     * Returns a success response with a message and data.
     *
     * @param string $message The success message.
     * @param array|Collection|JsonResource $data The response data (can be an array, collection or JSON resource).
     * @param int $statusCode The HTTP status code (default: 200 OK).
     * @return JsonResponse The JSON response object.
     */
    public function successResponseWithData($message = null, array|Collection|JsonResource|Model $data = [], $statusCode = Response::HTTP_OK): JsonResponse
    {
        unset($data['success']);

        return response()->json([
            'type' => 'success',
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }

    /**
     * Returns an error response with a message and optional error details.
     *
     * @param string $message The error message.
     * @param array|string $errors The error details (can be an array or string).
     * @param int $statusCode The HTTP status code (default: 400 BAD REQUEST).
     * @return JsonResponse The JSON response object.
     */
    public function errorResponse($message = null, $errors = [], $statusCode = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        // Convert MessageBag to array if it's an instance of MessageBag
        if ($errors instanceof \Illuminate\Support\MessageBag) {
            $errors = $errors->toArray();
        }

        // Remove 'http_status' and 'success' from the errors, if present
        unset($errors['http_status'], $errors['success']);

        return response()->json([
            'type' => 'error',
            'message' => $message,
            'errors' => $errors
        ], $statusCode);
    }

    /**
     * Returns a success response with only a message.
     *
     * @param string|null $message The success message (optional).
     * @param int $status The HTTP status code (default: 200 OK).
     * @return JsonResponse The JSON response object.
     */
    public function successResponse($message = null, $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json([
            'type' => 'success',
            'message' => $message
        ], $status);
    }
}
