<?php

declare(strict_types=1);

require_once __DIR__.'/../Billing/helpers.php';

use Modules\Files\Models\File;
use Modules\Qualifications\Models\Qualification;
use Modules\Tenancy\Models\Tenant;

const TYM_V1 = 'https://tym.skills.test/v1';

/** A published catalogue entry for tests. */
function catalogueEntry(string $title = 'Occupational Certificate: Bricklayer', string $saqa = '93627'): Qualification
{
    return asPlatform(function () use ($title, $saqa): Qualification {
        $q = Qualification::query()->create(['type' => 'trade', 'saqa_id' => $saqa, 'title' => $title, 'nqf_level' => 4, 'credits' => 361]);
        $q->forceFill(['status' => 'published'])->save();

        return $q;
    });
}

/** A clean, uploaded registration letter owned by the person. */
function registrationLetter(Tenant $tenant, string $ownerId): string
{
    return asTenant($tenant, fn () => File::query()->create([
        'owner_id' => $ownerId, 'purpose' => 'credential_document', 'original_name' => 'letter.pdf', 'mime' => 'application/pdf',
        'size' => 1000, 'store_driver' => 'local', 'path' => 'x/letter.pdf', 'status' => 'ready',
    ])->id);
}

function signInAs(object $test, string $email): void
{
    $test->flushHeaders()->flushSession()->withHeaders(webOrigin('tym.skills.test'));
    $test->postJson(TYM_V1.'/auth/login', ['email' => $email, 'password' => 'correct horse battery staple'])->assertOk();
}
