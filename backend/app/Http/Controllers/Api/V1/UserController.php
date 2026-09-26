<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Identity\Actions\SaveUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function index(Request $r): AnonymousResourceCollection
    {
        $data = $r->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return UserResource::collection(User::with('roles.permissions')->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")))->orderBy('id')->paginate(\App\Support\PerPage::resolve(20)));
    }

    public function store(SaveUserRequest $r, SaveUser $action): JsonResponse
    {
        return (new UserResource($action->execute($r->validated())))->response()->setStatusCode(201);
    }

    public function update(SaveUserRequest $r, User $user, SaveUser $action): UserResource
    {
        return new UserResource($action->execute($r->validated(), $user));
    }
}
