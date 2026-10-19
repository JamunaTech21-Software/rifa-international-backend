<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\UploadedFile;
use DateTimeImmutable;

/**
 * Validates request data and returns the cleaned values.
 *
 *   $data = Validator::make($input, [
 *       'full_name'     => 'required|string|min:2|max:100',
 *       'mobile'        => ['required', 'regex:/^01[3-9]\d{8}$/'],   // use an array when a rule contains "|"
 *       'passport_copy' => 'required_if:passport_status,have_passport|file|max_kb:5120|mimes:pdf,jpg,png',
 *   ], ['mobile.regex' => 'Enter a valid mobile number.'])->validate();
 *
 * - Strings are trimmed; empty strings become null.
 * - An empty optional field skips its other rules and is returned as null.
 * - Only the first failing rule per field is reported.
 * - validate() throws ValidationException (422) with per-field messages.
 *
 * Rules: required, required_if:field,value[,value], accepted, string, integer, boolean,
 * min:n, max:n, email, regex:/pattern/, in:a,b,c, date (Y-m-d), before_today,
 * digits_in:10,13,17, file, max_kb:n, mimes:pdf,jpg,png
 */
final class Validator
{
    private const MIME_TYPES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    private const IMPLICIT = ['required', 'required_if', 'accepted'];

    private const DEFAULT_MESSAGES = [
        'required' => 'The :attribute field is required.',
        'required_if' => 'The :attribute field is required.',
        'accepted' => 'The :attribute must be accepted.',
        'string' => 'The :attribute must be text.',
        'integer' => 'The :attribute must be a whole number.',
        'boolean' => 'The :attribute must be true or false.',
        'min.string' => 'The :attribute must be at least :param characters.',
        'min.numeric' => 'The :attribute must be at least :param.',
        'max.string' => 'The :attribute may not be longer than :param characters.',
        'max.numeric' => 'The :attribute may not be greater than :param.',
        'email' => 'Enter a valid email address.',
        'regex' => 'The :attribute format is invalid.',
        'in' => 'The selected :attribute is invalid.',
        'date' => 'Enter a valid date (YYYY-MM-DD).',
        'before_today' => 'The :attribute must be a date in the past.',
        'digits_in' => 'The :attribute must be :param digits.',
        'file' => 'Please upload a valid file.',
        'file.too_large' => 'The :attribute is too large.',
        'max_kb' => 'The :attribute may not be larger than :param.',
        'mimes' => 'The :attribute must be a file of type: :param.',
    ];

    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $validated = [];

    private bool $ran = false;

    /**
     * @param array<string, mixed>                $data       input incl. UploadedFile values
     * @param array<string, string|list<string>>  $rules
     * @param array<string, string>               $messages   'field.rule' or 'field' => message
     * @param array<string, string>               $attributes field => human name used in messages
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $messages = [],
        private readonly array $attributes = [],
    ) {
    }

    /**
     * @param array<string, mixed>               $data
     * @param array<string, string|list<string>> $rules
     * @param array<string, string>              $messages
     * @param array<string, string>              $attributes
     */
    public static function make(array $data, array $rules, array $messages = [], array $attributes = []): self
    {
        return new self($data, $rules, $messages, $attributes);
    }

    /**
     * @return array<string, mixed> validated values
     * @throws ValidationException
     */
    public function validate(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }

