<?php

namespace BalajiDharma\LaravelAdminCore\Actions\Permission;

use App\Models\Permission;
use BalajiDharma\LaravelAdminCore\Data\Permission\PermissionUpdateData;

class PermissionUpdateAction
{
    public function handle(PermissionUpdateData $data, Permission $permission): bool
    {
        return $permission->update([
            'name' => $data->getName(),
        ]);
    }
}
