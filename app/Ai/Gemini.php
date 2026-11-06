<?php

namespace App\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal Gemini client (generateContent + JSON-schema output).
 *
 * Models from config('worship.gemini.models') are tried in order; when one
 * is rate-limited (429) or unavailable (404/5xx) the next one is used.
 */
class Gemini
{
    public function configured(): bool
    {
        return filled(config('worship.gemini.api_key')) && config('worship.gemini.models') !== [];
    }

    /**
     * Ask for JSON matching $schema (OpenAPI-style schema) and return it decoded.
     *
     * @param  array<string, mixed>  $schema
     * @return array<mixed>
     *
     * @throws GeminiException
     */
    public function json(string $prompt, array $schema, ?string $system = null, float $temperature = 0.9): array
    {
        $text = $this->generate($prompt, $system, [
            'responseMimeType' => 'application/json',
            'responseSchema' => $schema,
            'temperature' => $temperature,
        ]);

        $decoded = json_decode($this->stripFences($text), true);

        if (! is_array($decoded)) {
            throw new GeminiException('The AI returned an unreadable response. Please try again.');
        }

        return $decoded;
    }

    /**
     * Plain-text generation.
     *
     * @throws GeminiException
     */
    public function text(string $prompt, ?string $system = null, float $temperature = 0.8): string
    {
        return trim($this->generate($prompt, $system, ['temperature' => $temperature]));
    }

    /**
     * @param  array<string, mixed>  $generationConfig
     *
     * @throws GeminiException
     */
    protected function generate(string $prompt, ?string $system, array $generationConfig): string
    {
        if (! $this->configured()) {
            throw new GeminiException('AI is not configured. Add GEMINI_API_KEY to your .env file.');
        }

        $body = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => $generationConfig,
        ];

        if ($system) {
            $body['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        $lastError = 'The AI service is unavailable right now.';
        $models = config('worship.gemini.models');

        // AI calls can outlast PHP's default 30s limit (slow model + fallback).
        // Give the request enough time for every model to answer or time out.
        if (! app()->runningUnitTests()) {
            set_time_limit(count($models) * (int) config('worship.gemini.timeout', 45) + 15);
        }

        foreach ($models as $model) {
            try {
                $response = $this->request($model, $body);
            } catch (ConnectionException $e) {
                $lastError = 'Could not reach the AI service. Check your internet connection.';
                Log::warning('Gemini connection failed', ['model' => $model, 'error' => $e->getMessage()]);

                continue;
            }

            if ($response->successful()) {
                return $this->extractText($response);
            }

            $lastError = $this->describe($response);
            Log::warning('Gemini request failed', ['model' => $model, 'status' => $response->status(), 'body' => $response->json('error.message')]);

            // Try the next model only for rate limits / unavailable models.
            if (! in_array($response->status(), [404, 429, 500, 502, 503, 504], true)) {
                break;
            }
        }

        throw new GeminiException($lastError);
    }

    /** @param array<string, mixed> $body */
    protected function request(string $model, array $body): Response
    {
        $base = rtrim((string) config('worship.gemini.base_url'), '/');

        return Http::withHeaders(['x-goog-api-key' => (string) config('worship.gemini.api_key')])
            ->acceptJson()
            ->timeout((int) config('worship.gemini.timeout', 45))
            ->post("{$base}/models/{$model}:generateContent", $body);
    }

    /** @throws GeminiException */
    protected function extractText(Response $response): string
    {
        $parts = $response->json('candidates.0.content.parts', []);
        $text = implode('', array_map(fn ($p) => is_array($p) ? (string) ($p['text'] ?? '') : '', is_array($parts) ? $parts : []));

        if ($text === '') {
            $reason = $response->json('promptFeedback.blockReason') ?? $response->json('candidates.0.finishReason');
            throw new GeminiException('The AI returned no content'.($reason ? " ({$reason})" : '').'. Please try again.');
        }

        return $text;
    }

    protected function describe(Response $response): string
    {
        return match (true) {
            $response->status() === 429 => 'The AI is rate-limited right now (free tier). Please wait a minute and try again.',
            in_array($response->status(), [401, 403], true) => 'The Gemini API key was rejected. Check GEMINI_API_KEY in .env.',
            $response->status() === 400 => 'The AI rejected the request: '.($response->json('error.message') ?? 'bad request'),
            default => 'The AI service is unavailable right now. Please try again shortly.',
        };
    }

    protected function stripFences(string $text): string
    {
        return trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text)) ?? $text);
    }
}
