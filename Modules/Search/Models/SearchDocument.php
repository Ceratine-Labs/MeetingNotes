<?php

namespace Modules\Search\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\HasUuid;
use Modules\Minutes\Models\Meeting;
use Modules\Tenancy\Concerns\BelongsToOrganisation;

/**
 * One searchable thing — a transcript, a minutes section, a decision or an action item.
 *
 * A derived record, not a source of truth: every row is rebuilt from the meeting it
 * belongs to whenever that meeting's minutes are persisted. Nothing should ever edit a
 * row here directly, and losing the whole table costs only a `search:reindex`.
 *
 * Extends Model rather than BaseModel because SoftDeletes would be actively wrong: a
 * soft-deleted index row would keep matching unless every query remembered to exclude
 * it, and there is nothing to recover. Stale rows are deleted outright.
 *
 * @property string $id
 * @property string $organisation_id
 * @property string $type
 * @property string $meeting_id
 * @property string|null $source_id
 * @property string $title
 * @property string|null $label
 * @property string $body
 * @property int $weight
 * @property \Illuminate\Support\Carbon|null $meeting_date
 */
class SearchDocument extends Model
{
    use BelongsToOrganisation;
    use HasUuid;

    /**
     * The raw meeting text. Lowest weight — it is where a name like "Maria" is most
     * likely to appear, but also the least structured result to land on.
     */
    public const TYPE_TRANSCRIPT = 'transcript';

    /**
     * One of the nine canonical minutes sections.
     */
    public const TYPE_SECTION = 'minutes_section';

    /**
     * A numbered decision (D1, D2…).
     */
    public const TYPE_DECISION = 'decision';

    /**
     * A numbered action item (A1, A2…).
     */
    public const TYPE_ACTION_ITEM = 'action_item';

    /**
     * Result ordering when relevance ties, lower first.
     *
     * Decisions and action items outrank prose because they are what someone searching
     * a meeting archive is usually trying to find — "what did we decide about X", not
     * "where was X mentioned".
     *
     * @var array<string, int>
     */
    public const WEIGHTS = [
        self::TYPE_DECISION => 10,
        self::TYPE_ACTION_ITEM => 20,
        self::TYPE_SECTION => 40,
        self::TYPE_TRANSCRIPT => 60,
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'search_documents';

    protected $fillable = [
        'organisation_id',
        'type',
        'meeting_id',
        'source_id',
        'title',
        'label',
        'body',
        'weight',
        'meeting_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'meeting_date' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_id');
    }

    /**
     * Human label for the result type, shown as a badge.
     */
    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_TRANSCRIPT => 'Transcript',
            self::TYPE_SECTION => 'Minutes',
            self::TYPE_DECISION => 'Decision',
            self::TYPE_ACTION_ITEM => 'Action',
            default => ucfirst($this->type),
        };
    }

    /**
     * Tabler badge colour per result type, so a result set is scannable by shape.
     */
    public function typeColour(): string
    {
        return match ($this->type) {
            self::TYPE_DECISION => 'bg-blue-lt',
            self::TYPE_ACTION_ITEM => 'bg-orange-lt',
            self::TYPE_SECTION => 'bg-green-lt',
            default => 'bg-secondary-lt',
        };
    }

    /**
     * A short excerpt of the body centred on the first match.
     *
     * Extracted at read time rather than stored, so the snippet always reflects the
     * term the user actually typed — a pre-computed excerpt would show the same text
     * whatever they searched for.
     *
     * @param  string  $term  The user's search term. Not a regex; matched literally.
     * @param  int  $radius  Characters of context to keep either side of the match.
     */
    public function snippet(string $term, int $radius = 90): string
    {
        $body = preg_replace('/\s+/', ' ', trim($this->body)) ?? '';

        if ($body === '') {
            return '';
        }

        // Case-insensitive literal search. stripos, not a regex, because the term comes
        // from user input and would otherwise need escaping to be safe.
        $position = $term !== '' ? stripos($body, $term) : false;

        if ($position === false) {
            // Matched on a stemmed form ("budgets" vs "budget"), so there is no literal
            // occurrence to centre on. Show the opening instead of nothing.
            return mb_strimwidth($body, 0, $radius * 2, '…');
        }

        $start = max(0, $position - $radius);
        $length = mb_strlen($term) + ($radius * 2);

        $excerpt = mb_substr($body, $start, $length);

        return ($start > 0 ? '…' : '').$excerpt.($start + $length < mb_strlen($body) ? '…' : '');
    }
}
