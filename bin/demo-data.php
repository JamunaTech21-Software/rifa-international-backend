<?php

declare(strict_types=1);

// Sample data for demos and admin-panel development (local only — refuses to run on staging/production).
//
//   php bin/demo-data.php                    20 applications (with status history, notes, DEMO PDFs on Cloudinary)
//                                            + 6 contact messages
//   php bin/demo-data.php --skip-documents   same, without uploading documents (offline)
//   php bin/demo-data.php --remove           delete all demo data again (and its Cloudinary files)
//
// Demo rows are recognised by ip_address 192.0.2.1 (a documentation-only address). Applicant emails use
// example.com, so nothing is ever delivered to a real person.

use App\Core\Config;
use App\Core\Database;
use App\Modules\Applications\Repositories\ApplicationRepository;
use App\Shared\Clock;
use App\Shared\Storage\DocumentStorage;

/** @var App\Core\App $app */
$app = require dirname(__DIR__) . '/bootstrap.php';
$c = $app->container();
$config = $c->get(Config::class);
$db = $c->get(Database::class);
$storage = $c->get(DocumentStorage::class);

const DEMO_IP = '192.0.2.1';

if (in_array($config->get('app.env'), ['staging', 'production'], true)) {
    fwrite(STDERR, "Refusing to touch demo data on APP_ENV={$config->get('app.env')}.\n");
    exit(1);
}

$options = getopt('', ['remove', 'skip-documents']);

if (isset($options['remove'])) {
    $documents = $db->fetchAll(
        'SELECT d.cloudinary_public_id, d.resource_type FROM application_documents d
         JOIN applications a ON a.id = d.application_id WHERE a.ip_address = ?',
        [DEMO_IP],
    );
    foreach ($documents as $document) {
        try {
            $storage->delete($document['cloudinary_public_id'], $document['resource_type']);
        } catch (Throwable $e) {
            echo "  could not delete {$document['cloudinary_public_id']} from Cloudinary: {$e->getMessage()}\n";
        }
    }
    $apps = $db->execute('DELETE FROM applications WHERE ip_address = ?', [DEMO_IP]);
    $messages = $db->execute('DELETE FROM contact_messages WHERE ip_address = ?', [DEMO_IP]);
    echo "Removed {$apps} demo applications (" . count($documents) . " documents) and {$messages} demo messages.\n";
    exit(0);
}

if ((int) $db->fetchValue('SELECT COUNT(*) FROM applications WHERE ip_address = ?', [DEMO_IP]) > 0) {
    fwrite(STDERR, "Demo data already exists. Run with --remove first.\n");
    exit(1);
}

$adminId = $db->fetchValue("SELECT id FROM admins WHERE role = 'super_admin' AND is_active = 1 ORDER BY id LIMIT 1");
$adminId = $adminId === null ? null : (int) $adminId;
$categories = array_column($db->fetchAll('SELECT id, slug FROM job_categories'), 'id', 'slug');
$now = $c->get(Clock::class)->now();
$references = $c->get(ApplicationRepository::class);
$folder = rtrim((string) $config->get('cloudinary.applications_folder', 'rifa/applications'), '/');
$withDocuments = !isset($options['skip-documents']);

