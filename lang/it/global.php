<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'Usare AIMage',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Descriva il lavoro sulle immagini che le serve; viene eseguito in background.',
    'denied' => 'Non hai il permesso di usare AIMage.',

    'new_job' => 'Nuova attività',
    'jobs' => 'Attività',
    'no_jobs' => 'Ancora niente. Descrivi cosa vuoi che venga fatto.',
    'instruction_placeholder' => 'es. ingrandisci tutte le immagini in products/, oppure genera 10 immagini di un lago di montagna all\'alba',
    'send' => 'Invia',
    'record' => 'Detta',
    'recording' => 'Registrazione in corso — clicca per fermare',
    'transcribing' => 'Trascrizione…',
    'speak_answer' => 'Leggi le risposte ad alta voce',

    // Models
    'text_model' => 'Modello di pianificazione',
    'image_model' => 'Modello di immagine',
    'voice_model' => 'Modello di dettatura',
    'output_folder' => 'Cartella dei risultati',
    // Model-specific controls. The keys are the gateway's field names;
    // these are only what a person reads above the picker.
    'control_size' => 'Dimensione',
    'control_quality' => 'Qualità',
    'control_background' => 'Sfondo',
    'control_aspect_ratio' => 'Proporzioni',
    'model_provider' => 'tramite :provider',

    // The two numbers the picker exists to show.
    'per_image' => 'per immagine',
    'est_cost' => 'Costo stimato',
    'est_time' => 'Attesa stimata',
    'eta_range' => '~:p50 s di norma, fino a :p90 s',
    'price_exact' => 'Prezzo fisso',
    'price_approx' => 'Approssimativo',
    'price_unknown' => 'Nessun prezzo pubblicato',
    'basis_tariff' => 'una tariffa fissa per immagine',
    'basis_tariff_max' => 'il livello di risoluzione più costoso, quindi un limite superiore',
    'basis_observed' => 'la mediana di esecuzioni passate comparabili',
    'basis_rates' => 'tariffe a token — l\'importo finale dipende dalla lunghezza',
    'basis_estimated' => 'un\'ipotesi; non esiste uno storico di fatturazione per essa',
    'basis_unpriced' => 'attualmente senza prezzo',
    'latency_measured' => 'misurato su :n esecuzioni reali',
    'latency_coarse' => 'aggregato tra le varianti di questo modello',
    'latency_seeded' => 'una stima, non una misurazione',
    'latency_none' => 'sconosciuto',
    'catalog_stale' => 'Elenco modelli dalla cache — il gateway non è raggiungibile.',

    // Job states
    'status_planning' => 'Pianificazione',
    'status_awaiting_input' => 'Attende la tua risposta',
    'status_awaiting_approval' => 'Attende la tua approvazione',
    'status_running' => 'In esecuzione',
    'status_succeeded' => 'Fatto',
    'status_failed' => 'Fallito',
    'status_cancelled' => 'Annullato',
    // Step states. `running`, `succeeded` and `failed` are shared with the job above.
    'status_queued' => 'In coda',
    'status_polling' => 'In attesa',
    'status_skipped' => 'Saltato',

    'approve' => 'Approva ed esegui',
    'cancel_job' => 'Annulla',
    'plan_summary' => ':steps passaggio/i, circa :images immagine/i',
    'progress' => ':done di :total completati',
    'failed_count' => ':n falliti',
    'reply_placeholder' => 'Risposta…',
    // Who said it, above each turn in the thread.
    'turn_user' => 'Lei',
    'turn_assistant' => 'Assistente',

    // Steps
    'step_generate' => 'Genera',
    'step_edit' => 'Modifica',
    'step_variate' => 'Variazione',
    'step_upscale' => 'Ingrandisci',
    'step_describe' => 'Descrivi',
    'before' => 'Prima',
    'after' => 'Dopo',

    // Keys
    'key_needed_title' => 'Serve una chiave API',
    'key_needed_body' => 'AIMage funziona tramite il gateway ai.artur.work. Inserisci la tua chiave oppure chiedi a '
        . 'un amministratore di impostarne una valida per tutto il sito.',
    'key_your_own' => 'La tua chiave',
    'key_site' => 'Chiave del sito',
    'key_using_own' => 'Si sta usando la tua chiave.',
    'key_using_site' => 'Si sta usando la chiave del sito.',
    'key_placeholder' => 'Incolla la tua chiave',
    'key_save' => 'Salva la chiave',
    'key_clear' => 'Rimuovi',
    'key_saved' => 'Chiave salvata e verificata.',
    'key_cleared' => 'Chiave rimossa.',

    // Batching health
    'batching_unavailable' => 'L’elaborazione in background non è disponibile, quindi il lavoro non partirà da solo.',
    'batching_not_registered' => 'Il tipo di attività AIMage non è registrato in questa installazione di Evolution CMS.',
    'batching_scheduler_down' => 'Lo scheduler non è in esecuzione. Avvialo con «php core/artisan schedule:work», oppure '
        . 'fai in modo che cron chiami «schedule:run» ogni minuto.',

    // Errors returned by the endpoints
    'error_forbidden' => 'Non hai il permesso di farlo.',
    'error_no_key' => 'Nessuna chiave API è configurata per te né per questo sito.',
    'error_empty_instruction' => 'Di\' cosa vuoi che venga fatto.',
    'error_unknown_model' => 'Il gateway non offre alcun modello :kind chiamato «:model».',
    'error_model_cannot_continue' => '«:model» non può eseguire il lavoro «:step» già in coda qui.',
    'error_folder_denied' => 'Non puoi scrivere in «:folder».',
    'error_job_not_found' => 'Quell’attività non esiste, o non è sua.',
    'error_job_finished' => 'Quell’attività è già terminata.',
    'error_not_awaiting_approval' => 'Quell’attività non è in attesa di approvazione.',
    'error_key_from_config' => 'La chiave del sito è impostata nella configurazione e non può essere modificata qui.',
    'error_key_rejected' => 'Il gateway ha rifiutato quella chiave.',
    'error_no_audio' => 'Nessun audio ricevuto.',
    'error_audio_too_large' => 'Quella registrazione è troppo grande.',
    'error_audio_unsupported' => 'Quel formato audio non è supportato.',
    'error_empty_transcript' => 'Non è stato possibile trascrivere nulla da quella registrazione.',
    'error_empty_text' => 'Non c\'è nulla da leggere ad alta voce.',
    'error_speech_disabled' => 'La lettura ad alta voce delle risposte non è configurata.',
    'error_voice_disabled' => 'L’immissione vocale e la lettura ad alta voce sono disattivate per questo sito.',

    // The file browser.
    'files_browse' => 'Sfoglia…',
    'files_title' => 'File',
    'files_up' => 'Su',
    'files_use_folder' => 'Metti i risultati qui',
    'files_here' => 'I risultati finiscono qui',
    'files_empty' => 'Non c’è nulla in questa cartella.',
    'files_resolution' => 'Risoluzione',
    'files_bytes' => 'Dimensione',
    'files_modified' => 'Modificato',
    'files_url' => 'URL',
    'files_copy' => 'Copia',
    'files_copied' => 'Copiato',
    'files_unknown' => 'Sconosciuto',
    'files_not_writable' => 'Qui non è possibile scrivere i risultati.',
    'files_close' => 'Chiudi',
    'files_locate' => 'Mostra dove si trova questo file',
    'error_file_not_found' => 'Quell’immagine non esiste, o non puoi vederla.',
];
