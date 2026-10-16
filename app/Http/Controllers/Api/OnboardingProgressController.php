<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OnboardingProgressController extends Controller
{
    private function key(Request $request, string $guide): array
    {
        abort_unless($request->user(), 401);
        validator(['guide_id' => $guide], ['guide_id' => ['required', Rule::in(['welcome', 'profile', 'attendance', 'schedules', 'salary', 'requests'])]])->validate();
        $data = $request->validate(['version' => 'required|integer|min:1|max:1000']);

        return ['employee_id' => (string) $request->user()->getAuthIdentifier(), 'guide_id' => $guide, 'version' => (int) $data['version']];
    }

    private function responseData(object $row): array
    {
        return array_merge(json_decode($row->progress, true), [
            'guide_id' => $row->guide_id, 'version' => (int) $row->version,
            'revision' => (int) $row->revision, 'updated_at' => $row->updated_at,
        ]);
    }

    public function show(Request $request, string $guide)
    {
        $row = DB::table('onboarding_progress')->where($this->key($request, $guide))->first();

        return response()->json(['data' => $row ? $this->responseData($row) : null]);
    }

    public function update(Request $request, string $guide)
    {
        $key = $this->key($request, $guide);
        $data = $request->validate([
            'revision' => 'required|integer|min:0',
            'status' => ['sometimes', Rule::in(['not_started', 'in_progress', 'deferred', 'completed'])],
            'last_step_id' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/'],
            'completed_step_ids' => 'sometimes|array|max:50',
            'completed_step_ids.*' => ['string', 'max:64', 'regex:/^[a-z0-9_-]+$/'],
            'task_results' => 'sometimes|array:profile_reviewed,avatar_updated,avatar_skipped',
            'task_results.*' => 'date',
        ]);

        return DB::transaction(function () use ($key, $data) {
            $empty = ['status' => 'not_started', 'last_step_id' => null, 'completed_step_ids' => [], 'task_results' => []];
            DB::table('onboarding_progress')->insertOrIgnore(array_merge($key, [
                'revision' => 0, 'progress' => json_encode($empty), 'created_at' => now(), 'updated_at' => now(),
            ]));
            $query = DB::table('onboarding_progress')->where($key);
            $row = (clone $query)->lockForUpdate()->first();
            abort_unless((int) $row->revision === (int) $data['revision'], 409, 'Tiến độ đã thay đổi trên thiết bị khác. Vui lòng tải lại.');
            $current = json_decode($row->progress, true);
            unset($data['revision']);
            $next = array_merge($current, $data);
            $next['completed_step_ids'] = array_values(array_unique(array_merge($current['completed_step_ids'], $data['completed_step_ids'] ?? [])));
            $next['task_results'] = array_merge($current['task_results'], $data['task_results'] ?? []);
            if (isset($next['task_results']['avatar_updated'])) {
                unset($next['task_results']['avatar_skipped']);
            }
            $query->update(['progress' => json_encode($next, JSON_THROW_ON_ERROR), 'revision' => $row->revision + 1, 'updated_at' => now()]);

            return response()->json(['data' => $this->responseData($query->first())]);
        });
    }
}