// [name, father, gender, age, category, status, days ago, has email, passport, experience, education]
$people = [
    ['Md. Rahim Uddin', 'Abdul Karim', 'male', 28, 'construction', 'received', 0, true, 'have_passport', '1_2', 'ssc'],
    ['Md. Sumon Mia', 'Abdul Jabbar', 'male', 24, 'factory-manufacturing', 'received', 1, false, 'will_apply', 'none', 'jsc'],
    ['Shahidul Islam', 'Nurul Islam', 'male', 31, 'plantation-agriculture', 'received', 1, true, 'have_passport', '3_5', 'psc'],
    ['Taslima Akter', 'Abul Hossain', 'female', 26, 'hospitality', 'received', 2, true, 'have_passport', 'lt_1', 'hsc'],
    ['Md. Jahangir Alam', 'Siddiqur Rahman', 'male', 47, 'cleaning-service', 'received', 3, false, 'have_passport', 'gt_5', 'none'],
    ['Rafiqul Islam', 'Mofizul Islam', 'male', 29, 'logistics-warehouse', 'under_review', 4, true, 'have_passport', '3_5', 'ssc'],
    ['Md. Sohel Rana', 'Abdur Rob', 'male', 33, 'construction', 'under_review', 5, true, 'have_passport', 'gt_5', 'jsc'],
    ['Nasrin Sultana', 'Golam Mostafa', 'female', 30, 'cleaning-service', 'under_review', 6, false, 'will_apply', '1_2', 'ssc'],
    ['Md. Alamgir Hossain', 'Kader Mia', 'male', 27, 'factory-manufacturing', 'under_review', 7, true, 'have_passport', '1_2', 'hsc'],
    ['Md. Kamrul Hasan', 'Abul Kashem', 'male', 25, 'construction', 'shortlisted', 9, true, 'have_passport', '1_2', 'ssc'],
    ['Md. Mizanur Rahman', 'Habibur Rahman', 'male', 35, 'plantation-agriculture', 'shortlisted', 11, true, 'have_passport', 'gt_5', 'psc'],
    ['Rozina Begum', 'Shamsul Haque', 'female', 29, 'hospitality', 'shortlisted', 12, true, 'have_passport', '3_5', 'diploma'],
    ['Md. Arif Hossain', 'Delwar Hossain', 'male', 26, 'logistics-warehouse', 'interview', 14, true, 'have_passport', '1_2', 'hsc'],
    ['Md. Faruk Ahmed', 'Mokbul Ahmed', 'male', 32, 'construction', 'interview', 16, true, 'have_passport', 'gt_5', 'ssc'],
    ['Md. Shakil Khan', 'Jalal Khan', 'male', 28, 'factory-manufacturing', 'medical', 18, true, 'have_passport', '3_5', 'ssc'],
    ['Md. Imran Hossain', 'Anwar Hossain', 'male', 30, 'construction', 'medical', 20, false, 'have_passport', '3_5', 'jsc'],
    ['Md. Rubel Mia', 'Sattar Mia', 'male', 27, 'plantation-agriculture', 'visa_processing', 23, true, 'have_passport', '1_2', 'psc'],
    ['Md. Nazmul Haque', 'Ekramul Haque', 'male', 34, 'construction', 'departed', 28, true, 'have_passport', 'gt_5', 'ssc'],
    ['Md. Selim Reza', 'Abdul Latif', 'male', 29, 'hospitality', 'on_hold', 10, true, 'will_apply', 'lt_1', 'hsc'],
    ['Md. Babul Sheikh', 'Rashid Sheikh', 'male', 52, 'cleaning-service', 'rejected', 8, false, 'have_passport', 'gt_5', 'none'],
];
$notes = [
    'shortlisted' => 'Phone call done — interested, documents look complete.',
    'interview' => 'Interview booked at the Banani office.',
    'medical' => 'Passed the interview. Waiting for the medical report.',
    'on_hold' => 'Waiting for the passport — will follow up next week.',
    'rejected' => 'Over the employer\'s age limit for this job.',
];

$pipeline = ['received', 'under_review', 'shortlisted', 'interview', 'medical', 'visa_processing', 'departed'];
$created = 0;
$uploaded = 0;
$firstMobile = null;

foreach ($people as $i => [$name, $father, $gender, $age, $category, $status, $daysAgo, $hasEmail, $passport, $experience, $education]) {
    $submitted = $now->modify("-{$daysAgo} days")->setTime(9 + $i % 8, ($i * 7) % 60);
    $sequence = $references->nextReferenceSequence($submitted->format('Y-m-d'));
    $reference = sprintf('RIFA-%s-%05d', $submitted->format('Ymd'), $sequence);
    $mobile = sprintf('+88017%08d', 11223300 + $i * 37);
    $isDuplicate = $i === 8; // same mobile as the first applicant
    if ($isDuplicate) {
        $mobile = $firstMobile;
    }
    $firstMobile ??= $mobile;

    // Status path: along the pipeline; on hold / rejected after review.
    $path = match ($status) {
        'on_hold', 'rejected' => ['received', 'under_review', $status],
        default => array_slice($pipeline, 0, array_search($status, $pipeline, true) + 1),
    };
    $step = max(1, intdiv(max(1, $daysAgo), count($path)));
    $changedAt = [];
    foreach ($path as $n => $to) {
        $changedAt[] = $n === 0 ? $submitted : $submitted->modify('+' . min($daysAgo, $n * $step) . ' days')->setTime(11 + $n, 15);
    }
    $lastChange = end($changedAt);

    $id = $db->insert('applications', [
        'reference_no' => $reference,
        'full_name' => $name,
        'father_name' => $father,
        'date_of_birth' => $submitted->modify("-{$age} years -" . (40 + $i * 9) . ' days')->format('Y-m-d'),
        'age_at_submission' => $age,
        'gender' => $gender,
        'nid_no' => $i % 4 === 3 ? null : sprintf('19%02d%013d', 90 + $i % 10, 4521000000000 + $i * 7919),
        'mobile' => $mobile,
        'email' => $hasEmail ? strtolower(str_replace(['Md. ', ' '], ['', '.'], $name)) . '@example.com' : null,
        'present_address' => ['Mirpur 10, Dhaka', 'Sadar, Cumilla', 'Kotwali, Chattogram', 'Sadar, Tangail', 'Shibganj, Bogura'][$i % 5],
        'job_category_id' => (int) $categories[$category],
        'work_experience' => $experience,
        'education_level' => $education,
        'passport_status' => $passport,
        'status' => $status,
        'is_age_flagged' => (int) ($age > 45),
        'is_duplicate_flagged' => (int) $isDuplicate,
        'declaration_accepted_at' => $submitted->format('Y-m-d H:i:s'),
        'ip_address' => DEMO_IP,
        'user_agent' => 'demo-data',
        'created_at' => $submitted->format('Y-m-d H:i:s'),
        'updated_at' => $lastChange->format('Y-m-d H:i:s'),
    ]);

    foreach ($path as $n => $to) {
        $db->insert('application_status_history', [
            'application_id' => $id,
            'from_status' => $n === 0 ? null : $path[$n - 1],
            'to_status' => $to,
            'changed_by' => $n === 0 ? null : $adminId,
            'created_at' => $changedAt[$n]->format('Y-m-d H:i:s'),
        ]);
    }
    if (isset($notes[$status])) {
        $db->insert('application_notes', [
            'application_id' => $id,
            'admin_id' => $adminId,
            'body' => $notes[$status],
            'created_at' => $lastChange->format('Y-m-d H:i:s'),
        ]);
    }

    if ($withDocuments) {
        $docs = $passport === 'have_passport' ? ['passport_copy' => 'Passport copy'] : [];
        if ($i % 3 === 0) {
            $docs['cv'] = 'CV';
        }
        foreach ($docs as $docType => $label) {
            $path = sys_get_temp_dir() . '/rifa-demo-' . bin2hex(random_bytes(4)) . '.pdf';
            file_put_contents($path, demoPdf(["DEMO {$label} - not a real document", $name, $reference]));
            try {
                $stored = $storage->uploadPrivate($path, "{$folder}/{$reference}", $docType);
                $references->addDocument($id, $docType, $stored, strtolower(str_replace(' ', '-', $label)) . '.pdf');
                $uploaded++;
            } finally {
                unlink($path);
            }
        }
    }

    $created++;
    echo "  {$reference}  {$name}  ({$status})\n";
}

