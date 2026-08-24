<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'Používat AIMage',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Popište dávku práce s obrázky; provede se na pozadí.',
    'denied' => 'Nemáte oprávnění používat AIMage.',

    'new_job' => 'Nová dávka',
    'jobs' => 'Dávky',
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

    'approve' => 'Schválit a spustit',
    'cancel_job' => 'Zrušit',
    'plan_summary' => 'kroků: :steps, obrázků přibližně :images',
    'progress' => 'hotovo :done z :total',
    'failed_count' => 'selhalo: :n',
    'reply_placeholder' => 'Odpověď…',

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
    'batching_unavailable' => 'Zpracování na pozadí není dostupné, práce se tedy sama nespustí.',
    'batching_not_registered' => 'Typ úlohy AIMage není v této instalaci Evolution CMS zaregistrován.',
    'batching_scheduler_down' => 'Plánovač neběží. Spusťte jej příkazem „php core/artisan schedule:work“, nebo '
        . 'nechte cron volat „schedule:run“ každou minutu.',

    // Errors returned by the endpoints
    'error_forbidden' => 'K tomu nemáte oprávnění.',
    'error_no_key' => 'Není nastaven klíč API ani pro vás, ani pro tento web.',
    'error_empty_instruction' => 'Napište, co se má udělat.',
    'error_unknown_model' => 'Brána nenabízí model typu :kind s názvem „:model“.',
    'error_folder_denied' => 'Do „:folder“ nemůžete zapisovat.',
    'error_job_not_found' => 'Taková dávka neexistuje, nebo není vaše.',
    'error_job_finished' => 'Tato dávka už skončila.',
    'error_not_awaiting_approval' => 'Tato dávka nečeká na schválení.',
    'error_key_from_config' => 'Klíč webu je nastaven v konfiguraci a odsud jej nelze změnit.',
    'error_key_rejected' => 'Brána tento klíč odmítla.',
    'error_no_audio' => 'Nebyl přijat žádný zvuk.',
    'error_audio_too_large' => 'Tato nahrávka je příliš velká.',
    'error_audio_unsupported' => 'Tento zvukový formát není podporován.',
    'error_empty_transcript' => 'Z této nahrávky se nepodařilo nic přepsat.',
    'error_empty_text' => 'Není co číst nahlas.',
    'error_speech_disabled' => 'Čtení odpovědí nahlas není nastaveno.',
];
