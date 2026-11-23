<?php

declare(strict_types=1);

namespace Modules\Funding\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Concerns\BelongsToTenant;

/**
 * @property string $id
 * @property string $user_id
 * @property string $holder_name
 * @property string $bank
 * @property string $branch_code
 * @property string $account_number
 * @property string $last4
 * @property string $account_type
 * @property string|null $proof_file_id
 * @property string|null $updated_by
 * @property Carbon|null $updated_at
 */
final class BankAccount extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** South African banks with their universal branch codes. */
    public const BANKS = [
        'absa' => ['ABSA', '632005'], 'african' => ['African Bank', '430000'], 'bankzero' => ['Bank Zero', '888000'], 'capitec' => ['Capitec', '470010'],
        'discovery' => ['Discovery Bank', '679000'], 'fnb' => ['FNB', '250655'], 'investec' => ['Investec', '580105'], 'nedbank' => ['Nedbank', '198765'],
        'oldmutual' => ['Old Mutual', '462005'], 'standard' => ['Standard Bank', '051001'], 'tyme' => ['TymeBank', '678910'], 'other' => ['Other bank', ''],
    ];

    public const TYPES = ['cheque' => 1, 'savings' => 2, 'transmission' => 3];

    protected $table = 'learner_bank_accounts';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['account_number'];

    protected function casts(): array
    {
        return ['account_number' => 'encrypted'];
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return ['holder_name' => $this->holder_name, 'bank' => $this->bank, 'bank_name' => self::BANKS[$this->bank][0] ?? $this->bank,
            'branch_code' => $this->branch_code, 'account' => '•••• '.$this->last4, 'account_type' => $this->account_type, 'proof_file_id' => $this->proof_file_id,
            'updated_at' => $this->updated_at?->toIso8601String()];
    }
}
