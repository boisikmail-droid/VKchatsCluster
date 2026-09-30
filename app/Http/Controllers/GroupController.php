<?php

namespace App\Http\Controllers;

use App\Models\ActivityEvent;
use App\Models\VkGroup;
use App\Models\VkGroupMember;
use App\Services\Vk\CommunitySync;
use App\Services\Vk\GroupRegistrar;
use App\Services\Vk\VkApiException;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    public function __construct(
        private GroupRegistrar $registrar,
        private CommunitySync $communitySync
    ) {
    }

    public function index()
    {
        $groups = VkGroup::orderBy('id')->get()->map(function (VkGroup $group) {
            return $group->present(false);
        })->values();

        return response()->json(['data' => $groups]);
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'access_token' => 'required|string',
            'confirmation_code' => 'required|string|max:255',
            'secret_key' => 'required|string|max:50',
            'vk_group_id' => 'nullable|integer|min:1',
            'screen_name' => 'nullable|string|max:64',
        ]);

        try {
            $group = $this->registrar->register($request->only([
                'access_token',
                'confirmation_code',
                'secret_key',
                'vk_group_id',
                'screen_name',
            ]));
        } catch (VkApiException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'vk_code' => $e->getCode(),
            ], 422);
        }

        return response()->json([
            'data' => $group->present(true),
        ], $group->wasRecentlyCreated ? 201 : 200);
    }

    public function show(int $id)
    {
        $group = VkGroup::findOrFail($id);

        return response()->json(['data' => $group->present(true)]);
    }

    public function sync(int $id)
    {
        $group = VkGroup::findOrFail($id);

        try {
            $result = $this->communitySync->sync($group);
        } catch (VkApiException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'vk_code' => $e->getCode(),
            ], 422);
        }

        $group->refresh();

        return response()->json([
            'members' => $result['members'],
            'total' => $result['total'],
            'data' => $group->present(true),
        ]);
    }

    public function members(int $id)
    {
        $group = VkGroup::findOrFail($id);

        $members = VkGroupMember::with('user')
            ->where('group_id', $group->id)
            ->orderByDesc('updated_at')
            ->paginate(50);

        return response()->json($members);
    }

    public function activity(int $id)
    {
        VkGroup::findOrFail($id);

        $events = ActivityEvent::with('user')
            ->where('group_id', $id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(50);

        return response()->json($events);
    }

    public function stats(int $id)
    {
        $group = VkGroup::findOrFail($id);

        $events = ActivityEvent::query()
            ->where('group_id', $group->id)
            ->selectRaw('event_type, actor, count(*) as total')
            ->groupBy('event_type', 'actor')
            ->orderBy('event_type')
            ->get()
            ->map(function ($row) {
                return [
                    'event_type' => $row->event_type,
                    'actor' => $row->actor,
                    'total' => (int) $row->total,
                ];
            })
            ->values();

        return response()->json([
            'group_id' => $group->id,
            'vk_id' => $group->vk_id,
            'members_count' => $group->members_count,
            'known_members' => $group->members()->where('is_member', true)->count(),
            'events' => $events,
        ]);
    }
}