// Contact messages: [name, email, phone, subject, message, status, hours ago]
$contactMessages = [
    ['Abdul Malek', 'malek@example.com', '01711223344', 'job_application', "Assalamu alaikum. I applied for a construction job last week. When will I get a call?", 'new', 3],
    ['Farhana Yasmin', 'farhana@example.com', '01822334455', 'general_inquiry', "Do you have any jobs for women in hotels? I have 2 years of experience as a housekeeper.", 'new', 20],
    ['Karim Trading Sdn Bhd', 'hr@example.com', '+60 12-345 6789', 'employer_partnership', "We are a construction company in Johor and need 30 general workers from March. Please send your proposal.", 'new', 30],
    ['Md. Hasan Ali', 'hasan@example.com', '01933445566', 'support', "I made a mistake in my mobile number on the application. How can I fix it?", 'read', 52],
    ['Sharmin Akter', 'sharmin@example.com', '01644556677', 'general_inquiry', "Is there any fee for applying? Someone in my village asked me for 20,000 taka.", 'replied', 76],
    ['Test Sender', 'test@example.com', '01555666777', 'general_inquiry', "Testing the contact form, please ignore.", 'archived', 120],
];
foreach ($contactMessages as [$name, $email, $phone, $subject, $message, $status, $hoursAgo]) {
    $at = $now->modify("-{$hoursAgo} hours");
    $id = $db->insert('contact_messages', [
        'full_name' => $name,
        'email' => $email,
        'phone' => $phone,
        'subject' => $subject,
        'message' => $message,
        'status' => $status,
        'ip_address' => DEMO_IP,
        'user_agent' => 'demo-data',
        'read_at' => $status === 'new' ? null : $at->modify('+2 hours')->format('Y-m-d H:i:s'),
        'created_at' => $at->format('Y-m-d H:i:s'),
    ]);
    if ($status === 'replied') {
        $db->insert('message_replies', [
            'contact_message_id' => $id,
            'admin_id' => $adminId,
            'body' => "Dear Sharmin,\n\nRifa International does not charge any fee for online applications. Please do not pay anyone. If someone asks you for money in our name, call our office at +880 1990-359053.",
            'sent_to' => $email,
            'sent_at' => $at->modify('+3 hours')->format('Y-m-d H:i:s'),
            'delivery_status' => 'sent',
            'created_at' => $at->modify('+3 hours')->format('Y-m-d H:i:s'),
        ]);
    }
}

echo "Created {$created} demo applications ({$uploaded} documents uploaded) and " . count($contactMessages) . " demo messages.\n";
echo "Remove them with: php bin/demo-data.php --remove\n";

/** A one-page A4 PDF with a few lines of text. @param list<string> $lines */
function demoPdf(array $lines): string
{
    $text = 'BT /F1 20 Tf 60 760 Td 28 TL';
    foreach ($lines as $line) {
        $text .= ' (' . strtr($line, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ') Tj T*';
    }
    $text .= ' ET';
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length ' . strlen($text) . " >>\nstream\n{$text}\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $body) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n{$body}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}
