<?php

declare(strict_types=1);

// Option lists shared by the API validation and the frontend dropdowns (GET /api/v1/lookups).
// Job categories live in the job_categories table. Keep values in sync with plan.md §4.

return [
    'genders' => [
        ['value' => 'male', 'label' => 'Male'],
        ['value' => 'female', 'label' => 'Female'],
    ],
    'work_experience' => [
        ['value' => 'none', 'label' => 'No experience'],
        ['value' => 'lt_1', 'label' => 'Less than 1 year'],
        ['value' => '1_2', 'label' => '1–2 years'],
        ['value' => '3_5', 'label' => '3–5 years'],
        ['value' => 'gt_5', 'label' => 'More than 5 years'],
    ],
    'education_levels' => [
        ['value' => 'none', 'label' => 'No formal education'],
        ['value' => 'psc', 'label' => 'PSC'],
        ['value' => 'jsc', 'label' => 'JSC/JDC'],
        ['value' => 'ssc', 'label' => 'SSC/Dakhil'],
        ['value' => 'hsc', 'label' => 'HSC/Alim'],
        ['value' => 'diploma', 'label' => 'Diploma'],
        ['value' => 'bachelor', 'label' => 'Bachelor'],
        ['value' => 'masters', 'label' => 'Masters or above'],
    ],
    'passport_statuses' => [
        ['value' => 'have_passport', 'label' => 'Have Passport'],
        ['value' => 'will_apply', 'label' => 'Will Apply Soon'],
    ],
    'contact_subjects' => [
        ['value' => 'general_inquiry', 'label' => 'General Inquiry'],
        ['value' => 'job_application', 'label' => 'Job Application'],
        ['value' => 'employer_partnership', 'label' => 'Employer Partnership'],
        ['value' => 'support', 'label' => 'Support'],
    ],
    // Admin only (not returned by /lookups); transitions are enforced in BE-12.
    'application_statuses' => [
        ['value' => 'received', 'label' => 'Received'],
        ['value' => 'under_review', 'label' => 'Under Review'],
        ['value' => 'shortlisted', 'label' => 'Shortlisted'],
        ['value' => 'interview', 'label' => 'Interview'],
        ['value' => 'medical', 'label' => 'Medical'],
        ['value' => 'visa_processing', 'label' => 'Visa Processing'],
        ['value' => 'departed', 'label' => 'Departed'],
        ['value' => 'on_hold', 'label' => 'On Hold'],
        ['value' => 'rejected', 'label' => 'Rejected'],
    ],
];
