<?php

declare(strict_types=1);

namespace Modules\Enrolment\Support;

use Illuminate\Validation\ValidationException;

/** The extra questions an intake asks, and checking an applicant's answers against them. */
final class Questions
{
    public const TYPES = ['text', 'yesno', 'choice'];

    /**
     * @param  list<array{key: string, label: string, type: string, options?: list<string>, required?: bool}>  $questions
     * @param  array<string, mixed>  $answers
     * @return array<string, string|bool>
     */
    public static function answers(array $questions, array $answers): array
    {
        $clean = [];
        $errors = [];
        foreach ($questions as $q) {
            $value = $answers[$q['key']] ?? null;
            $missing = $value === null || $value === '';
            if ($missing) {
                if ($q['required'] ?? false) {
                    $errors["answers.{$q['key']}"] = 'Please answer: '.$q['label'];
                }

                continue;
            }
            $ok = match ($q['type']) {
                'yesno' => is_bool($value),
                'choice' => is_string($value) && in_array($value, $q['options'] ?? [], true),
                default => is_string($value) && mb_strlen($value) <= 2000,
            };
            if (! $ok) {
                $errors["answers.{$q['key']}"] = 'That answer is not one of the choices: '.$q['label'];

                continue;
            }
            $clean[$q['key']] = is_string($value) ? trim($value) : $value;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }
}
