<?php

namespace App\Models;

use Spatie\Activitylog\Models\Activity as BaseActivity;

class Activity extends BaseActivity
{
    public function propertiesJson(): ?string
    {
        return $this->properties?->isNotEmpty() ? $this->properties->toJson() : null;
    }
}
