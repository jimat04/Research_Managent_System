<?php
/** Declarative mapping policy. No database, sessions or template writes. */
$field = static fn(string $label, string $source, string $type = 'text', bool $required = false): array =>
    compact('label', 'source', 'type', 'required');
$input = static fn(string $label, string $type = 'text', int $max = 500, array $options = []): array =>
    compact('label', 'type', 'max', 'options');
$yesNo = ['yes' => 'Yes', 'no' => 'No'];
$indexing = ['wos' => 'Web of Science', 'scopus' => 'Scopus', 'na' => 'N/A'];
$identity = ['research.title', 'research.authors'];
$fields = [
    'research.title' => $field('Research title', 'record.title', 'text', true),
    'research.authors' => $field('Research authors (saved order)', 'record.authors', 'authors', true),
    'research.work_type' => $field('Type of scholarly work', 'record.work_type_label', 'text', true),
    'research.date_started' => $field('Research start month', 'record.date_started', 'month'),
    'research.date_completed' => $field('Research completion month', 'record.date_completed', 'month'),
    'research.sdgs' => $field('SDG alignment', 'record.sdgs', 'sdgs'),
    'research.sdg_other' => $field('Other SDG alignment', 'record.sdg_other'),
    'conference.title' => $field('Conference title', 'record.conference_title'),
    'conference.host_organizer' => $field('Conference host / organizer', 'record.conference_host + record.conference_organizer'),
    'conference.dates' => $field('Conference dates', 'record.conference_start_date + record.conference_end_date', 'dates'),
    'conference.classification' => $field('Annex A conference classification', 'record.conference_classification'),
    'conference.modality' => $field('Presentation modality', 'record.presentation_modality'),
    'conference.venue' => $field('Conference venue', 'record.conference_venue'),
    'conference.organizer' => $field('Conference organizer', 'record.conference_organizer'),
    'journal.title' => $field('Target journal title', 'record.journal_title'),
    'journal.publisher' => $field('Journal publisher', 'record.journal_publisher'),
];
$aInputs = [
    'submission_target' => $input('Submission target (confirms which sections apply)', 'choice', 20, ['conference' => 'Conference', 'journal' => 'Journal', 'both' => 'Both']),
    'conference_deadline' => $input('Conference submission deadline', 'date'),
    'proceedings' => $input('Does the conference publish proceedings?', 'choice', 3, $yesNo),
    'conference_indexing' => $input('Proceedings indexing', 'multi', 10, $indexing),
    'registration_fee' => $input('Registration fee amount', 'decimal', 13),
    'registration_currency' => $input('Registration currency (three-letter code)', 'currency', 3),
    'journal_deadline' => $input('Journal submission deadline', 'date'),
    'peer_review_clear' => $input('Is the journal peer-review process clear?', 'choice', 3, $yesNo),
    'journal_indexing' => $input('Journal indexing', 'multi', 10, $indexing),
    'scope_aligned' => $input('Is the article aligned with the journal scope?', 'choice', 3, $yesNo),
    'scope_explanation' => $input('Explain the journal scope alignment', 'textarea', 4000),
    'publication_fee' => $input('Publication / processing fee amount', 'decimal', 13),
    'publication_currency' => $input('Publication currency (three-letter code)', 'currency', 3),
    'ai_used' => $input('Were AI tools used?', 'choice', 3, $yesNo),
    'ai_tools' => $input('AI-use details', 'ai_rows'),
    'checklist_profiles' => $input('Confirm updated EARIST-author profiles are supplied', 'choice', 3, $yesNo),
    'checklist_declaration' => $input('Confirm Author Declaration and Contributions Form is supplied', 'choice', 3, $yesNo),
    'checklist_manuscript' => $input('Confirm the scholarly work in the target format is supplied', 'choice', 3, $yesNo),
    'checklist_target_details' => $input('Confirm conference / journal details are supplied', 'choice', 3, $yesNo),
];
$qInputs = [
    'applicant_source' => $input('Applicant confirmation', 'choice', 10, ['profile' => 'I confirm my faculty profile is the applicant', 'manual' => 'Use the confirmed applicant details below']),
    'applicant_name' => $input('Confirmed applicant full name', 'text', 255),
    'applicant_appointment' => $input('Confirmed applicant appointment status', 'text', 80),
    'applicant_position' => $input('Confirmed applicant position / designation', 'text', 500),
    'applicant_department' => $input('Confirmed applicant college / department', 'text', 120),
    'applicant_contact' => $input('Confirmed applicant contact', 'text', 30),
    'applicant_email' => $input('Confirmed applicant email', 'email', 160),
    'presenter_author_id' => $input('Presenting author (choose explicitly)', 'author'),
    'qsf_classification' => $input('QSF nature of conference (confirm independently of Annex A)', 'choice', 20, ['philippines' => 'Regional/national/international conferences held in the Philippines', 'international' => 'International Conference']),
    'funding_source' => $input('Funding details', 'choice', 20, ['organizer_grant' => 'Funded by organizer / grant', 'personal' => 'Personal funding (on official time)', 'earist' => 'Requesting financial assistance from EARIST']),
    'estimated_expenses' => $input('Estimated expenses amount', 'decimal', 13),
    'expense_currency' => $input('Expense currency (three-letter code)', 'currency', 3),
    'recognition' => $input('Conference recognition / endorsement statement', 'choice', 3, $yesNo),
    'abstract_submitted' => $input('Abstract submitted?', 'choice', 3, $yesNo),
    'abstract_date' => $input('Abstract submission date', 'date'),
    'accepted' => $input('Accepted for presentation?', 'choice', 3, $yesNo),
    'acceptance_date' => $input('Acceptance date', 'date'),
    'attachments' => $input('Attachments you confirm are supplied', 'multi', 20, [
        'full_text' => 'Full text of the paper', 'invitation_acceptance' => 'Invitation and acceptance letters',
        'brochure' => 'Conference brochure', 'fee' => 'Organizer registration / participation fee document',
        'itinerary' => 'Signed itinerary with estimated travel expenses', 'legitimacy' => 'International Affairs certificate of legitimacy',
    ]),
];
$doc = static fn(string $label, array $common, array $inputs, array $reserved, array $fixed): array =>
    ['label' => $label, 'role' => 'faculty', 'enabled' => true, 'common' => $common, 'inputs' => $inputs, 'reserved' => $reserved, 'fixed' => $fixed];
