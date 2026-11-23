<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdatePasswordRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProfileRequest;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    /**
     * Change the signed-in administrator's name and email address.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $request->user()->update($request->safe()->only(['name', 'email']));

        return response()->json(['message' => 'Profile updated.']);
    }

    /**
     * Change the signed-in administrator's password. The other API tokens are revoked.
     */
    public function password(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update(['password' => $request->validated('password')]);
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password updated.']);
    }
}
