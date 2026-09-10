<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'Používat AIMage',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Popište, co je s obrázky potřeba udělat; poběží to na pozadí.',
    'denied' => 'Nemáte oprávnění používat AIMage.',

    'new_job' => 'Nová úloha',
    'jobs' => 'Úlohy',
    'no_jobs' => 'Zatím nic. Popište, co se má udělat.',
    'instruction_placeholder' => 'např. zvětšit všechny obrázky v products/, nebo vygenerovat 10 obrázků horského jezera za úsvitu',
    'send' => 'Odeslat',
    'record' => 'Diktovat',
    'recording' => 'Nahrává se — klepnutím zastavíte',
    'transcribing' => 'Přepisuje se…',
    'speak_answer' => 'Číst odpovědi nahlas',

    // Models
    'text_model' => 'Plánovací model',
    'image_model' => 'Model obrázků',
    'voice_model' => 'Model diktování',
    'output_folder' => 'Složka výsledků',
    // Model-specific controls. The keys are the gateway's field names;
    // these are only what a person reads above the picker.
    'control_size' => 'Velikost',
    'control_quality' => 'Kvalita',
    'control_background' => 'Pozadí',
    'control_aspect_ratio' => 'Poměr stran',
    'model_provider' => 'přes :provider',

    // The two numbers the picker exists to show.
    'per_image' => 'za obrázek',
    'est_cost' => 'Odhadovaná cena',
    'est_time' => 'Odhadované čekání',
    'eta_range' => '~:p50 s obvykle, až :p90 s',
    'price_exact' => 'Pevná cena',
    'price_approx' => 'Přibližně',
    'price_unknown' => 'Cena není zveřejněna',
    'basis_tariff' => 'pevný tarif za obrázek',
    'basis_tariff_max' => 'nejdražší stupeň rozlišení, tedy horní mez',
    'basis_observed' => 'medián srovnatelných dřívějších běhů',
    'basis_rates' => 'sazby za tokeny — výsledná částka závisí na délce',
    'basis_estimated' => 'předpoklad; neexistuje pro něj historie účtování',
    'basis_unpriced' => 'aktuálně bez ceny',
    'latency_measured' => 'změřeno z :n skutečných běhů',
    'latency_coarse' => 'sloučeno napříč variantami tohoto modelu',
    'latency_seeded' => 'odhad, nikoli měření',
    'latency_none' => 'neznámé',
    'catalog_stale' => 'Zobrazuje se seznam modelů z mezipaměti — brána byla nedostupná.',

    // Job states
    'status_planning' => 'Plánování',
    'status_awaiting_input' => 'Čeká na vaši odpověď',
    'status_awaiting_approval' => 'Čeká na vaše schválení',
    'status_running' => 'Probíhá',
    'status_succeeded' => 'Hotovo',
    'status_failed' => 'Selhalo',
    'status_cancelled' => 'Zrušeno',
    // Step states. `running`, `succeeded` and `failed` are shared with the job above.
    'status_queued' => 'Ve frontě',
    'status_polling' => 'Čeká',
    'status_skipped' => 'Přeskočeno',

    'approve' => 'Schválit a spustit',
    'cancel_job' => 'Zrušit',
    'plan_summary' => 'kroků: :steps, obrázků přibližně :images',
    'progress' => 'hotovo :done z :total',
    'failed_count' => 'selhalo: :n',
    'reply_placeholder' => 'Odpověď…',
    // Who said it, above each turn in the thread.
    'turn_user' => 'Vy',
    'turn_assistant' => 'Asistent',

    // Steps
    'step_generate' => 'Generovat',
    'step_edit' => 'Upravit',
    'step_variate' => 'Variace',
    'step_upscale' => 'Zvětšit',
    'step_describe' => 'Popsat',
    'before' => 'Před',
    'after' => 'Po',

    // Keys
    'key_needed_title' => 'Je potřeba klíč API',
    'key_needed_body' => 'AIMage funguje přes bránu ai.artur.work. Zadejte vlastní klíč, nebo požádejte správce '
        . 'o nastavení klíče pro celý web.',
    'key_your_own' => 'Váš klíč',
    'key_site' => 'Klíč webu',
    'key_using_own' => 'Používá se váš vlastní klíč.',
    'key_using_site' => 'Používá se klíč webu.',
    'key_placeholder' => 'Vložte svůj klíč',
    'key_save' => 'Uložit klíč',
    'key_clear' => 'Odebrat',
    'key_saved' => 'Klíč uložen a ověřen.',
    'key_cleared' => 'Klíč odebrán.',

    // Batching health
    'batching_unavailable' => 'Zpracování na pozadí není k dispozici, práce se sama nespustí.',
    'batching_not_registered' => 'Typ úlohy AIMage není v této instalaci Evolution CMS zaregistrován.',
    'batching_scheduler_down' => 'Plánovač neběží. Spusťte jej příkazem „php core/artisan schedule:work“, nebo '
        . 'nechte cron volat „schedule:run“ každou minutu.',

    // Errors returned by the endpoints
    'error_forbidden' => 'K tomu nemáte oprávnění.',
    'error_no_key' => 'Není nastaven klíč API ani pro vás, ani pro tento web.',
    'error_empty_instruction' => 'Napište, co se má udělat.',
    'error_unknown_model' => 'Brána nenabízí model typu :kind s názvem „:model“.',
    'error_model_cannot_continue' => '„:model“ neumí provést práci „:step“, která je tu už zařazena.',
    'error_folder_denied' => 'Do „:folder“ nemůžete zapisovat.',
    'error_job_not_found' => 'Taková úloha neexistuje nebo není vaše.',
    'error_job_finished' => 'Tato úloha už skončila.',
    'error_not_awaiting_approval' => 'Tato úloha nečeká na schválení.',
    'error_key_from_config' => 'Klíč webu je nastaven v konfiguraci a odsud jej nelze změnit.',
    'error_key_rejected' => 'Brána tento klíč odmítla.',
    'error_no_audio' => 'Nebyl přijat žádný zvuk.',
    'error_audio_too_large' => 'Tato nahrávka je příliš velká.',
    'error_audio_unsupported' => 'Tento zvukový formát není podporován.',
    'error_empty_transcript' => 'Z této nahrávky se nepodařilo nic přepsat.',
    'error_empty_text' => 'Není co číst nahlas.',
    'error_speech_disabled' => 'Čtení odpovědí nahlas není nastaveno.',
    'error_voice_disabled' => 'Hlasový vstup a čtení nahlas jsou pro tento web vypnuté.',

    // The file browser.
    'files_browse' => 'Procházet…',
    'files_title' => 'Soubory',
    'files_up' => 'Nahoru',
    'files_use_folder' => 'Ukládat výsledky sem',
    'files_here' => 'Výsledky jdou sem',
    'files_empty' => 'V této složce nic není.',
    'files_resolution' => 'Rozlišení',
    'files_bytes' => 'Velikost',
    'files_modified' => 'Změněno',
    'files_url' => 'URL',
    'files_copy' => 'Kopírovat',
    'files_copied' => 'Zkopírováno',
    'files_unknown' => 'Neznámé',
    'files_not_writable' => 'Sem nelze zapisovat výsledky.',
    'files_close' => 'Zavřít',
    'files_locate' => 'Ukázat, kde tento soubor je',
    'error_file_not_found' => 'Tento obrázek neexistuje nebo jej nemůžete vidět.',
];
