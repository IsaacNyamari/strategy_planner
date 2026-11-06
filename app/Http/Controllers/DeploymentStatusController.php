<?php

namespace App\Http\Controllers;

use App\Deployment\Deployer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON polled by the Deployments page while a deploy runs. It stays reachable
 * during maintenance mode (see bootstrap/app.php) so the log keeps updating.
 */
class DeploymentStatusController extends Controller
{
    public function __invoke(Request $request, Deployer $deployer): JsonResponse
    {
        return response()->json([
            'state' => $deployer->state(),
            'log' => $deployer->log(),
            'versions' => $request->boolean('versions') ? $deployer->versions(fresh: true) : null,
        ])->header('Cache-Control', 'no-store');
    }
}
