<?php

namespace App\Http\Controllers;

use App\Services\IntelService as I;
use App\Support\Paginate;
use Illuminate\Http\Request;

class IntelController
{
    public function insights(Request $r)
    {
        return Paginate::page(I::listInsights(ctx()), $r);
    }

    public function insight(string $id)
    {
        return I::getInsight(ctx(), $id);
    }

    public function dismissInsight(string $id)
    {
        return I::dismissInsight(ctx(), $id);
    }

    public function recommendations(Request $r)
    {
        return Paginate::page(I::listRecommendations(ctx()), $r);
    }

    public function recommendation(string $id)
    {
        return I::getRecommendation(ctx(), $id);
    }

    public function dismissRecommendation(string $id)
    {
        return I::dismissRecommendation(ctx(), $id);
    }

    public function applyRecommendation(string $id)
    {
        ctx();
        I::applyRecommendation();
    }

    public function chatHistory(Request $r)
    {
        $h = I::chatHistory(ctx());
        $messages = $h['messages'];
        unset($h['messages']);

        return [...$h, ...Paginate::page($messages, $r, fromEnd: true)];
    }

    public function chatSuggestions()
    {
        return I::chatSuggestions(ctx());
    }

    public function chatClear()
    {
        return I::clearChat(ctx());
    }
}
