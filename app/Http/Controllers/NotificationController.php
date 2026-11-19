<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Alertes à l'écran de l'utilisateur connecté (lot D4b). Chacun ne lit et ne
 * marque que les siennes.
 */
class NotificationController extends Controller
{
    /**
     * @param Request $request
     * @return JsonResponse `data` (les 50 plus récentes) et `non_lues`.
     */
    public function index(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        return response()->json([
            'data' => $utilisateur->notifications()->limit(50)->get()
                ->map(fn (DatabaseNotification $notification): array => $this->presenter($notification))
                ->all(),
            'non_lues' => $utilisateur->unreadNotifications()->count(),
        ]);
    }

    /**
     * Marque une de ses alertes comme lue.
     *
     * @param Request $request
     * @param string $notification
     * @return JsonResponse
     */
    public function lue(Request $request, string $notification): JsonResponse
    {
        $element = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $element->markAsRead();

        return response()->json($this->presenter($element));
    }

    /**
     * @param DatabaseNotification $notification
     * @return array<string, mixed>
     */
    private function presenter(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => class_basename($notification->type),
            'data' => $notification->data,
            'lue_le' => $notification->read_at,
            'creee_le' => $notification->created_at,
        ];
    }
}
