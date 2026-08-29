<?php

declare(strict_types=1);

namespace App\Services\Llm;

/**
 * The only way the application talks to a language model.
 *
 * Nothing outside `App\Services\Llm` may know which provider is in use, hold a
 * provider's API key, or construct a provider-shaped request. That is the whole
 * point of the interface: OpenAI and Anthropic are a setting, per stage, and
 * adding a third provider must not touch the pipeline.
 */
interface LlmClient
{
    /**
     * Transcribe page images.
     *
     * @param array<int,LlmImage> $images In page order. Order is significant —
     *                                    the model is told these are pages 1..n
     *                                    of one document.
     * @throws LlmException
     */
    public function ocr(array $images, string $prompt): LlmResponse;

    /**
     * A text-only call.
     *
     * The extraction stages work off the transcription rather than the images:
     * the reading has already been done and paid for, and sending the pages
     * again to three more calls would triple the cost of every document for no
     * gain.
     *
     * @throws LlmException
     */
    public function complete(string $prompt): LlmResponse;

    /** 'openai' or 'anthropic'. */
    public function provider(): string;

    /** The model id actually being called, for the audit trail. */
    public function model(): string;

    /**
     * A cheap call that proves the key works, for the Settings screen.
     *
     * @return array{ok:bool,message:string}
     */
    public function ping(): array;
}
