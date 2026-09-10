<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;

final readonly class RootedBodyController
{
    #[Post('/rooted/users', status: 201)]
    public function store(#[Body('user')] CreateUserRequest $user): array
    {
        return ['name' => $user->name, 'email' => $user->email];
    }

    #[Post('/rooted/avatars', status: 201)]
    public function upload(#[Body('profile')] AvatarUploadRequest $profile): array
    {
        return ['name' => $profile->name, 'filename' => $profile->avatar->getClientFilename()];
    }
}
