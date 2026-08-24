<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'AIMagen käyttö',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Kuvaile erä kuvatyötä; se suoritetaan taustalla.',
    'denied' => 'Sinulla ei ole oikeutta käyttää AIMagea.',

    'new_job' => 'Uusi erä',
    'jobs' => 'Erät',
    'no_jobs' => 'Ei vielä mitään. Kuvaile, mitä haluat tehtävän.',
    'instruction_placeholder' => 'esim. suurenna kaikki kuvat kansiossa products/, tai luo 10 kuvaa vuoristojärvestä aamunkoitteessa',
    'send' => 'Lähetä',
    'record' => 'Sanele',
    'recording' => 'Tallennetaan — pysäytä napsauttamalla',
    'transcribing' => 'Puretaan tekstiksi…',
    'speak_answer' => 'Lue vastaukset ääneen',

    // Models
    'text_model' => 'Suunnittelumalli',
    'image_model' => 'Kuvamalli',
    'voice_model' => 'Sanelumalli',
    'output_folder' => 'Tuloskansio',
    'model_provider' => 'palvelun :provider kautta',

    // The two numbers the picker exists to show.
    'per_image' => 'kuvaa kohti',
    'est_cost' => 'Arvioitu hinta',
    'est_time' => 'Arvioitu odotus',
    'eta_range' => '~:p50 s tyypillisesti, enintään :p90 s',
    'price_exact' => 'Kiinteä hinta',
    'price_approx' => 'Likimääräinen',
    'price_unknown' => 'Ei julkaistua hintaa',
    'basis_tariff' => 'kiinteä taksa kuvaa kohti',
    'basis_tariff_max' => 'kallein tarkkuustaso, siis yläraja',
    'basis_observed' => 'vastaavien aiempien ajojen mediaani',
    'basis_rates' => 'token-taksat — lopullinen summa riippuu pituudesta',
    'basis_estimated' => 'oletus; sille ei ole laskutushistoriaa',
    'basis_unpriced' => 'ei tällä hetkellä hinnoiteltu',
    'latency_measured' => 'mitattu :n todellisesta ajosta',
    'latency_coarse' => 'yhdistetty tämän mallin varianttien yli',
    'latency_seeded' => 'arvio, ei mittaus',
    'latency_none' => 'tuntematon',
    'catalog_stale' => 'Näytetään välimuistissa oleva malliluettelo — yhdyskäytävään ei saatu yhteyttä.',

    // Job states
    'status_planning' => 'Suunnittelee',
    'status_awaiting_input' => 'Odottaa vastaustasi',
    'status_awaiting_approval' => 'Odottaa hyväksyntääsi',
    'status_running' => 'Käynnissä',
    'status_succeeded' => 'Valmis',
    'status_failed' => 'Epäonnistui',
    'status_cancelled' => 'Peruutettu',

    'approve' => 'Hyväksy ja suorita',
    'cancel_job' => 'Peruuta',
    'plan_summary' => ':steps vaihetta, noin :images kuvaa',
    'progress' => ':done / :total valmiina',
    'failed_count' => ':n epäonnistui',
    'reply_placeholder' => 'Vastaus…',

    // Steps
    'step_generate' => 'Luo',
    'step_edit' => 'Muokkaa',
    'step_variate' => 'Variaatio',
    'step_upscale' => 'Suurenna',
    'step_describe' => 'Kuvaile',
    'before' => 'Ennen',
    'after' => 'Jälkeen',

    // Keys
    'key_needed_title' => 'API-avain tarvitaan',
    'key_needed_body' => 'AIMage toimii ai.artur.work-yhdyskäytävän kautta. Syötä oma avaimesi tai pyydä '
        . 'ylläpitäjää asettamaan koko sivustolle yhteinen avain.',
    'key_your_own' => 'Oma avaimesi',
    'key_site' => 'Sivuston yhteinen avain',
    'key_using_own' => 'Käytössä on oma avaimesi.',
    'key_using_site' => 'Käytössä on sivuston yhteinen avain.',
    'key_placeholder' => 'Liitä avaimesi',
    'key_save' => 'Tallenna avain',
    'key_clear' => 'Poista',
    'key_saved' => 'Avain tallennettu ja vahvistettu.',
    'key_cleared' => 'Avain poistettu.',

    // Batching health
    'batching_unavailable' => 'Taustasuoritus ei ole käytettävissä, joten työ ei käynnisty itsestään.',
    'batching_not_registered' => 'AIMagen tehtävätyyppiä ei ole rekisteröity tähän Evolution CMS -asennukseen.',
    'batching_scheduler_down' => 'Ajastin ei ole käynnissä. Käynnistä se komennolla ”php core/artisan schedule:work” tai '
        . 'aseta cron kutsumaan komentoa ”schedule:run” minuutin välein.',

    // Errors returned by the endpoints
    'error_forbidden' => 'Sinulla ei ole oikeutta tähän.',
    'error_no_key' => 'API-avainta ei ole määritetty sinulle eikä tälle sivustolle.',
    'error_empty_instruction' => 'Kerro, mitä haluat tehtävän.',
    'error_unknown_model' => 'Yhdyskäytävä ei tarjoa :kind-mallia nimeltä ”:model”.',
    'error_folder_denied' => 'Et voi kirjoittaa kansioon ”:folder”.',
    'error_job_not_found' => 'Kyseistä erää ei ole olemassa, tai se ei ole sinun.',
    'error_job_finished' => 'Kyseinen erä on jo päättynyt.',
    'error_not_awaiting_approval' => 'Kyseinen erä ei odota hyväksyntää.',
    'error_key_from_config' => 'Sivuston avain on asetettu asetustiedostossa, eikä sitä voi muuttaa täältä.',
    'error_key_rejected' => 'Yhdyskäytävä hylkäsi kyseisen avaimen.',
    'error_no_audio' => 'Ääntä ei vastaanotettu.',
    'error_audio_too_large' => 'Kyseinen tallenne on liian suuri.',
    'error_audio_unsupported' => 'Kyseistä äänimuotoa ei tueta.',
    'error_empty_transcript' => 'Kyseisestä tallenteesta ei saatu purettua mitään.',
    'error_empty_text' => 'Ei ole mitään luettavaa ääneen.',
    'error_speech_disabled' => 'Vastausten lukemista ääneen ei ole määritetty.',
];
