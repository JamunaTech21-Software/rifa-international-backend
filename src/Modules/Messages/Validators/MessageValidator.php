<?php

declare(strict_types=1);

namespace App\Modules\Messages\Validators;

use App\Core\Validator;
use App\Modules\Lookups\Services\LookupsService;

/**
 * Rules and user-facing messages for the Contact Us form.
 * Messages must stay identical to backend/docs/api.md and the frontend zod schema.
 */
final class MessageValidator
{
    public const PHONE_PATTERN = '/^\+?[0-9][0-9\s-]{5,18}[0-9]$/';

    public function __construct(private readonly LookupsService $lookups)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{full_name: string, email: string, phone: string, subject: string, message: string}
     */
    public function validate(array $input): array
    {
        /** @var array{full_name: string, email: string, phone: string, subject: string, message: string} */
        return Validator::make($input, [
            'full_name' => 'required|string|min:2|max:100',
            'email' => 'required|email|max:150',
            'phone' => ['required', 'string', 'max:20', 'regex:' . self::PHONE_PATTERN],
            'subject' => 'required|in:' . implode(',', $this->lookups->values('contact_subjects')),
            'message' => 'required|string|min:10|max:5000',
        ], [
            'full_name.required' => 'Please enter your name.',
            'full_name' => 'Name must be 2–100 characters.',
            'email.required' => 'Please enter your email address.',
            'email' => 'Enter a valid email address.',
            'phone.required' => 'Please enter your phone number.',
            'phone' => 'Enter a valid phone number.',
            'subject.required' => 'Please select a subject.',
            'subject' => 'Please select a valid subject.',
            'message.required' => 'Please write your message.',
            'message' => 'Message must be 10–5000 characters.',
        ])->validate();
    }
}
