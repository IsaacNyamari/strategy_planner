<?php

namespace App\WhatsApp;

use App\Http\Controllers\ExportController;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the same PDFs as the Download buttons, for attaching to WhatsApp.
 * Individual statements and the per-member contributions grid are
 * deliberately not available here (private financial details).
 */
class Attachments
{
    /**
     * @param  array<string, mixed>  $params
     * @return array{filename: string, content: string}|null
     */
    public function make(string $type, array $params): ?array
    {
        $controller = app(ExportController::class);

        $response = match ($type) {
            'roster' => $controller->roster(Request::create('/', 'GET', ['month' => $params['month'] ?? null]), 'pdf'),
            'uniforms' => $controller->uniforms(Request::create('/', 'GET', ['month' => $params['month'] ?? null]), 'pdf'),
            default => null, // nothing with individual members' balances is ever attached
        };

        return $response ? ['filename' => $this->filename($response, $type), 'content' => $this->content($response)] : null;
    }

    protected function content(Response $response): string
    {
        return $response instanceof BinaryFileResponse
            ? (string) file_get_contents($response->getFile()->getPathname())
            : (string) $response->getContent();
    }

    protected function filename(Response $response, string $type): string
    {
        preg_match('/filename="?([^";]+)"?/', (string) $response->headers->get('content-disposition'), $m);

        return $m[1] ?? $type.'.pdf';
    }
}
