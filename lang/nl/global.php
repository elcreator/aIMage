<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'AIMage gebruiken',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Beschrijf een batch beeldwerk; die wordt op de achtergrond uitgevoerd.',
    'denied' => 'U hebt geen toestemming om AIMage te gebruiken.',

    'new_job' => 'Nieuwe batch',
    'jobs' => 'Batches',
    'no_jobs' => 'Nog niets. Beschrijf wat er moet gebeuren.',
    'instruction_placeholder' => 'bijv. alle afbeeldingen in products/ opschalen, of 10 afbeeldingen van een bergmeer bij zonsopgang genereren',
    'send' => 'Verzenden',
    'record' => 'Dicteren',
    'recording' => 'Opname bezig — klik om te stoppen',
    'transcribing' => 'Transcriberen…',
    'speak_answer' => 'Antwoorden hardop voorlezen',

    // Models
    'text_model' => 'Planningsmodel',
    'image_model' => 'Afbeeldingsmodel',
    'voice_model' => 'Dicteermodel',
    'output_folder' => 'Resultatenmap',
    'model_provider' => 'via :provider',

    // The two numbers the picker exists to show.
    'per_image' => 'per afbeelding',
    'est_cost' => 'Geschatte kosten',
    'est_time' => 'Geschatte wachttijd',
    'eta_range' => '~:p50 s gebruikelijk, tot :p90 s',
    'price_exact' => 'Vaste prijs',
    'price_approx' => 'Bij benadering',
    'price_unknown' => 'Geen gepubliceerde prijs',
    'basis_tariff' => 'een vast tarief per afbeelding',
    'basis_tariff_max' => 'de duurste resolutielaag, dus een bovengrens',
    'basis_observed' => 'de mediaan van vergelijkbare eerdere runs',
    'basis_rates' => 'tokentarieven — het eindbedrag hangt af van de lengte',
    'basis_estimated' => 'een aanname; er is geen factuurgeschiedenis voor',
    'basis_unpriced' => 'op dit moment zonder prijs',
    'latency_measured' => 'gemeten op :n echte runs',
    'latency_coarse' => 'samengevoegd over de varianten van dit model',
    'latency_seeded' => 'een schatting, geen meting',
    'latency_none' => 'onbekend',
    'catalog_stale' => 'Modellijst uit de cache — de gateway was niet bereikbaar.',

    // Job states
    'status_planning' => 'Plannen',
    'status_awaiting_input' => 'Wacht op uw antwoord',
    'status_awaiting_approval' => 'Wacht op uw goedkeuring',
    'status_running' => 'Bezig',
    'status_succeeded' => 'Klaar',
    'status_failed' => 'Mislukt',
    'status_cancelled' => 'Geannuleerd',

    'approve' => 'Goedkeuren en uitvoeren',
    'cancel_job' => 'Annuleren',
    'plan_summary' => ':steps stap(pen), ongeveer :images afbeelding(en)',
    'progress' => ':done van :total klaar',
    'failed_count' => ':n mislukt',
    'reply_placeholder' => 'Antwoord…',

    // Steps
    'step_generate' => 'Genereren',
    'step_edit' => 'Bewerken',
    'step_variate' => 'Variatie',
    'step_upscale' => 'Opschalen',
    'step_describe' => 'Beschrijven',
    'before' => 'Voor',
    'after' => 'Na',

    // Keys
    'key_needed_title' => 'Er is een API-sleutel nodig',
    'key_needed_body' => 'AIMage werkt via de gateway ai.artur.work. Voer uw eigen sleutel in, of vraag een '
        . 'beheerder om er een voor de hele site in te stellen.',
    'key_your_own' => 'Uw sleutel',
    'key_site' => 'Sitebrede sleutel',
    'key_using_own' => 'Uw eigen sleutel wordt gebruikt.',
    'key_using_site' => 'De sitebrede sleutel wordt gebruikt.',
    'key_placeholder' => 'Plak uw sleutel',
    'key_save' => 'Sleutel opslaan',
    'key_clear' => 'Verwijderen',
    'key_saved' => 'Sleutel opgeslagen en geverifieerd.',
    'key_cleared' => 'Sleutel verwijderd.',

    // Batching health
    'batching_unavailable' => 'Achtergrondverwerking is niet beschikbaar, dus het werk start niet vanzelf.',
    'batching_not_registered' => 'Het AIMage-taaktype is niet geregistreerd in deze Evolution CMS-installatie.',
    'batching_scheduler_down' => 'De scheduler draait niet. Start hem met "php core/artisan schedule:work", of laat '
        . 'cron elke minuut "schedule:run" aanroepen.',

    // Errors returned by the endpoints
    'error_forbidden' => 'U hebt daar geen toestemming voor.',
    'error_no_key' => 'Er is geen API-sleutel ingesteld voor u of voor deze site.',
    'error_empty_instruction' => 'Zeg wat er moet gebeuren.',
    'error_unknown_model' => 'De gateway biedt geen :kind-model met de naam ":model".',
    'error_folder_denied' => 'U mag niet schrijven naar ":folder".',
    'error_job_not_found' => 'Die batch bestaat niet, of is niet van u.',
    'error_job_finished' => 'Die batch is al afgerond.',
    'error_not_awaiting_approval' => 'Die batch wacht niet op goedkeuring.',
    'error_key_from_config' => 'De sitesleutel staat in de configuratie en kan hier niet worden gewijzigd.',
    'error_key_rejected' => 'De gateway heeft die sleutel geweigerd.',
    'error_no_audio' => 'Er is geen audio ontvangen.',
    'error_audio_too_large' => 'Die opname is te groot.',
    'error_audio_unsupported' => 'Dat audioformaat wordt niet ondersteund.',
    'error_empty_transcript' => 'Er kon niets uit die opname worden getranscribeerd.',
    'error_empty_text' => 'Er is niets om voor te lezen.',
    'error_speech_disabled' => 'Antwoorden hardop voorlezen is niet geconfigureerd.',
];
