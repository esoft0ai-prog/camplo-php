<?php

namespace App\Http\Controllers;

use App\Services\SettingsService as S;
use App\Support\Fail;
use App\Support\Input;
use App\Support\Paginate;
use Illuminate\Http\Request;

class SettingsController
{
    private const PROVIDERS = ['gohighlevel', 'twenty_crm', 'hubspot', 'salesforce', 'activecampaign', 'mailchimp', 'brevo', 'notifuse', 'meta_ads', 'google_ads', 'slack', 'zapier', 'make',
        'tally', 'typeform', 'umami', 'instantly', 'apollo', 'lemlist', 'smartlead', 'systeme_io', 'custom'];

    private const AI_PROVIDERS = 'anthropic,openai,groq,gemini,deepseek,mistral,cohere,xai,qwen,mimo,arcee,glm,minimax,kimi,bytedance,openrouter,other';

    private static function provider(string $p): string
    {
        if (! in_array($p, self::PROVIDERS, true)) {
            throw Fail::bad('Input validation failed');
        }

        return $p;
    }

    public function integrations(Request $r)
    {
        return Paginate::page(S::listIntegrations(ctx()), $r, fn ($i) => $i['provider']);
    }

    public function connect(Request $r, string $provider)
    {
        $in = Input::get($r, ['apiKey' => 'nullable|string|max:4000', 'fields' => 'nullable|array', 'fields.*' => 'nullable|string|max:4000', 'campaignId' => 'nullable|uuid', 'method' => 'nullable|in:webhook,api_key,oauth']);

        return S::connectIntegration(ctx(), self::provider($provider), $in);
    }

    public function verify(string $provider)
    {
        return S::verifyIntegration(ctx(), self::provider($provider));
    }

    public function disconnect(string $provider)
    {
        return S::disconnectIntegration(ctx(), self::provider($provider));
    }

    public function verifyAi(Request $r)
    {
        return S::verifyAi(ctx(), Input::get($r, ['which' => 'nullable|in:primary,fallback'])['which'] ?? 'primary');
    }

    public function inbound(Request $r)
    {
        return Paginate::page(S::listInbound(ctx()), $r);
    }

    public function createInbound(Request $r)
    {
        $in = Input::get($r, ['sourceLabel' => 'required|string|min:1|max:255', 'campaignId' => 'nullable|uuid']);

        return S::createInbound(ctx(), $in['sourceLabel'], $in['campaignId'] ?? null);
    }

    public function deleteInbound(string $id)
    {
        return S::deleteInbound(ctx(), $id);
    }

    public function outbound(Request $r)
    {
        return Paginate::page(S::listOutbound(ctx()), $r);
    }

    public function createOutbound(Request $r)
    {
        return S::createOutbound(ctx(), Input::get($r, ['destinationUrl' => 'required|url', 'eventTrigger' => 'required|in:'.implode(',', S::OUTBOUND_EVENTS), 'secret' => 'nullable|string|max:500']));
    }

    public function deleteOutbound(string $id)
    {
        return S::deleteOutbound(ctx(), $id);
    }

    public function aiProvider()
    {
        return S::aiProvider(ctx());
    }

    public function patchAiProvider(Request $r)
    {
        return S::patchAiProvider(ctx(), Input::get($r, [
            'primary' => 'sometimes|array', 'primary.provider' => 'nullable|in:'.self::AI_PROVIDERS, 'primary.modelName' => 'nullable|string|max:255', 'primary.apiKey' => 'nullable|string|max:2000',
            'fallback' => 'sometimes|array', 'fallback.enabled' => 'sometimes|boolean', 'fallback.provider' => 'nullable|in:'.self::AI_PROVIDERS, 'fallback.modelName' => 'nullable|string|max:255', 'fallback.apiKey' => 'nullable|string|max:2000',
            'refreshIntervalMinutes' => 'sometimes|integer|min:5|max:1440', 'eventTriggers' => 'sometimes|array', 'eventTriggers.*' => 'string',
        ]));
    }

    public function telegram()
    {
        return S::telegram(ctx());
    }

    public function patchTelegram(Request $r)
    {
        return S::patchTelegram(ctx(), Input::get($r, ['criticalAlertsEnabled' => 'sometimes|boolean', 'dailyDigestEnabled' => 'sometimes|boolean']));
    }

    public function verifyTelegram(Request $r)
    {
        return S::verifyTelegram(ctx(), Input::get($r, ['botToken' => 'required|string|min:10|max:200'])['botToken']);
    }

    public function disconnectTelegram()
    {
        return S::disconnectTelegram(ctx());
    }
}
