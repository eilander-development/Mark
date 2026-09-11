<?php

namespace App\Models;

use Database\Factories\ExerciseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'youtube_id', 'title', 'channel', 'cues'])]
class Exercise extends Model
{
    /** @use HasFactory<ExerciseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'cues' => 'array',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toVideoArray(): array
    {
        $channel = (string) ($this->channel ?: '');
        $isAthlean = str_contains($channel, 'ATHLEAN');

        return [
            'videoId' => $this->youtube_id,
            'title' => $this->title ?: $this->name,
            'channel' => $isAthlean ? 'ATHLEAN-X™' : $channel,
            'cues' => $this->cues ?: [],
            'url' => $this->youtube_id
                ? 'https://www.youtube.com/watch?v='.$this->youtube_id
                : null,
            'isAthlean' => $isAthlean,
        ];
    }
}