return [
    'version' => 1,
    'fields' => $fields,
    'contribution_categories' => ['Conceptualization', 'Data curation', 'Formal analysis', 'Funding acquisition', 'Investigation', 'Methodology', 'Project administration', 'Resources', 'Software', 'Supervision', 'Validation', 'Visualization', 'Writing – original draft', 'Writing – review & editing'],
    'documents' => [
        'annex_a' => $doc('Annex A — Pre-Submission Clearance', array_merge($identity, ['research.work_type', 'research.sdgs', 'research.sdg_other', 'conference.title', 'conference.host_organizer', 'conference.dates', 'conference.classification', 'conference.modality', 'conference.venue', 'journal.title', 'journal.publisher']), $aInputs,
            ['clearance' => 'Project Head checks, indexing verification, clearance decisions and remarks', 'reference' => 'Official reference number', 'evaluation' => 'Evaluation date and signatures'],
            ['declarations' => 'Authenticity, confidentiality and privacy declarations', 'instructions' => 'Instructions, process flow and printed official information']),
        'annex_b' => $doc('Annex B — Authors Certification', array_merge($identity, ['research.date_started', 'research.date_completed']),
            ['contributions' => $input('Per-author contributions', 'contributions')],
            ['signatures' => 'Author signatures and signed dates'], ['certification' => 'Authorship certification', 'taxonomy' => 'Printed CRediT taxonomy and references']),
        'annex_c' => $doc('Annex C — Quality Evaluation Sheet', array_merge($identity, ['research.work_type']), [],
            ['ratings' => 'Seven evaluator ratings', 'remarks' => 'Evaluator remarks', 'total' => 'Total quality score', 'recommendation' => 'Evaluation recommendation', 'evaluator' => 'Evaluator identity, position, date and signature'],
            ['rubric' => 'Seven criteria, weights, rubric, computation instructions and recommendation matrix']),
        'annex_d' => ['label' => 'Annex D — Certification of Quality Review', 'role' => 'admin', 'enabled' => false,
            'common' => $identity, 'inputs' => [], 'reserved' => ['verified_score' => 'Verified final quality score', 'classification' => 'Official recommendation classification', 'certification' => 'Certification result and issuance date/place', 'signatures' => 'Authorized signatures'],
            'fixed' => ['officials' => 'Printed officials, positions, certification wording and rating bands']],
        'annex_e' => $doc('Annex E — IP Asset Evaluation Clearance', $identity, [],
            ['itso_result' => 'ITSO evaluation and clearance decisions', 'issuance' => 'Issuance date and place', 'signatures' => 'Prepared-by and attested-by signatures'],
            ['statements' => 'Three clearance statements and printed ITSO / ORDS roles']),
        'annex_f' => $doc('Annex F — Conflict and Confidentiality Declaration', $identity, [],
            ['review_signatories' => 'Review signatory names, review roles, signatures and dates (seven rows)'],
            ['declarations' => 'Conflict-of-interest and confidentiality declarations']),
        'qsf_rds_019' => $doc('EARIST-QSF-RDS-019 — Paper Presentation Request', array_merge($identity, ['research.sdgs', 'research.sdg_other', 'conference.title', 'conference.organizer', 'conference.venue', 'conference.dates', 'conference.modality']), $qInputs,
            ['signatures' => 'Applicant signature and signed date', 'approval' => 'Recommending approval signatures and signed dates'],
            ['privacy' => 'Certification, privacy and post-event commitments', 'officials' => 'Printed recommending officials', 'identifier' => 'EARIST-QSF-RDS-019 Rev. 00 (2.11.26) and printed pagination']),
    ],
];
