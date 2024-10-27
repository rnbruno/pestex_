<?php

namespace BalajiDharma\LaravelAdminCore\Actions\User;

use App\Models\User;
use BalajiDharma\LaravelAdminCore\Data\User\UserUpdateData;

class UserUpdateAction
{
    public function handle(UserUpdateData $data, User $user): User
    {
        $user->update([
            'name' => $data->getName(),
            'email' => $data->getEamil(),
        ]);

        if ($data->password) {
            $user->update([
                'password' => $data->getHashPassword(),
            ]);
        }

        $user->syncRoles($data->getRoles());

        return $user;
    }
}