        return $this->validated;
    }

    public function fails(): bool
    {
        $this->run();

        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        $this->run();

        return $this->errors;
    }

    private function run(): void
    {
        if ($this->ran) {
            return;
        }
        $this->ran = true;

        foreach ($this->rules as $field => $ruleSet) {
            $rules = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);
            $value = $this->normalise($this->data[$field] ?? null);

            if ($this->isEmpty($value)) {
                foreach ($rules as $rule) {
                    [$name, $param] = $this->parse($rule);
                    if (in_array($name, self::IMPLICIT, true) && !$this->passesImplicit($name, $param)) {
                        $this->fail($field, $name, $param, $value);
                        continue 2;
                    }
                }
                $this->validated[$field] = null;
                continue;
            }

            foreach ($rules as $rule) {
                [$name, $param] = $this->parse($rule);
                if ($name === 'required' || $name === 'required_if' || $name === 'nullable') {
                    continue;
                }
                if (!$this->passes($name, $param, $value, $rules)) {
                    $this->fail($field, $name, $param, $value, $rules);
                    continue 2;
                }
            }

            $this->validated[$field] = $this->cast($value, $rules);
        }
    }

    /** Implicit rules on an empty value: return true when the empty value is acceptable. */
    private function passesImplicit(string $name, string $param): bool
    {
        if ($name === 'required' || $name === 'accepted') {
            return false;
        }

        // required_if:other,value1,value2
        $parts = explode(',', $param);
        $other = array_shift($parts);
        $otherValue = $this->normalise($this->data[$other] ?? null);

        return !in_array((string) $otherValue, $parts, true);
    }

    /** @param list<string> $rules */
    private function passes(string $name, string $param, mixed $value, array $rules): bool
    {
        return match ($name) {
            'accepted' => in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true),
            'string' => is_string($value),
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'boolean' => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true),
            'min' => $this->size($value, $rules) >= (float) $param,
            'max' => $this->size($value, $rules) <= (float) $param,
            'email' => is_string($value) && strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'regex' => is_string($value) && preg_match($param, $value) === 1,
            'in' => in_array((string) $value, explode(',', $param), true),
            'date' => $this->toDate($value) !== null,
            'before_today' => ($date = $this->toDate($value)) !== null && $date < new DateTimeImmutable('today'),
            'digits_in' => is_string($value) && ctype_digit($value) && in_array((string) strlen($value), explode(',', $param), true),
            'file' => $value instanceof UploadedFile && $value->isValid(),
            'max_kb' => $value instanceof UploadedFile && $value->size() <= (int) $param * 1024,
            'mimes' => $value instanceof UploadedFile && $this->matchesMime($value, explode(',', $param)),
            default => throw new \InvalidArgumentException("Unknown validation rule [{$name}]."),
        };
    }

    /** @param list<string> $rules */
    private function fail(string $field, string $rule, string $param, mixed $value, array $rules = []): void
    {
        $key = $rule;
        if ($rule === 'min' || $rule === 'max') {
            $key .= $this->isNumericField($rules) ? '.numeric' : '.string';
        }
        if ($rule === 'file' && $value instanceof UploadedFile && $value->isTooLarge()) {
            $key = 'file.too_large';
        }

        $message = ($key === 'file.too_large' ? $this->messages["{$field}.max_kb"] ?? null : null)
            ?? $this->messages["{$field}.{$rule}"]
            ?? $this->messages[$field]
            ?? self::DEFAULT_MESSAGES[$key];

        $this->errors[$field] = strtr($message, [
            ':attribute' => $this->attributes[$field] ?? str_replace('_', ' ', $field),
            ':param' => $this->describeParam($rule, $param),
        ]);
    }

    private function describeParam(string $rule, string $param): string
    {
        return match ($rule) {
            'digits_in' => preg_replace('/,([^,]*)$/', ' or $1', str_replace(',', ', ', $param)) ?? $param,
            'max_kb' => ((int) $param >= 1024 ? round((int) $param / 1024, 1) . ' MB' : $param . ' KB'),
            'mimes' => strtoupper(str_replace(',', ', ', $param)),
            default => $param,
        };
    }

    /** @param list<string> $rules */
    private function size(mixed $value, array $rules): float
    {
        if ($this->isNumericField($rules) && is_numeric($value)) {
            return (float) $value;
        }

        return is_string($value) ? (float) mb_strlen($value) : 0.0;
    }

    /** @param list<string> $rules */
    private function isNumericField(array $rules): bool
    {
        return in_array('integer', $rules, true);
    }

    private function toDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** @param list<string> $extensions */
    private function matchesMime(UploadedFile $file, array $extensions): bool
    {
        $extension = $file->extension();
        if (!in_array($extension, $extensions, true) && !($extension === 'jpeg' && in_array('jpg', $extensions, true))) {
            return false;
        }

        return in_array($file->mimeType(), self::MIME_TYPES[$extension] ?? [], true);
    }

    /** @param list<string> $rules */
    private function cast(mixed $value, array $rules): mixed
    {
        if (in_array('integer', $rules, true)) {
            return (int) $value;
        }
        if (in_array('boolean', $rules, true) || in_array('accepted', $rules, true)) {
            return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
        }

        return $value;
    }

    private function normalise(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }
        if ($value instanceof UploadedFile && $value->isMissing()) {
            return null;
        }

        return $value;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === [];
    }

    /** @return array{0: string, 1: string} */
    private function parse(string $rule): array
    {
        $parts = explode(':', $rule, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
