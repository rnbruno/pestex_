<?php

namespace BalajiDharma\LaravelAdminCore\Actions\Menu;

use BalajiDharma\LaravelAdminCore\Data\Menu\MenuUpdateData;
use BalajiDharma\LaravelMenu\Models\Menu;

class MenuUpdateAction
{
    public function handle(MenuUpdateData $data, Menu $menu): bool
    {
        return $menu->update([
            'name' => $data->getName(),
            'description' => $data->getDescription(),
        ]);
    }
}
