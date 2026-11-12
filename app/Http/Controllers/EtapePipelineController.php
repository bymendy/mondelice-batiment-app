<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateEtapePipelineRequest;
use App\Models\EtapePipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Etapes du pipeline : lecture pour ceux qui voient les affaires,
 * modification (nom, probabilite) reservee a la Direction
 * (permission pipeline.parametrer).
 */
class EtapePipelineController extends Controller implements HasMiddleware
{
    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:opportunites.voir', only: ['index']),
            new Middleware('can:pipeline.parametrer', only: ['update']),
        ];
    }

    /**
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        return response()->json(EtapePipeline::query()->orderBy('ordre')->get());
    }

    /**
     * @param UpdateEtapePipelineRequest $request
     * @param EtapePipeline $etape
     * @return JsonResponse
     */
    public function update(UpdateEtapePipelineRequest $request, EtapePipeline $etape): JsonResponse
    {
        $etape->update($request->validated());

        return response()->json($etape);
    }
}
