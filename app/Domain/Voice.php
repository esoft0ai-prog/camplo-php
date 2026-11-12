<?php

namespace App\Domain;

/** Camplo's voice: plain words, no filler. Shared by the chat agent, the briefing writer and the memory layer. */
final class Voice
{
    /** @return array<int,array{0:string,1:string|callable}> */
    private static function banned(): array
    {
        return [
            ['/\bdelv(?:e|es|ed|ing) into\b/i', 'look at'], ['/\bdelv(?:e|es|ed|ing)\b/i', 'dig'],
            ['/\bunlock(s|ed|ing)?\b/i', fn ($x) => 'open'.(['s' => 's', 'ed' => 'ed', 'ing' => 'ing'][strtolower($x)] ?? '').' up'],
            ['/\bcomprehensive\b/i', 'full'], ['/\btapestry\b/i', 'mix'], ['/\b(?:a )?testament to\b/i', 'proof of'],
            ['/\bever-evolving\b/i', 'changing'], ['/\bseamless(ly)?\b/i', fn ($x) => 'smooth'.$x], ['/\brevolutionary\b/i', 'new'],
            ['/\bempower(s|ed|ing)?\b/i', fn ($x) => 'help'.$x], ['/\bjourney\b/i', 'process'], ['/\btransformation\b/i', 'change'],
            ['/\bgame[- ]changer\b/i', 'big win'], ['/\bcutting[- ]edge\b/i', 'latest'], ['/\binnovative\b/i', 'new'],
            ['/\bholistic\b/i', 'whole'], ['/\brobust\b/i', 'solid'], ['/\bsynerg(?:y|ies)\b/i', 'fit'], ['/\bauthentic\b/i', 'real'],
            ['/\bleverag(e|es|ed|ing)\b/i', fn ($x) => ['e' => 'use', 'es' => 'uses', 'ed' => 'used', 'ing' => 'using'][strtolower($x)] ?? 'use'],
            ['/\becosystem\b/i', 'setup'],
        ];
    }

    private const FILLER = '\b(?:it is|it\'s) (?:worth noting|important to (?:understand|note)) that\s*|\b(?:in conclusion|furthermore|moreover),\s*';

    private static function matchCase(string $src, string $out): string
    {
        if (mb_strlen($src) > 1 && $src === mb_strtoupper($src)) {
            return mb_strtoupper($out);
        }
        $f = mb_substr($src, 0, 1);

        return $f === mb_strtoupper($f) ? mb_strtoupper(mb_substr($out, 0, 1)).mb_substr($out, 1) : $out;
    }

    /** Rewrite banned words and strip filler phrases, keeping markdown and links intact. */
    public static function scrub(string $text): string
    {
        // Remove filler openers; capitalise the next word when it now starts a sentence.
        $out = preg_replace_callback('/(?:'.self::FILLER.')([a-z])?/i', function (array $m) use ($text) {
            [$whole, $at] = $m[0];
            $c = isset($m[1]) && $m[1][1] >= 0 ? $m[1][0] : '';
            if ($c === '') {
                return '';
            }
            $before = rtrim(substr($text, 0, $at));

            return $before === '' || preg_match('/[.!?:\n*]$/', $before) ? strtoupper($c) : $c;
        }, $text, -1, $count, PREG_OFFSET_CAPTURE);
        foreach (self::banned() as [$re, $rep]) {
            $out = preg_replace_callback($re, fn ($m) => self::matchCase($m[0], is_string($rep) ? $rep : $rep($m[1] ?? '')), $out);
        }

        return $out;
    }

    public static function bannedHits(string $text): array
    {
        $hits = [];
        foreach (self::banned() as [$re]) {
            if (preg_match($re, $text, $m)) {
                $hits[] = strtolower($m[0]);
            }
        }

        return $hits;
    }

    /** greeting | thanks | venting | about_assistant | off_topic | work — small talk uses the quick tier. */
    public static function classify(string $message): string
    {
        $m = strtolower(trim($message));
        $short = strlen($m) <= 60;
        if ($short && preg_match('/^(hi|hey|hello|yo|sup|good (morning|afternoon|evening)|morning|howdy|hiya|what\'?s up)\b[\s!.,?a-z]*$/', $m) && ! preg_match('/\b(lead|campaign|sla|cpl|page)\b/', $m)) {
            return 'greeting';
        }
        if ($short && preg_match('/^(thanks|thank you|thx|ty|cheers|great,? thanks|perfect|awesome|nice one|appreciate it)\b/', $m)) {
            return 'thanks';
        }
        if (preg_match('/\b(who|what) are you\b|\bare you (an? )?(ai|bot|human|real)\b|\bwhat can you do\b|\bdo you remember\b|\bhow do you work\b/', $m)) {
            return 'about_assistant';
        }
        if (preg_match('/\b(i\'?m|i am|so) (tired|frustrated|stressed|annoyed|exhausted|done|fed up)\b|\b(this is|it\'?s) (so )?(annoying|frustrating|ridiculous)\b|\bugh+\b/', $m)) {
            return 'venting';
        }
        if (preg_match('/\b(weather|recipe|movie|football|song|joke|poem|bitcoin price)\b/', $m) && ! preg_match('/\b(lead|campaign|sla|cpl|ad|page|client)\b/', $m)) {
            return 'off_topic';
        }

        return 'work';
    }

    /** Memory defence: operational facts and working preferences only — never instructions, credentials or venting. */
    public static function safeMemory(string $fact): bool
    {
        $f = trim($fact);
        if (mb_strlen($f) < 8 || mb_strlen($f) > 300) {
            return false;
        }
        if (preg_match('/\b(ignore|disregard|override|forget)\b.*\b(instruction|prompt|rule|previous|above)s?\b|\bsystem prompt\b|\byou (must|should) (always|never) (say|reply|answer|obey)\b|\bjailbreak\b|\bpretend\b/i', $f)) {
            return false;
        }
        if (preg_match('/\b(sk-|gsk_|xox[bp]-|AKIA)[A-Za-z0-9]|\bpassword\b|\bapi key\b|\btoken\b/i', $f)) {
            return false;
        }

        return ! preg_match('/\b(i hate|i\'?m (sad|depressed|tired|exhausted)|my (wife|husband|girlfriend|boyfriend|kids?))\b/i', $f);
    }

    /** An explicit "remember / from now on / I prefer" statement from the user. */
    public static function explicitPreference(string $message): ?string
    {
        $m = preg_replace('/\s+/', ' ', trim($message));
        if (! preg_match('/^(?:please )?(?:remember(?: that)?|note(?: that)?|from now on,?|going forward,?|for future reference,?)\s+(.{8,240})$/i', $m, $hit)
            && ! preg_match('/^(i prefer .{6,240}|we (?:always|never|usually) .{6,240}|our .{3,40} (?:is|are) .{3,200})$/i', $m, $hit)) {
            return null;
        }
        $fact = preg_replace('/[.!]+$/', '', $hit[1]);

        return self::safeMemory($fact) ? $fact : null;
    }
}
