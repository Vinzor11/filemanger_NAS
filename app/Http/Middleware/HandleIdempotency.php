<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HandleIdempotency
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key = $request->header('X-Idempotency-Key');
        if (! is_string($key) || trim($key) === '') {
            return $this->error('Missing X-Idempotency-Key header.', 422);
        }

        $actorId = $request->user()?->id;
        $payload = $request->except(['file']);
        if ($request->hasFile('file')) {
            $payload['file_meta'] = [
                'name' => $request->file('file')?->getClientOriginalName(),
                'size' => $request->file('file')?->getSize(),
                'mime' => $request->file('file')?->getClientMimeType(),
            ];
        }
        $requestHash = hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($payload));

        $record = IdempotencyKey::query()
            ->where('actor_user_id', $actorId)
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->first();

        if ($record !== null) {
            if ($record->request_hash !== $requestHash) {
                return $this->error('Idempotency key reuse with different payload.', 409);
            }

            if ($record->status === 'completed') {
                return $this->replayResponse($record);
            }

            if ($record->status === 'in_progress') {
                return $this->error('Request is already in progress.', 409);
            }
        }

        $record ??= IdempotencyKey::query()->create([
            'actor_user_id' => $actorId,
            'scope' => $scope,
            'idempotency_key' => $key,
            'request_hash' => $requestHash,
            'status' => 'in_progress',
            'expires_at' => now()->addHours(24),
        ]);

        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (Throwable $exception) {
            $record->update([
                'status' => 'failed',
            ]);

            throw $exception;
        }

        if ($response->getStatusCode() < 500) {
            $record->update([
                'status' => 'completed',
                'response_code' => $response->getStatusCode(),
                'response_body' => json_encode($this->responsePayload($response)),
            ]);
        } else {
            $record->update(['status' => 'failed']);
        }

        return $response;
    }

    private function replayResponse(IdempotencyKey $record): Response
    {
        $payload = json_decode($record->response_body ?? '{}', true);
        $status = $record->response_code ?? 200;

        if (is_string(Arr::get($payload, 'redirect_to')) && Arr::get($payload, 'redirect_to') !== '') {
            return redirect()->to(Arr::get($payload, 'redirect_to'))->with('status', 'Request already processed.');
        }

        return response()->json([
            'replayed' => true,
            'data' => Arr::get($payload, 'data'),
            'message' => Arr::get($payload, 'message', 'Idempotent replay'),
            'redirect_to' => Arr::get($payload, 'redirect_to'),
        ], $status);
    }

    private function responsePayload(Response $response): array
    {
        if ($response instanceof JsonResponse) {
            return [
                'message' => 'OK',
                'data' => $response->getData(true),
            ];
        }

        if (method_exists($response, 'getTargetUrl')) {
            return [
                'message' => 'Redirect',
                'redirect_to' => $response->getTargetUrl(),
            ];
        }

        return [
            'message' => Str::limit(strip_tags($response->getContent()), 500),
        ];
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
        ], $status);
    }
}
