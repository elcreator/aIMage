<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'Выкарыстанне AIMage',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Апішыце пачку працы зь відарысамі; яна выконваецца ў фонавым рэжыме.',
    'denied' => 'У вас няма дазволу карыстацца AIMage.',

    'new_job' => 'Новая пачка',
    'jobs' => 'Пачкі',
    'no_jobs' => 'Пакуль нічога няма. Апішыце, што трэба зрабіць.',
    'instruction_placeholder' => 'напр.: павялічыць усе відарысы ў products/ або згенераваць 10 відарысаў горнага возера на світанку',
    'send' => 'Даслаць',
    'record' => 'Прадыктаваць',
    'recording' => 'Ідзе запіс — націсніце, каб спыніць',
    'transcribing' => 'Распазнаванне…',
    'speak_answer' => 'Агучваць адказы',

    // Models
    'text_model' => 'Мадэль планавання',
    'image_model' => 'Мадэль відарысаў',
    'voice_model' => 'Мадэль распазнавання маўлення',
    'output_folder' => 'Тэчка вынікаў',
    'model_provider' => 'праз :provider',

    // The two numbers the picker exists to show.
    'per_image' => 'за відарыс',
    'est_cost' => 'Прыблізны кошт',
    'est_time' => 'Прыблізнае чаканне',
    'eta_range' => '~:p50 с звычайна, да :p90 с',
    'price_exact' => 'Фіксаваны кошт',
    'price_approx' => 'Прыблізна',
    'price_unknown' => 'Кошт не апублікаваны',
    'basis_tariff' => 'фіксаваны тарыф за відарыс',
    'basis_tariff_max' => 'найвышэйшы па кошце ўзровень разрознасці, гэта значыць верхняя мяжа',
    'basis_observed' => 'медыяна параўнальных мінулых запускаў',
    'basis_rates' => 'патокенныя тарыфы — выніковая сума залежыць ад даўжыні',
    'basis_estimated' => 'здагадка; гісторыі спісанняў для яе няма',
    'basis_unpriced' => 'зараз без кошту',
    'latency_measured' => 'вымерана па :n сапраўдных запусках',
    'latency_coarse' => 'усярэднена па варыянтах гэтай мадэлі',
    'latency_seeded' => 'ацэнка, а не вымярэнне',
    'latency_none' => 'невядома',
    'catalog_stale' => 'Паказаны кэшаваны спіс мадэляў — шлюз недаступны.',

    // Job states
    'status_planning' => 'Планаванне',
    'status_awaiting_input' => 'Патрэбны ваш адказ',
    'status_awaiting_approval' => 'Патрэбна ваша пацвярджэнне',
    'status_running' => 'Выконваецца',
    'status_succeeded' => 'Гатова',
    'status_failed' => 'Памылка',
    'status_cancelled' => 'Скасавана',

    'approve' => 'Пацвердзіць і запусціць',
    'cancel_job' => 'Скасаваць',
    'plan_summary' => 'крокаў: :steps, відарысаў прыблізна :images',
    'progress' => 'гатова :done з :total',
    'failed_count' => 'з памылкай: :n',
    'reply_placeholder' => 'Адказ…',

    // Steps
    'step_generate' => 'Генерацыя',
    'step_edit' => 'Рэдагаванне',
    'step_variate' => 'Варыяцыя',
    'step_upscale' => 'Павелічэнне',
    'step_describe' => 'Апісанне',
    'before' => 'Было',
    'after' => 'Стала',

    // Keys
    'key_needed_title' => 'Патрэбны ключ API',
    'key_needed_body' => 'AIMage працуе праз шлюз ai.artur.work. Увядзіце ўласны ключ або папрасіце адміністратара '
        . 'задаць агульны ключ для ўсяго сайта.',
    'key_your_own' => 'Ваш ключ',
    'key_site' => 'Агульны ключ сайта',
    'key_using_own' => 'Выкарыстоўваецца ваш уласны ключ.',
    'key_using_site' => 'Выкарыстоўваецца агульны ключ сайта.',
    'key_placeholder' => 'Устаўце ключ',
    'key_save' => 'Захаваць ключ',
    'key_clear' => 'Выдаліць',
    'key_saved' => 'Ключ захаваны і правераны.',
    'key_cleared' => 'Ключ выдалены.',

    // Batching health
    'batching_unavailable' => 'Фонавае выкананне недаступнае, таму праца сама не запусціцца.',
    'batching_not_registered' => 'Тып задач AIMage не зарэгістраваны ў гэтай усталёўцы Evolution CMS.',
    'batching_scheduler_down' => 'Планавальнік не працуе. Запусціце яго камандай «php core/artisan schedule:work» або '
        . 'наладзьце cron на выклік «schedule:run» кожную хвіліну.',

    // Errors returned by the endpoints
    'error_forbidden' => 'У вас няма дазволу на гэта дзеянне.',
    'error_no_key' => 'Ключ API не наладжаны ні для вас, ні для гэтага сайта.',
    'error_empty_instruction' => 'Напішыце, што трэба зрабіць.',
    'error_unknown_model' => 'Шлюз не прапануе мадэлі :kind з назвай «:model».',
    'error_folder_denied' => 'Вам недаступны запіс у «:folder».',
    'error_job_not_found' => 'Такой пачкі няма або яна не ваша.',
    'error_job_finished' => 'Гэтая пачка ўжо завершана.',
    'error_not_awaiting_approval' => 'Гэтая пачка не чакае пацвярджэння.',
    'error_key_from_config' => 'Ключ сайта зададзены ў канфігурацыі, і яго нельга змяніць тут.',
    'error_key_rejected' => 'Шлюз адхіліў гэты ключ.',
    'error_no_audio' => 'Аўдыя не атрымана.',
    'error_audio_too_large' => 'Гэты запіс занадта вялікі.',
    'error_audio_unsupported' => 'Гэты фармат аўдыя не падтрымліваецца.',
    'error_empty_transcript' => 'З гэтага запісу не ўдалося нічога распазнаць.',
    'error_empty_text' => 'Няма чаго агучваць.',
    'error_speech_disabled' => 'Агучванне адказаў не наладжана.',
];
