<?php

return [
    // Permissions, referenced by lang_key from the migration.
    'permissions_group' => 'AIMage',
    'permission_access' => 'Używanie AIMage',

    // Page furniture
    'title' => 'AIMage',
    'tagline' => 'Opisz partię pracy nad obrazami; zostanie wykonana w tle.',
    'denied' => 'Nie masz uprawnień do korzystania z AIMage.',

    'new_job' => 'Nowa partia',
    'jobs' => 'Partie',
    'no_jobs' => 'Jeszcze nic. Opisz, co ma zostać zrobione.',
    'instruction_placeholder' => 'np. powiększ wszystkie obrazy w products/ albo wygeneruj 10 obrazów górskiego jeziora o świcie',
    'send' => 'Wyślij',
    'record' => 'Dyktuj',
    'recording' => 'Nagrywanie — kliknij, aby zatrzymać',
    'transcribing' => 'Transkrypcja…',
    'speak_answer' => 'Czytaj odpowiedzi na głos',

    // Models
    'text_model' => 'Model planujący',
    'image_model' => 'Model obrazu',
    'voice_model' => 'Model dyktowania',
    'output_folder' => 'Folder wyników',
    'model_provider' => 'przez :provider',

    // The two numbers the picker exists to show.
    'per_image' => 'za obraz',
    'est_cost' => 'Szacowany koszt',
    'est_time' => 'Szacowane oczekiwanie',
    'eta_range' => '~:p50 s zwykle, do :p90 s',
    'price_exact' => 'Cena stała',
    'price_approx' => 'W przybliżeniu',
    'price_unknown' => 'Brak opublikowanej ceny',
    'basis_tariff' => 'stała stawka za obraz',
    'basis_tariff_max' => 'najdroższy poziom rozdzielczości, czyli górna granica',
    'basis_observed' => 'mediana porównywalnych wcześniejszych uruchomień',
    'basis_rates' => 'stawki za tokeny — końcowa kwota zależy od długości',
    'basis_estimated' => 'założenie; nie ma dla niego historii rozliczeń',
    'basis_unpriced' => 'obecnie bez ceny',
    'latency_measured' => 'zmierzone na :n rzeczywistych uruchomieniach',
    'latency_coarse' => 'zagregowane po wariantach tego modelu',
    'latency_seeded' => 'oszacowanie, a nie pomiar',
    'latency_none' => 'nieznane',
    'catalog_stale' => 'Wyświetlana jest lista modeli z pamięci podręcznej — brama jest nieosiągalna.',

    // Job states
    'status_planning' => 'Planowanie',
    'status_awaiting_input' => 'Czeka na Twoją odpowiedź',
    'status_awaiting_approval' => 'Czeka na Twoje zatwierdzenie',
    'status_running' => 'W trakcie',
    'status_succeeded' => 'Gotowe',
    'status_failed' => 'Niepowodzenie',
    'status_cancelled' => 'Anulowano',

    'approve' => 'Zatwierdź i uruchom',
    'cancel_job' => 'Anuluj',
    'plan_summary' => 'kroki: :steps, obrazy: około :images',
    'progress' => 'ukończono :done z :total',
    'failed_count' => 'niepowodzenia: :n',
    'reply_placeholder' => 'Odpowiedź…',

    // Steps
    'step_generate' => 'Generowanie',
    'step_edit' => 'Edycja',
    'step_variate' => 'Wariacja',
    'step_upscale' => 'Powiększanie',
    'step_describe' => 'Opis',
    'before' => 'Przed',
    'after' => 'Po',

    // Keys
    'key_needed_title' => 'Potrzebny jest klucz API',
    'key_needed_body' => 'AIMage działa przez bramę ai.artur.work. Wprowadź własny klucz albo poproś administratora '
        . 'o ustawienie klucza dla całej witryny.',
    'key_your_own' => 'Twój klucz',
    'key_site' => 'Klucz witryny',
    'key_using_own' => 'Używany jest Twój własny klucz.',
    'key_using_site' => 'Używany jest klucz witryny.',
    'key_placeholder' => 'Wklej swój klucz',
    'key_save' => 'Zapisz klucz',
    'key_clear' => 'Usuń',
    'key_saved' => 'Klucz zapisany i zweryfikowany.',
    'key_cleared' => 'Klucz usunięty.',

    // Batching health
    'batching_unavailable' => 'Przetwarzanie w tle jest niedostępne, więc praca nie uruchomi się sama.',
    'batching_not_registered' => 'Typ zadania AIMage nie jest zarejestrowany w tej instalacji Evolution CMS.',
    'batching_scheduler_down' => 'Harmonogram nie działa. Uruchom go poleceniem „php core/artisan schedule:work” albo '
        . 'skonfiguruj cron, aby wywoływał „schedule:run” co minutę.',

    // Errors returned by the endpoints
    'error_forbidden' => 'Nie masz uprawnień do tej operacji.',
    'error_no_key' => 'Nie skonfigurowano klucza API ani dla Ciebie, ani dla tej witryny.',
    'error_empty_instruction' => 'Napisz, co ma zostać zrobione.',
    'error_unknown_model' => 'Brama nie udostępnia modelu :kind o nazwie „:model”.',
    'error_folder_denied' => 'Nie możesz zapisywać w „:folder”.',
    'error_job_not_found' => 'Taka partia nie istnieje albo nie należy do Ciebie.',
    'error_job_finished' => 'Ta partia już się zakończyła.',
    'error_not_awaiting_approval' => 'Ta partia nie czeka na zatwierdzenie.',
    'error_key_from_config' => 'Klucz witryny jest ustawiony w konfiguracji i nie można go tutaj zmienić.',
    'error_key_rejected' => 'Brama odrzuciła ten klucz.',
    'error_no_audio' => 'Nie odebrano dźwięku.',
    'error_audio_too_large' => 'To nagranie jest zbyt duże.',
    'error_audio_unsupported' => 'Ten format dźwięku nie jest obsługiwany.',
    'error_empty_transcript' => 'Z tego nagrania nie udało się nic przepisać.',
    'error_empty_text' => 'Nie ma nic do przeczytania na głos.',
    'error_speech_disabled' => 'Czytanie odpowiedzi na głos nie jest skonfigurowane.',
];
