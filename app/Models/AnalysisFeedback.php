<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FailureCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisFeedback extends Model
{
    use HasFactory;

    protected $table = 'analysis_feedback';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'was_helpful' => 'boolean',
            'root_cause_correct' => 'boolean',
            'correct_category' => FailureCategory::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public $timestamps = false;

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
