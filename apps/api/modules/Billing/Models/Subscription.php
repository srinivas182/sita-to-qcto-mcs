<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Audit\Concerns\Auditable;
use Modules\Billing\Support\SubscriptionStatus;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $package_id
 * @property string|null $pending_package_id
 * @property string $interval
 * @property SubscriptionStatus $status
 * @property string $collection
 * @property string|null $payment_method_id
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property bool $cancel_at_period_end
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $past_due_since
 * @property Carbon|null $trial_reminder_sent_at
 * @property-read Package $package
 * @property-read Package|null $pendingPackage
 * @property-read PaymentMethod|null $paymentMethod
 */
final class Subscription extends Model
{
    use Auditable, BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'package_id', 'pending_package_id', 'interval', 'status', 'collection', 'payment_method_id',
        'trial_ends_at', 'current_period_start', 'current_period_end', 'cancel_at_period_end', 'cancelled_at',
        'past_due_since', 'trial_reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'datetime',
            'past_due_since' => 'datetime',
            'trial_reminder_sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /** @return BelongsTo<Package, $this> */
    public function pendingPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'pending_package_id');
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
