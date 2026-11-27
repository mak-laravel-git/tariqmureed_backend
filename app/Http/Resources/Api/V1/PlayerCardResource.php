<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A player on the website's Players page.
 *
 * @mixin Player
 */
class PlayerCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isNational = (bool) $this->team?->national_team;
        $teamName = $this->team->name ?? '';

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->fullname,
            // An empty address makes the website draw its placeholder picture.
            'image' => $this->image ?? '',
            'role' => $this->position ?? 'Player',
            // A national side is shown as the country, so the same name is never printed twice.
            'team' => $isNational ? '' : $teamName,
            'country' => $isNational ? $teamName : '',
        ];
    }
}
