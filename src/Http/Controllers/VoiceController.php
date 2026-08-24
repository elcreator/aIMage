<?php

namespace EvolutionCMS\aIMage\Http\Controllers;

use EvolutionCMS\aIMage\Gateway\GatewayException;
use EvolutionCMS\aIMage\Support\Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Speech in, speech out — both strictly intermediate.
 *
 * Dictating an instruction is often the fastest way to describe a batch, and
 * having a long plan read back is useful while looking at something else. But
 * neither is ever the product: this is an image plugin, and a job that ends in
 * audio has not done its job. Transcription therefore returns *text for the
 * conversation*, not a finished anything.
 */
class VoiceController extends Controller
{
    /** Containers the gateway accepts; webm and ogg are transcoded upstream. */
    private const ALLOWED_EXTENSIONS = ['webm', 'ogg', 'mp3', 'mp4', 'm4a', 'wav', 'mpga', 'mpeg', 'flac'];

    /** 25 MB, matching what the upstream transcription models accept. */
    private const MAX_BYTES = 26_214_400;

    public function transcribe(Request $request): JsonResponse
    {
        if (!$this->authorized()) {
            return $this->denied();
        }

        $client = $this->client();

        if ($client === null) {
            return $this->fail('no_key', __('aIMage::global.error_no_key'), 409);
        }

        $file = $request->file('audio');

        if ($file === null || !$file->isValid()) {
            return $this->fail('no_audio', __('aIMage::global.error_no_audio'));
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return $this->fail('audio_too_large', __('aIMage::global.error_audio_too_large'));
        }

        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return $this->fail('audio_unsupported', __('aIMage::global.error_audio_unsupported'));
        }

        $bytes = @file_get_contents($file->getRealPath());

        if ($bytes === false || $bytes === '') {
            return $this->fail('no_audio', __('aIMage::global.error_no_audio'));
        }

        $model = trim((string) $request->input('model')) ?: Config::defaultModel('voice');

        try {
            $response = $client->transcribe($model, $bytes, 'speech.' . $extension, [
                // Passed through when the browser knows it; a stated language
                // is worth more to a transcriber than any prompt tuning.
                'language' => trim((string) $request->input('language', '')),
            ]);
        } catch (GatewayException $e) {
            return $this->fail(
                $e->isAuthFailure() ? 'key_rejected' : 'transcription_failed',
                $e->getMessage(),
                $e->isAuthFailure() ? 403 : 502
            );
        }

        $text = trim((string) ($response['text'] ?? ''));

        if ($text === '') {
            return $this->fail('empty_transcript', __('aIMage::global.error_empty_transcript'));
        }

        return $this->ok(['text' => $text, 'model' => $model]);
    }

    /**
     * Read a line back to the manager.
     *
     * The audio is streamed straight through and never written to the file
     * area: it is not a deliverable, and putting it there would pollute the
     * folders the batch results live in.
     */
    public function speak(Request $request)
    {
        if (!$this->authorized()) {
            return $this->denied();
        }

        $client = $this->client();

        if ($client === null) {
            return $this->fail('no_key', __('aIMage::global.error_no_key'), 409);
        }

        $text = trim((string) $request->input('text'));

        if ($text === '') {
            return $this->fail('empty_text', __('aIMage::global.error_empty_text'));
        }

        $model = trim((string) $request->input('model')) ?: Config::defaultModel('speech');

        if ($model === '') {
            return $this->fail('speech_disabled', __('aIMage::global.error_speech_disabled'), 409);
        }

        try {
            $audio = $client->speak(
                $model,
                mb_substr($text, 0, 4000),
                trim((string) $request->input('voice')) ?: Config::speechVoice()
            );
        } catch (GatewayException $e) {
            return $this->fail(
                $e->isAuthFailure() ? 'key_rejected' : 'speech_failed',
                $e->getMessage(),
                $e->isAuthFailure() ? 403 : 502
            );
        }

        return response($audio, 200, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'no-store',
        ]);
    }
}
