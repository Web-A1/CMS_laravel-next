<?php

namespace App\Http\Resources;

use App\Services\Articles\ArticleMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentAuthorResource extends JsonResource
{
    private bool $home = false;

    public function forHome(): static
    {
        $this->home = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $media = app(ArticleMedia::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'avatarUrl' => $media->publicUrl($this->avatar_path),
            'avatarVariants' => $media->variants($this->avatar_path),
            'photoUrl' => $media->publicUrl($this->photo_path),
            'photoVariants' => $media->variants($this->photo_path),
            'aboutUrl' => '/about',
            ...($this->home ? ['homeRoleText' => $this->home_role_text, 'cutoutKey' => $this->home_cutout_key] : []),
        ];
    }
}
