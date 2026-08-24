<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'Brug AIMage',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Beskriv en batch billedarbejde; den udføres i baggrunden.',
    'denied' => 'Du har ikke tilladelse til at bruge AIMage.',

    'new_job' => 'Ny batch',
    'jobs' => 'Batches',
    'no_jobs' => 'Ingenting endnu. Beskriv, hvad der skal gøres.',
    'instruction_placeholder' => 'f.eks. opskaler alle billeder i products/, eller generér 10 billeder af en bjergsø ved daggry',
    'send' => 'Send',
    'record' => 'Diktér',
    'recording' => 'Optager — klik for at stoppe',
    'transcribing' => 'Transskriberer…',
    'speak_answer' => 'Læs svar højt',

    // Models
    'text_model' => 'Planlægningsmodel',
    'image_model' => 'Billedmodel',
    'voice_model' => 'Dikteringsmodel',
    'output_folder' => 'Resultatmappe',
    'model_provider' => 'via :provider',

    // The two numbers the picker exists to show.
    'per_image' => 'pr. billede',
    'est_cost' => 'Anslået pris',
    'est_time' => 'Anslået ventetid',
    'eta_range' => '~:p50 s typisk, op til :p90 s',
    'price_exact' => 'Fast pris',
    'price_approx' => 'Cirka',
    'price_unknown' => 'Ingen offentliggjort pris',
    'basis_tariff' => 'en fast takst pr. billede',
    'basis_tariff_max' => 'det dyreste opløsningstrin, altså en øvre grænse',
    'basis_observed' => 'medianen af sammenlignelige tidligere kørsler',
    'basis_rates' => 'tokentakster — det endelige beløb afhænger af længden',
    'basis_estimated' => 'et skøn; der findes ingen faktureringshistorik for det',
    'basis_unpriced' => 'ikke prissat i øjeblikket',
    'latency_measured' => 'målt på :n reelle kørsler',
    'latency_coarse' => 'samlet på tværs af denne models varianter',
    'latency_seeded' => 'et skøn, ikke en måling',
    'latency_none' => 'ukendt',
    'catalog_stale' => 'Viser en cachelagret modelliste — gatewayen kunne ikke nås.',

    // Job states
    'status_planning' => 'Planlægger',
    'status_awaiting_input' => 'Afventer dit svar',
    'status_awaiting_approval' => 'Afventer din godkendelse',
    'status_running' => 'Kører',
    'status_succeeded' => 'Færdig',
    'status_failed' => 'Mislykkedes',
    'status_cancelled' => 'Annulleret',

    'approve' => 'Godkend og kør',
    'cancel_job' => 'Annullér',
    'plan_summary' => ':steps trin, ca. :images billede(r)',
    'progress' => ':done ud af :total færdige',
    'failed_count' => ':n mislykkedes',
    'reply_placeholder' => 'Svar…',

    // Steps
    'step_generate' => 'Generér',
    'step_edit' => 'Redigér',
    'step_variate' => 'Variation',
    'step_upscale' => 'Opskalér',
    'step_describe' => 'Beskriv',
    'before' => 'Før',
    'after' => 'Efter',

    // Keys
    'key_needed_title' => 'Der kræves en API-nøgle',
    'key_needed_body' => 'AIMage arbejder gennem gatewayen ai.artur.work. Indtast din egen nøgle, eller bed en '
        . 'administrator om at angive én for hele webstedet.',
    'key_your_own' => 'Din nøgle',
    'key_site' => 'Nøgle for hele webstedet',
    'key_using_own' => 'Din egen nøgle bruges.',
    'key_using_site' => 'Webstedets nøgle bruges.',
    'key_placeholder' => 'Indsæt din nøgle',
    'key_save' => 'Gem nøgle',
    'key_clear' => 'Fjern',
    'key_saved' => 'Nøglen er gemt og bekræftet.',
    'key_cleared' => 'Nøglen er fjernet.',

    // Batching health
    'batching_unavailable' => 'Baggrundsbehandling er utilgængelig, så arbejdet starter ikke af sig selv.',
    'batching_not_registered' => 'AIMage-opgavetypen er ikke registreret i denne Evolution CMS-installation.',
    'batching_scheduler_down' => 'Planlæggeren kører ikke. Start den med "php core/artisan schedule:work", eller '
        . 'lad cron kalde "schedule:run" hvert minut.',

    // Errors returned by the endpoints
    'error_forbidden' => 'Du har ikke tilladelse til det.',
    'error_no_key' => 'Der er ikke konfigureret en API-nøgle til dig eller til dette websted.',
    'error_empty_instruction' => 'Sig, hvad der skal gøres.',
    'error_unknown_model' => 'Gatewayen tilbyder ingen :kind-model ved navn ":model".',
    'error_folder_denied' => 'Du må ikke skrive til ":folder".',
    'error_job_not_found' => 'Den batch findes ikke, eller den er ikke din.',
    'error_job_finished' => 'Den batch er allerede afsluttet.',
    'error_not_awaiting_approval' => 'Den batch afventer ikke godkendelse.',
    'error_key_from_config' => 'Webstedets nøgle er sat i konfigurationen og kan ikke ændres her.',
    'error_key_rejected' => 'Gatewayen afviste den nøgle.',
    'error_no_audio' => 'Der blev ikke modtaget nogen lyd.',
    'error_audio_too_large' => 'Den optagelse er for stor.',
    'error_audio_unsupported' => 'Det lydformat understøttes ikke.',
    'error_empty_transcript' => 'Der kunne ikke transskriberes noget fra den optagelse.',
    'error_empty_text' => 'Der er intet at læse højt.',
    'error_speech_disabled' => 'Oplæsning af svar er ikke konfigureret.',
];
