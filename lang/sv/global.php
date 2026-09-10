<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'Använda AIMage',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Beskriv det bildarbete du behöver; det körs i bakgrunden.',
    'denied' => 'Du har inte behörighet att använda AIMage.',

    'new_job' => 'Ny uppgift',
    'jobs' => 'Uppgifter',
    'no_jobs' => 'Inget än. Beskriv vad du vill ha gjort.',
    'instruction_placeholder' => 't.ex. skala upp alla bilder i products/, eller generera 10 bilder av en bergssjö i gryningen',
    'send' => 'Skicka',
    'record' => 'Diktera',
    'recording' => 'Spelar in — klicka för att stoppa',
    'transcribing' => 'Transkriberar…',
    'speak_answer' => 'Läs upp svar',

    // Models
    'text_model' => 'Planeringsmodell',
    'image_model' => 'Bildmodell',
    'voice_model' => 'Dikteringsmodell',
    'output_folder' => 'Resultatmapp',
    // Model-specific controls. The keys are the gateway's field names;
    // these are only what a person reads above the picker.
    'control_size' => 'Storlek',
    'control_quality' => 'Kvalitet',
    'control_background' => 'Bakgrund',
    'control_aspect_ratio' => 'Bildformat',
    'model_provider' => 'via :provider',

    // The two numbers the picker exists to show.
    'per_image' => 'per bild',
    'est_cost' => 'Uppskattad kostnad',
    'est_time' => 'Uppskattad väntetid',
    'eta_range' => '~:p50 s vanligtvis, upp till :p90 s',
    'price_exact' => 'Fast pris',
    'price_approx' => 'Ungefärligt',
    'price_unknown' => 'Inget publicerat pris',
    'basis_tariff' => 'en fast taxa per bild',
    'basis_tariff_max' => 'den dyraste upplösningsnivån, alltså en övre gräns',
    'basis_observed' => 'medianen av jämförbara tidigare körningar',
    'basis_rates' => 'tokentaxor — slutbeloppet beror på längden',
    'basis_estimated' => 'ett antagande; det finns ingen faktureringshistorik för det',
    'basis_unpriced' => 'för närvarande utan pris',
    'latency_measured' => 'uppmätt från :n verkliga körningar',
    'latency_coarse' => 'sammanslaget över den här modellens varianter',
    'latency_seeded' => 'en uppskattning, inte en mätning',
    'latency_none' => 'okänt',
    'catalog_stale' => 'Visar en cachad modellista — gatewayen gick inte att nå.',

    // Job states
    'status_planning' => 'Planerar',
    'status_awaiting_input' => 'Väntar på ditt svar',
    'status_awaiting_approval' => 'Väntar på ditt godkännande',
    'status_running' => 'Pågår',
    'status_succeeded' => 'Klart',
    'status_failed' => 'Misslyckades',
    'status_cancelled' => 'Avbrutet',
    // Step states. `running`, `succeeded` and `failed` are shared with the job above.
    'status_queued' => 'I kö',
    'status_polling' => 'Väntar',
    'status_skipped' => 'Överhoppad',

    'approve' => 'Godkänn och kör',
    'cancel_job' => 'Avbryt',
    'plan_summary' => ':steps steg, cirka :images bild(er)',
    'progress' => ':done av :total klara',
    'failed_count' => ':n misslyckades',
    'reply_placeholder' => 'Svar…',
    // Who said it, above each turn in the thread.
    'turn_user' => 'Du',
    'turn_assistant' => 'Assistent',

    // Steps
    'step_generate' => 'Generera',
    'step_edit' => 'Redigera',
    'step_variate' => 'Variation',
    'step_upscale' => 'Skala upp',
    'step_describe' => 'Beskriv',
    'before' => 'Före',
    'after' => 'Efter',

    // Keys
    'key_needed_title' => 'En API-nyckel behövs',
    'key_needed_body' => 'AIMage arbetar via gatewayen ai.artur.work. Ange din egen nyckel, eller be en administratör '
        . 'att ställa in en för hela webbplatsen.',
    'key_your_own' => 'Din nyckel',
    'key_site' => 'Nyckel för hela webbplatsen',
    'key_using_own' => 'Din egen nyckel används.',
    'key_using_site' => 'Webbplatsens nyckel används.',
    'key_placeholder' => 'Klistra in din nyckel',
    'key_save' => 'Spara nyckel',
    'key_clear' => 'Ta bort',
    'key_saved' => 'Nyckeln har sparats och verifierats.',
    'key_cleared' => 'Nyckeln har tagits bort.',

    // Batching health
    'batching_unavailable' => 'Bakgrundsbearbetning är inte tillgänglig, så arbetet startar inte av sig självt.',
    'batching_not_registered' => 'AIMage-uppgiftstypen är inte registrerad i den här Evolution CMS-installationen.',
    'batching_scheduler_down' => 'Schemaläggaren körs inte. Starta den med "php core/artisan schedule:work", eller '
        . 'låt cron anropa "schedule:run" varje minut.',

    // Errors returned by the endpoints
    'error_forbidden' => 'Du har inte behörighet till det.',
    'error_no_key' => 'Ingen API-nyckel är konfigurerad för dig eller för den här webbplatsen.',
    'error_empty_instruction' => 'Säg vad du vill ha gjort.',
    'error_unknown_model' => 'Gatewayen erbjuder ingen :kind-modell som heter ":model".',
    'error_model_cannot_continue' => '”:model” kan inte utföra det ”:step”-arbete som redan står i kö här.',
    'error_folder_denied' => 'Du får inte skriva till ":folder".',
    'error_job_not_found' => 'Den uppgiften finns inte, eller så är den inte din.',
    'error_job_finished' => 'Den uppgiften är redan klar.',
    'error_not_awaiting_approval' => 'Den uppgiften väntar inte på godkännande.',
    'error_key_from_config' => 'Webbplatsens nyckel är satt i konfigurationen och kan inte ändras här.',
    'error_key_rejected' => 'Gatewayen avvisade den nyckeln.',
    'error_no_audio' => 'Inget ljud togs emot.',
    'error_audio_too_large' => 'Den inspelningen är för stor.',
    'error_audio_unsupported' => 'Det ljudformatet stöds inte.',
    'error_empty_transcript' => 'Inget kunde transkriberas från den inspelningen.',
    'error_empty_text' => 'Det finns inget att läsa upp.',
    'error_speech_disabled' => 'Uppläsning av svar är inte konfigurerad.',
    'error_voice_disabled' => 'Röstinmatning och uppläsning är avstängda för den här webbplatsen.',

    // The file browser.
    'files_browse' => 'Bläddra…',
    'files_title' => 'Filer',
    'files_up' => 'Upp',
    'files_use_folder' => 'Lägg resultat här',
    'files_here' => 'Resultaten hamnar här',
    'files_empty' => 'Det finns inget i den här mappen.',
    'files_resolution' => 'Upplösning',
    'files_bytes' => 'Storlek',
    'files_modified' => 'Ändrad',
    'files_url' => 'URL',
    'files_copy' => 'Kopiera',
    'files_copied' => 'Kopierat',
    'files_unknown' => 'Okänt',
    'files_not_writable' => 'Resultat kan inte skrivas här.',
    'files_close' => 'Stäng',
    'files_locate' => 'Visa var filen ligger',
    'error_file_not_found' => 'Den bilden finns inte, eller så får du inte se den.',
];
