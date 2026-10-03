<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One till action that is not itself a document (App\Services\Pos\PosActivity). */
class PosEvent extends Model
{
    use BelongsToCompany;

    public const CART_CLEARED = 'cart_cleared';     // «Άδειασμα» with items in the cart

    public const ITEM_REMOVED = 'item_removed';     // an item taken off (or its last unit)

    public const QTY_REDUCED = 'qty_reduced';       // fewer units of an item than scanned

    public const OPEN_PRICE = 'open_price';         // a price typed for an open-price item

    public const DISCOUNT = 'discount';             // a line discount % set / raised

    public const REPRINT = 'reprint';               // «Επανεκτύπωση»

    public const ISSUE_FAILED = 'issue_failed';     // «Δεν εκδόθηκε»

    public const RETURN_OPENED = 'return_opened';

    public const RETURN_CANCELLED = 'return_cancelled';

    public const X_REPORT = 'x_report';             // interim report printed

    /** Greek labels (owner-facing). */
    public const LABELS = [
        self::CART_CLEARED => 'Άδειασμα καλαθιού',
        self::ITEM_REMOVED => 'Αφαίρεση είδους',
        self::QTY_REDUCED => 'Μείωση ποσότητας',
        self::OPEN_PRICE => 'Ελεύθερη τιμή',
        self::DISCOUNT => 'Έκπτωση',
        self::REPRINT => 'Επανεκτύπωση',
        self::ISSUE_FAILED => 'Αποτυχία έκδοσης',
        self::RETURN_OPENED => 'Άνοιγμα επιστροφής',
        self::RETURN_CANCELLED => 'Ακύρωση επιστροφής',
        self::X_REPORT => 'Αναφορά (X)',
    ];

    protected $fillable = ['company_id', 'pos_session_id', 'user_id', 'type', 'invoice_id', 'product_id', 'qty', 'amount', 'note'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'amount' => 'decimal:2'];
    }

    public function label(): string
    {
        return self::LABELS[$this->type] ?? $this->type;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();   // a deleted item still names the event
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
