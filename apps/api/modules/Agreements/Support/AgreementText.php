<?php

declare(strict_types=1);

namespace Modules\Agreements\Support;

/**
 * Agreement wording is plain text with a few conventions: "# " and "## " headings,
 * numbered clauses ("1." / "1.1"), blank lines between paragraphs, and {{merge.fields}}.
 * Everything is escaped before formatting, so wording can never inject markup.
 */
final class AgreementText
{
    /** Fields a template may use, with what they mean. */
    public const FIELDS = [
        'provider.name' => 'Training provider (trading name)',
        'provider.legal_name' => 'Training provider (registered name)',
        'provider.registration_number' => 'Training provider CIPC number',
        'employer.legal_name' => 'Host employer (registered name)',
        'employer.registration_number' => 'Host employer CIPC number',
        'employer.sdl_number' => 'Host employer levy number',
        'employer.contact_name' => 'Host employer contact',
        'site.name' => 'Workplace',
        'site.address' => 'Workplace address',
        'site.approval_number' => 'Workplace approval number',
        'mentor.name' => 'Workplace mentor',
        'learner.name' => 'Learner',
        'qualification.title' => 'Qualification',
        'qualification.saqa_id' => 'SAQA ID',
        'start_date' => 'Start date',
        'end_date' => 'End date',
        'today' => 'Date sent',
    ];

    /** @return list<string> */
    public static function fieldsIn(string $body): array
    {
        preg_match_all('/\{\{\s*([a-z_.]+)\s*\}\}/', $body, $m);

        return array_values(array_unique($m[1]));
    }

    /** @return list<string> fields used that we don't know */
    public static function unknown(string $body): array
    {
        return array_values(array_diff(self::fieldsIn($body), array_keys(self::FIELDS)));
    }

    /** @param  array<string, string>  $values */
    public static function merge(string $body, array $values): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z_.]+)\s*\}\}/', fn (array $m): string => $values[$m[1]] ?? $m[0], $body);
    }

    public static function html(string $text): string
    {
        $out = [];
        foreach (preg_split("/\R{2,}/", trim(str_replace("\r", '', $text))) ?: [] as $block) {
            $e = nl2br(htmlspecialchars(trim($block), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
            $out[] = match (true) {
                str_starts_with($block, '## ') => '<h3>'.substr($e, 3).'</h3>',
                str_starts_with($block, '# ') => '<h2>'.substr($e, 2).'</h2>',
                preg_match('/^\d+(\.\d+)*\.?\s/', $block) === 1 => '<p class="clause">'.$e.'</p>',
                default => '<p>'.$e.'</p>',
            };
        }

        return implode("\n", $out);
    }
}
