<?php

namespace App\Social;

use App\WhatsApp\WahaException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Posts to the team's Facebook Page through Meta's official Graph API.
 * Enabled once FACEBOOK_PAGE_ID and FACEBOOK_PAGE_TOKEN (a Page access token
 * with pages_manage_posts) are set.
 */
class FacebookPage
{
    public function enabled(): bool
    {
        return filled(config('services.facebook.page_id')) && filled(config('services.facebook.page_token'));
    }

    /**
     * Publish now. With an image, it becomes a photo post with the text as caption.
     *
     * @return string the new post id
     *
     * @throws WahaException (reused as the app's "user-safe send error")
     */
    public function publish(string $message, ?string $imageUrl = null): string
    {
        if (! $this->enabled()) {
            throw new WahaException('Facebook Page is not connected.');
        }

        $base = 'https://graph.facebook.com/'.config('services.facebook.graph_version').'/'.config('services.facebook.page_id');

        try {
            $response = Http::asForm()->timeout(30)->post(
                $imageUrl ? $base.'/photos' : $base.'/feed',
                array_filter([
                    $imageUrl ? 'caption' : 'message' => $message,
                    'url' => $imageUrl,
                    'access_token' => (string) config('services.facebook.page_token'),
                ]),
            );
        } catch (ConnectionException) {
            throw new WahaException('Could not reach Facebook.');
        }

        if ($response->failed()) {
            throw new WahaException('Facebook: '.($response->json('error.message') ?? 'error '.$response->status()));
        }

        return (string) ($response->json('post_id') ?? $response->json('id'));
    }
}
