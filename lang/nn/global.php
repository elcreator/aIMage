<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'Bruke AIMage',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Skildre ein bunke biletarbeid; det blir køyrt i bakgrunnen.',
    'denied' => 'Du har ikkje løyve til å bruke AIMage.',

    'new_job' => 'Ny bunke',
    'jobs' => 'Bunkar',
    'no_jobs' => 'Ingenting enno. Skildre kva du vil ha gjort.',
    'instruction_placeholder' => 't.d. skalér opp alle bilete i products/, eller generer 10 bilete av eit fjellvatn i grålysinga',
    'send' => 'Send',
    'record' => 'Diktér',
    'recording' => 'Tek opp — klikk for å stoppe',
    'transcribing' => 'Transkriberer…',
    'speak_answer' => 'Les opp svar',

    // Models
    'text_model' => 'Planleggingsmodell',
    'image_model' => 'Biletmodell',
    'voice_model' => 'Dikteringsmodell',
    'output_folder' => 'Resultatmappe',
    'model_provider' => 'via :provider',

    // The two numbers the picker exists to show.
    'per_image' => 'per bilete',
    'est_cost' => 'Estimert kostnad',
    'est_time' => 'Estimert ventetid',
    'eta_range' => '~:p50 s vanleg, opptil :p90 s',
    'price_exact' => 'Fast pris',
    'price_approx' => 'Omtrentleg',
    'price_unknown' => 'Ingen publisert pris',
    'basis_tariff' => 'ein fast takst per bilete',
    'basis_tariff_max' => 'det dyraste oppløysingssteget, altså ei øvre grense',
    'basis_observed' => 'medianen av samanliknbare tidlegare køyringar',
    'basis_rates' => 'tokentakstar — sluttsummen kjem an på lengda',
    'basis_estimated' => 'ei antaking; det finst ingen faktureringshistorikk for den',
    'basis_unpriced' => 'ikkje prissett for tida',
    'latency_measured' => 'målt frå :n verkelege køyringar',
    'latency_coarse' => 'slått saman på tvers av variantane til denne modellen',
    'latency_seeded' => 'eit estimat, ikkje ei måling',
    'latency_none' => 'ukjent',
    'catalog_stale' => 'Viser ei mellomlagra modelliste — gatewayen var ikkje tilgjengeleg.',

    // Job states
    'status_planning' => 'Planlegg',
    'status_awaiting_input' => 'Ventar på svaret ditt',
    'status_awaiting_approval' => 'Ventar på godkjenninga di',
    'status_running' => 'Køyrer',
    'status_succeeded' => 'Ferdig',
    'status_failed' => 'Mislykkast',
    'status_cancelled' => 'Avbrote',

    'approve' => 'Godkjenn og køyr',
    'cancel_job' => 'Avbryt',
    'plan_summary' => ':steps steg, om lag :images bilete',
    'progress' => ':done av :total ferdige',
    'failed_count' => ':n mislykkast',
    'reply_placeholder' => 'Svar…',

    // Steps
    'step_generate' => 'Generer',
    'step_edit' => 'Rediger',
    'step_variate' => 'Variasjon',
    'step_upscale' => 'Skalér opp',
    'step_describe' => 'Skildre',
    'before' => 'Før',
    'after' => 'Etter',

    // Keys
    'key_needed_title' => 'Det trengst ein API-nøkkel',
    'key_needed_body' => 'AIMage arbeider gjennom gatewayen ai.artur.work. Skriv inn din eigen nøkkel, eller be ein '
        . 'administrator setje opp ein for heile nettstaden.',
    'key_your_own' => 'Nøkkelen din',
    'key_site' => 'Nøkkel for heile nettstaden',
    'key_using_own' => 'Din eigen nøkkel er i bruk.',
    'key_using_site' => 'Nøkkelen til nettstaden er i bruk.',
    'key_placeholder' => 'Lim inn nøkkelen din',
    'key_save' => 'Lagre nøkkel',
    'key_clear' => 'Fjern',
    'key_saved' => 'Nøkkelen er lagra og stadfesta.',
    'key_cleared' => 'Nøkkelen er fjerna.',

    // Batching health
    'batching_unavailable' => 'Bakgrunnskøyring er utilgjengeleg, så arbeidet startar ikkje av seg sjølv.',
    'batching_not_registered' => 'AIMage-oppgåvetypen er ikkje registrert i denne Evolution CMS-installasjonen.',
    'batching_scheduler_down' => 'Planleggjaren køyrer ikkje. Start han med «php core/artisan schedule:work», eller '
        . 'la cron kalle «schedule:run» kvart minutt.',

    // Errors returned by the endpoints
    'error_forbidden' => 'Du har ikkje løyve til det.',
    'error_no_key' => 'Ingen API-nøkkel er sett opp for deg eller for denne nettstaden.',
    'error_empty_instruction' => 'Sei kva du vil ha gjort.',
    'error_unknown_model' => 'Gatewayen tilbyr ingen :kind-modell som heiter «:model».',
    'error_folder_denied' => 'Du kan ikkje skrive til «:folder».',
    'error_job_not_found' => 'Den bunken finst ikkje, eller er ikkje din.',
    'error_job_finished' => 'Den bunken er alt ferdig.',
    'error_not_awaiting_approval' => 'Den bunken ventar ikkje på godkjenning.',
    'error_key_from_config' => 'Nøkkelen til nettstaden er sett i konfigurasjonen og kan ikkje endrast her.',
    'error_key_rejected' => 'Gatewayen avviste den nøkkelen.',
    'error_no_audio' => 'Ingen lyd vart motteken.',
    'error_audio_too_large' => 'Det opptaket er for stort.',
    'error_audio_unsupported' => 'Det lydformatet er ikkje støtta.',
    'error_empty_transcript' => 'Ingenting kunne transkriberast frå det opptaket.',
    'error_empty_text' => 'Det er ingenting å lese opp.',
    'error_speech_disabled' => 'Opplesing av svar er ikkje sett opp.',
];
