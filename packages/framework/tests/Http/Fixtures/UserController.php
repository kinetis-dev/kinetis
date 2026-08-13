<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Hidden;
use Kinetis\Http\Attributes\Patch;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Attributes\Query;
use Kinetis\Http\Attributes\Response as ResponseDoc;
use Kinetis\Http\Responses\ErrorResponse;
use Psr\Http\Message\ResponseInterface;

final readonly class UserController
{
    #[Post('/users', status: 201)]
    public function store(#[Body] CreateUserRequest $data): UserResponse
    {
        return new UserResponse(name: $data->name, email: $data->email);
    }

    #[Get('/users/dashboard')]
    #[Hidden]
    public function dashboard(): array
    {
        return ['html' => true];
    }

    #[Get('/users')]
    public function index(#[Query] int $page = 1, #[Query] int $limit = 20): array
    {
        return ['page' => $page, 'limit' => $limit];
    }

    #[Get('/users/{id}')]
    public function show(int $id): array
    {
        return ['id' => $id];
    }

    #[Get('/users/{id}/maybe')]
    #[ResponseDoc(404, description: 'User not found.')]
    public function showOrNotFound(int $id): ResponseInterface|array
    {
        if ($id === 999) {
            return ErrorResponse::create(404, "User {$id} not found.");
        }

        return ['id' => $id];
    }

    #[Patch('/users/{id}/status')]
    public function updateStatus(int $id, #[Body] UpdateStatusRequest $data): array
    {
        return ['id' => $id, 'status' => $data->status];
    }

    #[Patch('/users/{id}/preferences')]
    public function updatePreferences(int $id, #[Body] UserPreferencesRequest $data): array
    {
        return ['id' => $id, 'theme' => $data->theme, 'notificationsEnabled' => $data->notificationsEnabled];
    }
}
