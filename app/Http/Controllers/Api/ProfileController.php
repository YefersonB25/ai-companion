<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user()->profile ?? (object) []);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'personal'                        => 'nullable|array',
            'personal.city'                   => 'nullable|string|max:100',
            'personal.country'                => 'nullable|string|max:100',
            'personal.birthdate'              => 'nullable|string|max:20',
            'personal.occupation'             => 'nullable|string|max:150',
            'personal.marital_status'         => 'nullable|string|max:50',
            'personal.children'               => 'nullable|integer|min:0',

            'health'                          => 'nullable|array',
            'health.allergies'                => 'nullable|array|max:20',
            'health.allergies.*'              => 'nullable|string|max:100',
            'health.conditions'               => 'nullable|array|max:10',
            'health.conditions.*'             => 'nullable|string|max:150',
            'health.medications'              => 'nullable|array',
            'health.medications.*'            => 'string|max:150',
            'health.blood_type'               => 'nullable|string|max:10',
            'health.fitness_goals'            => 'nullable|array',
            'health.fitness_goals.*'          => 'string|max:150',

            'preferences'                     => 'nullable|array',
            'preferences.diet'                => 'nullable|string|max:80',
            'preferences.favorite_foods'      => 'nullable|array',
            'preferences.favorite_foods.*'    => 'string|max:100',
            'preferences.disliked_foods'      => 'nullable|array',
            'preferences.disliked_foods.*'    => 'string|max:100',
            'preferences.hobbies'             => 'nullable|array|max:20',
            'preferences.hobbies.*'           => 'nullable|string|max:100',
            'preferences.music'               => 'nullable|array',
            'preferences.music.*'             => 'string|max:100',
            'preferences.sports'              => 'nullable|array',
            'preferences.sports.*'            => 'string|max:100',

            'routines'                        => 'nullable|array',
            'routines.wake_time'              => 'nullable|string|max:10',
            'routines.sleep_time'             => 'nullable|string|max:10',
            'routines.work_schedule'          => 'nullable|string|max:50',
            'routines.exercise_frequency'     => 'nullable|string|max:80',
            'routines.exercise_type'          => 'nullable|string|max:80',

            'relationships'                   => 'nullable|array',
            'relationships.*.name'            => 'required|string|max:100',
            'relationships.*.relation'        => 'required|string|max:80',
            'relationships.*.notes'           => 'nullable|string|max:300',

            'goals'                           => 'nullable|array',
            'goals.short_term'                => 'nullable|array|max:10',
            'goals.short_term.*'              => 'nullable|string|max:200',
            'goals.long_term'                 => 'nullable|array|max:10',
            'goals.long_term.*'               => 'nullable|string|max:200',
            'goals.savings_goal'              => 'nullable|string|max:200',
        ]);

        $request->user()->profile()->updateOrCreate(
            ['user_id' => $request->user()->id],
            $data
        );

        return response()->json($request->user()->fresh('profile')->profile);
    }
}
